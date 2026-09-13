<?php

declare(strict_types=1);

namespace Flexa\Smtp\Health;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Health\Checks\DnsCheck;
use Flexa\Smtp\Health\Checks\EnvironmentCheck;
use Flexa\Smtp\Health\Checks\TransportCheck;
use Flexa\Smtp\Health\Contracts\HealthCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the health-check lifecycle: it runs the checks (only in a daily cron or an
 * explicit admin refresh, never on a front-end request), caches the resulting
 * {@see HealthReport} in an option, and serves that cache to readers. Keeping the
 * only network-touching path here is what enforces the "no remote calls on normal
 * requests" rule for the whole feature.
 */
final class HealthChecker {
	use HasInstance;

	public const CRON_HOOK = 'flexa_smtp_health';
	public const OPTION    = 'flexa_smtp_health_report';

	/**
	 * A cached report older than this is reported as stale so the UI can prompt a
	 * refresh; it does not trigger a run on its own.
	 */
	public const STALE_AFTER = 25 * HOUR_IN_SECONDS;

	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_scheduled' ] );
		add_action( 'admin_init', [ $this, 'maybe_schedule' ] );
	}

	/**
	 * Cron entry point: run the checks and discard the return (an action callback
	 * must not return a value).
	 */
	public function run_scheduled(): void {
		$this->run();
	}

	/**
	 * Ensure the daily run is scheduled. Runs on admin_init so a site that
	 * upgraded into this version (rather than freshly activating) still gets the
	 * event without a manual re-activation.
	 */
	public function maybe_schedule(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Run every registered check, build the report, cache it, and return it. This
	 * is the only method that performs the checks' network I/O.
	 */
	public function run(): HealthReport {
		$results = [];
		foreach ( $this->checks() as $check ) {
			foreach ( $check->run() as $result ) {
				$results[] = $result;
			}
		}

		$report = HealthReport::from_results( $results, time() );
		update_option( self::OPTION, $report->to_array(), false );

		return $report;
	}

	/**
	 * The cached report as a plain array, or null when nothing has run yet. Safe to
	 * call on any request: it only reads the option, never runs a check.
	 *
	 * @return array<string, mixed>|null
	 */
	public function cached(): ?array {
		$data = get_option( self::OPTION, null );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * The registered checks. Filterable so a companion plugin can add its own
	 * checks; non-{@see HealthCheck} entries are ignored.
	 *
	 * @return list<HealthCheck>
	 */
	public function checks(): array {
		$checks = [
			new EnvironmentCheck(),
			new TransportCheck(),
			new DnsCheck(),
		];

		/**
		 * Filter the list of health checks. Handlers must return a list of
		 * {@see HealthCheck} instances; a companion plugin adds its own checks here.
		 *
		 * @param list<HealthCheck> $checks
		 */
		return apply_filters( 'flexa_smtp.health.checks', $checks );
	}
}
