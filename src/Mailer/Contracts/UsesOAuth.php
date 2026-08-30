<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Contracts;

use Flexa\Smtp\Mailer\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by mailers that authenticate with OAuth2 (Gmail, Outlook/365, Zoho).
 * The concrete mailer describes *which* provider — its authorize/token endpoints
 * and scopes — and the shared base owns *how*: minting the consent URL, exchanging
 * the code, refreshing, and attaching the bearer token to each send. The REST
 * OAuth endpoint drives {@see authorize_url()} and {@see exchange_code()}; the
 * connection state is persisted by the token store, never in the settings schema.
 */
interface UsesOAuth {
	/**
	 * Provider authorization (consent) endpoint.
	 */
	public function authorize_endpoint(): string;

	/**
	 * Provider token endpoint (used for both code exchange and refresh).
	 */
	public function token_endpoint(): string;

	/**
	 * OAuth scopes to request.
	 *
	 * @return list<string>
	 */
	public function scopes(): array;

	/**
	 * Extra query parameters appended to the authorize URL (e.g. access_type,
	 * prompt) so the provider always returns a refresh token.
	 *
	 * @return array<string, string>
	 */
	public function extra_authorize_params(): array;

	/**
	 * Build the full consent URL the admin is sent to.
	 */
	public function authorize_url( string $redirect_uri, string $state ): string;

	/**
	 * Exchange an authorization code for tokens and persist them.
	 */
	public function exchange_code( string $code, string $redirect_uri ): Result;

	/**
	 * Whether a token bundle is stored for this provider.
	 */
	public function is_connected(): bool;

	/**
	 * Forget the stored tokens (disconnect).
	 */
	public function disconnect(): void;
}
