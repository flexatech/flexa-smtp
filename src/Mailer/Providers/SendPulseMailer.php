<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;

defined( 'ABSPATH' ) || exit;

/**
 * SendPulse SMTP API. Uses OAuth2 client-credentials: exchange the client id /
 * secret for a bearer token (cached in a transient until it expires), then POST
 * the message to /smtp/emails.
 */
final class SendPulseMailer extends AbstractApiMailer {
	protected const SLUG = 'sendpulse';

	private const TOKEN_TRANSIENT = 'flexa_smtp_sendpulse_token';
	private const TOKEN_URL       = 'https://api.sendpulse.com/oauth/access_token';

	public static function credential_schema(): array {
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

	protected function endpoint(): string {
		return 'https://api.sendpulse.com/smtp/emails';
	}

	protected function auth_headers(): array {
		// Auth is a bearer token fetched in send().
		return [];
	}

	protected function build_payload( Message $message ): array {
		$from = $this->from( $message );

		$email = [
			'subject' => $message->subject,
			'from'    => $this->compact_pairs(
				[
					'name'  => $from['name'],
					'email' => $from['email'],
				]
			),
			'to'      => $this->map_emails( $message->to ),
		];

		if ( [] !== $message->cc ) {
			$email['cc'] = $this->map_emails( $message->cc );
		}
		if ( [] !== $message->bcc ) {
			$email['bcc'] = $this->map_emails( $message->bcc );
		}

		$html = $this->html_part( $message );
		$text = $this->text_part( $message );
		if ( '' !== $html ) {
			$email['html'] = $html;
		}
		if ( '' !== $text ) {
			$email['text'] = $text;
		}
		if ( '' === $html && '' === $text ) {
			$email['text'] = $message->body;
		}

		return [ 'email' => $email ];
	}

	public function send( Message $message ): Result {
		if ( ! $this->is_configured() ) {
			return Result::error( sprintf( '%s is not fully configured.', $this->slug() ), [ 'mailer' => $this->slug() ] );
		}

		$token = $this->access_token();
		if ( '' === $token ) {
			return Result::error( 'could not obtain a SendPulse access token', [ 'mailer' => $this->slug() ] );
		}

		$payload = wp_json_encode( $this->build_payload( $message ) );
		if ( false === $payload ) {
			return Result::error( 'could not encode the message payload', [ 'mailer' => $this->slug() ] );
		}

		return $this->execute(
			$this->endpoint(),
			[
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			$payload
		);
	}

	/**
	 * Response metadata shared by every Result this transport returns.
	 *
	 * @return array{mailer:string, code:int}
	 */
	private function result_meta( int $code ): array {
		return [
			'mailer' => $this->slug(),
			'code'   => $code,
		];
	}

	protected function interpret( int $code, string $raw ): Result {
		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( $raw, true );
			if ( is_array( $data ) && array_key_exists( 'result', $data ) && false === $data['result'] ) {
				return Result::error( $this->extract_error( $raw, $code ), $this->result_meta( $code ) );
			}

			return Result::success( $this->result_meta( $code ) );
		}

		return Result::error( $this->extract_error( $raw, $code ), $this->result_meta( $code ) );
	}

	/**
	 * Return a valid bearer token, using the cached one when present or fetching
	 * a fresh one via the client-credentials grant.
	 */
	private function access_token(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 30,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => (string) wp_json_encode(
					[
						'grant_type'    => 'client_credentials',
						'client_id'     => $this->cred( 'client_id' ),
						'client_secret' => $this->cred( 'client_secret' ),
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return '';
		}

		$expires = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		set_transient( self::TOKEN_TRANSIENT, $data['access_token'], max( 60, $expires - 60 ) );

		return $data['access_token'];
	}
}
