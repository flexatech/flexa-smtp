<?php

declare(strict_types=1);

namespace Flexa\Smtp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the `flexa_smtp_settings` option: the typed schema,
 * defaults, type-coerced reads, secret encryption, and the sanitizer used on
 * every write. The REST endpoint, mailer manager, and CLI all go through here so
 * the schema can never drift between callers.
 *
 * Storage view vs runtime view:
 * - {@see raw()}     — stored merged over defaults, secrets still ENCRYPTED.
 * - {@see all()}     — same, but secrets DECRYPTED (for actually sending mail).
 * - {@see for_rest()}— secrets MASKED, never plaintext, for the admin UI.
 *
 * Per-mailer credential fields come from {@see mailer_schema()}, which each
 * provider (WP3/WP4) extends via the `flexa_smtp.settings.mailer_schema` filter.
 */
final class Settings {
	public const OPTION_KEY = 'flexa_smtp_settings';

	/**
	 * Sentinel the UI echoes back for a secret the user did not edit; on save it
	 * means "keep the stored ciphertext" rather than overwrite with the mask.
	 */
	public const SECRET_MASK = '__flexa_smtp_secret__';

	/**
	 * @var list<string>
	 */
	private const BOOL_KEYS = [
		'force_from_email',
		'force_from_name',
		'disable_delivery',
		'fallback_enabled',
		'enable_email_log',
		'enable_open_tracking',
		'enable_click_tracking',
		'enable_weekly_report',
		'enable_monthly_report',
	];

	/**
	 * @var list<string>
	 */
	private const STRING_KEYS = [
		'current_mailer',
		'from_email',
		'from_name',
		'fallback_mailer',
		'report_recipients',
	];

	/**
	 * @var list<string>
	 */
	private const INT_KEYS = [
		'log_retention_days',
	];

	/**
	 * Per-mailer credential field definitions, keyed by mailer slug. Each field:
	 * ['type' => 'string'|'int'|'bool'|'enum', 'secret' => bool, 'enum' => list<string>].
	 *
	 * WP1 ships the transport-level mailers (mail, smtp). API/OAuth providers add
	 * their fields via the filter when they land, so the sanitizer stays the one
	 * place that knows how to coerce and encrypt every mailer's credentials.
	 *
	 * @return array<string, array<string, array{type:string, secret?:bool, enum?:list<string>}>>
	 */
	public static function mailer_schema(): array {
		$schema = [
			'mail' => [],
			'smtp' => [
				'host'       => [ 'type' => 'string' ],
				'port'       => [ 'type' => 'int' ],
				'encryption' => [ 'type' => 'enum', 'enum' => [ 'none', 'ssl', 'tls' ] ],
				'auth'       => [ 'type' => 'bool' ],
				'user'       => [ 'type' => 'string' ],
				'pass'       => [ 'type' => 'string', 'secret' => true ],
			],
		];

		/**
		 * Filter the per-mailer credential schema. Providers register their
		 * fields here so Settings can coerce and encrypt them uniformly.
		 *
		 * @param array<string, array<string, array{type:string, secret?:bool, enum?:list<string>}>> $schema
		 */
		return apply_filters( 'flexa_smtp.settings.mailer_schema', $schema );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = [];
		foreach ( self::BOOL_KEYS as $key ) {
			$defaults[ $key ] = false;
		}
		foreach ( self::STRING_KEYS as $key ) {
			$defaults[ $key ] = '';
		}
		foreach ( self::INT_KEYS as $key ) {
			$defaults[ $key ] = 0;
		}
		$defaults['current_mailer']     = 'mail';
		$defaults['mailers']            = [];
		// Logging is on by default with a 30-day retention window.
		$defaults['enable_email_log']   = true;
		$defaults['log_retention_days'] = 30;

		return $defaults;
	}

	/**
	 * Stored settings merged over defaults, coerced to declared types. Secrets
	 * remain encrypted — this is the on-disk view.
	 *
	 * @return array<string, mixed>
	 */
	public static function raw(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return self::coerce( $stored );
	}

	/**
	 * Runtime view: like {@see raw()} but every secret field decrypted, for the
	 * mailer that actually has to send.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$out     = self::raw();
		$schema  = self::mailer_schema();
		$mailers = is_array( $out['mailers'] ) ? $out['mailers'] : [];

		foreach ( $mailers as $slug => $fields ) {
			if ( ! is_array( $fields ) || ! isset( $schema[ $slug ] ) ) {
				continue;
			}
			foreach ( $schema[ $slug ] as $field => $def ) {
				if ( ! empty( $def['secret'] ) && isset( $fields[ $field ] ) && is_string( $fields[ $field ] ) ) {
					$mailers[ $slug ][ $field ] = Encryption::decrypt( $fields[ $field ] );
				}
			}
		}

		$out['mailers'] = $mailers;

		return $out;
	}

	public static function get( string $key ): mixed {
		return self::raw()[ $key ] ?? null;
	}

	/**
	 * Decrypted credentials for one mailer.
	 *
	 * @return array<string, mixed>
	 */
	public static function mailer( string $slug ): array {
		$mailers = self::all()['mailers'];
		if ( is_array( $mailers ) && isset( $mailers[ $slug ] ) && is_array( $mailers[ $slug ] ) ) {
			return $mailers[ $slug ];
		}

		return [];
	}

