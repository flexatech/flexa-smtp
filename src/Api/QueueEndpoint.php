<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Queue\Queue;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery queue surface (DL4):
 *   GET  /queue               — current counts + engine info (read-only)
 *   POST /queue/run           — run the worker now
 *   POST /queue/retry-failed  — requeue failed rows with a fresh attempt budget
 *   POST /queue/clear-failed  — delete failed rows
 *
 * The GET only reads counts, so opening the screen never sends mail; the POSTs are
 * deliberate admin actions gated behind the settings capability.
 */
final class QueueEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/queue',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_queue' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/run',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run_queue' ],
					'permission_callback' => [ $this, 'settings_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/retry-failed',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'retry_failed' ],
					'permission_callback' => [ $this, 'settings_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/clear-failed',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'clear_failed' ],
					'permission_callback' => [ $this, 'settings_permission' ],
				],
			]
		);
	}

	public function get_queue( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( Queue::instance()->stats() );
	}

	public function run_queue( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( Queue::instance()->run_now() );
	}

	public function retry_failed( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( Queue::instance()->requeue_failed() );
	}

	public function clear_failed( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( Queue::instance()->clear_failed() );
	}
}
