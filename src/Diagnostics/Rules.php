<?php

declare(strict_types=1);

namespace Flexa\Smtp\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * The data behind {@see Diagnostics}. Kept deliberately conservative: a code or
 * phrase only maps to "retryable" when retrying is genuinely safe/useful. Anything
 * unrecognised falls through to UNKNOWN / not-retryable so we never loop on a
 * permanent failure.
 *
 * This is pure data (no WordPress calls, no i18n) so it stays trivially testable;
 * the human-readable copy lives in {@see Diagnostics}.
 */
final class Rules {
	// Response codes (SMTP reply codes and HTTP status codes share the space here
	// because both are small integers and the sets do not collide meaningfully).

	/**
	 * code => [ category, retryable ]. Decisive: a code listed here fixes
	 * retryability; text patterns may still refine the category but cannot flip a
	 * permanent code to retryable.
	 *
	 * @var array<int, array{0:string, 1:bool}>
	 */
	public const CODE_MAP = [
		// Temporary — safe to retry.
		421 => [ Diagnostics::CATEGORY_SERVER, true ],
		450 => [ Diagnostics::CATEGORY_SERVER, true ],
		451 => [ Diagnostics::CATEGORY_SERVER, true ],
		452 => [ Diagnostics::CATEGORY_RATE_LIMIT, true ],
		429 => [ Diagnostics::CATEGORY_RATE_LIMIT, true ],
		408 => [ Diagnostics::CATEGORY_TIMEOUT, true ],
		425 => [ Diagnostics::CATEGORY_SERVER, true ],
		500 => [ Diagnostics::CATEGORY_SERVER, true ],
		502 => [ Diagnostics::CATEGORY_SERVER, true ],
		503 => [ Diagnostics::CATEGORY_SERVER, true ],
		504 => [ Diagnostics::CATEGORY_TIMEOUT, true ],

		// Authentication — never retry blindly, the credentials are wrong.
		401 => [ Diagnostics::CATEGORY_AUTH, false ],
		403 => [ Diagnostics::CATEGORY_AUTH, false ],
		530 => [ Diagnostics::CATEGORY_AUTH, false ],
		535 => [ Diagnostics::CATEGORY_AUTH, false ],

		// Client/request errors — retrying the same payload will fail again.
		400 => [ Diagnostics::CATEGORY_CONFIGURATION, false ],
		404 => [ Diagnostics::CATEGORY_CONFIGURATION, false ],
		422 => [ Diagnostics::CATEGORY_PROVIDER_REJECTION, false ],

		// Permanent delivery failures.
		550 => [ Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],
		551 => [ Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],
		553 => [ Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],
		554 => [ Diagnostics::CATEGORY_PROVIDER_REJECTION, false ],
		552 => [ Diagnostics::CATEGORY_PROVIDER_REJECTION, false ],
	];

	/**
	 * Ordered, most-specific first. Each: [ needle (lowercase substring), category,
	 * retryable ]. The first match that appears in the error text wins for category;
	 * a decisive CODE_MAP entry still overrides retryability.
	 *
	 * @var list<array{0:string, 1:string, 2:bool}>
	 */
	public const TEXT_RULES = [
		[ 'rate limit', Diagnostics::CATEGORY_RATE_LIMIT, true ],
		[ 'too many request', Diagnostics::CATEGORY_RATE_LIMIT, true ],
		[ 'throttle', Diagnostics::CATEGORY_RATE_LIMIT, true ],
		[ 'quota', Diagnostics::CATEGORY_RATE_LIMIT, false ],

		[ 'timed out', Diagnostics::CATEGORY_TIMEOUT, true ],
		[ 'timeout', Diagnostics::CATEGORY_TIMEOUT, true ],
		[ 'curl error 28', Diagnostics::CATEGORY_TIMEOUT, true ],

		[ 'could not resolve', Diagnostics::CATEGORY_DNS, false ],
		[ 'getaddrinfo', Diagnostics::CATEGORY_DNS, false ],
		[ 'name or service not known', Diagnostics::CATEGORY_DNS, false ],
		[ 'dns', Diagnostics::CATEGORY_DNS, false ],

		[ 'certificate', Diagnostics::CATEGORY_TLS, false ],
		[ 'ssl', Diagnostics::CATEGORY_TLS, false ],
		[ 'tls', Diagnostics::CATEGORY_TLS, false ],
		[ 'starttls', Diagnostics::CATEGORY_TLS, false ],

		[ 'connection reset', Diagnostics::CATEGORY_CONNECTION, true ],
		[ 'connection refused', Diagnostics::CATEGORY_CONNECTION, true ],
		[ 'could not connect', Diagnostics::CATEGORY_CONNECTION, true ],
		[ 'curl error 7', Diagnostics::CATEGORY_CONNECTION, true ],
		[ 'network is unreachable', Diagnostics::CATEGORY_CONNECTION, true ],

		[ 'invalid api key', Diagnostics::CATEGORY_AUTH, false ],
		[ 'unauthorized', Diagnostics::CATEGORY_AUTH, false ],
		[ 'authentication', Diagnostics::CATEGORY_AUTH, false ],
		[ 'authenticate', Diagnostics::CATEGORY_AUTH, false ],
		[ 'credential', Diagnostics::CATEGORY_AUTH, false ],
		[ 'invalid login', Diagnostics::CATEGORY_AUTH, false ],
		[ 'password not accepted', Diagnostics::CATEGORY_AUTH, false ],

		[ 'domain not verified', Diagnostics::CATEGORY_CONFIGURATION, false ],
		[ 'not verified', Diagnostics::CATEGORY_CONFIGURATION, false ],
		[ 'sandbox', Diagnostics::CATEGORY_CONFIGURATION, false ],
		[ 'sender address', Diagnostics::CATEGORY_CONFIGURATION, false ],
		[ 'from address', Diagnostics::CATEGORY_CONFIGURATION, false ],

		[ 'user unknown', Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],
		[ 'no such user', Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],
		[ 'mailbox unavailable', Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],
		[ 'recipient address rejected', Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],
		[ 'does not exist', Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],
		[ 'invalid recipient', Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],
		[ 'invalid email', Diagnostics::CATEGORY_INVALID_RECIPIENT, false ],

		[ 'spam', Diagnostics::CATEGORY_PROVIDER_REJECTION, false ],
		[ 'blacklist', Diagnostics::CATEGORY_PROVIDER_REJECTION, false ],
		[ 'blocklist', Diagnostics::CATEGORY_PROVIDER_REJECTION, false ],
		[ 'policy', Diagnostics::CATEGORY_PROVIDER_REJECTION, false ],
		[ 'rejected', Diagnostics::CATEGORY_PROVIDER_REJECTION, false ],

		[ 'service unavailable', Diagnostics::CATEGORY_SERVER, true ],
		[ 'try again later', Diagnostics::CATEGORY_SERVER, true ],
		[ 'temporarily', Diagnostics::CATEGORY_SERVER, true ],
		[ 'internal server error', Diagnostics::CATEGORY_SERVER, true ],
	];
}
