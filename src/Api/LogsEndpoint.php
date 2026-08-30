<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Domain\ClickEventRepository;
use Flexa\Smtp\Domain\EmailLog;
use Flexa\Smtp\Domain\EmailLogRepository;
use Flexa\Smtp\Domain\OpenEventRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only REST surface for the email log:
 *   GET /logs          — filtered, paginated list
 *   GET /logs/{id}     — one entry with body + open/click summary
 *   GET /logs/export   — CSV download of the current filter
 *
 * Every value handed to the client is raw (the React app escapes on render);
 * the CSV writer neutralises spreadsheet-formula injection. All routes require
 * can_manage(). Data access goes through the Domain repositories — no SQL here.
 */
final class LogsEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/logs',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_logs' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => $this->list_args(),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs/export',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'export_logs' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => $this->list_args(),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_log' ],
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

	public function list_logs( WP_REST_Request $request ): WP_REST_Response {
		$repo     = new EmailLogRepository();
		$per_page = $this->per_page( $request );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$filters  = $this->filters( $request );

		$total = $repo->count( $filters );
		$items = $repo->query(
			$filters + [
				'limit'  => $per_page,
				'offset' => ( $page - 1 ) * $per_page,
			]
		);

		return new WP_REST_Response(
			[
				'items'       => array_map( static fn ( EmailLog $log ): array => $log->to_array(), $items ),
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);
	}

	public function get_log( WP_REST_Request $request ): WP_REST_Response {
		$id  = absint( $request->get_param( 'id' ) );
		$log = ( new EmailLogRepository() )->find( $id );

		if ( ! $log instanceof EmailLog ) {
			return new WP_REST_Response(
				[ 'error' => __( 'Log entry not found.', 'flexa-smtp' ) ],
				404
			);
		}

		$data                 = $log->to_array();
		$data['body_content'] = $log->body_content;
		$data['opens']        = ( new OpenEventRepository() )->total_for_log( $id );
		$data['clicks']       = array_map(
			static fn ( $event ): array => $event->to_array(),
			( new ClickEventRepository() )->for_log( $id )
		);

		return new WP_REST_Response( $data );
	}

	/**
	 * Streams a CSV of the current filter as a file download. Ends the request
	 * itself (exit) because a REST callback otherwise JSON-encodes its return.
	 */
	public function export_logs( WP_REST_Request $request ): WP_REST_Response {
		$repo    = new EmailLogRepository();
		$filters = $this->filters( $request );

		// Cap the export so a huge table can't exhaust memory in one request.
		$rows = $repo->query( $filters + [ 'limit' => 5000, 'offset' => 0 ] );

		if ( headers_sent() ) {
			// Fallback: cannot stream, return JSON so the caller still gets data.
			return new WP_REST_Response(
				[ 'items' => array_map( static fn ( EmailLog $l ): array => $l->to_array(), $rows ) ]
			);
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="flexa-smtp-logs-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false !== $out ) {
			fputcsv( $out, [ 'ID', 'Date', 'Status', 'Mailer', 'From', 'To', 'Subject', 'Source', 'Error' ] );

			foreach ( $rows as $log ) {
				fputcsv(
					$out,
					array_map(
						[ $this, 'csv_safe' ],
						[
							(string) $log->id,
							$log->date_time,
							EmailLog::STATUS_SENT === $log->status ? 'sent' : 'failed',
							$log->mailer,
							$log->email_from,
							$this->join_recipients( $log ),
							$log->subject,
							$log->source,
							$log->reason_error,
						]
					)
				);
			}

			fclose( $out );
		}

		exit;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function list_args(): array {
		return [
			'status'    => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'mailer'    => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			],
			'search'    => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_from' => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_to'   => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'orderby'   => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			],
			'order'     => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			],
			'page'      => [
				'type'              => 'integer',
				'required'          => false,
				'default'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page'  => [
				'type'              => 'integer',
				'required'          => false,
				'default'           => 20,
				'sanitize_callback' => 'absint',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function filters( WP_REST_Request $request ): array {
		$filters = [
			'mailer'    => (string) $request->get_param( 'mailer' ),
			'search'    => (string) $request->get_param( 'search' ),
			'date_from' => (string) $request->get_param( 'date_from' ),
			'date_to'   => (string) $request->get_param( 'date_to' ),
			'orderby'   => (string) $request->get_param( 'orderby' ),
			'order'     => (string) $request->get_param( 'order' ),
		];

		$status = $request->get_param( 'status' );
		if ( '' !== (string) $status && null !== $status && in_array( (string) $status, [ '0', '1' ], true ) ) {
			$filters['status'] = (int) $status;
		}

		return $filters;
	}

	private function per_page( WP_REST_Request $request ): int {
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = 20;
		}

		return min( 200, $per_page );
	}

	private function join_recipients( EmailLog $log ): string {
		$addresses = array_map(
			static fn ( array $r ): string => (string) ( $r['address'] ?? '' ),
			$log->email_to
		);

		return implode( ', ', array_filter( $addresses ) );
	}

	/**
	 * Neutralise spreadsheet formula injection: a leading =, +, -, @ (or control
	 * char) makes Excel/Sheets evaluate the cell, so prefix such values with a
	 * single quote.
	 */
	public function csv_safe( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		$first = $value[0];
		if ( in_array( $first, [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
