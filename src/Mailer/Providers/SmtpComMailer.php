<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * SMTP.com Messages API v4. Bearer-authenticated; every send is tied to a
 * verified sender "channel".
 */
final class SmtpComMailer extends AbstractApiMailer {
	protected const SLUG = 'smtpcom';

	public static function credential_schema(): array {
		return [
			'api_key' => [
				'type'   => 'string',
				'secret' => true,
			],
			'channel' => [ 'type' => 'string' ],
		];
	}

	protected function required_creds(): array {
		return [ 'api_key', 'channel' ];
	}

	protected function endpoint(): string {
		return 'https://api.smtp.com/v4/messages';
	}

	protected function auth_headers(): array {
		return [ 'Authorization' => 'Bearer ' . $this->cred( 'api_key' ) ];
	}

	protected function build_payload( Message $message ): array {
		$from = $this->from( $message );

		$body_part = $this->is_html( $message )
			? [
				'type'    => 'text/html',
				'content' => $message->body,
			]
			: [
				'type'    => 'text/plain',
				'content' => $message->body,
			];

		$payload = [
			'channel'    => $this->cred( 'channel' ),
			'recipients' => [
				'to' => array_map(
					static fn ( array $r ): array => array_filter(
						[
							'address' => (string) $r['address'],
							'name'    => (string) $r['name'],
						],
						static fn ( string $v ): bool => '' !== $v
					),
					$message->to
				),
			],
			'originator' => [
				'from' => array_filter(
					[
						'address' => $from['email'],
						'name'    => $from['name'],
					],
					static fn ( string $v ): bool => '' !== $v
				),
			],
			'subject'    => $message->subject,
			'body'       => [ 'parts' => [ $body_part ] ],
		];

		if ( [] !== $message->cc ) {
			$payload['recipients']['cc'] = array_map(
				static fn ( array $r ): array => [ 'address' => (string) $r['address'] ],
				$message->cc
			);
		}
		if ( [] !== $message->bcc ) {
			$payload['recipients']['bcc'] = array_map(
				static fn ( array $r ): array => [ 'address' => (string) $r['address'] ],
				$message->bcc
			);
		}

		return $payload;
	}
}
