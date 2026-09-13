<?php

declare(strict_types=1);

namespace Flexa\Smtp\Monitoring;

use Flexa\Smtp\Domain\EmailLogRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Provider monitoring: per-mailer reliability computed from this site's own email
 * log over a trailing window. It answers "which of my providers are failing, and
 * why" without any remote calls. These are local metrics, not the provider's
 * published status page — the response says so explicitly so the number is never
 * mistaken for provider-wide health.
 *
 * A provider's health signal is derived only from its failure rate, and only once
 * there is enough volume to be meaningful (below {@see self::MIN_SAMPLE} sends it
 * is reported as "insufficient data" rather than a false green or red).
 */
final class Monitor {
	public const HEALTH_HEALTHY  = 'healthy';
	public const HEALTH_DEGRADED = 'degraded';
	public const HEALTH_FAILING  = 'failing';
	public const HEALTH_UNKNOWN  = 'insufficient_data';

	/** Minimum attempts before a failure rate is treated as a signal. */
	private const MIN_SAMPLE = 5;

	/** Failure-rate thresholds (percent) for the health signal. */
	private const DEGRADED_AT = 10.0;
	private const FAILING_AT  = 40.0;

	/**
	 * @return array{
	 *   range: array{days:int, from:string, to:string},
	 *   totals: array{sent:int, failed:int, total:int, failure_rate:float},
	 *   providers: list<array{mailer:string, sent:int, failed:int, total:int, failure_rate:float, avg_duration_ms:int, last_sent_at:string, last_failed_at:string, health:string}>,
	 *   categories: list<array{category:string, count:int}>,
	 *   scope: string
	 * }
	 */
	public static function snapshot( int $days ): array {
		$days = max( 1, min( 365, $days ) );

		$today    = current_datetime();
		$now      = $today->getTimestamp() + $today->getOffset();
		$to       = gmdate( 'Y-m-d 23:59:59', $now );
		$start_ts = $now - ( $days - 1 ) * DAY_IN_SECONDS;
		$from     = gmdate( 'Y-m-d 00:00:00', $start_ts );

		$repo = new EmailLogRepository();

		$providers  = [];
		$total_sent = 0;
		$total_fail = 0;
		foreach ( $repo->provider_stats( $from, $to ) as $row ) {
			$total_sent += $row['sent'];
			$total_fail += $row['failed'];

			$providers[] = [
				'mailer'          => $row['mailer'],
				'sent'            => $row['sent'],
				'failed'          => $row['failed'],
				'total'           => $row['total'],
				'failure_rate'    => self::rate( $row['failed'], $row['total'] ),
				'avg_duration_ms' => $row['avg_duration_ms'],
				'last_sent_at'    => $row['last_sent_at'],
				'last_failed_at'  => $row['last_failed_at'],
				'health'          => self::health( $row['total'], $row['failed'] ),
			];
		}

		$total = $total_sent + $total_fail;

		return [
			'range'      => [
				'days' => $days,
				'from' => $from,
				'to'   => $to,
			],
			'totals'     => [
				'sent'         => $total_sent,
				'failed'       => $total_fail,
				'total'        => $total,
				'failure_rate' => self::rate( $total_fail, $total ),
			],
			'providers'  => $providers,
			'categories' => $repo->category_counts( $from, $to ),
			// Machine-readable scope marker so the client always labels these as
			// local metrics, not the provider's own uptime.
			'scope'      => 'local',
		];
	}

	/**
	 * Health signal from volume + failure count. Under the sample floor we admit we
	 * do not know rather than showing a misleading green/red.
	 */
	private static function health( int $total, int $failed ): string {
		if ( $total < self::MIN_SAMPLE ) {
			return self::HEALTH_UNKNOWN;
		}

		$rate = self::rate( $failed, $total );
		if ( $rate >= self::FAILING_AT ) {
			return self::HEALTH_FAILING;
		}
		if ( $rate >= self::DEGRADED_AT ) {
			return self::HEALTH_DEGRADED;
		}

		return self::HEALTH_HEALTHY;
	}

	/**
	 * Percentage (0–100, one decimal) of $part over $whole; 0 when $whole is 0.
	 */
	private static function rate( int $part, int $whole ): float {
		if ( $whole <= 0 ) {
			return 0.0;
		}

		return round( ( $part / $whole ) * 100, 1 );
	}
}
