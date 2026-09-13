<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Monitoring\Monitor;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Provider monitoring:
 *   GET /monitoring?days=N — per-mailer reliability over the trailing window.
 *
 * Pure aggregation over the local email log (no remote calls); the response's
 * `scope: local` marker tells the client these are this site's numbers, not the
 * provider's own status.
 */
final class MonitoringEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/monitoring',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_monitoring' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'days' => [
							'type'              => 'integer',
							'required'          => false,
							'default'           => 30,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	public function get_monitoring( WP_REST_Request $request ): WP_REST_Response {
		$days = (int) $request->get_param( 'days' );

		return new WP_REST_Response( Monitor::snapshot( $days > 0 ? $days : 30 ) );
	}
}
