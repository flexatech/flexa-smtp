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
			'id'           => $this->id,
			'subject'      => $this->subject,
			'from'         => $this->email_from,
			'to'           => $this->email_to,
			'mailer'       => $this->mailer,
			'status'       => $this->status,
			'sent'         => self::STATUS_SENT === $this->status,
			'content_type' => $this->content_type,
			'reason_error' => $this->reason_error,
			'source'       => $this->source,
			'extra_info'   => $this->extra_info,
			'date_time'    => $this->date_time,
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
