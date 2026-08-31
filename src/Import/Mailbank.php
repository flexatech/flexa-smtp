<?php

declare(strict_types=1);

namespace Flexa\Smtp\Import;

use Flexa\Smtp\Domain\EmailLog;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Mail Bank keeps its config in a custom meta table and logs in mail_bank_logs
// (literal names). All reads bind values via prepare(); writes go via the repo.

/**
 * Import from "WP Mail Bank" (Tech Banker). Config is a serialized blob in
 * `{prefix}mail_bank_meta` (meta_key=email_configuration); logs are rows in
 * `{prefix}mail_bank_logs`. OAuth transports are inferred from the SMTP host.
 */
final class Mailbank extends AbstractImporter {
	public function slug(): string {
		return 'mailbank';
	}

	public function label(): string {
		return 'WP Mail Bank';
	}

	public function is_available(): bool {
		return [] !== $this->config() || $this->table_exists( $this->logs_table() );
	}

	public function import_settings(): bool {
		$settings = $this->config();
		if ( [] === $settings ) {
			return false;
		}

		$host    = isset( $settings['hostname'] ) && is_string( $settings['hostname'] ) ? $settings['hostname'] : '';
		$payload = [];

		// Transport: explicit mailer_type, or an OAuth host when auth_type=oauth2.
		if ( ! empty( $settings['mailer_type'] ) ) {
			$payload['current_mailer'] = 'php_mail_function' === $settings['mailer_type'] ? 'mail' : 'smtp';
		}
		$oauth = $this->oauth_slug_for_host( $host );
		if ( isset( $settings['auth_type'] ) && 'oauth2' === $settings['auth_type'] && '' !== $oauth ) {
			$payload['current_mailer'] = $oauth;
		}

		if ( ! empty( $settings['sender_email'] ) && is_string( $settings['sender_email'] ) ) {
			$payload['from_email'] = $settings['sender_email'];
		}
		if ( ! empty( $settings['sender_name'] ) && is_string( $settings['sender_name'] ) ) {
			$payload['from_name'] = $settings['sender_name'];
		}
		if ( isset( $settings['from_email_configuration'] ) ) {
			$payload['force_from_email'] = 'override' === $settings['from_email_configuration'];
		}
		if ( isset( $settings['sender_name_configuration'] ) ) {
			$payload['force_from_name'] = 'override' === $settings['sender_name_configuration'];
		}

		$creds = [];
		if ( '' !== $host ) {
			$creds['host'] = $host;
		}
		if ( ! empty( $settings['port'] ) ) {
			$creds['port'] = (int) $settings['port'];
		}
		if ( isset( $settings['enc_type'] ) ) {
			$creds['encryption'] = $this->map_encryption( $settings['enc_type'] );
		}
		if ( isset( $settings['auth_type'] ) ) {
			$creds['auth'] = in_array( $settings['auth_type'], [ 'login', 'plain' ], true );
		}
		if ( ! empty( $settings['username'] ) && is_string( $settings['username'] ) ) {
			$creds['user'] = $settings['username'];
		}
		if ( ! empty( $settings['password'] ) && is_string( $settings['password'] ) ) {
			$decoded       = base64_decode( $settings['password'], true );
			$creds['pass'] = false !== $decoded ? $decoded : $settings['password'];
		}
		if ( [] !== $creds ) {
			$payload['mailers'] = [ 'smtp' => $creds ];
		}

		// OAuth client credentials, when the host identifies the provider.
		if ( '' !== $oauth && ! empty( $settings['client_id'] ) && is_string( $settings['client_id'] ) ) {
			$payload['mailers'][ $oauth ]['client_id'] = $settings['client_id'];
		}
		if ( '' !== $oauth && ! empty( $settings['client_secret'] ) && is_string( $settings['client_secret'] ) ) {
			$payload['mailers'][ $oauth ]['client_secret'] = $settings['client_secret'];
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
			$sent   = 'Sent' === (string) ( $row['status'] ?? '' );
			$log_id = $this->logs()->create(
				[
					'subject'      => (string) ( $row['subject'] ?? '' ),
					'email_from'   => (string) ( $row['sender_email'] ?? '' ),
					'email_to'     => $this->emails_from_string( (string) ( $row['email_to'] ?? '' ) ),
					'mailer'       => 'smtp',
					'status'       => $sent ? EmailLog::STATUS_SENT : EmailLog::STATUS_FAILED,
					'content_type' => 'text/html',
					'body_content' => (string) ( $row['content'] ?? '' ),
					'reason_error' => '',
					'source'       => '',
					'extra_info'   => [ 'imported_from' => $this->slug() ],
					'date_time'    => gmdate( 'Y-m-d H:i:s', (int) ( $row['timestamp'] ?? time() ) ),
				]
			);

			if ( $log_id > 0 ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * The serialized `email_configuration` blob from the mail_bank_meta table.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		global $wpdb;

		$table = $wpdb->base_prefix . 'mail_bank_meta';
		if ( ! $this->table_exists( $table ) ) {
			return [];
		}

		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE meta_key = %s', $table, 'email_configuration' ) );
		$val = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;

		return is_array( $val ) ? $val : [];
	}

	private function oauth_slug_for_host( string $host ): string {
		if ( 'smtp.live.com' === $host ) {
			return 'outlook';
		}
		if ( 'smtp.gmail.com' === $host ) {
			return 'gmail';
		}

		return '';
	}

	private function logs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mail_bank_logs';
	}
}
