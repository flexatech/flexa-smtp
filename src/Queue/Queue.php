<?php

declare(strict_types=1);

namespace Flexa\Smtp\Queue;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Domain\EmailLog;
use Flexa\Smtp\Domain\EmailLogRepository;
use Flexa\Smtp\Mailer\MailerManager;
use Flexa\Smtp\Mailer\PhpMailerBridge;
use Flexa\Smtp\Mailer\Result;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The opt-in delivery queue (DL4). When enabled, {@see MailerManager} hands off a
 * fully-populated message here instead of sending it inline; a worker later
 * rebuilds the PHPMailer and sends it, retrying only errors the diagnostics engine
 * marked retryable, with exponential backoff. It prefers Action Scheduler when the
 * host has it (WooCommerce sites usually do) and falls back to WP-Cron; either way
 * the queue table is the source of truth.
 *
 * Nothing here runs on a normal front-end request beyond the cheap enqueue insert:
 * the actual sending only happens in the async worker.
 */
final class Queue {
	use HasInstance;

	public const RUN_HOOK  = 'flexa_smtp_queue_run';
	public const TICK_HOOK = 'flexa_smtp_queue_tick';

	/** Action Scheduler group so our actions are easy to find and purge. */
	private const AS_GROUP = 'flexa-smtp';

	/** Recurring safety-net interval that catches any run a precise event missed. */
	private const TICK_SCHEDULE = 'flexa_smtp_fifteen_minutes';

	/** Short lock so overlapping worker runs do not thrash the same batch. */
	private const LOCK = 'flexa_smtp_queue_lock';

	/** A row claimed longer than this is treated as abandoned and reclaimed. */
	private const STUCK_SECONDS = 300;

	/** Outcome codes from {@see enqueue()}. */
	public const ENQUEUE_FAILED    = 0;
	public const ENQUEUE_OK        = 1;
	public const ENQUEUE_DUPLICATE = 2;

	private ?QueueRepository $repository  = null;
	private ?EmailLogRepository $log_repo = null;

	public function register(): void {
		add_action( self::RUN_HOOK, [ $this, 'run_worker' ] );
		add_action( self::TICK_HOOK, [ $this, 'run_worker' ] );
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
		add_action( 'admin_init', [ $this, 'sync' ] );
		add_action( 'flexa_smtp.settings.updated', [ $this, 'sync' ] );
	}

	/**
	 * Persist a message for out-of-band delivery. Returns one of the ENQUEUE_*
	 * codes so the caller can settle the log row correctly (queued, duplicate, or
	 * fall back to a synchronous send when the insert fails).
	 */
	public function enqueue( PhpMailerBridge $php, int $log_id, bool $from_sync_retry ): int {
		$payload = $php->to_payload();
		$key     = $this->idempotency_key( $payload );

		/**
		 * Skip enqueuing a message identical to one already waiting. Best-effort:
		 * the key is a fingerprint of sender, recipients, subject, and body hash.
		 *
		 * @param bool $dedupe
		 */
		if ( ! $from_sync_retry && (bool) apply_filters( 'flexa_smtp.queue.dedupe', true ) && $this->repo()->active_key_exists( $key ) ) {
			return self::ENQUEUE_DUPLICATE;
		}

		$max       = $this->max_attempts();
		$attempts  = 0;
		$available = current_time( 'mysql' );

		if ( $from_sync_retry ) {
			// One attempt already happened synchronously; schedule the next with backoff.
			$attempts  = 1;
			$available = $this->future( $this->backoff( 1 ) );
		} elseif ( ! (bool) Settings::get( 'enable_retry' ) ) {
			// Pure async send, retries off: a single attempt only.
			$max = 1;
		}

		$id = $this->repo()->insert(
			[
				'log_id'          => $log_id,
				'idempotency_key' => $key,
				'payload'         => $payload,
				'attempts'        => $attempts,
				'max_attempts'    => $max,
				'available_at'    => $available,
			]
		);

		return $id > 0 ? self::ENQUEUE_OK : self::ENQUEUE_FAILED;
	}

	/**
	 * Ask the async runner to process the queue. With no argument it runs as soon as
	 * possible; pass a unix timestamp to run then (used to fire a backoff'd retry
	 * close to its due time instead of waiting for the recurring tick). Uses Action
	 * Scheduler when present, otherwise a one-off WP-Cron event; both are deduped so
	 * only one run is ever pending.
	 */
	public function schedule_worker( ?int $when = null ): void {
		$ts = null === $when ? time() + 30 : max( time() + 30, $when );

		if ( $this->uses_action_scheduler()
			&& function_exists( 'as_has_scheduled_action' )
			&& function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' ) ) {
			if ( as_has_scheduled_action( self::RUN_HOOK, [], self::AS_GROUP ) ) {
				return;
			}
			if ( null === $when ) {
				as_enqueue_async_action( self::RUN_HOOK, [], self::AS_GROUP );
			} else {
				as_schedule_single_action( $ts, self::RUN_HOOK, [], self::AS_GROUP );
			}
			return;
		}

		if ( false === wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_single_event( $ts, self::RUN_HOOK );
		}
	}

