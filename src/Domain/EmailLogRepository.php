<?php

declare(strict_types=1);

namespace Flexa\Smtp\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
// This is the single data-access class for the email_logs table. Every dynamic
// value is bound with $wpdb->prepare(); only the table name (a constant) and a
// whitelisted ORDER BY column are interpolated, neither of which can be prepared.

/**
 * All reads and writes for `flexa_smtp_email_logs`. Callers never touch $wpdb
 * directly. Rows flagged deleted (flag_delete=1) are excluded from every read.
 */
final class EmailLogRepository {
	private const TABLE = 'flexa_smtp_email_logs';

	/**
	 * @var list<string>
	 */
	private const ORDERABLE = [ 'id', 'date_time', 'status', 'mailer' ];

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Insert a log row. Returns the new id (0 on failure).
	 *
	 * @param array{
	 *   subject?:string, email_from?:string, email_to?:list<array{address:string,name:string}>|string,
	 *   mailer?:string, status?:int, content_type?:string, body_content?:string,
	 *   reason_error?:string, source?:string, extra_info?:array<string,mixed>, date_time?:string
	 * } $data
	 */
	public function create( array $data ): int {
		global $wpdb;

		$to    = $data['email_to'] ?? [];
		$extra = $data['extra_info'] ?? [];

		$row = [
			'subject'      => (string) ( $data['subject'] ?? '' ),
			'email_from'   => (string) ( $data['email_from'] ?? '' ),
			'email_to'     => maybe_serialize( is_array( $to ) ? $to : [] ),
			'mailer'       => (string) ( $data['mailer'] ?? '' ),
			'status'       => (int) ( $data['status'] ?? 0 ),
			'content_type' => (string) ( $data['content_type'] ?? '' ),
			'body_content' => (string) ( $data['body_content'] ?? '' ),
			'reason_error' => (string) ( $data['reason_error'] ?? '' ),
			'source'       => (string) ( $data['source'] ?? '' ),
			'extra_info'   => is_array( $extra ) ? (string) wp_json_encode( $extra ) : '',
			'flag_delete'  => 0,
			'date_time'    => (string) ( $data['date_time'] ?? current_time( 'mysql' ) ),
		];

		$ok = $wpdb->insert(
			$this->table(),
			$row,
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update selected columns of one row. Used to settle a PENDING row into its
	 * SENT/FAILED outcome once the transport reports back. Only the keys present
	 * in $data are touched.
	 *
	 * @param array{status?:int, mailer?:string, reason_error?:string, body_content?:string, extra_info?:array<string,mixed>} $data
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$row     = [];
		$formats = [];
		if ( array_key_exists( 'status', $data ) ) {
			$row['status'] = (int) $data['status'];
			$formats[]     = '%d';
		}
		if ( array_key_exists( 'mailer', $data ) ) {
			$row['mailer'] = (string) $data['mailer'];
			$formats[]     = '%s';
		}
		if ( array_key_exists( 'reason_error', $data ) ) {
			$row['reason_error'] = (string) $data['reason_error'];
			$formats[]           = '%s';
		}
		if ( array_key_exists( 'body_content', $data ) ) {
			$row['body_content'] = (string) $data['body_content'];
			$formats[]           = '%s';
		}
		if ( array_key_exists( 'extra_info', $data ) ) {
			$row['extra_info'] = is_array( $data['extra_info'] ) ? (string) wp_json_encode( $data['extra_info'] ) : '';
			$formats[]         = '%s';
		}

		if ( [] === $row ) {
			return false;
		}

		$ok = $wpdb->update( $this->table(), $row, [ 'id' => $id ], $formats, [ '%d' ] );

		return false !== $ok;
	}

	public function find( int $id ): ?EmailLog {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; id is bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND flag_delete = 0", $id ), ARRAY_A );

		return is_array( $row ) ? EmailLog::from_row( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return list<EmailLog>
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		[ $where, $params ] = $this->build_where( $args );

		$orderby = $this->orderable( (string) ( $args['orderby'] ?? 'id' ) );
		$order   = strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$limit   = max( 1, min( 1000, (int) ( $args['limit'] ?? 50 ) ) );
		$offset  = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$table = $this->table();
		$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a placeholder string; every value is in $params.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map(
			static fn ( array $row ): EmailLog => EmailLog::from_row( $row ),
			$rows
		);
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		[ $where, $params ] = $this->build_where( $args );

		$table = $this->table();
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		if ( [] === $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no dynamic values; $where is static "1=1".
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- values bound via $params.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Sent/failed counts for logs in a date range (WP7 reports). Pending rows
	 * are excluded from both.
	 *
	 * @return array{sent:int, failed:int, total:int}
	 */
	public function status_counts( string $from, string $to ): array {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; range bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$table} WHERE flag_delete = 0 AND date_time BETWEEN %s AND %s GROUP BY status", $from, $to ), ARRAY_A );

		$sent   = 0;
		$failed = 0;
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$status = (int) ( $row['status'] ?? -1 );
			$count  = (int) ( $row['c'] ?? 0 );
			if ( EmailLog::STATUS_SENT === $status ) {
				$sent = $count;
			} elseif ( EmailLog::STATUS_FAILED === $status ) {
				$failed = $count;
			}
		}

		return [ 'sent' => $sent, 'failed' => $failed, 'total' => $sent + $failed ];
	}

	/**
	 * Per-day sent/failed counts, keyed by Y-m-d (WP7 chart series).
	 *
	 * @return array<string, array{sent:int, failed:int}>
	 */
	public function daily_status( string $from, string $to ): array {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; range bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(date_time) AS d, status, COUNT(*) AS c FROM {$table} WHERE flag_delete = 0 AND date_time BETWEEN %s AND %s GROUP BY d, status", $from, $to ), ARRAY_A );

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$day = (string) ( $row['d'] ?? '' );
			if ( '' === $day ) {
				continue;
			}
			$out[ $day ] ??= [ 'sent' => 0, 'failed' => 0 ];
			$count = (int) ( $row['c'] ?? 0 );
			if ( EmailLog::STATUS_SENT === (int) ( $row['status'] ?? -1 ) ) {
				$out[ $day ]['sent'] = $count;
			} elseif ( EmailLog::STATUS_FAILED === (int) ( $row['status'] ?? -1 ) ) {
				$out[ $day ]['failed'] = $count;
			}
		}

		return $out;
	}

	/**
	 * Sent-count breakdown by mailer for a range, busiest first.
	 *
	 * @return list<array{mailer:string, count:int}>
	 */
	public function mailer_breakdown( string $from, string $to, int $limit = 10 ): array {
		global $wpdb;

		$table = $this->table();
		$limit = max( 1, min( 50, $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; values bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT mailer, COUNT(*) AS c FROM {$table} WHERE flag_delete = 0 AND status = %d AND date_time BETWEEN %s AND %s GROUP BY mailer ORDER BY c DESC LIMIT %d", EmailLog::STATUS_SENT, $from, $to, $limit ), ARRAY_A );

		return array_map(
			static fn ( array $row ): array => [
				'mailer' => (string) ( $row['mailer'] ?? '' ),
				'count'  => (int) ( $row['c'] ?? 0 ),
			],
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * Hard-delete rows by id. Returns the number of rows removed.
	 *
	 * @param list<int> $ids
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( [] === $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are %d, ids bound via prepare.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );

		return (int) $deleted;
	}

	/**
	 * Retention: hard-delete rows older than $days. A value <= 0 disables the
	 * purge (keep forever). Returns the number of rows removed.
	 */
	public function delete_older_than( int $days ): int {
		global $wpdb;

		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; cutoff is bound.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE date_time < %s", $cutoff ) );

		return (int) $deleted;
	}

	/**
	 * Assemble the WHERE clause and its bound parameters from the filter args.
	 * The returned clause always starts from "flag_delete = 0" so deleted rows
	 * never surface.
	 *
	 * @param array<string, mixed> $args
	 * @return array{0:string, 1:list<scalar>}
	 */
	private function build_where( array $args ): array {
		global $wpdb;

		$clauses = [ 'flag_delete = 0' ];
		$params  = [];

		if ( isset( $args['status'] ) && '' !== $args['status'] && null !== $args['status'] ) {
			$clauses[] = 'status = %d';
			$params[]  = (int) $args['status'];
		}

		if ( ! empty( $args['mailer'] ) && is_string( $args['mailer'] ) ) {
			$clauses[] = 'mailer = %s';
			$params[]  = $args['mailer'];
		}

		if ( ! empty( $args['date_from'] ) && is_string( $args['date_from'] ) ) {
			$clauses[] = 'date_time >= %s';
			$params[]  = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) && is_string( $args['date_to'] ) ) {
			$clauses[] = 'date_time <= %s';
			$params[]  = $args['date_to'];
		}

		if ( ! empty( $args['search'] ) && is_string( $args['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$clauses[] = '(subject LIKE %s OR email_from LIKE %s OR email_to LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		return [ implode( ' AND ', $clauses ), $params ];
	}

	private function orderable( string $column ): string {
		return in_array( $column, self::ORDERABLE, true ) ? $column : 'id';
	}
}
