<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Diagnostics\Diagnostics;
use Flexa\Smtp\Domain\EmailLog;
use Flexa\Smtp\Logging\MailLogger;
use Flexa\Smtp\Mailer\Contracts\PhpMailerConfigurator;
use Flexa\Smtp\Mailer\Contracts\MailerInterface;
use Flexa\Smtp\Queue\Queue;
use Flexa\Smtp\Support\Settings;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * The heart of the send pipeline. It installs {@see PhpMailerBridge} as the
 * global PHPMailer, applies the From overrides, and owns {@see dispatch()} —
 * the one place that selects the active mailer, honours dev-mode and fallback,
 * and fires the send lifecycle hooks. Providers never log or branch on their
 * own; they just report a {@see Result}.
 */
final class MailerManager {
	use HasInstance;

	public function register(): void {
		add_action( 'plugins_loaded', [ $this, 'install_bridge' ], 100 );
		add_filter( 'wp_mail_from', [ $this, 'filter_from_email' ], 20 );
		add_filter( 'wp_mail_from_name', [ $this, 'filter_from_name' ], 20 );
	}

	/**
	 * Put our PHPMailer subclass in the global slot before the first wp_mail().
	 * wp_mail() only creates its own instance when the global is not already a
	 * PHPMailer, so once ours is in place it is reused (and cleared) each call.
	 */
	public function install_bridge(): void {
		global $phpmailer;

		if ( $phpmailer instanceof PhpMailerBridge ) {
			return;
		}

		if ( ! class_exists( PHPMailer::class ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		$bridge = new PhpMailerBridge( true );
		$bridge->set_manager( $this );

		$phpmailer = $bridge;
	}

	public function filter_from_email( string $from ): string {
		$configured = (string) Settings::get( 'from_email' );
		if ( '' === $configured ) {
			return $from;
		}

		if ( (bool) Settings::get( 'force_from_email' ) ) {
			return $configured;
		}

		// Non-forced: only fill in when the caller did not set a From of its own,
		// i.e. WordPress handed us its computed default.
		return $from === self::default_from_email() ? $configured : $from;
	}

	public function filter_from_name( string $name ): string {
		$configured = (string) Settings::get( 'from_name' );
		if ( '' === $configured ) {
			return $name;
		}

		if ( (bool) Settings::get( 'force_from_name' ) ) {
			return $configured;
		}

		return 'WordPress' === $name ? $configured : $name;
	}

	/**
	 * Route a fully-populated PHPMailer through the active mailer, with fallback.
	 *
	 * @throws Exception When every mailer in the chain fails, so wp_mail() emits
	 *                   `wp_mail_failed` exactly as it would for a native failure.
	 */
	public function dispatch( PhpMailerBridge $php ): bool {
		if ( (bool) Settings::get( 'disable_delivery' ) ) {
			/**
			 * Dev-mode: nothing is actually sent. Fires so logging/UX can record
			 * the suppressed message.
			 *
			 * @param PhpMailerBridge $php
			 * @param string          $slug
			 */
			do_action( 'flexa_smtp.mail.before_send', $php, 'disabled' );
			do_action( 'flexa_smtp.mail.sent', $php, 'disabled', [ 'disabled' => true ] );

			return true;
		}

		// Opt-in queue: hand the fully-populated message off for async delivery and
		// return true right away. before_send still fires first so the message is
		// logged and tracking rewrites the body before we snapshot the payload.
		if ( $this->should_queue() ) {
			do_action( 'flexa_smtp.mail.before_send', $php, $this->primary_slug() );

			$log_id = MailLogger::instance()->current_log_id();
			$code   = Queue::instance()->enqueue( $php, $log_id, false );

			if ( Queue::ENQUEUE_OK === $code ) {
				do_action( 'flexa_smtp.mail.queued', $php, EmailLog::STATUS_QUEUED );
				Queue::instance()->schedule_worker();

				return true;
			}

			if ( Queue::ENQUEUE_DUPLICATE === $code ) {
				do_action( 'flexa_smtp.mail.queued', $php, EmailLog::STATUS_CANCELLED );

				return true;
			}

			// ENQUEUE_FAILED: fall through to the synchronous path so the email is not
			// lost. before_send already ran; its logger/tracking hooks are idempotent.
		}

		$last_error  = '';
		$last_result = null;
		foreach ( $this->mailer_chain() as $slug ) {
			$mailer = MailerRegistry::instance()->make( $slug );
			if ( ! $mailer instanceof MailerInterface ) {
				$last_error = sprintf( 'unknown mailer "%s"', $slug );
				continue;
			}

			/**
			 * Fires just before an attempt with a given mailer.
			 *
			 * @param PhpMailerBridge $php
			 * @param string          $slug
			 */
			do_action( 'flexa_smtp.mail.before_send', $php, $slug );

			$result = $this->attempt( $mailer, $php );

			if ( $result->ok ) {
				/**
				 * Fires after a successful send. $meta carries the normalised
				 * DeliveryResult fields (response_code, provider_message_id,
				 * duration_ms) alongside the legacy mailer/code keys.
				 *
				 * @param PhpMailerBridge      $php
				 * @param string               $slug
				 * @param array<string, mixed> $meta
				 */
				do_action( 'flexa_smtp.mail.sent', $php, $slug, $result->to_meta() );

				return true;
			}

			$last_error  = (string) $result->error;
			$last_result = $result;

			/**
			 * Fires after a failed attempt (each mailer in the fallback chain).
			 * $meta carries error_category + retryable from Diagnostics plus
			 * response_code/duration_ms.
			 *
			 * @param PhpMailerBridge      $php
			 * @param string               $slug
			 * @param string               $error
			 * @param array<string, mixed> $meta
			 */
			do_action( 'flexa_smtp.mail.failed', $php, $slug, $last_error, $result->to_meta() );
		}

		// Opt-in retry: a retryable failure is queued for another attempt with backoff
		// instead of surfacing as a hard failure. wp_mail() then returns true because
		// the message is still in flight; this only happens when the admin enabled it.
		if ( $this->should_retry( $last_result ) ) {
			$log_id = MailLogger::instance()->current_log_id();
			if ( Queue::ENQUEUE_OK === Queue::instance()->enqueue( $php, $log_id, true ) ) {
				do_action( 'flexa_smtp.mail.queued', $php, EmailLog::STATUS_RETRYING );
				Queue::instance()->schedule_worker();

				return true;
			}
		}

		throw new Exception( esc_html( '' !== $last_error ? $last_error : 'flexa-smtp: no mailer could send the message' ) );
	}

	/**
	 * Run the mailer chain for a message the queue worker rebuilt, returning the
	 * final {@see Result}. Unlike {@see dispatch()} it fires no lifecycle hooks and
	 * never queues — the worker owns settling the log row — so there is no risk of
	 * re-enqueuing. Honours dev-mode just like the synchronous path.
	 */
	public function deliver( PhpMailerBridge $php ): Result {
		if ( (bool) Settings::get( 'disable_delivery' ) ) {
			return Result::success(
				[
					'mailer'   => 'disabled',
					'disabled' => true,
				]
			);
		}

		$last = null;
		foreach ( $this->mailer_chain() as $slug ) {
			$mailer = MailerRegistry::instance()->make( $slug );
			if ( ! $mailer instanceof MailerInterface ) {
				$last = Result::error( sprintf( 'unknown mailer "%s"', $slug ) );
				continue;
			}

			$result = $this->attempt( $mailer, $php );
			if ( $result->ok ) {
				return $result;
			}
			$last = $result;
		}

		return $last ?? Result::error( 'flexa-smtp: no mailer could send the message' );
	}

	/**
	 * Whether outgoing mail should be deferred to the queue on this request. The
	 * bypass filter lets latency-sensitive callers (e.g. the "send test email"
	 * action) force a synchronous send.
	 */
	private function should_queue(): bool {
		if ( ! (bool) Settings::get( 'enable_queue' ) ) {
			return false;
		}

		/**
		 * Return true to skip the queue and send this message synchronously.
		 *
		 * @param bool $bypass
		 */
		if ( (bool) apply_filters( 'flexa_smtp.mail.bypass_queue', false ) ) {
			return false;
		}

		return class_exists( Queue::class );
	}

	/**
	 * Whether a failed synchronous send should be queued for a background retry.
	 */
	private function should_retry( ?Result $result ): bool {
		if ( ! (bool) Settings::get( 'enable_retry' ) || ! class_exists( Queue::class ) ) {
			return false;
		}
		if ( ! $result instanceof Result || true !== $result->retryable ) {
			return false;
		}
		if ( (int) Settings::get( 'queue_max_attempts' ) < 2 ) {
			return false;
		}

		/**
		 * Final say on whether a message is retried. Defaults to true for any error
		 * the diagnostics engine marked retryable.
		 *
		 * @param bool        $retry
		 * @param string|null $category
		 * @param Result      $result
		 */
		return (bool) apply_filters( 'flexa_smtp.queue.should_retry', true, $result->error_category, $result );
	}

	private function primary_slug(): string {
		$chain = $this->mailer_chain();

		return $chain[0] ?? 'mail';
	}

	/**
	 * @return list<string>
	 */
	private function mailer_chain(): array {
		$primary = (string) Settings::get( 'current_mailer' );
		if ( '' === $primary ) {
			$primary = 'mail';
		}

		$chain = [ $primary ];

		if ( (bool) Settings::get( 'fallback_enabled' ) ) {
			$fallback = (string) Settings::get( 'fallback_mailer' );
			if ( '' !== $fallback ) {
				$chain[] = $fallback;
			}
		}

		return array_values( array_unique( $chain ) );
	}

	/**
	 * Run one mailer, time it, and (on failure) attach the normalised diagnosis so
	 * the whole plugin reads retryability/category off a single {@see Result}.
	 */
	private function attempt( MailerInterface $mailer, PhpMailerBridge $php ): Result {
		$started = microtime( true );
		$result  = $this->run( $mailer, $php );
		$result  = $result->with_duration( (int) round( ( microtime( true ) - $started ) * 1000 ) );

		if ( $result->ok ) {
			return $result;
		}

		$code = $result->response_code ?? $this->parse_smtp_code( (string) $result->error );
		$diag = Diagnostics::classify( $code, (string) $result->error, $mailer->slug() );

		return $result->with_transport( $code, null )->with_diagnosis( $diag );
	}

	private function run( MailerInterface $mailer, PhpMailerBridge $php ): Result {
		if ( $mailer instanceof PhpMailerConfigurator ) {
			$mailer->configure( $php );
			try {
				return $php->send_native()
					? Result::success( [ 'mailer' => $mailer->slug() ] )
					: Result::error( (string) $php->ErrorInfo, [ 'mailer' => $mailer->slug() ] );
			} catch ( Exception $e ) {
				return Result::error( $e->getMessage(), [ 'mailer' => $mailer->slug() ] );
			}
		}

		// API/OAuth provider: hand it a built message and let it call its HTTP API.
		try {
			$php->preSend();
		} catch ( Exception $e ) {
			return Result::error( $e->getMessage(), [ 'mailer' => $mailer->slug() ] );
		}

		return $mailer->send( Message::from_phpmailer( $php ) );
	}

	/**
	 * Best-effort SMTP reply code from a PHPMailer error string (which rarely
	 * exposes it cleanly). Matches a standalone 4xx/5xx number, the range mail
	 * servers use for replies. Returns null when nothing plausible is present.
	 */
	private function parse_smtp_code( string $error ): ?int {
		if ( 1 === preg_match( '/\b([45]\d{2})\b/', $error, $m ) ) {
			return (int) $m[1];
		}

		return null;
	}

	private static function default_from_email(): string {
		// Mirrors WordPress core's wp_mail() default sender derivation.
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$sitename = is_string( $sitename ) ? strtolower( $sitename ) : '';
		if ( str_starts_with( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return 'wordpress@' . $sitename;
	}
}
