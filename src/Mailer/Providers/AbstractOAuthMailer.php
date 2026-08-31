<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Contracts\UsesOAuth;
use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;
use Flexa\Smtp\OAuth\TokenStore;

defined( 'ABSPATH' ) || exit;

/**
 * Shared OAuth2 machinery on top of {@see AbstractApiMailer}. Concrete providers
 * only declare their endpoints, scopes, and how to shape one message; this base
 * owns the whole token lifecycle: minting the consent URL, exchanging the code,
 * transparently refreshing an expired access token before a send, and storing
 * everything (encrypted) via {@see TokenStore}.
 *
 * Every provider's user-entered credentials are the same two fields — client id
 * and secret — so those live here in {@see oauth_fields()}; the runtime tokens are
 * kept out of the settings schema entirely.
 */
abstract class AbstractOAuthMailer extends AbstractApiMailer implements UsesOAuth {
	/**
	 * Refresh a token this many seconds before it actually expires, to avoid racing
	 * the clock on a slow request.
	 */
	private const EXPIRY_SKEW = 60;

	/**
	 * The client credentials every OAuth provider needs. Concrete providers merge
	 * any extras (e.g. a data-centre/region enum) on top.
	 *
	 * @return array<string, array{type:string, secret?:bool, enum?:list<string>}>
	 */
	protected static function oauth_fields(): array {
		return [
			'client_id'     => [ 'type' => 'string' ],
			'client_secret' => [
				'type'   => 'string',
				'secret' => true,
			],
		];
	}

	protected function required_creds(): array {
		return [ 'client_id', 'client_secret' ];
	}

	/**
	 * Configured means: client credentials present AND a token bundle stored. The
	 * authorize flow itself only needs the credentials, which it reads directly.
	 */
	public function is_configured(): bool {
		return parent::is_configured() && $this->is_connected();
	}

	public function is_connected(): bool {
		return TokenStore::is_connected( $this->slug() );
	}

	public function disconnect(): void {
		TokenStore::clear( $this->slug() );
	}

	public function extra_authorize_params(): array {
		return [];
	}

	public function authorize_url( string $redirect_uri, string $state ): string {
		$params = array_merge(
			[
				'client_id'     => $this->cred( 'client_id' ),
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => implode( ' ', $this->scopes() ),
				'state'         => $state,
			],
			$this->extra_authorize_params()
		);

		return $this->authorize_endpoint() . '?' . http_build_query( $params );
	}

	public function exchange_code( string $code, string $redirect_uri ): Result {
		$response = wp_remote_post(
			$this->token_endpoint(),
			[
				'timeout' => 30,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				],
				'body'    => [
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => $redirect_uri,
					'client_id'     => $this->cred( 'client_id' ),
					'client_secret' => $this->cred( 'client_secret' ),
				],
			]
		);

		return $this->store_token_response( $response );
	}

	/**
	 * OAuth mailers inject the bearer token at send time via {@see auth_headers_for()},
	 * so the header-less parent hook is unused.
	 */
	protected function auth_headers(): array {
		return [];
	}

	public function send( Message $message ): Result {
		$creds = $this->creds();
		foreach ( $this->required_creds() as $field ) {
			if ( '' === (string) ( $creds[ $field ] ?? '' ) ) {
				return Result::error( sprintf( '%s is missing its client credentials.', $this->slug() ), [ 'mailer' => $this->slug() ] );
			}
		}

		if ( ! $this->is_connected() ) {
			return Result::error( sprintf( '%s is not connected. Authorize it first.', $this->slug() ), [ 'mailer' => $this->slug() ] );
		}

		$token = $this->access_token();
		if ( '' === $token ) {
			return Result::error( sprintf( 'could not obtain a valid %s access token; please reconnect.', $this->slug() ), [ 'mailer' => $this->slug() ] );
		}

		return $this->send_with_token( $message, $token );
	}

	/**
	 * Default OAuth send: JSON body + Bearer auth. Providers whose payload is not
	 * JSON or whose auth scheme differs (Zoho) override this.
	 */
	protected function send_with_token( Message $message, string $token ): Result {
		$payload = wp_json_encode( $this->build_payload( $message ) );
		if ( false === $payload ) {
			return Result::error( 'could not encode the message payload', [ 'mailer' => $this->slug() ] );
		}

		$headers = array_merge(
			[ 'Content-Type' => 'application/json' ],
			$this->auth_headers_for( $token )
		);

		return $this->execute( $this->endpoint(), $headers, $payload );
	}

	/**
	 * Auth headers for a resolved token. Bearer by default; Zoho overrides.
	 *
	 * @return array<string, string>
	 */
	protected function auth_headers_for( string $token ): array {
		return [ 'Authorization' => 'Bearer ' . $token ];
	}

	/**
	 * Return a valid access token, refreshing transparently when the stored one has
	 * expired (or is about to). Empty string when no refresh is possible.
	 */
	protected function access_token(): string {
		$bundle  = TokenStore::get( $this->slug() );
		$access  = (string) ( $bundle['access_token'] ?? '' );
		$expires = (int) ( $bundle['expires_at'] ?? 0 );

		if ( '' !== $access && $expires > ( time() + self::EXPIRY_SKEW ) ) {
			return $access;
		}

		$refresh = (string) ( $bundle['refresh_token'] ?? '' );
		if ( '' === $refresh ) {
			// No way to refresh; hand back whatever we have (may still work briefly).
			return $access;
		}

		$response = wp_remote_post(
			$this->token_endpoint(),
			[
				'timeout' => 30,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				],
				'body'    => [
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
					'client_id'     => $this->cred( 'client_id' ),
					'client_secret' => $this->cred( 'client_secret' ),
				],
			]
		);

		if ( ! $this->store_token_response( $response )->ok ) {
			return '';
		}

		return (string) ( TokenStore::get( $this->slug() )['access_token'] ?? '' );
	}

	/**
	 * Parse a token-endpoint response and persist the bundle. Used by both the code
	 * exchange and the refresh path. A refresh that omits refresh_token keeps the
	 * stored one (the token store merges).
	 *
	 * @param array<string, mixed>|\WP_Error $response
	 */
	protected function store_token_response( $response ): Result {
		if ( is_wp_error( $response ) ) {
			return Result::error( $response->get_error_message(), [ 'mailer' => $this->slug() ] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return Result::error(
				$this->extract_error( $raw, $code ),
				[
					'mailer' => $this->slug(),
					'code'   => $code,
				]
			);
		}

		$bundle = [
			'access_token' => $data['access_token'],
			'expires_at'   => time() + ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600 ),
			'token_type'   => isset( $data['token_type'] ) && is_string( $data['token_type'] ) ? $data['token_type'] : 'Bearer',
		];

		if ( isset( $data['refresh_token'] ) && is_string( $data['refresh_token'] ) && '' !== $data['refresh_token'] ) {
			$bundle['refresh_token'] = $data['refresh_token'];
		}
		if ( isset( $data['scope'] ) && is_string( $data['scope'] ) ) {
			$bundle['scope'] = $data['scope'];
		}

		TokenStore::save( $this->slug(), $bundle );

		return Result::success(
			[
				'mailer' => $this->slug(),
				'code'   => $code,
			]
		);
	}
}
