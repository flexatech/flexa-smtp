<?php

declare(strict_types=1);

namespace Flexa\Smtp\Import\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * One migration source (another SMTP plugin whose settings/logs we can read).
 * Each importer knows exactly where its source stores data and how to map it
 * onto Flexa SMTP's own settings schema and log tables.
 */
interface ImporterInterface {
	/**
	 * Stable identifier used in the REST payload and the imported-sources flag.
	 */
	public function slug(): string;

	/**
	 * Human-readable name of the source plugin, for the admin UI and notice.
	 */
	public function label(): string;

	/**
	 * Whether this source has data on the current site (settings option present
	 * or a known table exists). Drives both the detection notice and the UI list.
	 */
	public function is_available(): bool;

	/**
	 * Read the source's configuration and merge it onto Flexa SMTP's settings
	 * (through {@see \Flexa\Smtp\Support\Settings::save()}, so secrets are
	 * encrypted and unknown keys dropped). Returns true when anything was applied.
	 */
	public function import_settings(): bool;

	/**
	 * Copy the source's email log rows (and any open/click events) into Flexa
	 * SMTP's tables. Returns the number of log rows imported.
	 */
	public function import_logs(): int;
}
