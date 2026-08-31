<?php

declare(strict_types=1);

namespace Flexa\Smtp\Reports;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs the weekly/monthly report digests. The cron hook names
 * match the ones {@see \Flexa\Smtp\Setup\Deactivator} clears, so deactivation
 * always unschedules them. Each run re-checks its setting, so toggling a report
 * off stops delivery even before the stale event is cleared.
 */
final class Scheduler {
	use HasInstance;

	public const WEEKLY            = 'flexa_smtp_report_weekly';
	public const MONTHLY           = 'flexa_smtp_report_monthly';
	private const MONTHLY_SCHEDULE = 'flexa_smtp_monthly';

	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_monthly_schedule' ] );
		add_action( self::WEEKLY, [ $this, 'run_weekly' ] );
		add_action( self::MONTHLY, [ $this, 'run_monthly' ] );
		add_action( 'flexa_smtp.settings.updated', [ $this, 'sync' ] );

		$this->sync();
	}

	/**
	 * WordPress has no built-in monthly interval; register a ~30-day one.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function add_monthly_schedule( array $schedules ): array {
		$schedules[ self::MONTHLY_SCHEDULE ] = [
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly (Flexa SMTP)', 'flexa-smtp' ),
		];

		return $schedules;
	}

	/**
	 * Bring the cron schedule in line with the current settings: schedule an
	 * event when its report is enabled, clear it when disabled.
	 */
	public function sync(): void {
		$this->reconcile( self::WEEKLY, 'weekly', (bool) Settings::get( 'enable_weekly_report' ) );
		$this->reconcile( self::MONTHLY, self::MONTHLY_SCHEDULE, (bool) Settings::get( 'enable_monthly_report' ) );
	}

	private function reconcile( string $hook, string $recurrence, bool $enabled ): void {
		$next = wp_next_scheduled( $hook );

		if ( $enabled && false === $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $hook );
		} elseif ( ! $enabled && false !== $next ) {
			wp_unschedule_event( $next, $hook );
		}
	}

	public function run_weekly(): void {
		if ( (bool) Settings::get( 'enable_weekly_report' ) ) {
			Digest::send( 7, __( 'weekly', 'flexa-smtp' ) );
		}
	}

	public function run_monthly(): void {
		if ( (bool) Settings::get( 'enable_monthly_report' ) ) {
			Digest::send( 30, __( 'monthly', 'flexa-smtp' ) );
		}
	}
}
