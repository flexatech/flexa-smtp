<?php

declare(strict_types=1);

namespace Flexa\Smtp\Health;

defined( 'ABSPATH' ) || exit;

/**
 * One line item in a {@see HealthReport}: the outcome of a single check, in a
 * shape the React admin can render directly. A single {@see Contracts\HealthCheck}
 * may emit several of these (e.g. the DNS check reports SPF, DMARC and DKIM
 * separately) so each concern gets its own status and remediation.
 *
 * Status vocabulary is deliberately small and honest:
 *   PASS        — verified good.
 *   WARN        — advisory; delivery can still work but should be improved.
 *   ERROR       — a real problem that will likely stop or degrade delivery.
 *   NOT_CHECKED — could not be verified (function disabled, no selector, etc.);
 *                 never guessed as pass or fail.
 *   NA          — not applicable to the current configuration.
 */
final class CheckResult {
	public const PASS        = 'pass';
	public const WARN        = 'warn';
	public const ERROR       = 'error';
	public const NOT_CHECKED = 'not_checked';
	public const NA          = 'na';

	/**
	 * @param array<string, scalar> $context Extra, non-secret detail for the UI
	 *                                        (host, port, resolved record, code).
	 */
	public function __construct(
		public readonly string $group,
		public readonly string $id,
		public readonly string $label,
		public readonly string $status,
		public readonly string $message,
		public readonly string $remediation = '',
		public readonly array $context = [],
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'group'       => $this->group,
			'id'          => $this->id,
			'label'       => $this->label,
			'status'      => $this->status,
			'message'     => $this->message,
			'remediation' => $this->remediation,
			'context'     => $this->context,
		];
	}
}
