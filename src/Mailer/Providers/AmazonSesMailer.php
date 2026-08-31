<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;
use Flexa\Smtp\Support\AwsV4Signer;

defined( 'ABSPATH' ) || exit;

/**
 * Amazon SES v2 HTTP API, authenticated with a hand-rolled SigV4 signature
 * ({@see AwsV4Signer}) so no AWS SDK is bundled. Uses "Simple" content; file
 * attachments (which SES only accepts as raw MIME) are not carried in this pass.
 */
final class AmazonSesMailer extends AbstractApiMailer {
	protected const SLUG = 'amazonses';

	public static function credential_schema(): array {
		return [
			'access_key' => [ 'type' => 'string' ],
			'secret_key' => [
				'type'   => 'string',
				'secret' => true,
			],
			'region'     => [
				'type' => 'enum',
				'enum' => [
					'us-east-1',
					'us-east-2',
					'us-west-1',
					'us-west-2',
					'eu-west-1',
					'eu-west-2',
					'eu-central-1',
					'ap-south-1',
					'ap-southeast-1',
					'ap-southeast-2',
					'ap-northeast-1',
					'ca-central-1',
					'sa-east-1',
				],
			],
		];
	}

	protected function required_creds(): array {
		return [ 'access_key', 'secret_key', 'region' ];
	}

	protected function endpoint(): string {
		$region = $this->cred( 'region', 'us-east-1' );

		return sprintf( 'https://email.%s.amazonaws.com/v2/email/outbound-emails', $region );
	}

	protected function auth_headers(): array {
		// SES auth is a per-request SigV4 signature computed in send().
		return [];
	}

	protected function build_payload( Message $message ): array {
		$from     = $this->from( $message );
		$from_hdr = '' !== $from['name'] ? sprintf( '%s <%s>', $from['name'], $from['email'] ) : $from['email'];

		$destination = [ 'ToAddresses' => $this->map_addresses( $message->to ) ];
		if ( [] !== $message->cc ) {
			$destination['CcAddresses'] = $this->map_addresses( $message->cc );
		}
		if ( [] !== $message->bcc ) {
			$destination['BccAddresses'] = $this->map_addresses( $message->bcc );
		}

		$body    = [];
		$charset = '' !== $message->charset ? $message->charset : 'UTF-8';
		$html    = $this->html_part( $message );
		$text    = $this->text_part( $message );
		if ( '' !== $html ) {
			$body['Html'] = [
				'Data'    => $html,
				'Charset' => $charset,
			];
		}
		if ( '' !== $text ) {
			$body['Text'] = [
				'Data'    => $text,
				'Charset' => $charset,
			];
		}
		if ( [] === $body ) {
			$body['Text'] = [
				'Data'    => $message->body,
				'Charset' => $charset,
			];
		}

		$payload = [
			'FromEmailAddress' => $from_hdr,
			'Destination'      => $destination,
			'Content'          => [
				'Simple' => [
					'Subject' => [
						'Data'    => $message->subject,
						'Charset' => $charset,
					],
					'Body'    => $body,
				],
			],
		];

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$payload['ReplyToAddresses'] = [ $reply['email'] ];
		}

		return $payload;
	}

	public function send( Message $message ): Result {
		if ( ! $this->is_configured() ) {
			return Result::error( sprintf( '%s is not fully configured.', $this->slug() ), [ 'mailer' => $this->slug() ] );
		}

		$payload = wp_json_encode( $this->build_payload( $message ) );
		if ( false === $payload ) {
			return Result::error( 'could not encode the message payload', [ 'mailer' => $this->slug() ] );
		}

		$url     = $this->endpoint();
		$headers = AwsV4Signer::sign(
			'POST',
			$url,
			$this->cred( 'region', 'us-east-1' ),
			'ses',
			$this->cred( 'access_key' ),
			$this->cred( 'secret_key' ),
			$payload,
			[ 'Content-Type' => 'application/json' ]
		);

		return $this->execute( $url, $headers, $payload );
	}
}
