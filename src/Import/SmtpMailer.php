<?php

declare(strict_types=1);

namespace Flexa\Smtp\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Import from "SMTP Mailer" (naa986). Everything lives in the flat
 * `smtp_mailer_options` option; the password is base64-encoded. The plugin has
 * no log store, so {@see import_logs()} is a no-op.
 */
final class SmtpMailer extends AbstractImporter {
	private const OPTION = 'smtp_mailer_options';

	public function slug(): string {
		return 'smtpmailer';
	}

	public function label(): string {
		return 'SMTP Mailer';
	}

	public function is_available(): bool {
		$option = get_option( self::OPTION, [] );

		return is_array( $option ) && [] !== $option;
	}

	public function import_settings(): bool {
		$option = get_option( self::OPTION, [] );
		if ( ! is_array( $option ) || [] === $option ) {
			return false;
		}

		$payload = [ 'current_mailer' => 'smtp' ];

		if ( ! empty( $option['from_email'] ) && is_string( $option['from_email'] ) ) {
			$payload['from_email'] = $option['from_email'];
		}
		if ( ! empty( $option['from_name'] ) && is_string( $option['from_name'] ) ) {
			$payload['from_name'] = $option['from_name'];
		}

		$creds = [];
		if ( ! empty( $option['smtp_host'] ) && is_string( $option['smtp_host'] ) ) {
			$creds['host'] = $option['smtp_host'];
		}
		if ( ! empty( $option['smtp_port'] ) ) {
			$creds['port'] = (int) $option['smtp_port'];
		}
		if ( isset( $option['type_of_encryption'] ) ) {
			$creds['encryption'] = $this->map_encryption( $option['type_of_encryption'] );
		}
		if ( array_key_exists( 'smtp_auth', $option ) ) {
			$creds['auth'] = $this->to_bool( $option['smtp_auth'] );
		}
		if ( ! empty( $option['smtp_username'] ) && is_string( $option['smtp_username'] ) ) {
			$creds['user'] = $option['smtp_username'];
		}
		if ( ! empty( $option['smtp_password'] ) && is_string( $option['smtp_password'] ) ) {
			$decoded       = base64_decode( $option['smtp_password'], true );
			$creds['pass'] = false !== $decoded ? $decoded : $option['smtp_password'];
		}

		if ( [] !== $creds ) {
			$payload['mailers'] = [ 'smtp' => $creds ];
		}

		return $this->apply_settings( $payload );
	}

	public function import_logs(): int {
		return 0;
	}
}
