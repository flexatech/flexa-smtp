<?php

declare(strict_types=1);

namespace Flexa\Smtp\Queue;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Single data-access class for the email_queue table. Every value is bound via
// $wpdb->prepare() (%s/%d) and the table identifier via %i (WP 6.2+). The queue
// is a work list, not a cache, so object caching does not apply.

/**
 * All reads and writes for `flexa_smtp_email_queue`. Claiming uses an atomic
 * UPDATE ... ORDER BY ... LIMIT stamped with a unique token, so two overlapping
 * workers can never grab the same rows. Callers never touch $wpdb directly.
 */
final class QueueRepository {
	public const STATUS_PENDING = 'pending';
	public const STATUS_CLAIMED = 'claimed';
	public const STATUS_FAILED  = 'failed';

	private const TABLE = 'flexa_smtp_email_queue';

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Insert a queued message. Returns the new id (0 on failure).
	 *
	 * @param array{
	 *   log_id?:int, idempotency_key?:string, payload:array<string, mixed>,
	 *   attempts?:int, max_attempts?:int, available_at?:string
	 * } $data
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$now = self::now();
		$row = [
			'log_id'          => (int) ( $data['log_id'] ?? 0 ),
			'idempotency_key' => (string) ( $data['idempotency_key'] ?? '' ),
			'payload'         => (string) wp_json_encode( $data['payload'] ),
			'status'          => self::STATUS_PENDING,
			'attempts'        => (int) ( $data['attempts'] ?? 0 ),
			'max_attempts'    => max( 1, (int) ( $data['max_attempts'] ?? 1 ) ),
			'claim'           => '',
			'available_at'    => (string) ( $data['available_at'] ?? $now ),
			'reserved_at'     => null,
			'last_error'      => '',
			'created_at'      => $now,
			'updated_at'      => $now,
		];

		$ok = $wpdb->insert(
			$this->table(),
			$row,
			[ '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Is there already an in-flight (pending or claimed) row with this key? Used to
	 * skip enqueuing the exact same message twice while one is still waiting.
	 */
	public function active_key_exists( string $key ): bool {
		global $wpdb;

		if ( '' === $key ) {
			return false;
		}

		$table = $this->table();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE idempotency_key = %s AND status IN ( %s, %s ) LIMIT 1',
				$table,
				$key,
				self::STATUS_PENDING,
				self::STATUS_CLAIMED
			)
		);

		return null !== $found;
	}

	/**
	 * Return rows stuck in 'claimed' longer than $seconds (a worker that died mid-run)
	 * to 'pending' so they get retried. Returns the number reset.
	 */
	public function reclaim_stuck( int $seconds ): int {
		global $wpdb;

		$table  = $this->table();
		$cutoff = self::past( max( 60, $seconds ) );
		$reset  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, claim = '', reserved_at = NULL, updated_at = %s
				WHERE status = %s AND reserved_at IS NOT NULL AND reserved_at < %s",
				$table,
				self::STATUS_PENDING,
				self::now(),
				self::STATUS_CLAIMED,
				$cutoff
			)
		);

		return (int) $reset;
	}

	/**
	 * Atomically claim up to $limit due rows for this worker and return their ids.
	 *
	 * @return list<int>
	 */
	public function claim_batch( string $claim, int $limit ): array {
		global $wpdb;

		$table = $this->table();
		$now   = self::now();
		$limit = max( 1, min( 100, $limit ) );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, claim = %s, reserved_at = %s, updated_at = %s
				WHERE status = %s AND available_at <= %s
				ORDER BY available_at ASC LIMIT %d',
				$table,
				self::STATUS_CLAIMED,
				$claim,
				$now,
				$now,
				self::STATUS_PENDING,
				$now,
				$limit
			)
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE claim = %s AND status = %s ORDER BY id ASC', $table, $claim, self::STATUS_CLAIMED )
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	public function find( int $id ): ?QueueItem {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );

		return is_array( $row ) ? QueueItem::from_row( $row ) : null;
	}

	/**
	 * A successful send needs no archive — the linked log row holds the record — so
	 * the queue row is removed.
	 */
	public function mark_done( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Return a row to 'pending' for a later attempt after a retryable failure.
	 */
	public function reschedule( int $id, int $attempts, string $available_at, string $last_error ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			[
				'status'       => self::STATUS_PENDING,
				'attempts'     => $attempts,
				'claim'        => '',
				'reserved_at'  => null,
				'available_at' => $available_at,
				'last_error'   => $last_error,
				'updated_at'   => self::now(),
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Mark a row as permanently failed (attempts exhausted or non-retryable error).
	 */
	public function mark_failed( int $id, int $attempts, string $last_error ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			[
				'status'      => self::STATUS_FAILED,
				'attempts'    => $attempts,
				'claim'       => '',
				'reserved_at' => null,
				'last_error'  => $last_error,
				'updated_at'  => self::now(),
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Number of rows due to run now (pending and past their available_at).
	 */
	public function count_due(): int {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND available_at <= %s', $table, self::STATUS_PENDING, self::now() )
		);
	}

	/**
	 * The earliest available_at among pending rows (local mysql string), or '' when
	 * none are waiting. Used to line up the next worker run precisely.
	 */
	public function next_pending_at(): string {
		global $wpdb;

		$table = $this->table();
		$next  = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MIN(available_at) FROM %i WHERE status = %s', $table, self::STATUS_PENDING )
		);

		return is_string( $next ) ? $next : '';
	}

	/**
	 * Counts by status plus the next scheduled run time, for the admin queue view.
	 *
	 * @return array{pending:int, claimed:int, failed:int, due:int, next_at:string}
	 */
	public function stats(): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM %i GROUP BY status', $table ), ARRAY_A );

		$counts = [
			self::STATUS_PENDING => 0,
			self::STATUS_CLAIMED => 0,
			self::STATUS_FAILED  => 0,
		];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( array_key_exists( $status, $counts ) ) {
				$counts[ $status ] = (int) ( $row['c'] ?? 0 );
			}
		}

		return [
			'pending' => $counts[ self::STATUS_PENDING ],
			'claimed' => $counts[ self::STATUS_CLAIMED ],
			'failed'  => $counts[ self::STATUS_FAILED ],
			'due'     => $this->count_due(),
			'next_at' => $this->next_pending_at(),
		];
	}

	/**
	 * Move failed rows back to pending with a fresh attempt budget so they run again
	 * now. Returns the number requeued.
	 */
	public function requeue_failed( int $extra_attempts ): int {
		global $wpdb;

		$table = $this->table();
		$now   = self::now();
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, claim = '', reserved_at = NULL, available_at = %s,
					max_attempts = attempts + %d, updated_at = %s
				WHERE status = %s",
				$table,
				self::STATUS_PENDING,
				$now,
				max( 1, $extra_attempts ),
				$now,
				self::STATUS_FAILED
			)
		);

		return (int) $moved;
	}

	/**
	 * Delete all failed rows. Returns the number removed.
	 */
	public function clear_failed(): int {
		global $wpdb;

		$deleted = $wpdb->delete( $this->table(), [ 'status' => self::STATUS_FAILED ], [ '%s' ] );

		return (int) $deleted;
	}

	private static function now(): string {
		return current_time( 'mysql' );
	}

	private static function future( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) + $seconds ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- paired with gmdate to render a local wall-clock string consistent with current_time('mysql').
	}

	private static function past( int $seconds ): string {
		return self::future( -$seconds );
	}
}
