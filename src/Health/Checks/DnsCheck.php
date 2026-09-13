<?php

declare(strict_types=1);

namespace Flexa\Smtp\Health\Checks;

use Flexa\Smtp\Health\CheckResult;
use Flexa\Smtp\Health\Contracts\HealthCheck;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Authentication DNS for the sending domain: SPF and DMARC are looked up and
 * reported as advisory (a missing record does not stop mail, so it is a WARN,
 * never an ERROR). DKIM is intentionally NOT_CHECKED: the selector is
 * provider-specific and cannot be discovered generically, so we tell the truth
 * rather than guess.
 *
 * DNS lookups are slow on some hosts and are network I/O, so this check only ever
 * runs inside the health runner (cron or explicit refresh), never on a front-end
 * request. Results are cached with the rest of the report.
 */
final class DnsCheck implements HealthCheck {
	public function id(): string {
		return 'dns';
	}

	public function label(): string {
		return __( 'Sending domain DNS', 'flexa-smtp' );
	}

	/**
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$domain = $this->sending_domain();

		if ( '' === $domain ) {
			return [
				$this->row( 'spf', CheckResult::NA, __( 'No public sending domain to check.', 'flexa-smtp' ) ),
			];
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			return [
				$this->row( 'spf', CheckResult::NOT_CHECKED, __( 'DNS lookups are disabled on this server.', 'flexa-smtp' ), '', [ 'domain' => $domain ] ),
			];
		}

		return [
			$this->spf_result( $domain ),
			$this->dmarc_result( $domain ),
			$this->dkim_result( $domain ),
		];
	}

	private function spf_result( string $domain ): CheckResult {
		$records = $this->txt_records( $domain );
		foreach ( $records as $txt ) {
			if ( str_starts_with( strtolower( $txt ), 'v=spf1' ) ) {
				return $this->row(
					'spf',
					CheckResult::PASS,
					__( 'An SPF record is published for the sending domain.', 'flexa-smtp' ),
					'',
					[ 'domain' => $domain ]
				);
			}
		}

		return $this->row(
			'spf',
			CheckResult::WARN,
			__( 'No SPF record was found for the sending domain. Messages are more likely to be marked as spam.', 'flexa-smtp' ),
			__( 'Publish an SPF (TXT) record that authorises your provider to send for this domain.', 'flexa-smtp' ),
			[ 'domain' => $domain ]
		);
	}

	private function dmarc_result( string $domain ): CheckResult {
		$records = $this->txt_records( '_dmarc.' . $domain );
		foreach ( $records as $txt ) {
			if ( str_starts_with( strtolower( $txt ), 'v=dmarc1' ) ) {
				return $this->row(
					'dmarc',
					CheckResult::PASS,
					__( 'A DMARC record is published for the sending domain.', 'flexa-smtp' ),
					'',
					[ 'domain' => $domain ]
				);
			}
		}

		return $this->row(
			'dmarc',
			CheckResult::WARN,
			__( 'No DMARC record was found for the sending domain.', 'flexa-smtp' ),
			__( 'Publish a DMARC (TXT) record at _dmarc.yourdomain to tell receivers how to handle unauthenticated mail.', 'flexa-smtp' ),
			[ 'domain' => $domain ]
		);
	}

	private function dkim_result( string $domain ): CheckResult {
		return $this->row(
			'dkim',
			CheckResult::NOT_CHECKED,
			__( 'DKIM uses a provider-specific selector that cannot be detected automatically.', 'flexa-smtp' ),
			__( 'Confirm DKIM is set up and passing in your email provider dashboard.', 'flexa-smtp' ),
			[ 'domain' => $domain ]
		);
	}

	/**
	 * @return list<string>
	 */
	private function txt_records( string $host ): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed DNS lookup returns false/emits a warning; we treat "no record" as an advisory result, not a fatal error.
		$records = @dns_get_record( $host, DNS_TXT );
		if ( ! is_array( $records ) ) {
			return [];
		}

		$out = [];
		foreach ( $records as $record ) {
			if ( isset( $record['txt'] ) && is_string( $record['txt'] ) ) {
				$out[] = $record['txt'];
			}
		}

		return $out;
	}

	/**
	 * The domain part of the configured From address, falling back to the site
	 * host. Empty for a non-public host (localhost or no dot) so we do not report
	 * misleading DNS results in local development.
	 */
	private function sending_domain(): string {
		$from   = (string) Settings::get( 'from_email' );
		$domain = '';

		if ( '' !== $from && is_email( $from ) ) {
			$at     = strrpos( $from, '@' );
			$domain = false !== $at ? substr( $from, $at + 1 ) : '';
		}

		if ( '' === $domain ) {
			$host   = wp_parse_url( home_url(), PHP_URL_HOST );
			$domain = is_string( $host ) ? $host : '';
		}

		$domain = strtolower( trim( $domain ) );
		if ( '' === $domain || ! str_contains( $domain, '.' ) || 'localhost' === $domain ) {
			return '';
		}

		return $domain;
	}

	/**
	 * @param array<string, scalar> $context
	 */
	private function row( string $id, string $status, string $message, string $remediation = '', array $context = [] ): CheckResult {
		$labels = [
			'spf'   => __( 'SPF record', 'flexa-smtp' ),
			'dmarc' => __( 'DMARC record', 'flexa-smtp' ),
			'dkim'  => __( 'DKIM', 'flexa-smtp' ),
		];

		return new CheckResult(
			$this->id(),
			$id,
			$labels[ $id ] ?? $id,
			$status,
			$message,
			$remediation,
			$context
		);
	}
}
