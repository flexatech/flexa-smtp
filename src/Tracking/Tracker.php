<?php

declare(strict_types=1);

namespace Flexa\Smtp\Tracking;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Mailer\PhpMailerBridge;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires open/click tracking into the send pipeline. It reacts to
 * `flexa_smtp.log.created` — fired by {@see \Flexa\Smtp\Logging\MailLogger} once
 * the log row exists (so a real log id is available) but before the transport
 * sends — and rewrites the outgoing HTML body in place: click links first, then
 * the open pixel. The logged body was snapshotted before this runs, so the stored
 * copy stays clean while the recipient receives the tracked version.
 *
 * Tracking therefore requires logging to be enabled (there must be a row to attach
 * opens/clicks to) and only touches HTML emails.
 */
final class Tracker {
	use HasInstance;

	public function register(): void {
		add_action( 'flexa_smtp.log.created', [ $this, 'on_log_created' ], 10, 2 );
	}

	/**
	 * @param mixed $log_id
	 * @param mixed $php
	 */
	public function on_log_created( $log_id, $php ): void {
		$log_id = (int) $log_id;
		if ( $log_id <= 0 || ! $php instanceof PhpMailerBridge ) {
			return;
		}
		if ( ! self::is_html( $php ) ) {
			return;
		}

		$open  = (bool) Settings::get( 'enable_open_tracking' );
		$click = (bool) Settings::get( 'enable_click_tracking' );
		if ( ! $open && ! $click ) {
			return;
		}

		$body = (string) $php->Body;
		if ( '' === trim( $body ) ) {
			return;
		}

		if ( $click ) {
			$body = LinkRewriter::rewrite( $body, $log_id );
		}
		if ( $open ) {
			$body = PixelInjector::inject( $body, $log_id );
		}

		$php->Body = $body;
	}

	private static function is_html( PhpMailerBridge $php ): bool {
		return str_contains( strtolower( (string) $php->ContentType ), 'html' );
	}
}
