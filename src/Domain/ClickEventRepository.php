<?php

declare(strict_types=1);

namespace Flexa\Smtp\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Single data-access class for the click_events table. All values bound via
// $wpdb->prepare(); only the table name (a constant) is interpolated.

/**
 * Reads and writes for `flexa_smtp_click_events`. One row per (log, url)
 * accumulates a click count; {@see record()} is the upsert the link tracker
 * (WP6) calls.
 */
final class ClickEventRepository {
	private const TABLE = 'flexa_smtp_click_events';

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Register one click on a URL for a log: create the row on first click,
	 * otherwise increment its count. Returns the resulting total for that URL.
	 *
	 * @param array<string, mixed> $extra
	 */
	public function record( int $log_id, string $url, array $extra = [] ): int {
		global $wpdb;

		$existing = $this->find( $log_id, $url );
		$now      = current_time( 'mysql' );

		if ( is_array( $existing ) ) {
			$table = $this->table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; values bound.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET count = count + 1, date_time = %s WHERE id = %d", $now, (int) $existing['id'] ) );

			return (int) $existing['count'] + 1;
		}

		$wpdb->insert(
			$this->table(),
			[
				'log_id'     => $log_id,
				'url'        => $url,
				'count'      => 1,
				'extra_info' => (string) wp_json_encode( $extra ),
				'date_time'  => $now,
			],
			[ '%d', '%s', '%d', '%s', '%s' ]
		);

		return 1;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find( int $log_id, string $url ): ?array {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; values bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, count FROM {$table} WHERE log_id = %d AND url = %s", $log_id, $url ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * All click rows for a log, newest-first.
	 *
	 * @return list<ClickEvent>
	 */
	public function for_log( int $log_id ): array {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; log_id bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE log_id = %d ORDER BY count DESC, id DESC", $log_id ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map(
			static fn ( array $row ): ClickEvent => ClickEvent::from_row( $row ),
			$rows
		);
	}

	public function total_for_log( int $log_id ): int {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; log_id bound.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(count),0) FROM {$table} WHERE log_id = %d", $log_id ) );
	}
}