	/**
	 * Admin-safe view: secrets replaced with SECRET_MASK when set (so the UI
	 * renders dots) or '' when empty. Never returns plaintext secrets.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_rest(): array {
		$out     = self::raw();
		$schema  = self::mailer_schema();
		$mailers = is_array( $out['mailers'] ) ? $out['mailers'] : [];

		foreach ( $mailers as $slug => $fields ) {
			if ( ! is_array( $fields ) || ! isset( $schema[ $slug ] ) ) {
				continue;
			}
			foreach ( $schema[ $slug ] as $field => $def ) {
				if ( ! empty( $def['secret'] ) ) {
					$stored                     = isset( $fields[ $field ] ) && is_string( $fields[ $field ] ) ? $fields[ $field ] : '';
					$mailers[ $slug ][ $field ] = '' === $stored ? '' : self::SECRET_MASK;
				}
			}
		}

		$out['mailers'] = $mailers;

		return $out;
	}

	/**
	 * Sanitize a (possibly partial) incoming payload, deep-merge it over what is
	 * stored, persist, and fire the update hook. Returns the new raw settings.
	 *
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	public static function save( array $incoming ): array {
		$old   = self::raw();
		$clean = self::sanitize( $incoming, $old );

		$new = array_merge( $old, $clean );
		// array_merge is shallow; merge the mailers sub-array explicitly so a
		// screen that submits one mailer never wipes the others' credentials.
		if ( isset( $clean['mailers'] ) ) {
			$old_mailers    = is_array( $old['mailers'] ) ? $old['mailers'] : [];
			$new['mailers'] = self::merge_mailers( $old_mailers, $clean['mailers'] );
		}

		update_option( self::OPTION_KEY, $new );

		/**
		 * Fires after settings are persisted. Args are the raw (secrets still
		 * encrypted) views; consumers needing a decrypted secret call
		 * {@see mailer()}.
		 *
		 * @param array<string, mixed> $new
		 * @param array<string, mixed> $old
		 */
		do_action( 'flexa_smtp.settings.updated', $new, $old );

