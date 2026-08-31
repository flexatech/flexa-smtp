<?php

declare(strict_types=1);

namespace Flexa\Smtp\Reports;

use Flexa\Smtp\Domain\ClickEventRepository;
use Flexa\Smtp\Domain\EmailLogRepository;
use Flexa\Smtp\Domain\OpenEventRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the email report for a trailing window of days: headline totals,
 * open/click rates, a continuous per-day series for the chart, the mailer
 * breakdown, and the most-clicked links. Pure aggregation over the three
 * repositories — no side effects — so both {@see \Flexa\Smtp\Api\ReportsEndpoint}
 * and the scheduled {@see Digest} read from one place.
 */
final class Stats {
	/**
	 * @return array{
	 *   range: array{days:int, from:string, to:string},
	 *   totals: array{sent:int, failed:int, total:int, opens:int, clicks:int, opened_messages:int, clicked_messages:int, open_rate:float, click_rate:float},
	 *   series: list<array{date:string, sent:int, failed:int, opens:int, clicks:int}>,
	 *   mailers: list<array{mailer:string, count:int}>,
	 *   top_links: list<array{url:string, clicks:int}>
	 * }
	 */
	public static function summary( int $days ): array {
		$days = max( 1, min( 365, $days ) );

		// Local "wall clock" epoch (site timezone) so the day buckets below line
		// up with log timestamps stored via current_time( 'mysql' ).
		$today    = current_datetime();
		$now      = $today->getTimestamp() + $today->getOffset();
		$to       = gmdate( 'Y-m-d 23:59:59', $now );
		$start_ts = $now - ( $days - 1 ) * DAY_IN_SECONDS;
		$from     = gmdate( 'Y-m-d 00:00:00', $start_ts );

		$logs   = new EmailLogRepository();
		$opens  = new OpenEventRepository();
		$clicks = new ClickEventRepository();

		$status = $logs->status_counts( $from, $to );
		$open   = $opens->stats_in_range( $from, $to );
		$click  = $clicks->stats_in_range( $from, $to );

		$sent = $status['sent'];

		$daily_status = $logs->daily_status( $from, $to );
		$daily_opens  = $opens->daily_opens( $from, $to );
		$daily_clicks = $clicks->daily_clicks( $from, $to );

		$series = [];
		for ( $i = 0; $i < $days; $i++ ) {
			$day        = gmdate( 'Y-m-d', $start_ts + $i * DAY_IN_SECONDS );
			$day_status = $daily_status[ $day ] ?? [
				'sent'   => 0,
				'failed' => 0,
			];
			$series[]   = [
				'date'   => $day,
				'sent'   => (int) $day_status['sent'],
				'failed' => (int) $day_status['failed'],
				'opens'  => (int) ( $daily_opens[ $day ] ?? 0 ),
				'clicks' => (int) ( $daily_clicks[ $day ] ?? 0 ),
			];
		}

		return [
			'range'     => [
				'days' => $days,
				'from' => $from,
				'to'   => $to,
			],
			'totals'    => [
				'sent'             => $sent,
				'failed'           => $status['failed'],
				'total'            => $status['total'],
				'opens'            => $open['opens'],
				'clicks'           => $click['clicks'],
				'opened_messages'  => $open['messages'],
				'clicked_messages' => $click['messages'],
				'open_rate'        => self::rate( $open['messages'], $sent ),
				'click_rate'       => self::rate( $click['messages'], $sent ),
			],
			'series'    => $series,
			'mailers'   => $logs->mailer_breakdown( $from, $to ),
			'top_links' => $clicks->top_urls( $from, $to ),
		];
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
