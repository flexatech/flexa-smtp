<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Health\HealthChecker;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Email health check surface:
 *   GET  /health     — the cached report (never runs a check; safe on any load)
 *   POST /health/run — run the checks now, cache, and return the fresh report
 *
 * The GET is read-only so opening the screen never triggers network I/O; the
 * POST is the deliberate "re-run now" action. The daily cron keeps the cache
 * warm between manual runs.
 */
final class HealthEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/health',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_health' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/health/run',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run_health' ],
					'permission_callback' => [ $this, 'settings_permission' ],
				],
			]
		);
	}

	public function get_health( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$cached = HealthChecker::instance()->cached();

		if ( null === $cached ) {
			return new WP_REST_Response(
				[
					'status'       => 'not_checked',
					'generated_at' => 0,
					'checks'       => [],
					'cached'       => false,
					'stale'        => true,
				]
			);
		}

		return new WP_REST_Response( $this->decorate( $cached ) );
	}

	public function run_health( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$report = HealthChecker::instance()->run();

		return new WP_REST_Response( $this->decorate( $report->to_array() ) );
	}

	/**
	 * Add the client-facing flags (cached, stale) derived from the report age.
	 *
	 * @param array<string, mixed> $report
	 * @return array<string, mixed>
	 */
	private function decorate( array $report ): array {
		$generated = (int) ( $report['generated_at'] ?? 0 );
		$stale     = 0 === $generated || ( time() - $generated ) > HealthChecker::STALE_AFTER;

		$report['cached'] = true;
		$report['stale']  = $stale;

		return $report;
	}
}
