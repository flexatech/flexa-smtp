<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;
use Flexa\Smtp\OAuth\TokenStore;

defined( 'ABSPATH' ) || exit;

/**
 * Zoho Mail via its REST API. Zoho is data-centre scoped: the OAuth and API hosts
 * both change per region, so a `region` credential selects the correct suffix.
 * Auth uses the `Zoho-oauthtoken` scheme (not Bearer), and a send needs the
 * account id, which is looked up once and cached in the token bundle.
 */
final class ZohoMailer extends AbstractOAuthMailer {
	protected const SLUG = 'zoho';

	/**
	 * region => [ oauth/accounts host suffix, mail API host suffix ].
	 *
	 * @var array<string, array{0:string, 1:string}>
	 */
	private const DCS = [
		'com' => [ 'zoho.com', 'zoho.com' ],
		'eu'  => [ 'zoho.eu', 'zoho.eu' ],
		'in'  => [ 'zoho.in', 'zoho.in' ],
		'au'  => [ 'zoho.com.au', 'zoho.com.au' ],
		'jp'  => [ 'zoho.jp', 'zoho.jp' ],
	];

	public static function credential_schema(): array {
		return array_merge(
			self::oauth_fields(),
			[
				'region' => [ 'type' => 'enum', 'enum' => [ 'com', 'eu', 'in', 'au', 'jp' ] ],
			]
		);
	}

	public function authorize_endpoint(): string {
		return 'https://accounts.' . $this->dc( 0 ) . '/oauth/v2/auth';
	}

	public function token_endpoint(): string {
		return 'https://accounts.' . $this->dc( 0 ) . '/oauth/v2/token';
	}

	public function scopes(): array {
		return [ 'ZohoMail.messages.CREATE', 'ZohoMail.accounts.READ' ];
	}

	public function extra_authorize_params(): array {
		return [
			'access_type' => 'offline',
			'prompt'      => 'consent',
		];
	}

	protected function endpoint(): string {
		// The real send URL needs the account id and is built in send_with_token();
		// this satisfies the abstract contract and is not used directly.
		return 'https://mail.' . $this->dc( 1 ) . '/api/accounts';
	}

	protected function build_payload( Message $message ): array {
		$from = $this->from( $message );

		$payload = [
			'fromAddress' => $from['email'],
			'toAddress'   => implode( ',', $this->map_addresses( $message->to ) ),
			'subject'     => $message->subject,
			'content'     => '' !== $message->body ? $message->body : $this->text_part( $message ),
			'mailFormat'  => $this->is_html( $message ) ? 'html' : 'plaintext',
			'askReceipt'  => 'no',
		];

		if ( [] !== $message->cc ) {
			$payload['ccAddress'] = implode( ',', $this->map_addresses( $message->cc ) );
		}
		if ( [] !== $message->bcc ) {
			$payload['bccAddress'] = implode( ',', $this->map_addresses( $message->bcc ) );
		}

		return $payload;
	}

	protected function auth_headers_for( string $token ): array {
		return [ 'Authorization' => 'Zoho-oauthtoken ' . $token ];
	}

	protected function send_with_token( Message $message, string $token ): Result {
		$account_id = $this->account_id( $token );
		if ( '' === $account_id ) {
			return Result::error( 'could not resolve the Zoho account id; please reconnect.', [ 'mailer' => $this->slug() ] );
		}

		$payload = wp_json_encode( $this->build_payload( $message ) );
		if ( false === $payload ) {
			return Result::error( 'could not encode the message payload', [ 'mailer' => $this->slug() ] );
		}

		$url = 'https://mail.' . $this->dc( 1 ) . '/api/accounts/' . rawurlencode( $account_id ) . '/messages';

		return $this->execute(
			$url,
			array_merge( [ 'Content-Type' => 'application/json' ], $this->auth_headers_for( $token ) ),
			$payload
		);
	}

	protected function interpret( int $code, string $raw ): Result {
		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( $raw, true );
			// Zoho wraps status in {status:{code:200,...}}; a non-200 there is a failure.
			if ( is_array( $data ) && isset( $data['status']['code'] ) && 200 !== (int) $data['status']['code'] ) {
				return Result::error( $this->extract_error( $raw, $code ), [ 'mailer' => $this->slug(), 'code' => $code ] );
			}

			return Result::success( [ 'mailer' => $this->slug(), 'code' => $code ] );
		}

		return Result::error( $this->extract_error( $raw, $code ), [ 'mailer' => $this->slug(), 'code' => $code ] );
	}

	/**
	 * The connected account's id, cached in the (non-secret) token bundle after the
	 * first /accounts lookup.
	 */
	private function account_id( string $token ): string {
		$cached = (string) ( TokenStore::get( $this->slug() )['account_id'] ?? '' );
		if ( '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://mail.' . $this->dc( 1 ) . '/api/accounts',
			[
				'timeout' => 30,
				'headers' => [
					'Accept'        => 'application/json',
					'Authorization' => 'Zoho-oauthtoken ' . $token,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['data'][0] ) || ! is_array( $data['data'][0] ) ) {
			return '';
		}

		$account_id = (string) ( $data['data'][0]['accountId'] ?? '' );
		if ( '' !== $account_id ) {
			TokenStore::save( $this->slug(), [ 'account_id' => $account_id ] );
		}

		return $account_id;
	}

	private function dc( int $index ): string {
		$region = $this->cred( 'region', 'com' );
		$dc     = self::DCS[ $region ] ?? self::DCS['com'];

		return $dc[ $index ];
	}
}