		return $new;
	}

	/**
	 * @param array<string, mixed> $incoming
	 * @param array<string, mixed> $stored   Raw stored settings (encrypted secrets).
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $incoming, array $stored ): array {
		$clean = [];

		foreach ( self::BOOL_KEYS as $key ) {
			if ( array_key_exists( $key, $incoming ) ) {
				$clean[ $key ] = self::to_bool( $incoming[ $key ] );
			}
		}

		if ( array_key_exists( 'current_mailer', $incoming ) ) {
			$clean['current_mailer'] = self::sanitize_slug( $incoming['current_mailer'], 'mail' );
		}
		if ( array_key_exists( 'fallback_mailer', $incoming ) ) {
			$clean['fallback_mailer'] = self::sanitize_slug( $incoming['fallback_mailer'], '' );
		}
		if ( array_key_exists( 'from_email', $incoming ) ) {
			$email               = is_string( $incoming['from_email'] ) ? sanitize_email( $incoming['from_email'] ) : '';
			$clean['from_email'] = $email;
		}
		if ( array_key_exists( 'from_name', $incoming ) ) {
			$clean['from_name'] = is_string( $incoming['from_name'] ) ? sanitize_text_field( $incoming['from_name'] ) : '';
		}
		if ( array_key_exists( 'report_recipients', $incoming ) ) {
			$clean['report_recipients'] = is_string( $incoming['report_recipients'] ) ? sanitize_text_field( $incoming['report_recipients'] ) : '';
		}

		foreach ( self::INT_KEYS as $key ) {
			if ( array_key_exists( $key, $incoming ) ) {
				$clean[ $key ] = is_numeric( $incoming[ $key ] ) ? max( 0, (int) $incoming[ $key ] ) : 0;
			}
		}

		if ( isset( $incoming['mailers'] ) && is_array( $incoming['mailers'] ) ) {
			$stored_mailers   = is_array( $stored['mailers'] ) ? $stored['mailers'] : [];
			$clean['mailers'] = self::sanitize_mailers( $incoming['mailers'], $stored_mailers );
		}

		return $clean;
	}

	/**
	 * @param array<mixed> $incoming
	 * @param array<mixed> $stored
	 * @return array<string, array<string, mixed>>
	 */
	private static function sanitize_mailers( array $incoming, array $stored ): array {
		$schema = self::mailer_schema();
		$out    = [];

		foreach ( $incoming as $slug => $fields ) {
			if ( ! is_string( $slug ) || ! isset( $schema[ $slug ] ) || ! is_array( $fields ) ) {
				continue;
			}

			$stored_mailer = isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) ? $stored[ $slug ] : [];
			$clean_mailer  = [];

			foreach ( $schema[ $slug ] as $field => $def ) {
				if ( ! array_key_exists( $field, $fields ) ) {
					continue;
				}
				$value = $fields[ $field ];

				if ( ! empty( $def['secret'] ) ) {
					$incoming_secret = is_string( $value ) ? $value : '';
					if ( '' === $incoming_secret || self::SECRET_MASK === $incoming_secret ) {
						// Untouched — keep the stored ciphertext (if any).
						if ( isset( $stored_mailer[ $field ] ) && is_string( $stored_mailer[ $field ] ) ) {
							$clean_mailer[ $field ] = $stored_mailer[ $field ];
						}
					} else {
						$clean_mailer[ $field ] = Encryption::encrypt( $incoming_secret );
					}
					continue;
				}

				$clean_mailer[ $field ] = self::coerce_field( $value, $def );
			}

			$out[ $slug ] = $clean_mailer;
		}

		return $out;
	}

	/**
	 * @param array<string, array<string, mixed>> $stored
	 * @param array<string, array<string, mixed>> $incoming
	 * @return array<string, array<string, mixed>>
	 */
	private static function merge_mailers( array $stored, array $incoming ): array {
		foreach ( $incoming as $slug => $fields ) {
			$base           = isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) ? $stored[ $slug ] : [];
			$stored[ $slug ] = array_merge( $base, $fields );
		}

		return $stored;
	}

	/**
	 * @param array<string, mixed> $stored
	 * @return array<string, mixed>
	 */
	private static function coerce( array $stored ): array {
		$out = self::defaults();

		foreach ( self::BOOL_KEYS as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$out[ $key ] = self::to_bool( $stored[ $key ] );
			}
		}
		foreach ( self::STRING_KEYS as $key ) {
			if ( isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ) {
				$out[ $key ] = $stored[ $key ];
			}
		}
		foreach ( self::INT_KEYS as $key ) {
			if ( isset( $stored[ $key ] ) && is_numeric( $stored[ $key ] ) ) {
				$out[ $key ] = max( 0, (int) $stored[ $key ] );
			}
		}

		$schema  = self::mailer_schema();
		$mailers = isset( $stored['mailers'] ) && is_array( $stored['mailers'] ) ? $stored['mailers'] : [];
		$clean   = [];
		foreach ( $mailers as $slug => $fields ) {
			if ( ! is_string( $slug ) || ! isset( $schema[ $slug ] ) || ! is_array( $fields ) ) {
				continue;
			}
			$clean_mailer = [];
			foreach ( $schema[ $slug ] as $field => $def ) {
				if ( ! array_key_exists( $field, $fields ) ) {
					continue;
				}
				if ( ! empty( $def['secret'] ) ) {
					$clean_mailer[ $field ] = is_string( $fields[ $field ] ) ? $fields[ $field ] : '';
					continue;
				}
				$clean_mailer[ $field ] = self::coerce_field( $fields[ $field ], $def );
			}
			$clean[ $slug ] = $clean_mailer;
		}
		$out['mailers'] = $clean;

		return $out;
	}

	/**
	 * @param array{type:string, secret?:bool, enum?:list<string>} $def
	 */
	private static function coerce_field( mixed $value, array $def ): mixed {
		switch ( $def['type'] ) {
			case 'int':
				return is_numeric( $value ) ? (int) $value : 0;
			case 'bool':
				return self::to_bool( $value );
			case 'enum':
				$allowed = $def['enum'] ?? [];
				$str     = is_string( $value ) ? $value : '';
				return in_array( $str, $allowed, true ) ? $str : ( $allowed[0] ?? '' );
			case 'string':
			default:
				return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
	}

	private static function sanitize_slug( mixed $value, string $fallback ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return $fallback;
		}
		$slug = sanitize_key( $value );

		return '' === $slug ? $fallback : $slug;
	}

	private static function to_bool( mixed $value ): bool {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, [ 'false', '0', '', 'off', 'no' ], true ) ) {
				return false;
			}
		}

		return (bool) $value;
	}
}
