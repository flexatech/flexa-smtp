<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Support\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /wp-json/flexa-smtp/v1/settings — returns or persists the plugin
 * settings. GET never exposes plaintext secrets ({@see Settings::for_rest()});
 * POST is a partial merge, so a screen submitting one section never wipes the
 * rest. Schema/defaults/sanitization live in {@see Settings}.
 */
final class SettingsEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'settings_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'settings_permission' ],
				],
			]
		);
	}

	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( Settings::for_rest() );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		// get_json_params() returns null on an empty/invalid body; the cast
		// normalises that to an empty array.
		$incoming = (array) $request->get_json_params();

		Settings::save( $incoming );

		return $this->get_settings( $request );
	}
}
