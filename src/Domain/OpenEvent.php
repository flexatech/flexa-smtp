<?php

declare(strict_types=1);

namespace Flexa\Smtp\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * An open (pixel) event for a logged email. Written by the tracking endpoint
 * (WP6); read here so the log detail can surface open counts.
 */
final class OpenEvent {
	/**
	 * @param array<string, mixed> $extra_info
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $log_id,
		public readonly int $count,
		public readonly array $extra_info,
		public readonly string $date_time,
	) {}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		$extra = $row['extra_info'] ?? '';
		if ( is_string( $extra ) && '' !== $extra ) {
			$decoded = json_decode( $extra, true );
			$extra   = is_array( $decoded ) ? $decoded : [];
		} elseif ( ! is_array( $extra ) ) {
			$extra = [];
		}

		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			log_id: (int) ( $row['log_id'] ?? 0 ),
			count: (int) ( $row['count'] ?? 0 ),
			extra_info: $extra,
			date_time: (string) ( $row['date_time'] ?? '' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'         => $this->id,
			'log_id'     => $this->log_id,
			'count'      => $this->count,
			'extra_info' => $this->extra_info,
			'date_time'  => $this->date_time,
		];
	}
}
