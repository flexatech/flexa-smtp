<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Mailer\Contracts\ConfiguresPhpMailer;
use Flexa\Smtp\Mailer\Contracts\MailerInterface;
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

		$last_error = '';
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
				 * Fires after a successful send.
				 *
				 * @param PhpMailerBridge      $php
				 * @param string               $slug
				 * @param array<string, mixed> $meta
				 */
				do_action( 'flexa_smtp.mail.sent', $php, $slug, $result->meta );

				return true;
			}

			$last_error = (string) $result->error;

			/**
			 * Fires after a failed attempt (each mailer in the fallback chain).
			 *
			 * @param PhpMailerBridge      $php
			 * @param string               $slug
			 * @param string               $error
			 * @param array<string, mixed> $meta
			 */
			do_action( 'flexa_smtp.mail.failed', $php, $slug, $last_error, $result->meta );
		}

		throw new Exception( '' !== $last_error ? $last_error : 'flexa-smtp: no mailer could send the message' );
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

	private function attempt( MailerInterface $mailer, PhpMailerBridge $php ): Result {
		if ( $mailer instanceof ConfiguresPhpMailer ) {
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