	/**
	 * Worker entry point (cron/Action Scheduler callback). Reclaims stuck rows,
	 * claims a batch, sends each, and reschedules itself while work remains. Runs
	 * regardless of the feature toggles so any leftover rows always drain.
	 */
	public function run_worker(): void {
		if ( false !== get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$deadline = microtime( true ) + $this->time_budget();

		try {
			$this->repo()->reclaim_stuck( self::STUCK_SECONDS );

			$claim = substr( md5( uniqid( 'flexa_smtp_', true ) ), 0, 32 );
			$ids   = $this->repo()->claim_batch( $claim, $this->batch_size() );

			foreach ( $ids as $id ) {
				$item = $this->repo()->find( $id );
				if ( ! $item instanceof QueueItem ) {
					continue;
				}
				$this->process_item( $item );

				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
		} finally {
			delete_transient( self::LOCK );
		}

		// Keep going while rows are due now; otherwise line up the next run for the
		// earliest backoff'd retry so it fires near its due time, not on the next tick.
		if ( $this->repo()->count_due() > 0 ) {
			$this->schedule_worker();
			return;
		}

		$next = $this->repo()->next_pending_at();
		if ( '' !== $next ) {
			$this->schedule_worker( (int) get_gmt_from_date( $next, 'U' ) );
		}
	}

	/**
	 * Send one queued message and record the outcome on both the queue row and the
	 * linked log row. Never throws — a failure just reschedules or fails the row.
	 */
	private function process_item( QueueItem $item ): void {
		$php    = PhpMailerBridge::from_payload( $item->payload );
		$result = MailerManager::instance()->deliver( $php );

		$attempts    = $item->attempts + 1;
		$retry_count = max( 0, $attempts - 1 );
		$fields      = $this->result_fields( $result );

		if ( $result->ok ) {
			$this->settle_log( $item->log_id, EmailLog::STATUS_SENT, (string) ( $result->meta['mailer'] ?? '' ), '', $retry_count, $fields );
			$this->repo()->mark_done( $item->id );

			/**
			 * Fires after a queued message is sent successfully.
			 *
			 * @param QueueItem            $item
			 * @param array<string, mixed> $meta
			 */
			do_action( 'flexa_smtp.queue.sent', $item, $result->to_meta() );
			return;
		}

		$error     = (string) $result->error;
		$retryable = ( true === $result->retryable ) && (bool) Settings::get( 'enable_retry' );

		if ( $retryable && $attempts < $item->max_attempts ) {
			$available = $this->future( $this->backoff( $attempts ) );
			$this->settle_log( $item->log_id, EmailLog::STATUS_RETRYING, '', $error, $retry_count, $fields );
			$this->repo()->reschedule( $item->id, $attempts, $available, $error );

			/**
			 * Fires when a queued message failed with a retryable error and another
			 * attempt has been scheduled.
			 *
			 * @param QueueItem $item
			 * @param string    $error
			 */
			do_action( 'flexa_smtp.queue.retry_scheduled', $item, $error );
			return;
		}

		$this->settle_log( $item->log_id, EmailLog::STATUS_FAILED, '', $error, $retry_count, $fields );
		$this->repo()->mark_failed( $item->id, $attempts, $error );

		/**
		 * Fires when a queued message failed for good (non-retryable or attempts
		 * exhausted).
		 *
		 * @param QueueItem $item
		 * @param string    $error
		 */
		do_action( 'flexa_smtp.queue.failed', $item, $error );
	}

	/**
	 * Update the linked log row to the given outcome. No-op when the message was not
	 * logged (log_id 0).
	 *
	 * @param array<string, mixed> $fields
	 */
	private function settle_log( int $log_id, int $status, string $mailer, string $reason, int $retry_count, array $fields ): void {
		if ( $log_id <= 0 ) {
			return;
		}

		$data = [
			'status'       => $status,
			'reason_error' => $reason,
			'retry_count'  => $retry_count,
		] + $fields;

		if ( '' !== $mailer ) {
			$data['mailer'] = $mailer;
		}

		$this->logs()->update( $log_id, $data );
	}

	/**
	 * Map the DeliveryResult fields onto log columns, keeping only what is present.
	 *
	 * @return array<string, mixed>
	 */
	private function result_fields( Result $result ): array {
		$fields = [];

		if ( null !== $result->error_category ) {
			$fields['error_category'] = $result->error_category;
		}
		if ( null !== $result->response_code ) {
			$fields['response_code'] = (string) $result->response_code;
		}
		if ( null !== $result->provider_message_id ) {
			$fields['provider_message_id'] = $result->provider_message_id;
		}
		if ( isset( $result->meta['duration_ms'] ) && is_numeric( $result->meta['duration_ms'] ) ) {
			$fields['duration_ms'] = (int) $result->meta['duration_ms'];
		}

		return $fields;
	}

	// --- Admin surface (REST) -------------------------------------------------

	/**
	 * Queue state for the admin view.
	 *
	 * @return array<string, mixed>
	 */
	public function stats(): array {
		$stats                     = $this->repo()->stats();
		$stats['action_scheduler'] = $this->uses_action_scheduler();
		$stats['enabled']          = (bool) Settings::get( 'enable_queue' );
		$stats['retry_enabled']    = (bool) Settings::get( 'enable_retry' );
		$stats['max_attempts']     = $this->max_attempts();

		return $stats;
	}

	/**
	 * Run the worker now (manual admin trigger) and return the fresh stats.
	 *
	 * @return array<string, mixed>
	 */
	public function run_now(): array {
		$this->run_worker();

		return $this->stats();
	}

	/**
	 * Requeue every failed row with a fresh attempt budget and kick the worker.
	 *
	 * @return array<string, mixed>
	 */
	public function requeue_failed(): array {
		$moved = $this->repo()->requeue_failed( $this->max_attempts() );
		if ( $moved > 0 ) {
			$this->schedule_worker();
		}

		return $this->stats();
	}

	/**
	 * Delete all failed rows.
	 *
	 * @return array<string, mixed>
	 */
	public function clear_failed(): array {
		$this->repo()->clear_failed();

		return $this->stats();
	}

	// --- Scheduling -----------------------------------------------------------

	/**
	 * Keep the recurring safety-net tick in line with the feature toggles: schedule
	 * it while queue or retry is on, clear it otherwise.
	 */
	public function sync(): void {
		$enabled = (bool) Settings::get( 'enable_queue' ) || (bool) Settings::get( 'enable_retry' );
		$next    = wp_next_scheduled( self::TICK_HOOK );

		if ( $enabled && false === $next ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, self::TICK_SCHEDULE, self::TICK_HOOK );
		} elseif ( ! $enabled && false !== $next ) {
			wp_unschedule_event( $next, self::TICK_HOOK );
		}
	}

	/**
	 * @param array<string, array{interval:int, display:string}> $schedules
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function add_schedule( array $schedules ): array {
		$schedules[ self::TICK_SCHEDULE ] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every Fifteen Minutes (Flexa SMTP)', 'flexa-smtp' ),
		];

		return $schedules;
	}

	private function uses_action_scheduler(): bool {
		$available = function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );

		/**
		 * Filter whether Action Scheduler is used to run the queue. Defaults to true
		 * when the functions exist; return false to force the WP-Cron fallback.
		 *
		 * @param bool $available
		 */
		return (bool) apply_filters( 'flexa_smtp.queue.use_action_scheduler', $available );
	}

