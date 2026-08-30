<?php

declare(strict_types=1);

namespace Flexa\Smtp\OAuth;

use Flexa\Smtp\Support\Encryption;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted store for OAuth tokens, kept in its own option (`flexa_smtp_oauth_tokens`)
 * separate from the user-editable settings. Access and refresh tokens are runtime
 * artefacts of the connect flow — never entered by hand — so they live here rather
 * than in the mailer credential schema. Both token fields are AES-256-GCM encrypted
 * exactly like the other provider secrets, so a database-only leak exposes nothing.
 *
 * Stored shape, per provider slug:
 *   access_token (enc) · refresh_token (enc) · expires_at (int, unix) ·
 *   scope · token_type · email
 */
final class TokenStore {
	public const OPTION_KEY = 'flexa_smtp_oauth_tokens';

	/**
	 * @var list<string>
	 */
	private const SECRET_FIELDS = [ 'access_token', 'refresh_token' ];

	/**
	 * Decrypted token bundle for a provider, or an empty array when not connected.
	 *
	 * @return array<string, mixed>
	 */
	public static function get( string $slug ): array {
		$all = self::read();
		if ( ! isset( $all[ $slug ] ) || ! is_array( $all[ $slug ] ) ) {
			return [];
		}

		$bundle = $all[ $slug ];
		foreach ( self::SECRET_FIELDS as $field ) {
			if ( isset( $bundle[ $field ] ) && is_string( $bundle[ $field ] ) ) {
				$bundle[ $field ] = Encryption::decrypt( $bundle[ $field ] );
			}
		}

		return $bundle;
	}

	/**
	 * Merge a (possibly partial) token bundle over what is stored, encrypting the
	 * secret fields. A refresh that omits refresh_token therefore keeps the old one.
	 *
	 * @param array<string, mixed> $tokens
	 */
	public static function save( string $slug, array $tokens ): void {
		$all      = self::read();
		$existing = isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : [];
		$merged   = array_merge( $existing, $tokens );

		foreach ( self::SECRET_FIELDS as $field ) {
			if ( isset( $merged[ $field ] ) && is_string( $merged[ $field ] ) && '' !== $merged[ $field ] && ! Encryption::is_encrypted( $merged[ $field ] ) ) {
				$merged[ $field ] = Encryption::encrypt( $merged[ $field ] );
			}
		}

		$all[ $slug ] = $merged;
		update_option( self::OPTION_KEY, $all, false );
	}

	public static function clear( string $slug ): void {
		$all = self::read();
		if ( isset( $all[ $slug ] ) ) {
			unset( $all[ $slug ] );
			update_option( self::OPTION_KEY, $all, false );
		}
	}

	public static function is_connected( string $slug ): bool {
		$bundle = self::get( $slug );

		return '' !== (string) ( $bundle['refresh_token'] ?? '' ) || '' !== (string) ( $bundle['access_token'] ?? '' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function read(): array {
		$stored = get_option( self::OPTION_KEY, [] );

		return is_array( $stored ) ? $stored : [];
	}
}
