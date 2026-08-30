<?php

declare(strict_types=1);

namespace Flexa\Smtp\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation is intentionally non-destructive: settings and email logs
 * survive so a re-activation restores the prior state. Full teardown happens
 * only on uninstall (uninstall.php) or via the danger zone (Maintenance\Eraser).
 */
final class Deactivator {
	public static function deactivate(): void {
		// Clear any scheduled cron events registered by Reports\Scheduler (WP7).
		foreach ( [
			'flexa_smtp_retention',
			'flexa_smtp_report_weekly',
			'flexa_smtp_report_monthly',
		] as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
