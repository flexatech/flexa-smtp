<?php

declare(strict_types=1);

namespace Flexa\Smtp\Maintenance;

use Flexa\Smtp\Database\Schema;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wipes all Flexa SMTP data. The single destructive path — used by the REST
 * danger zone (DangerZoneEndpoint) and the `wp flexa-smtp reset` CLI command —
 * so the two can never drift. Never inline a second DELETE anywhere else.
 */
final class Eraser {
	/**
	 * @return array{settings_removed:bool, tables_dropped:bool}
	 */
	public static function erase_all(): array {
		$removed = delete_option( Settings::OPTION_KEY );

		$dropped = false;
		if ( class_exists( Schema::class ) ) {
			Schema::drop();
			$dropped = true;
		}

		do_action( 'flexa_smtp.data_reset' );

		return [
			'settings_removed' => (bool) $removed,
			'tables_dropped'   => $dropped,
		];
	}
}
