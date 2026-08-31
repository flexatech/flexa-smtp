<?php

declare(strict_types=1);

namespace Flexa\Smtp\Api;

use Flexa\Smtp\Mailer\Contracts\UsesOAuth;
use Flexa\Smtp\Mailer\MailerRegistry;
use Flexa\Smtp\OAuth\StateSigner;
use Flexa\Smtp\OAuth\TokenStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth2 connect flow for the OAuth mailers (Gmail, Outlook, Zoho):
 *   GET  /oauth/{provider}/authorize  — mint the consent URL (admin-gated)
 *   GET  /oauth/{provider}/callback   — exchange the code (state-signature gated)
 *   POST /oauth/{provider}/disconnect — forget the tokens (admin-gated)
 *   GET  /oauth/status                — connection state of every OAuth provider
 *
 * The callback cannot use cookie+nonce auth (the provider redirects the browser to
 * it with no REST nonce), so it is gated on the HMAC {@see StateSigner} token that
 * only an authenticated admin could have minted at /authorize. That signed state
 * replaces YaySMTP's `__return_true`.
 */
final class OAuthEndpoint extends Endpoint {
	private const PROVIDER_ARG = [
		'provider' => [
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_key',
		],
	];

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/oauth/(?P<provider>[a-z0-9_-]+)/authorize',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'authorize' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => self::PROVIDER_ARG,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/oauth/(?P<provider>[a-z0-9_-]+)/callback',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'callback' ],
					'permission_callback' => [ $this, 'callback_permission' ],
					'args'                => self::PROVIDER_ARG + [
						'code'  => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'state' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/oauth/(?P<provider>[a-z0-9_-]+)/disconnect',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'disconnect' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => self::PROVIDER_ARG,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/oauth/status',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'status' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);
	}

	public function authorize( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug   = (string) $request->get_param( 'provider' );
		$mailer = $this->oauth_mailer( $slug );
		if ( ! $mailer instanceof UsesOAuth ) {
			return $this->unknown_provider( $slug );
		}

		$state = StateSigner::issue( $slug );
		$url   = $mailer->authorize_url( $this->redirect_uri( $slug ), $state );

		return new WP_REST_Response( [ 'url' => $url ] );
	}

	public function callback( WP_REST_Request $request ): void {
		$slug   = (string) $request->get_param( 'provider' );
		$mailer = $this->oauth_mailer( $slug );

		$error = (string) $request->get_param( 'error' );
		if ( ! $mailer instanceof UsesOAuth ) {
			$this->redirect_back( $slug, 'error', 'unknown_provider' );
		}
		if ( '' !== $error ) {
			$this->redirect_back( $slug, 'error', $error );
		}

		$code = (string) $request->get_param( 'code' );
		if ( '' === $code ) {
			$this->redirect_back( $slug, 'error', 'missing_code' );
		}

		$result = $mailer->exchange_code( $code, $this->redirect_uri( $slug ) );

		$this->redirect_back( $slug, $result->ok ? 'connected' : 'error', $result->ok ? '' : (string) $result->error );
	}

	public function disconnect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug   = (string) $request->get_param( 'provider' );
		$mailer = $this->oauth_mailer( $slug );
		if ( ! $mailer instanceof UsesOAuth ) {
			return $this->unknown_provider( $slug );
		}

		$mailer->disconnect();

		return new WP_REST_Response( [ 'connected' => false ] );
	}

	public function status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$out = [];
		foreach ( MailerRegistry::instance()->slugs() as $slug ) {
			$mailer = MailerRegistry::instance()->make( $slug );
			if ( ! $mailer instanceof UsesOAuth ) {
				continue;
			}

			$bundle       = TokenStore::get( $slug );
			$out[ $slug ] = [
				'connected'  => $mailer->is_connected(),
				'scope'      => (string) ( $bundle['scope'] ?? '' ),
				'expires_at' => (int) ( $bundle['expires_at'] ?? 0 ),
			];
		}

		return new WP_REST_Response( [ 'providers' => $out ] );
	}

	/**
	 * Gate the callback on a valid signed state bound to this provider.
	 */
	public function callback_permission( WP_REST_Request $request ): bool|WP_Error {
		$slug  = (string) $request->get_param( 'provider' );
		$state = (string) $request->get_param( 'state' );

		if ( '' === $state || ! StateSigner::verify( $state, $slug ) ) {
			return new WP_Error(
				'flexa_smtp_oauth_state',
				__( 'The OAuth state could not be verified.', 'flexa-smtp' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	private function oauth_mailer( string $slug ): ?UsesOAuth {
		if ( '' === $slug || ! MailerRegistry::instance()->has( $slug ) ) {
			return null;
		}

		$mailer = MailerRegistry::instance()->make( $slug );

		return $mailer instanceof UsesOAuth ? $mailer : null;
	}

	private function redirect_uri( string $slug ): string {
		return rest_url( self::NAMESPACE . '/oauth/' . $slug . '/callback' );
	}

	private function unknown_provider( string $slug ): WP_Error {
		return new WP_Error(
			'flexa_smtp_unknown_provider',
			/* translators: %s: mailer slug. */
			sprintf( __( '"%s" is not an OAuth mailer.', 'flexa-smtp' ), $slug ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Redirect the browser back to the admin screen after the callback, then exit.
	 */
	private function redirect_back( string $slug, string $status, string $detail ): void {
		$args = [
			'page'         => 'flexa-smtp',
			'oauth'        => $slug,
			'oauth_status' => $status,
		];
		if ( '' !== $detail ) {
			$args['oauth_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
