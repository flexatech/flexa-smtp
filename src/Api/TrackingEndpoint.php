<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Domain\ClickEventRepository;
use Flexa\Smtp\Domain\OpenEventRepository;
use Flexa\Smtp\Tracking\TokenSigner;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Public tracking endpoints hit by the recipient's mail client:
 *   GET /track/open/{token}  — 1x1 pixel; records an open, returns a GIF
 *   GET /track/click/{token} — records a click, then redirects to the real URL
 *
 * The recipient is never logged in, so neither route can use cookie/nonce auth.
 * Each is gated on the HMAC {@see TokenSigner} token embedded in the email at send
 * time: an invalid or tampered token is rejected (403) in the permission callback,
 * NOT `__return_true`. This class is also the single source of the route strings —
 * {@see open_url()}/{@see click_url()} are what the injector and rewriter embed.
 */
final class TrackingEndpoint extends Endpoint {
	/**
	 * A 1x1 fully-transparent GIF (43 bytes), base64-encoded.
	 */
	private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	private const TOKEN_ARG = [
		'token' => [
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => [ self::class, 'sanitize_token' ],
		],
	];

	public static function open_url( int $log_id ): string {
		return rest_url( self::NAMESPACE . '/track/open/' . TokenSigner::issue_open( $log_id ) );
	}

	public static function click_url( int $log_id, string $url ): string {
		return rest_url( self::NAMESPACE . '/track/click/' . TokenSigner::issue_click( $log_id, $url ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/track/open/(?P<token>[A-Za-z0-9_\-.]+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'open' ],
					'permission_callback' => [ $this, 'open_permission' ],
					'args'                => self::TOKEN_ARG,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/track/click/(?P<token>[A-Za-z0-9_\-.]+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'click' ],
					'permission_callback' => [ $this, 'click_permission' ],
					'args'                => self::TOKEN_ARG,
				],
			]
		);
	}

	public function open( WP_REST_Request $request ): void {
		$log_id = TokenSigner::verify_open( (string) $request->get_param( 'token' ) );
		if ( null !== $log_id ) {
			( new OpenEventRepository() )->record( $log_id, $this->context() );
		}

		$gif = (string) base64_decode( self::PIXEL, true );

		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: ' . strlen( $gif ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image body.
		echo $gif;
		exit;
	}

	public function click( WP_REST_Request $request ): void {
		$data = TokenSigner::verify_click( (string) $request->get_param( 'token' ) );

		$url = null !== $data ? $data['url'] : home_url( '/' );
		if ( null !== $data && $this->is_web_url( $url ) ) {
			( new ClickEventRepository() )->record( $data['log_id'], $url, $this->context() );
		} else {
			$url = home_url( '/' );
		}

		// wp_redirect (not wp_safe_redirect): the destination is intentionally an
		// external site, but it came from an HMAC-signed token so it is exactly the
		// URL we embedded — it cannot have been swapped for an open redirect.
		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public function open_permission( WP_REST_Request $request ): bool|WP_Error {
		return null !== TokenSigner::verify_open( (string) $request->get_param( 'token' ) )
			? true
			: $this->bad_token();
	}

	public function click_permission( WP_REST_Request $request ): bool|WP_Error {
		return null !== TokenSigner::verify_click( (string) $request->get_param( 'token' ) )
			? true
			: $this->bad_token();
	}

	public static function sanitize_token( mixed $value ): string {
		$value = is_string( $value ) ? $value : '';

		return (string) preg_replace( '/[^A-Za-z0-9_\-.]/', '', $value );
	}

	private function bad_token(): WP_Error {
		return new WP_Error(
			'flexa_smtp_bad_token',
			__( 'Invalid tracking token.', 'flexa-smtp' ),
			[ 'status' => 403 ]
		);
	}

	private function is_web_url( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return 'http' === $scheme || 'https' === $scheme;
	}

	/**
	 * Minimal, non-identifying context stored with an event.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return '' !== $ua ? [ 'ua' => substr( $ua, 0, 255 ) ] : [];
	}
}
