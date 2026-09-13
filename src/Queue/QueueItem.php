<?php

declare(strict_types=1);

namespace Flexa\Smtp\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * One queued message. Immutable view over a `flexa_smtp_email_queue` row. The
 * payload column (a full serialized message, see {@see \Flexa\Smtp\Mailer\PhpMailerBridge::to_payload()})
 * is JSON-decoded here so the worker only ever sees a native array.
 */
final class QueueItem {
	/**
	 * @param array<string, mixed> $payload
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $log_id,
		public readonly string $idempotency_key,
		public readonly array $payload,
		public readonly string $status,
		public readonly int $attempts,
		public readonly int $max_attempts,
		public readonly string $available_at,
		public readonly string $last_error,
	) {}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		$payload = [];
		$raw     = $row['payload'] ?? '';
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			log_id: (int) ( $row['log_id'] ?? 0 ),
			idempotency_key: (string) ( $row['idempotency_key'] ?? '' ),
			payload: $payload,
			status: (string) ( $row['status'] ?? '' ),
			attempts: (int) ( $row['attempts'] ?? 0 ),
			max_attempts: (int) ( $row['max_attempts'] ?? 1 ),
			available_at: (string) ( $row['available_at'] ?? '' ),
			last_error: (string) ( $row['last_error'] ?? '' ),
		);
	}
}
