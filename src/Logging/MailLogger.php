<?php

declare(strict_types=1);

namespace Flexa\Smtp\Logging;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Domain\EmailLog;
use Flexa\Smtp\Domain\EmailLogRepository;
use Flexa\Smtp\Mailer\PhpMailerBridge;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the mailer lifecycle hooks fired by {@see \Flexa\Smtp\Mailer\MailerManager}
 * into exactly one log row per wp_mail() call. Providers never write logs
 * themselves — this subscriber owns both outcomes so success and failure can
 * never drift.
 *
 * A single wp_mail() may try several mailers (fallback), firing before_send once
 * per attempt and either one `mail.sent` (final success) or, when every attempt
 * fails, a `wp_mail_failed` from wp_mail() after MailerManager re-throws. Because
 * wp_mail() is sequential within a request, one "pending" slot is enough to
 * correlate those events; the snapshot is captured on the first before_send.
 */
final class MailLogger {
	use HasInstance;

	/**
	 * The message captured on the first before_send of the current dispatch, or
	 * null when nothing is in flight / logging is disabled for this send.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $pending = null;

	/**
	 * Per-attempt failure reasons accumulated across the fallback chain.
	 *
	 * @var list<string>
	 */
	private array $errors = [];

	private ?EmailLogRepository $repository = null;

	public function register(): void {
		add_action( 'flexa_smtp.mail.before_send', [ $this, 'on_before_send' ], 10, 2 );
		add_action( 'flexa_smtp.mail.failed', [ $this, 'on_failed' ], 10, 4 );
		add_action( 'flexa_smtp.mail.sent', [ $this, 'on_sent' ], 10, 3 );
		// Fires when MailerManager re-throws after the whole chain fails.
		add_action( 'wp_mail_failed', [ $this, 'on_wp_mail_failed' ], 5 );
	}

	/**
	 * @param mixed  $php
	 * @param string $slug
	 */
	public function on_before_send( $php, $slug ): void {
		unset( $slug );

		if ( ! $php instanceof PhpMailerBridge ) {
			return;
		}
		if ( null !== $this->pending ) {
			// Already snapshotted this dispatch (a later mailer in the chain).
			return;
		}
		if ( ! (bool) Settings::get( 'enable_email_log' ) ) {
			return;
		}

		$this->errors   = [];
		$snapshot       = $this->snapshot( $php );
		$snapshot['id'] = $this->repo()->create(
			[
				'subject'      => (string) $snapshot['subject'],
				'email_from'   => (string) $snapshot['from'],
				'email_to'     => $snapshot['to'],
				'mailer'       => (string) $snapshot['mailer_hint'],
				'status'       => EmailLog::STATUS_PENDING,
				'content_type' => (string) $snapshot['content_type'],
				'body_content' => (string) $snapshot['body'],
				'source'       => (string) $snapshot['source'],
				'extra_info'   => $snapshot['extra'],
			]
		);
		$this->pending  = $snapshot;

		if ( (int) $snapshot['id'] > 0 ) {
			/**
			 * Fires once the log row exists (still PENDING) for the message about
			 * to be sent — before the transport reads $php. Tracking hooks this to
			 * rewrite $php->Body against the now-known log id; the snapshot above
			 * already captured the clean body, so the stored copy is untouched.
			 *
			 * @param int             $log_id
			 * @param PhpMailerBridge $php
			 */
			do_action( 'flexa_smtp.log.created', (int) $snapshot['id'], $php );
		}
	}

	/**
	 * @param mixed $php
	 * @param mixed $slug
	 * @param mixed $error
	 * @param mixed $meta
	 */
	public function on_failed( $php, $slug, $error, $meta ): void {
		unset( $php, $meta );

		if ( null === $this->pending ) {
			return;
		}

		$error = is_string( $error ) ? $error : '';
		$slug  = is_string( $slug ) ? $slug : '';

		$this->errors[] = '' !== $slug ? sprintf( '[%s] %s', $slug, $error ) : $error;
	}

	/**
	 * @param mixed $php
	 * @param mixed $slug
	 * @param mixed $meta
	 */
	public function on_sent( $php, $slug, $meta ): void {
		unset( $php );

		if ( null === $this->pending ) {
			return;
		}

		$meta          = is_array( $meta ) ? $meta : [];
		$disabled      = ! empty( $meta['disabled'] );
		$snapshot      = $this->pending;
		$this->pending = null;

		$extra = $snapshot['extra'];
		if ( $disabled ) {
			$extra['delivery_disabled'] = true;
		}

		$this->settle(
			$snapshot,
			EmailLog::STATUS_SENT,
			is_string( $slug ) && '' !== $slug ? $slug : (string) $snapshot['mailer_hint'],
			'',
			$extra
		);
	}

	/**
	 * @param mixed $error WP_Error from wp_mail(), unused — we already have the
	 *                     per-attempt reasons.
	 */
	public function on_wp_mail_failed( $error ): void {
		unset( $error );

		if ( null === $this->pending ) {
			return;
		}

		$snapshot      = $this->pending;
		$this->pending = null;

		$reason = [] !== $this->errors
			? implode( ' | ', $this->errors )
			: __( 'The message could not be sent.', 'flexa-smtp' );

		$this->settle( $snapshot, EmailLog::STATUS_FAILED, (string) $snapshot['mailer_hint'], $reason, $snapshot['extra'] );
	}

	/**
	 * Settle the PENDING row created at before_send into its final outcome.
	 *
	 * @param array<string, mixed> $snapshot
	 * @param array<string, mixed> $extra
	 */
	private function settle( array $snapshot, int $status, string $mailer, string $reason, array $extra ): void {
		$id = (int) ( $snapshot['id'] ?? 0 );
		if ( $id <= 0 ) {
			return;
		}

		$this->repo()->update(
			$id,
			[
				'status'       => $status,
				'mailer'       => '' !== $mailer ? $mailer : (string) $snapshot['mailer_hint'],
				'reason_error' => $reason,
				'extra_info'   => $extra,
			]
		);
	}

	/**
	 * Capture everything we want to log from the fully-populated PHPMailer, plus
	 * the originating source, before the transport can mutate anything.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot( PhpMailerBridge $php ): array {
		$to  = $this->map_addresses( $php->getToAddresses() );
		$cc  = $this->map_addresses( $php->getCcAddresses() );
		$bcc = $this->map_addresses( $php->getBccAddresses() );

		$extra = [];
		if ( [] !== $cc ) {
			$extra['cc'] = $cc;
		}
		if ( [] !== $bcc ) {
			$extra['bcc'] = $bcc;
		}

		return [
			'subject'      => (string) $php->Subject,
			'from'         => (string) $php->From,
			'from_name'    => (string) $php->FromName,
			'to'           => $to,
			'content_type' => (string) $php->ContentType,
			'body'         => (string) $php->Body,
			'source'       => SourceDetector::detect(),
			'mailer_hint'  => (string) Settings::get( 'current_mailer' ),
			'extra'        => $extra,
		];
	}

	/**
	 * @param array<int, mixed> $addresses PHPMailer [ [address, name], ... ]
	 * @return list<array{address:string, name:string}>
	 */
	private function map_addresses( array $addresses ): array {
		$out = [];
		foreach ( $addresses as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = [
				'address' => (string) ( $entry[0] ?? '' ),
				'name'    => (string) ( $entry[1] ?? '' ),
			];
		}

		return $out;
	}

	private function repo(): EmailLogRepository {
		if ( null === $this->repository ) {
			$this->repository = new EmailLogRepository();
		}

		return $this->repository;
	}
}
