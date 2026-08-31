<?php

declare(strict_types=1);

namespace Flexa\Smtp\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Import from Easy WP SMTP (v2+). Its `easy_wp_smtp` option and
 * `{prefix}easywpsmtp_emails_log` / `{prefix}easywpsmtp_email_tracking_events`
 * tables mirror WP Mail SMTP's layout, so the shared mapper handles both.
 */
final class EasyWpSmtp extends AbstractImporter {
	private const OPTION = 'easy_wp_smtp';

	public function slug(): string {
		return 'easywpsmtp';
	}

	public function label(): string {
		return 'Easy WP SMTP';
	}

	public function is_available(): bool {
		$option = get_option( self::OPTION, [] );

		return ( is_array( $option ) && [] !== $option ) || $this->table_exists( $this->logs_table() );
	}

	public function import_settings(): bool {
		$option = get_option( self::OPTION, [] );
		if ( ! is_array( $option ) || [] === $option ) {
			return false;
		}

		return $this->apply_settings( $this->map_wpms_settings( $option ) );
	}

	public function import_logs(): int {
		global $wpdb;

		return $this->import_wpms_logs( $this->logs_table(), $wpdb->prefix . 'easywpsmtp_email_tracking_events' );
	}

	private function logs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'easywpsmtp_emails_log';
	}
}
