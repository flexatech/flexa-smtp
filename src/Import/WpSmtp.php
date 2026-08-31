<?php

declare(strict_types=1);

namespace Flexa\Smtp\Import;

use Flexa\Smtp\Domain\EmailLog;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Reads the foreign `wpsmtp_logs` table (literal name); writes via the repository.

/**
 * Import from "WP SMTP" — flat `wp_smtp_options` option (plaintext password) and
 * a `{prefix}wpsmtp_logs` table (subject, to, message, error, timestamp).
 */
final class WpSmtp extends AbstractImporter {
	private const OPTION = 'wp_smtp_options';

	public function slug(): string {
		return 'wpsmtp';
	}

	public function label(): string {
		return 'WP SMTP';
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

		$payload = [ 'current_mailer' => 'smtp' ];

		if ( ! empty( $option['from'] ) && is_string( $option['from'] ) ) {
			$payload['from_email'] = $option['from'];
		}
		if ( ! empty( $option['fromname'] ) && is_string( $option['fromname'] ) ) {
			$payload['from_name'] = $option['fromname'];
		}

		$creds = [];
		if ( ! empty( $option['host'] ) && is_string( $option['host'] ) ) {
			$creds['host'] = $option['host'];
		}
		if ( ! empty( $option['port'] ) ) {
			$creds['port'] = (int) $option['port'];
		}
		if ( isset( $option['smtpsecure'] ) ) {
			$creds['encryption'] = $this->map_encryption( $option['smtpsecure'] );
		}
		if ( array_key_exists( 'smtpauth', $option ) ) {
			$creds['auth'] = $this->to_bool( $option['smtpauth'] );
		}
		if ( ! empty( $option['username'] ) && is_string( $option['username'] ) ) {
			$creds['user'] = $option['username'];
		}
		if ( ! empty( $option['password'] ) && is_string( $option['password'] ) ) {
			$creds['pass'] = $option['password'];
		}

		if ( [] !== $creds ) {
			$payload['mailers'] = [ 'smtp' => $creds ];
		}

		return $this->apply_settings( $payload );
	}

	public function import_logs(): int {
		global $wpdb;

		$table = $this->logs_table();
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
		if ( ! is_array( $rows ) || [] === $rows ) {
			return 0;
		}

		$count = 0;
		foreach ( $rows as $row ) {
			$to      = maybe_unserialize( (string) ( $row['to'] ?? '' ) );
			$to_list = is_array( $to ) ? $to : ( '' !== (string) ( $row['to'] ?? '' ) ? [ (string) $row['to'] ] : [] );

			$error  = (string) ( $row['error'] ?? '' );
			$log_id = $this->logs()->create(
				[
					'subject'      => (string) ( $row['subject'] ?? '' ),
					'email_from'   => '',
					'email_to'     => $this->normalize_to( $to_list ),
					'mailer'       => 'smtp',
					'status'       => '' !== $error ? EmailLog::STATUS_FAILED : EmailLog::STATUS_SENT,
					'content_type' => 'text/html',
					'body_content' => (string) ( $row['message'] ?? '' ),
					'reason_error' => $error,
					'source'       => '',
					'extra_info'   => [ 'imported_from' => $this->slug() ],
					'date_time'    => (string) ( $row['timestamp'] ?? current_time( 'mysql' ) ),
				]
			);

			if ( $log_id > 0 ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param array<mixed> $to
	 * @return list<array{address:string, name:string}>
	 */
	private function normalize_to( array $to ): array {
		$out = [];
		foreach ( $to as $entry ) {
			if ( is_string( $entry ) && '' !== $entry ) {
				$out[] = [
					'address' => sanitize_email( $entry ),
					'name'    => '',
				];
			} elseif ( is_array( $entry ) ) {
				$out[] = [
					'address' => sanitize_email( (string) ( $entry[0] ?? $entry['address'] ?? '' ) ),
					'name'    => sanitize_text_field( (string) ( $entry[1] ?? $entry['name'] ?? '' ) ),
				];
			}
		}

		return $out;
	}

	private function logs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpsmtp_logs';
	}
}
