<?php

declare(strict_types=1);

namespace Flexa\Smtp\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Import from WP Mail SMTP — settings live in the `wp_mail_smtp` option
 * (mail/smtp/provider sub-arrays) and logs in `{prefix}wpmailsmtp_emails_log`
 * with tracking in `{prefix}wpmailsmtp_email_tracking_events` (Pro).
 */
final class WpMailSmtp extends AbstractImporter {
	private const OPTION = 'wp_mail_smtp';

	public function slug(): string {
		return 'wpmailsmtp';
	}

	public function label(): string {
		return 'WP Mail SMTP';
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

		return $this->import_wpms_logs( $this->logs_table(), $wpdb->prefix . 'wpmailsmtp_email_tracking_events' );
	}

	private function logs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpmailsmtp_emails_log';
	}
}
