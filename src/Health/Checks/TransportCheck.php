<?php

declare(strict_types=1);

namespace Flexa\Smtp\Health\Checks;

use Flexa\Smtp\Health\CheckResult;
use Flexa\Smtp\Health\Contracts\HealthCheck;
use Flexa\Smtp\Mailer\Contracts\MailerInterface;
use Flexa\Smtp\Mailer\MailerRegistry;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Connectivity of the active transport. For a custom SMTP mailer this opens a
 * TCP socket to host:port with a short timeout — a cheap reachability probe, not
 * a full SMTP conversation, so no credentials are sent. For API providers it
 * confirms credentials are present (a live call would hit the provider's API and
 * count against rate limits, so real delivery is validated by "Send test email"
 * instead). PHP mail() has no remote endpoint, so it reports N/A.
 *
 * Runs only inside the health runner (cron or explicit refresh), never on a
 * front-end request.
 */
final class TransportCheck implements HealthCheck {
	private const CONNECT_TIMEOUT = 5;

	public function id(): string {
		return 'transport';
	}

	public function label(): string {
		return __( 'Provider connectivity', 'flexa-smtp' );
	}

	/**
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$slug = (string) Settings::get( 'current_mailer' );
		if ( '' === $slug ) {
			$slug = 'mail';
		}

		if ( 'mail' === $slug ) {
			return [
				$this->row(
					'reachability',
					CheckResult::NA,
					__( 'PHP mail() hands off to the local mail server, so there is no SMTP or API endpoint to test here.', 'flexa-smtp' ),
					'',
					[ 'mailer' => $slug ]
				),
			];
		}

		if ( 'smtp' === $slug ) {
			return [ $this->smtp_result() ];
		}

		return [ $this->api_result( $slug ) ];
	}

	private function smtp_result(): CheckResult {
		$creds = Settings::mailer( 'smtp' );
		$host  = (string) ( $creds['host'] ?? '' );
		$port  = (int) ( $creds['port'] ?? 0 );

		if ( '' === $host || $port <= 0 ) {
			return $this->row(
				'reachability',
				CheckResult::ERROR,
				__( 'No SMTP host and port are configured.', 'flexa-smtp' ),
				__( 'Enter the SMTP host and port on the settings screen.', 'flexa-smtp' ),
				[ 'mailer' => 'smtp' ]
			);
		}

		if ( ! function_exists( 'fsockopen' ) ) {
			return $this->row(
				'reachability',
				CheckResult::NOT_CHECKED,
				__( 'The server has disabled the socket functions needed to test the connection.', 'flexa-smtp' ),
				'',
				[
					'host' => $host,
					'port' => $port,
				]
			);
		}

		$errno  = 0;
		$errstr = '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen, WordPress.PHP.NoSilencedErrors.Discouraged -- a raw TCP reachability probe; wp_remote_* cannot test an SMTP port, and we surface the error via $errstr rather than a PHP warning.
		$socket = @fsockopen( $host, $port, $errno, $errstr, self::CONNECT_TIMEOUT );

		if ( false === $socket ) {
			return $this->row(
				'reachability',
				CheckResult::ERROR,
				/* translators: 1: host, 2: port. */
				sprintf( __( 'Could not connect to %1$s on port %2$d.', 'flexa-smtp' ), $host, $port ),
				__( 'Check the host and port, and confirm your server allows outbound connections on that port.', 'flexa-smtp' ),
				[
					'host'  => $host,
					'port'  => $port,
					'errno' => $errno,
					'error' => $errstr,
				]
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the raw probe socket opened above; there is no WP_Filesystem equivalent for a network socket.
		fclose( $socket );

		return $this->row(
			'reachability',
			CheckResult::PASS,
			/* translators: 1: host, 2: port. */
			sprintf( __( 'Connected to %1$s on port %2$d.', 'flexa-smtp' ), $host, $port ),
			'',
			[
				'host' => $host,
				'port' => $port,
			]
		);
	}

	private function api_result( string $slug ): CheckResult {
		$mailer = MailerRegistry::instance()->make( $slug );

		if ( ! $mailer instanceof MailerInterface ) {
			return $this->row(
				'reachability',
				CheckResult::ERROR,
				/* translators: %s: mailer slug. */
				sprintf( __( 'The selected mailer "%s" is not available.', 'flexa-smtp' ), $slug ),
				__( 'Select an available mailer on the settings screen.', 'flexa-smtp' ),
				[ 'mailer' => $slug ]
			);
		}

		if ( ! $mailer->is_configured() ) {
			return $this->row(
				'reachability',
				CheckResult::ERROR,
				/* translators: %s: mailer slug. */
				sprintf( __( 'The "%s" mailer is missing required credentials.', 'flexa-smtp' ), $slug ),
				__( 'Complete the credentials for this mailer, then send a test email to confirm delivery.', 'flexa-smtp' ),
				[ 'mailer' => $slug ]
			);
		}

		return $this->row(
			'reachability',
			CheckResult::PASS,
			/* translators: %s: mailer slug. */
			sprintf( __( 'The "%s" mailer has credentials configured. Use "Send test email" to confirm live delivery.', 'flexa-smtp' ), $slug ),
			'',
			[ 'mailer' => $slug ]
		);
	}

	/**
	 * @param array<string, scalar> $context
	 */
	private function row( string $id, string $status, string $message, string $remediation = '', array $context = [] ): CheckResult {
		return new CheckResult( $this->id(), $id, $this->label(), $status, $message, $remediation, $context );
	}
}
