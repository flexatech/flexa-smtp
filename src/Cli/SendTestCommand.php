<?php

declare(strict_types=1);

namespace Flexa\Smtp\Cli;

use Flexa\Smtp\Support\Settings;
use WP_CLI;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Send a test email through the full Flexa SMTP pipeline (active mailer, From
 * overrides, fallback) so you can confirm delivery works without opening a
 * browser. Mirrors the REST test-mail endpoint exactly.
 *
 *     wp flexa-smtp test <to> [--subject=<subject>] [--html]
 */
final class SendTestCommand {
	public static function register(): void {
		if ( ! class_exists( WP_CLI::class ) ) {
			return;
		}
		WP_CLI::add_command( 'flexa-smtp test', self::class );
	}

	/**
	 * Send a test email to a recipient.
	 *
	 * ## OPTIONS
	 *
	 * <to>
	 * : The recipient email address.
	 *
	 * [--subject=<subject>]
	 * : Custom subject line. Defaults to a generated "test email from <site>".
	 *
	 * [--html]
	 * : Send an HTML body instead of plain text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flexa-smtp test you@example.com
	 *     wp flexa-smtp test you@example.com --subject="Ping" --html
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc ): void {
		$to = sanitize_email( (string) ( $args[0] ?? '' ) );
		if ( '' === $to || ! is_email( $to ) ) {
			WP_CLI::error( 'A valid recipient email address is required.' );
		}

		$subject = isset( $assoc['subject'] ) ? sanitize_text_field( (string) $assoc['subject'] ) : '';
		if ( '' === $subject ) {
			$subject = sprintf(
				'Flexa SMTP test email from %s',
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			);
		}

		$is_html = isset( $assoc['html'] );
		$body    = $is_html
			? '<p>This is a Flexa SMTP test email. If you received it, your mailer is configured correctly.</p>'
			: 'This is a Flexa SMTP test email. If you received it, your mailer is configured correctly.';
		$headers = $is_html ? [ 'Content-Type: text/html; charset=UTF-8' ] : [];

		$captured = '';
		$listener = static function ( WP_Error $error ) use ( &$captured ): void {
			$captured = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $listener );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_action( 'wp_mail_failed', $listener );

		if ( $sent ) {
			WP_CLI::success(
				sprintf(
					'Test email sent to %s via %s.',
					$to,
					(string) Settings::get( 'current_mailer' )
				)
			);
			return;
		}

		WP_CLI::error(
			'' !== $captured ? $captured : 'The test email could not be sent.'
		);
	}
}
