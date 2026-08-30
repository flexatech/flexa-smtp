<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Reports\Digest;
use Flexa\Smtp\Reports\Stats;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Reports (WP7):
 *   GET  /reports?days=N — aggregated stats for the trailing window (chart data)
 *   POST /reports/send   — send a digest now, for previewing the scheduled email
 */
final class ReportsEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/reports',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_reports' ],
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

		register_rest_route(
			self::NAMESPACE,
			'/reports/send',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'send' ],
					'permission_callback' => [ $this, 'settings_permission' ],
					'args'                => [
						'days' => [
							'type'              => 'integer',
							'required'          => false,
							'default'           => 7,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	public function get_reports( WP_REST_Request $request ): WP_REST_Response {
		$days = (int) $request->get_param( 'days' );

		return new WP_REST_Response( Stats::summary( $days > 0 ? $days : 30 ) );
	}

	public function send( WP_REST_Request $request ): WP_REST_Response {
		$days       = (int) $request->get_param( 'days' );
		$days       = $days > 0 ? $days : 7;
		$recipients = Digest::send( $days, __( 'test', 'flexa-smtp' ) );

		return new WP_REST_Response(
			[
				'sent'       => [] !== $recipients,
				'recipients' => $recipients,
			]
		);
	}
}
