<?php

declare(strict_types=1);

namespace Flexa\Smtp\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-signed, self-contained tokens for the open pixel and click links.
 *
 * The tracking endpoints are hit by the *recipient's* mail client, which is never
 * logged in — so they cannot use cookie/nonce auth. Instead every pixel and link
 * carries a token that encodes what it is allowed to record (which log row, and for
 * a click the exact destination URL) signed with the site secret. A forged or
 * tampered token fails {@see verify_open()}/{@see verify_click()} and is rejected —
 * the deliberate replacement for YaySMTP's `__return_true` tracking routes. Because
 * the click URL is signed, the redirect target cannot be swapped (no open redirect).
 *
 * Tokens intentionally do NOT expire: a tracked email can be opened months later.
 */
final class TokenSigner {
	public static function issue_open( int $log_id ): string {
		return self::pack( [ 't' => 'o', 'l' => $log_id ] );
	}

	public static function issue_click( int $log_id, string $url ): string {
		return self::pack( [ 't' => 'c', 'l' => $log_id, 'u' => $url ] );
	}

	/**
	 * @return int|null The log id, or null when the token is invalid.
	 */
	public static function verify_open( string $token ): ?int {
		$data = self::unpack( $token );
		if ( null === $data || 'o' !== ( $data['t'] ?? '' ) ) {
			return null;
		}

		$log_id = (int) ( $data['l'] ?? 0 );

		return $log_id > 0 ? $log_id : null;
	}

	/**
	 * @return array{log_id:int, url:string}|null Null when the token is invalid.
	 */
	public static function verify_click( string $token ): ?array {
		$data = self::unpack( $token );
		if ( null === $data || 'c' !== ( $data['t'] ?? '' ) ) {
			return null;
		}

		$log_id = (int) ( $data['l'] ?? 0 );
		$url    = (string) ( $data['u'] ?? '' );
		if ( $log_id <= 0 || '' === $url ) {
			return null;
		}

		return [ 'log_id' => $log_id, 'url' => $url ];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function pack( array $payload ): string {
		$body = self::b64url_encode( (string) wp_json_encode( $payload ) );

		return $body . '.' . self::sign( $body );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function unpack( string $token ): ?array {
		if ( ! str_contains( $token, '.' ) ) {
			return null;
		}

		[ $body, $sig ] = explode( '.', $token, 2 );

		if ( ! hash_equals( self::sign( $body ), $sig ) ) {
			return null;
		}

		$decoded = self::b64url_decode( $body );
		$data    = json_decode( $decoded, true );

		return is_array( $data ) ? $data : null;
	}

	private static function sign( string $body ): string {
		return hash_hmac( 'sha256', $body, self::key() );
	}

	private static function key(): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'flexa-smtp-insecure-fallback';

		return hash( 'sha256', 'flexa-smtp-tracking-token|' . $salt );
	}

	private static function b64url_encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $encoded ): string {
		$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );

		return false === $decoded ? '' : $decoded;
	}
}
