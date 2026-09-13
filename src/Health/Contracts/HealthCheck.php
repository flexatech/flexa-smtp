<?php

declare(strict_types=1);

namespace Flexa\Smtp\Health\Contracts;

use Flexa\Smtp\Health\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * A single, self-contained health check. Implementations own one concern
 * (environment, transport, DNS) and may return several {@see CheckResult} rows.
 *
 * Checks may perform network I/O (DNS lookups, a socket connect), so they are
 * only ever invoked by {@see \Flexa\Smtp\Health\HealthChecker} inside a cron run
 * or an explicit admin refresh — never during a normal front-end request. A
 * check that cannot verify something must report NOT_CHECKED, not guess.
 */
interface HealthCheck {
	/**
	 * Stable group slug, used to bucket this check's rows in the UI.
	 */
	public function id(): string;

	/**
	 * Human-readable group label (i18n).
	 */
	public function label(): string;

	/**
	 * @return list<CheckResult>
	 */
	public function run(): array;
}