	private function max_attempts(): int {
		return max( 1, min( 10, (int) Settings::get( 'queue_max_attempts' ) ) );
	}

	private function batch_size(): int {
		/**
		 * How many messages one worker run may process.
		 *
		 * @param int $size
		 */
		return max( 1, min( 100, (int) apply_filters( 'flexa_smtp.queue.batch_size', 20 ) ) );
	}

	private function time_budget(): float {
		/**
		 * Wall-clock seconds one worker run may spend before yielding.
		 *
		 * @param int $seconds
		 */
		return (float) max( 5, min( 120, (int) apply_filters( 'flexa_smtp.queue.time_budget', 20 ) ) );
	}

	/**
	 * Seconds to wait before the Nth attempt (1-based), capped. Filterable.
	 */
	private function backoff( int $attempts ): int {
		$steps = [ 60, 300, 1800, 7200, 21600 ];
		$index = max( 1, min( $attempts, count( $steps ) ) ) - 1;

		/**
		 * Filter the backoff delay (seconds) before the next attempt.
		 *
		 * @param int $delay
		 * @param int $attempts
		 */
		return (int) apply_filters( 'flexa_smtp.queue.backoff', $steps[ $index ], $attempts );
	}

	private function future( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) + $seconds ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- paired with gmdate to render a local wall-clock string consistent with current_time('mysql').
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function idempotency_key( array $payload ): string {
		$from = is_array( $payload['from'] ?? null ) ? (string) ( $payload['from']['address'] ?? '' ) : '';
		$to   = [];
		foreach ( is_array( $payload['to'] ?? null ) ? $payload['to'] : [] as $entry ) {
			if ( is_array( $entry ) ) {
				$to[] = (string) ( $entry['address'] ?? '' );
			}
		}

		$material = implode( '|', [ $from, implode( ',', $to ), (string) ( $payload['subject'] ?? '' ), md5( (string) ( $payload['body'] ?? '' ) ) ] );

		return hash( 'sha256', $material );
	}

	private function repo(): QueueRepository {
		if ( null === $this->repository ) {
			$this->repository = new QueueRepository();
		}

		return $this->repository;
	}

	private function logs(): EmailLogRepository {
		if ( null === $this->log_repo ) {
			$this->log_repo = new EmailLogRepository();
		}

		return $this->log_repo;
	}
}
