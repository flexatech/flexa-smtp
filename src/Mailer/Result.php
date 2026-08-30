<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable outcome of a single send attempt. Providers return this; the manager
 * turns it into hooks (and, in WP5, log rows).
 */
final class Result {
	/**
	 * @param array<string, mixed> $meta
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly ?string $error,
		public readonly array $meta
	) {}

	/**
	 * @param array<string, mixed> $meta
	 */
	public static function success( array $meta = [] ): self {
		return new self( true, null, $meta );
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	public static function error( ?string $error, array $meta = [] ): self {
		return new self( false, '' !== (string) $error ? (string) $error : 'unknown error', $meta );
	}
}
