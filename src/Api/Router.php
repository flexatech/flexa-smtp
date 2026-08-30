<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Concerns\HasInstance;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every REST endpoint in one place. Extensions add their own
 * endpoints by hooking the `flexa_smtp.rest.register_routes` action.
 *
 * Every endpoint class MUST be newed up here — an endpoint that exists but is
 * never added to this list is silently dead.
 */
final class Router {
	use HasInstance;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		( new SettingsEndpoint() )->register_routes();
		( new TestMailEndpoint() )->register_routes();
		( new LogsEndpoint() )->register_routes();
		( new OAuthEndpoint() )->register_routes();
		( new TrackingEndpoint() )->register_routes();

		// WP7+ endpoints register here as each work package lands, e.g.:
		// ( new ReportsEndpoint() )->register_routes();

		do_action( 'flexa_smtp.rest.register_routes' );
	}
}
