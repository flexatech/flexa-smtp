<?php

declare(strict_types=1);

namespace Flexa\Smtp\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * The normalised verdict for one failed send: what went wrong, how bad it is,
 * whether retrying could help, and what a human should do about it. Produced by
 * {@see Diagnostics::classify()} and consumed by the log/diagnostics UI. Holds no
 * credentials or secrets — `technical` is the raw provider text only.
 */
final class Diagnosis {
	public const SEVERITY_INFO    = 'info';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_ERROR   = 'error';

	public function __construct(
		public readonly string $category,
		public readonly string $severity,
		public readonly bool $retryable,
		public readonly string $explanation,
		public readonly string $action,
		public readonly string $technical,
	) {}

	/**
	 * JS-friendly array for REST. Raw values only; the React client escapes on
	 * render.
	 *
	 * @return array{category:string, severity:string, retryable:bool, explanation:string, action:string, technical:string}
	 */
	public function to_array(): array {
		return [
			'category'    => $this->category,
			'severity'    => $this->severity,
			'retryable'   => $this->retryable,
			'explanation' => $this->explanation,
			'action'      => $this->action,
			'technical'   => $this->technical,
		];
	}
}
