<?php

declare(strict_types=1);

namespace Flexa\Smtp\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One logged email. Immutable view over a `flexa_smtp_email_logs` row. The
 * recipient list is stored serialized (a message may carry many To/Cc/Bcc) and
 * extra_info is stored as JSON; both are decoded here so the rest of the plugin
 * only ever sees native PHP structures.
 */
final class EmailLog {
	public const STATUS_FAILED = 0;
	public const STATUS_SENT   = 1;
	// A row inserted when the send is attempted, before its outcome is known.
	// Because wp_mail() is synchronous the row is updated to SENT/FAILED within
	// the same request; it only lingers if the request dies mid-send.
	public const STATUS_PENDING = 2;
	// Reserved for the opt-in queue/retry engine (DL4). Declared now so the schema
	// and consumers share one vocabulary; the synchronous path never sets them.
	public const STATUS_QUEUED    = 3;
	public const STATUS_RETRYING  = 4;
	public const STATUS_CANCELLED = 5;

	/**
	 * @param list<array{address:string, name:string}> $email_to
	 * @param array<string, mixed>                     $extra_info
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $subject,
		public readonly string $email_from,
		public readonly array $email_to,
		public readonly string $mailer,
		public readonly int $status,
		public readonly string $content_type,
		public readonly string $body_content,
		public readonly string $reason_error,
		public readonly string $source,
		public readonly array $extra_info,
		public readonly bool $flag_delete,
		public readonly string $date_time,
		public readonly string $error_category,
		public readonly string $response_code,
		public readonly string $provider_message_id,
		public readonly int $duration_ms,
		public readonly int $retry_count,
		public readonly string $idempotency_key,
	) {}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			subject: (string) ( $row['subject'] ?? '' ),
			email_from: (string) ( $row['email_from'] ?? '' ),
			email_to: self::decode_recipients( $row['email_to'] ?? '' ),
			mailer: (string) ( $row['mailer'] ?? '' ),
			status: (int) ( $row['status'] ?? 0 ),
			content_type: (string) ( $row['content_type'] ?? '' ),
			body_content: (string) ( $row['body_content'] ?? '' ),
			reason_error: (string) ( $row['reason_error'] ?? '' ),
			source: (string) ( $row['source'] ?? '' ),
			extra_info: self::decode_extra( $row['extra_info'] ?? '' ),
			flag_delete: (bool) ( $row['flag_delete'] ?? 0 ),
			date_time: (string) ( $row['date_time'] ?? '' ),
			error_category: (string) ( $row['error_category'] ?? '' ),
			response_code: (string) ( $row['response_code'] ?? '' ),
			provider_message_id: (string) ( $row['provider_message_id'] ?? '' ),
			duration_ms: (int) ( $row['duration_ms'] ?? 0 ),
			retry_count: (int) ( $row['retry_count'] ?? 0 ),
			idempotency_key: (string) ( $row['idempotency_key'] ?? '' ),
		);
	}

	/**
	 * JS-friendly array for REST. Raw values only — the React client escapes on
	 * render, so no HTML is emitted here.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = [
			'id'                  => $this->id,
			'subject'             => $this->subject,
			'from'                => $this->email_from,
			'to'                  => $this->email_to,
			'mailer'              => $this->mailer,
			'status'              => $this->status,
			'sent'                => self::STATUS_SENT === $this->status,
			'content_type'        => $this->content_type,
			'reason_error'        => $this->reason_error,
			'source'              => $this->source,
			'extra_info'          => $this->extra_info,
			'date_time'           => $this->date_time,
			'error_category'      => $this->error_category,
			'response_code'       => $this->response_code,
			'provider_message_id' => $this->provider_message_id,
			'duration_ms'         => $this->duration_ms,
			'retry_count'         => $this->retry_count,
		];

		/**
		 * Filter the array shape of a single log entry before it is returned to
		 * the client.
		 *
		 * @param array<string, mixed> $data
		 * @param EmailLog             $log
		 */
		return apply_filters( 'flexa_smtp.log.to_array', $data, $this );
	}

	/**
	 * @return list<array{address:string, name:string}>
	 */
	private static function decode_recipients( mixed $value ): array {
		if ( is_array( $value ) ) {
			$raw = $value;
		} elseif ( is_string( $value ) && '' !== $value ) {
			$decoded = maybe_unserialize( $value );
			$raw     = is_array( $decoded ) ? $decoded : [ $value ];
		} else {
			return [];
		}

		$out = [];
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) ) {
				$out[] = [
					'address' => (string) ( $entry['address'] ?? '' ),
					'name'    => (string) ( $entry['name'] ?? '' ),
				];
			} elseif ( is_string( $entry ) ) {
				$out[] = [
					'address' => $entry,
					'name'    => '',
				];
			}
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decode_extra( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return [];
	}
}
