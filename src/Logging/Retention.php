<?php

declare(strict_types=1);

namespace Flexa\Smtp\Logging;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Domain\EmailLogRepository;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Daily housekeeping: prunes email logs older than the configured retention
 * window. The cron hook name matches the one {@see \Flexa\Smtp\Setup\Deactivator}
 * clears, so deactivation always unschedules it. A retention of 0 keeps logs
 * forever (the purge no-ops).
 */
final class Retention {
	use HasInstance;

	public const HOOK = 'flexa_smtp_retention';

	public function register(): void {
		// Wrap in a void closure: purge() returns the deleted-row count for CLI/
		// tests, but an action callback must not return anything.
		add_action(
			self::HOOK,
			function (): void {
				$this->purge();
			}
		);

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Delete logs past the retention window. Returns the number of rows removed
	 * so callers (CLI, tests) can report it.
	 */
	public function purge(): int {
		$days = (int) Settings::get( 'log_retention_days' );
		if ( $days <= 0 ) {
			return 0;
		}

		$removed = ( new EmailLogRepository() )->delete_older_than( $days );

		/**
		 * Fires after the retention purge, so extensions (e.g. tracking-event
		 * cleanup in WP6/WP7) can piggyback.
		 *
		 * @param int $removed Number of log rows deleted.
		 * @param int $days    Retention window in days.
		 */
		do_action( 'flexa_smtp.logs.purged', $removed, $days );

		return $removed;
	}
}
