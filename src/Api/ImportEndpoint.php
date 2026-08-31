<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Import\ImporterRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Import from other SMTP plugins (WP8):
 *   GET  /import — list detected sources (drives the UI list + admin notice)
 *   POST /import — run an import for one source (settings, logs, or both)
 */
final class ImportEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/import',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_sources' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'source' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						],
						'mode'   => [
							'type'              => 'string',
							'required'          => false,
							'default'           => 'both',
							'enum'              => [ 'settings', 'logs', 'both' ],
							'sanitize_callback' => 'sanitize_key',
						],
						'force'  => [
							'type'              => 'boolean',
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				],
			]
		);
	}

	public function list_sources( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$registry = ImporterRegistry::instance();
		$imported = $registry->imported_log_sources();

		$sources = [];
		foreach ( $registry->available() as $slug => $importer ) {
			$sources[] = [
				'slug'          => $slug,
				'label'         => $importer->label(),
				'logs_imported' => in_array( $slug, $imported, true ),
			];
		}

		return new WP_REST_Response( [ 'sources' => $sources ] );
	}

	public function run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug     = (string) $request->get_param( 'source' );
		$mode     = (string) $request->get_param( 'mode' );
		$force    = (bool) $request->get_param( 'force' );
		$registry = ImporterRegistry::instance();
		$importer = $registry->get( $slug );

		if ( null === $importer || ! $importer->is_available() ) {
			return new WP_Error(
				'flexa_smtp_import_source_unavailable',
				__( 'No importable data was found for the selected plugin.', 'flexa-smtp' ),
				[ 'status' => 400 ]
			);
		}

		$settings_imported = false;
		$logs_imported     = 0;
		$logs_skipped      = false;

		if ( in_array( $mode, [ 'settings', 'both' ], true ) ) {
			$settings_imported = $importer->import_settings();
		}

		if ( in_array( $mode, [ 'logs', 'both' ], true ) ) {
			$already = in_array( $slug, $registry->imported_log_sources(), true );
			if ( $already && ! $force ) {
				$logs_skipped = true;
			} else {
				$logs_imported = $importer->import_logs();
				$registry->mark_logs_imported( $slug );
			}
		}

		return new WP_REST_Response(
			[
				'source'            => $slug,
				'settings_imported' => $settings_imported,
				'logs_imported'     => $logs_imported,
				'logs_skipped'      => $logs_skipped,
			]
		);
	}
}
