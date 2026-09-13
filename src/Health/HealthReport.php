<?php

declare(strict_types=1);

namespace Flexa\Smtp\Health;

defined( 'ABSPATH' ) || exit;

/**
 * The aggregated result of a health run: every {@see CheckResult} plus a single
 * overall status (the worst status present) and the timestamp it was generated.
 * Persisted as an array by {@see HealthChecker} and served read-only over REST;
 * the checks themselves only run in cron or an explicit admin refresh, never on a
 * front-end request.
 */
final class HealthReport {
	/**
	 * Severity ranking used to pick the overall status. NOT_CHECKED and NA are
	 * neutral: they never drag the overall status down.
	 */
	private const RANK = [
		CheckResult::PASS  => 1,
		CheckResult::WARN  => 2,
		CheckResult::ERROR => 3,
	];

	/**
	 * @param list<CheckResult> $checks
	 */
	public function __construct(
		public readonly string $status,
		public readonly int $generated_at,
		public readonly array $checks,
	) {}

	/**
	 * Build a report from raw results, computing the overall status as the highest
	 * severity present. When nothing was actually checked the overall is NOT_CHECKED.
	 *
	 * @param list<CheckResult> $results
	 */
	public static function from_results( array $results, int $generated_at ): self {
		$worst = 0;
		foreach ( $results as $result ) {
			$rank  = self::RANK[ $result->status ] ?? 0;
			$worst = max( $worst, $rank );
		}

		$overall = array_search( $worst, self::RANK, true );

		return new self(
			is_string( $overall ) ? $overall : CheckResult::NOT_CHECKED,
			$generated_at,
			$results,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'status'       => $this->status,
			'generated_at' => $this->generated_at,
			'checks'       => array_map( static fn ( CheckResult $c ): array => $c->to_array(), $this->checks ),
		];
	}
}
