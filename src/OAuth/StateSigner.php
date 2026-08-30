<?php

declare(strict_types=1);

namespace Flexa\Smtp\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-signed `state` parameter for the OAuth authorize→callback round trip.
 *
 * The provider redirects the *browser* back to our REST callback as a top-level
 * GET, which carries the auth cookie but no REST nonce — so cookie-based REST auth
 * (which requires a valid X-WP-Nonce) cannot recognise the user there. Instead the
 * callback is gated on this signed state: it is minted only while an authenticated
 * admin starts the flow, is bound to the provider, expires quickly, and cannot be
 * forged without the site secret. This is the deliberate improvement over YaySMTP,
 * which left its OAuth/tracking endpoints on `__return_true`.
 */
final class StateSigner {
	private const TTL = 600;

	public static function issue( string $provider ): string {
		$payload = [
			'p' => $provider,
			'u' => get_current_user_id(),
			'e' => time() + self::TTL,
			'n' => wp_generate_password( 12, false ),
		];

		$body = self::b64url_encode( (string) wp_json_encode( $payload ) );

		return $body . '.' . self::sign( $body );
	}

	public static function verify( string $state, string $provider ): bool {
		if ( ! str_contains( $state, '.' ) ) {
			return false;
		}

		[ $body, $sig ] = explode( '.', $state, 2 );

		if ( ! hash_equals( self::sign( $body ), $sig ) ) {
			return false;
		}

		$decoded = self::b64url_decode( $body );
		$data    = json_decode( $decoded, true );
		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( (string) ( $data['p'] ?? '' ) !== $provider ) {
			return false;
		}

		return (int) ( $data['e'] ?? 0 ) >= time();
	}

	private static function sign( string $body ): string {
		return hash_hmac( 'sha256', $body, self::key() );
	}

	private static function key(): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'flexa-smtp-insecure-fallback';

		return hash( 'sha256', 'flexa-smtp-oauth-state|' . $salt );
	}

	private static function b64url_encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $encoded ): string {
		$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );

		return false === $decoded ? '' : $decoded;
	}
}
