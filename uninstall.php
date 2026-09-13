<?php
/**
 * Uninstall handler. Removes every trace of Flexa SMTP: the settings option,
 * the DB version marker, and all flexa_smtp_* tables. Runs in isolation (the
 * plugin is NOT bootstrapped during uninstall) so it cannot use the plugin's
 * classes — the option key and table list below are deliberately duplicated
 * from Flexa\Smtp\Support\Settings and Flexa\Smtp\Database\Schema. Keep in sync.
 *
 * @package Flexa\Smtp
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'flexa_smtp_settings' );
delete_option( 'flexa_smtp_db_version' );
delete_option( 'flexa_smtp_health_report' );

$flexa_smtp_tables = [
	'flexa_smtp_email_logs',
	'flexa_smtp_open_events',
	'flexa_smtp_click_events',
	'flexa_smtp_email_queue',
];

foreach ( $flexa_smtp_tables as $flexa_smtp_table ) {
	$flexa_smtp_name = $wpdb->prefix . $flexa_smtp_table;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name is a hard-coded literal, not user input; dropping the plugin's own tables on uninstall, where caching does not apply to a one-off DDL statement.
	$wpdb->query( "DROP TABLE IF EXISTS {$flexa_smtp_name}" );
}
