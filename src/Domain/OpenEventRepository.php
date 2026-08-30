<?php

declare(strict_types=1);

namespace Flexa\Smtp\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Single data-access class for the open_events table. All values bound via
// $wpdb->prepare(); only the table name (a constant) is interpolated.

/**
 * Reads and writes for `flexa_smtp_open_events`. One row per (log) accumulates
 * an open count; {@see record()} is the upsert the tracking pixel (WP6) calls.
 */
final class OpenEventRepository {
	private const TABLE = 'flexa_smtp_open_events';

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Register one open for a log: create the row on first open, otherwise
	 * increment its count. Returns the resulting total count.
	 *
	 * @param array<string, mixed> $extra
	 */
	public function record( int $log_id, array $extra = [] ): int {
		global $wpdb;

		$existing = $this->find_by_log( $log_id );
		$now      = current_time( 'mysql' );

		if ( $existing instanceof OpenEvent ) {
			$table = $this->table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; values bound.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET count = count + 1, date_time = %s WHERE id = %d", $now, $existing->id ) );

			return $existing->count + 1;
		}

		$wpdb->insert(
			$this->table(),
			[
				'log_id'     => $log_id,
				'count'      => 1,
				'extra_info' => (string) wp_json_encode( $extra ),
				'date_time'  => $now,
			],
			[ '%d', '%d', '%s', '%s' ]
		);

		return 1;
	}

	public function find_by_log( int $log_id ): ?OpenEvent {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; log_id bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE log_id = %d", $log_id ), ARRAY_A );

		return is_array( $row ) ? OpenEvent::from_row( $row ) : null;
	}

	public function total_for_log( int $log_id ): int {
		$event = $this->find_by_log( $log_id );

		return $event instanceof OpenEvent ? $event->count : 0;
	}

	/**
	 * Total opens and distinct messages opened for emails sent in a date range
	 * (bucketed by the log's send date). WP7 reports.
	 *
	 * @return array{opens:int, messages:int}
	 */
	public function stats_in_range( string $from, string $to ): array {
		global $wpdb;

		$open = $this->table();
		$logs = $wpdb->prefix . 'flexa_smtp_email_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only; range bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(o.count),0) AS opens, COUNT(DISTINCT o.log_id) AS messages FROM {$open} o INNER JOIN {$logs} l ON l.id = o.log_id WHERE l.flag_delete = 0 AND l.date_time BETWEEN %s AND %s", $from, $to ), ARRAY_A );

		return [
			'opens'    => (int) ( $row['opens'] ?? 0 ),
			'messages' => (int) ( $row['messages'] ?? 0 ),
		];
	}

	/**
	 * Opens per day (by the log's send date), keyed by Y-m-d. WP7 chart series.
	 *
	 * @return array<string, int>
	 */
	public function daily_opens( string $from, string $to ): array {
		global $wpdb;

		$open = $this->table();
		$logs = $wpdb->prefix . 'flexa_smtp_email_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only; range bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(l.date_time) AS d, COALESCE(SUM(o.count),0) AS opens FROM {$open} o INNER JOIN {$logs} l ON l.id = o.log_id WHERE l.flag_delete = 0 AND l.date_time BETWEEN %s AND %s GROUP BY d", $from, $to ), ARRAY_A );

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) ( $row['d'] ?? '' ) ] = (int) ( $row['opens'] ?? 0 );
		}
		unset( $out[''] );

		return $out;
	}
}
