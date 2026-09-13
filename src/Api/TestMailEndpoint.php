<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/flexa-smtp/v1/test-mail — send a test email through the full
 * pipeline (active mailer, From overrides, fallback) so the admin can confirm a
 * mailer really works. The failure reason is captured from `wp_mail_failed` and
 * returned verbatim to the UI.
 */
final class TestMailEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/test-mail',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'send_test' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'to'      => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_email',
						],
						'subject' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'html'    => [
							'type'     => 'boolean',
							'required' => false,
							'default'  => true,
						],
					],
				],
			]
		);
	}

	public function send_test( WP_REST_Request $request ): WP_REST_Response {
		$to = sanitize_email( (string) $request->get_param( 'to' ) );
		if ( '' === $to || ! is_email( $to ) ) {
			return new WP_REST_Response(
				[
					'ok'    => false,
					'error' => __( 'A valid recipient email address is required.', 'flexa-smtp' ),
				],
				400
			);
		}

		$subject = (string) $request->get_param( 'subject' );
		if ( '' === $subject ) {
			$subject = sprintf(
				/* translators: %s: site name. */
				__( 'Flexa SMTP test email from %s', 'flexa-smtp' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			);
		}

		$is_html = (bool) $request->get_param( 'html' );
		$body    = $is_html
			? '<p>' . esc_html__( 'This is a Flexa SMTP test email. If you received it, your mailer is configured correctly.', 'flexa-smtp' ) . '</p>'
			: __( 'This is a Flexa SMTP test email. If you received it, your mailer is configured correctly.', 'flexa-smtp' );
		$headers = $is_html ? [ 'Content-Type: text/html; charset=UTF-8' ] : [];

		$captured = '';
		$listener = static function ( WP_Error $error ) use ( &$captured ): void {
			$captured = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $listener );
		// Send synchronously even when the queue is enabled, so the admin gets an
		// immediate pass/fail instead of a "queued" result they cannot verify.
		add_filter( 'flexa_smtp.mail.bypass_queue', '__return_true' );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_filter( 'flexa_smtp.mail.bypass_queue', '__return_true' );
		remove_action( 'wp_mail_failed', $listener );

		if ( $sent ) {
			return new WP_REST_Response(
				[
					'ok'     => true,
					'mailer' => (string) Settings::get( 'current_mailer' ),
				]
			);
		}

		return new WP_REST_Response(
			[
				'ok'    => false,
				'error' => '' !== $captured ? $captured : __( 'The test email could not be sent.', 'flexa-smtp' ),
			]
		);
	}
}
