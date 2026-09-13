<?php

declare(strict_types=1);

namespace Flexa\Smtp\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a raw send failure (a response code and/or the provider's error text)
 * into a normalised {@see Diagnosis}: category, severity, whether a retry could
 * help, and plain-language explanation + recommended action.
 *
 * The classification is intentionally conservative. A response code in
 * {@see Rules::CODE_MAP} is decisive for retryability, so a permanent failure can
 * never be talked into a retry by a stray word in the error text; text patterns
 * only refine the *category*. Anything we do not recognise becomes
 * {@see self::CATEGORY_UNKNOWN} and is treated as not-retryable.
 */
final class Diagnostics {
	public const CATEGORY_AUTH               = 'auth';
	public const CATEGORY_CONNECTION         = 'connection';
	public const CATEGORY_TIMEOUT            = 'timeout';
	public const CATEGORY_TLS                = 'tls';
	public const CATEGORY_DNS                = 'dns';
	public const CATEGORY_RATE_LIMIT         = 'rate_limit';
	public const CATEGORY_PROVIDER_REJECTION = 'provider_rejection';
	public const CATEGORY_INVALID_RECIPIENT  = 'invalid_recipient';
	public const CATEGORY_CONFIGURATION      = 'configuration';
	public const CATEGORY_SERVER             = 'server';
	public const CATEGORY_UNKNOWN            = 'unknown';

	/**
	 * Classify a failed send.
	 *
	 * @param int|null $code      SMTP reply code or HTTP status, when known.
	 * @param string   $raw       The provider's error text (no secrets expected).
	 * @param string   $transport Optional mailer slug, for future per-provider rules.
	 */
	public static function classify( ?int $code, string $raw, string $transport = '' ): Diagnosis {
		unset( $transport );

		$text = strtolower( $raw );

		$code_decisive = null !== $code && isset( Rules::CODE_MAP[ $code ] );
		$code_category = $code_decisive ? Rules::CODE_MAP[ $code ][0] : null;
		$code_retry    = $code_decisive ? Rules::CODE_MAP[ $code ][1] : null;

		$text_category = null;
		$text_retry    = null;
		foreach ( Rules::TEXT_RULES as [ $needle, $category, $retry ] ) {
			if ( '' !== $text && str_contains( $text, $needle ) ) {
				$text_category = $category;
				$text_retry    = $retry;
				break;
			}
		}

		// A decisive code wins on retryability; otherwise trust the text rule; else
		// default to not-retryable so we never loop on an unrecognised failure.
		if ( $code_decisive ) {
			$retryable = (bool) $code_retry;
		} elseif ( null !== $text_retry ) {
			$retryable = $text_retry;
		} else {
			$retryable = false;
		}

		// Category: text refines the code's category, but only when it does not
		// contradict a decisive code's retryability. Otherwise the matched phrase is
		// likely incidental (e.g. "timed out" inside a permanent 550) and we keep the
		// code's category.
		if ( null === $text_category ) {
			$category = $code_category ?? self::CATEGORY_UNKNOWN;
		} elseif ( ! $code_decisive || $text_retry === $code_retry ) {
			$category = $text_category;
		} else {
			$category = $code_category ?? self::CATEGORY_UNKNOWN;
		}

		return new Diagnosis(
			$category,
			$retryable ? Diagnosis::SEVERITY_WARNING : Diagnosis::SEVERITY_ERROR,
			$retryable,
			self::explanation( $category ),
			self::action( $category ),
			self::technical( $code, $raw ),
		);
	}

	/**
	 * Rebuild a Diagnosis for a stored log row from its saved category, without
	 * re-running classification. Used by the read/UI path where we already have the
	 * category persisted but want the human copy.
	 */
	public static function for_category( string $category, ?int $code, string $raw, bool $retryable ): Diagnosis {
		$known = in_array( $category, self::categories(), true ) ? $category : self::CATEGORY_UNKNOWN;

		return new Diagnosis(
			$known,
			$retryable ? Diagnosis::SEVERITY_WARNING : Diagnosis::SEVERITY_ERROR,
			$retryable,
			self::explanation( $known ),
			self::action( $known ),
			self::technical( $code, $raw ),
		);
	}

	/**
	 * @return list<string>
	 */
	public static function categories(): array {
		return [
			self::CATEGORY_AUTH,
			self::CATEGORY_CONNECTION,
			self::CATEGORY_TIMEOUT,
			self::CATEGORY_TLS,
			self::CATEGORY_DNS,
			self::CATEGORY_RATE_LIMIT,
			self::CATEGORY_PROVIDER_REJECTION,
			self::CATEGORY_INVALID_RECIPIENT,
			self::CATEGORY_CONFIGURATION,
			self::CATEGORY_SERVER,
			self::CATEGORY_UNKNOWN,
		];
	}

	private static function explanation( string $category ): string {
		return match ( $category ) {
			self::CATEGORY_AUTH               => __( 'The mail provider rejected the login. The username, password, or API key is wrong or no longer valid.', 'flexa-smtp' ),
			self::CATEGORY_CONNECTION         => __( 'Your site could not open a connection to the mail server. This is often a temporary network issue or a blocked port.', 'flexa-smtp' ),
			self::CATEGORY_TIMEOUT            => __( 'The mail server did not respond in time. The message may or may not have been accepted, so it was not resent automatically.', 'flexa-smtp' ),
			self::CATEGORY_TLS                => __( 'The secure (TLS/SSL) connection to the mail server could not be established. The encryption setting or the server certificate may be the cause.', 'flexa-smtp' ),
			self::CATEGORY_DNS                => __( 'The mail server hostname could not be resolved. The host name may be misspelled or DNS is temporarily unavailable.', 'flexa-smtp' ),
			self::CATEGORY_RATE_LIMIT         => __( 'The provider is throttling your account because too many messages were sent in a short time.', 'flexa-smtp' ),
			self::CATEGORY_PROVIDER_REJECTION => __( 'The provider accepted the connection but refused this message, often due to reputation, content, or policy rules.', 'flexa-smtp' ),
			self::CATEGORY_INVALID_RECIPIENT  => __( 'The recipient address was rejected as invalid or non-existent.', 'flexa-smtp' ),
			self::CATEGORY_CONFIGURATION      => __( 'The request was rejected because of a configuration problem, such as an unverified sending domain or an invalid From address.', 'flexa-smtp' ),
			self::CATEGORY_SERVER             => __( 'The provider reported a temporary problem on its side. This usually clears up on its own.', 'flexa-smtp' ),
			default                           => __( 'The message could not be sent and the failure did not match a known pattern.', 'flexa-smtp' ),
		};
	}

	private static function action( string $category ): string {
		return match ( $category ) {
			self::CATEGORY_AUTH               => __( 'Re-enter the SMTP/API credentials. Some providers require an app-specific password or a freshly generated API key.', 'flexa-smtp' ),
			self::CATEGORY_CONNECTION         => __( 'Check that the host and port are correct and that your host allows outbound connections on that port. Try again shortly.', 'flexa-smtp' ),
			self::CATEGORY_TIMEOUT            => __( 'Check with the provider before resending to avoid a duplicate, then retry. Consider a different port or provider if it recurs.', 'flexa-smtp' ),
			self::CATEGORY_TLS                => __( 'Verify the encryption setting (SSL vs TLS) matches the port, and that the server certificate is valid.', 'flexa-smtp' ),
			self::CATEGORY_DNS                => __( 'Double-check the mail server hostname for typos. If it is correct, wait and try again.', 'flexa-smtp' ),
			self::CATEGORY_RATE_LIMIT         => __( 'Slow down sending or upgrade your provider plan. The message can be retried after the limit resets.', 'flexa-smtp' ),
			self::CATEGORY_PROVIDER_REJECTION => __( 'Review the provider response for the specific reason, and check your domain reputation and message content.', 'flexa-smtp' ),
			self::CATEGORY_INVALID_RECIPIENT  => __( 'Confirm the recipient address is correct. Do not keep resending to an invalid address.', 'flexa-smtp' ),
			self::CATEGORY_CONFIGURATION      => __( 'Verify your sending domain with the provider and make sure the From address uses a verified domain.', 'flexa-smtp' ),
			self::CATEGORY_SERVER             => __( 'No action needed. The message can be retried; the provider issue should resolve shortly.', 'flexa-smtp' ),
			default                           => __( 'Review the technical details below, and check the provider dashboard for more information.', 'flexa-smtp' ),
		};
	}

	private static function technical( ?int $code, string $raw ): string {
		$clean = trim( wp_strip_all_tags( $raw ) );
		if ( mb_strlen( $clean ) > 500 ) {
			$clean = mb_substr( $clean, 0, 500 ) . '…';
		}

		if ( null !== $code && 0 !== $code ) {
			return '' !== $clean ? sprintf( '%d: %s', $code, $clean ) : (string) $code;
		}

		return $clean;
	}
}
