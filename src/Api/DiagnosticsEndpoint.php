<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Diagnostics\Diagnostics;
use Flexa\Smtp\Domain\EmailLog;
use Flexa\Smtp\Domain\EmailLogRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only diagnostics for a single failed email:
 *   GET /diagnostics/{id} — the normalised Diagnosis (category, severity,
 *                           retryable, explanation, recommended action, technical).
 *
 * Answers "why did this email fail?" for both non-technical admins (explanation +
 * action) and developers (technical). Requires can_manage(); no secrets exposed.
 */
final class DiagnosticsEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/diagnostics/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_diagnosis' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'id' => [
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	public function get_diagnosis( WP_REST_Request $request ): WP_REST_Response {
		$id  = absint( $request->get_param( 'id' ) );
		$log = ( new EmailLogRepository() )->find( $id );

		if ( ! $log instanceof EmailLog ) {
			return new WP_REST_Response(
				[ 'error' => __( 'Log entry not found.', 'flexa-smtp' ) ],
				404
			);
		}

		if ( EmailLog::STATUS_FAILED !== $log->status ) {
			return new WP_REST_Response(
				[
					'id'        => $log->id,
					'status'    => $log->status,
					'diagnosis' => null,
					'message'   => __( 'This email did not fail, so there is nothing to diagnose.', 'flexa-smtp' ),
				]
			);
		}

		$code      = ctype_digit( $log->response_code ) ? (int) $log->response_code : null;
		$fresh     = Diagnostics::classify( $code, $log->reason_error, $log->mailer );
		$diagnosis = '' !== $log->error_category
			? Diagnostics::for_category( $log->error_category, $code, $log->reason_error, $fresh->retryable )
			: $fresh;

		return new WP_REST_Response(
			[
				'id'        => $log->id,
				'status'    => $log->status,
				'diagnosis' => $diagnosis->to_array(),
			]
		);
	}
}
