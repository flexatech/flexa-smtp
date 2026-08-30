<?php

declare(strict_types=1);

namespace Flexa\Smtp\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned schema migrator for every flexa_smtp_* table. Runs on activation
 * and on admin_init via maybe_upgrade(). All SQL lives here; data access goes
 * through the repositories under Domain/.
 *
 * The email_to column is longtext (serialized recipient list) because a single
 * message may carry many To/Cc/Bcc addresses; extra_info is longtext JSON.
 */
final class Schema {
	public const DB_VERSION     = '0.1.0';
	public const VERSION_OPTION = 'flexa_smtp_db_version';

	/**
	 * Every table this plugin owns, without the WP prefix. Shared with
	 * Maintenance\Eraser (drop); uninstall.php duplicates the list because the
	 * plugin is not bootstrapped there.
	 *
	 * @var list<string>
	 */
	public const TABLES = [
		'flexa_smtp_email_logs',
		'flexa_smtp_open_events',
		'flexa_smtp_click_events',
	];

	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		if ( $installed === self::DB_VERSION ) {
			return;
		}
		self::migrate();
	}

	public static function migrate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$schema = [];

		$schema[] = "CREATE TABLE {$p}flexa_smtp_email_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subject text NOT NULL,
			email_from varchar(190) NOT NULL DEFAULT '',
			email_to longtext,
			mailer varchar(50) NOT NULL DEFAULT '',
			status tinyint(1) NOT NULL DEFAULT 0,
			content_type varchar(100) NOT NULL DEFAULT '',
			body_content longtext,
			reason_error text,
			source varchar(190) NOT NULL DEFAULT '',
			extra_info longtext,
			flag_delete tinyint(1) NOT NULL DEFAULT 0,
			date_time datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY mailer (mailer),
			KEY date_time (date_time)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$p}flexa_smtp_open_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id bigint(20) unsigned NOT NULL,
			count int(11) NOT NULL DEFAULT 0,
			extra_info longtext,
			date_time datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY log_id (log_id)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$p}flexa_smtp_click_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id bigint(20) unsigned NOT NULL,
			url text NOT NULL,
			count int(11) NOT NULL DEFAULT 0,
			extra_info longtext,
			date_time datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY log_id (log_id)
		) {$charset_collate};";

		foreach ( $schema as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function drop(): void {
		global $wpdb;

		foreach ( self::TABLES as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a class constant, not user input; identifiers cannot be bound via prepare().
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
		}

		delete_option( self::VERSION_OPTION );
	}
}
