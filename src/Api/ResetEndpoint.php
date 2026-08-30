<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Maintenance\Eraser;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Danger-zone reset. POST /reset runs the single destructive path
 * {@see Eraser::erase_all()} — the same one WP-CLI uses — so the two can never
 * drift. Gated on manage_options; the typed confirm phrase is enforced in the UI.
 */
final class ResetEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/reset',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'reset' ],
					'permission_callback' => [ $this, 'settings_permission' ],
				],
			]
		);
	}

	public function reset( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$result = Eraser::erase_all();

		return new WP_REST_Response(
			[
				'ok'               => true,
				'settings_removed' => $result['settings_removed'],
				'tables_dropped'   => $result['tables_dropped'],
			]
		);
	}
}
