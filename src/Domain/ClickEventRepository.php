<?php

declare(strict_types=1);

namespace Flexa\Smtp\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Single data-access class for the click_events table. All values are bound via
// $wpdb->prepare() (%s/%d) and every table identifier with %i (WP 6.2+).

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
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET count = count + 1, date_time = %s WHERE id = %d', $table, $now, (int) $existing['id'] ) );

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
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, count FROM %i WHERE log_id = %d AND url = %s', $table, $log_id, $url ), ARRAY_A );

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
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE log_id = %d ORDER BY count DESC, id DESC', $table, $log_id ), ARRAY_A );
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
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(count),0) FROM %i WHERE log_id = %d', $table, $log_id ) );
	}

	/**
	 * Total clicks and distinct messages clicked for emails sent in a date range
	 * (bucketed by the log's send date). WP7 reports.
	 *
	 * @return array{clicks:int, messages:int}
	 */
	public function stats_in_range( string $from, string $to ): array {
		global $wpdb;

		$click = $this->table();
		$logs  = $wpdb->prefix . 'flexa_smtp_email_logs';
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(c.count),0) AS clicks, COUNT(DISTINCT c.log_id) AS messages FROM %i c INNER JOIN %i l ON l.id = c.log_id WHERE l.flag_delete = 0 AND l.date_time BETWEEN %s AND %s', $click, $logs, $from, $to ), ARRAY_A );

		return [
			'clicks'   => (int) ( $row['clicks'] ?? 0 ),
			'messages' => (int) ( $row['messages'] ?? 0 ),
		];
	}

	/**
	 * Clicks per day (by the log's send date), keyed by Y-m-d. WP7 chart series.
	 *
	 * @return array<string, int>
	 */
	public function daily_clicks( string $from, string $to ): array {
		global $wpdb;

		$click = $this->table();
		$logs  = $wpdb->prefix . 'flexa_smtp_email_logs';
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(l.date_time) AS d, COALESCE(SUM(c.count),0) AS clicks FROM %i c INNER JOIN %i l ON l.id = c.log_id WHERE l.flag_delete = 0 AND l.date_time BETWEEN %s AND %s GROUP BY d', $click, $logs, $from, $to ), ARRAY_A );

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) ( $row['d'] ?? '' ) ] = (int) ( $row['clicks'] ?? 0 );
		}
		unset( $out[''] );

		return $out;
	}

	/**
	 * Most-clicked URLs for emails sent in a range, busiest first. WP7 reports.
	 *
	 * @return list<array{url:string, clicks:int}>
	 */
	public function top_urls( string $from, string $to, int $limit = 5 ): array {
		global $wpdb;

		$click = $this->table();
		$logs  = $wpdb->prefix . 'flexa_smtp_email_logs';
		$limit = max( 1, min( 50, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT c.url, COALESCE(SUM(c.count),0) AS clicks FROM %i c INNER JOIN %i l ON l.id = c.log_id WHERE l.flag_delete = 0 AND l.date_time BETWEEN %s AND %s GROUP BY c.url ORDER BY clicks DESC LIMIT %d', $click, $logs, $from, $to, $limit ), ARRAY_A );

		return array_map(
			static fn ( array $row ): array => [
				'url'    => (string) ( $row['url'] ?? '' ),
				'clicks' => (int) ( $row['clicks'] ?? 0 ),
			],
			is_array( $rows ) ? $rows : []
		);
	}
}
