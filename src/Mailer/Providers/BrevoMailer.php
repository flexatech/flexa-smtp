<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Brevo (formerly Sendinblue) transactional email API. Authenticates with an
 * `api-key` header; a successful send returns 201.
 */
final class BrevoMailer extends AbstractApiMailer {
	protected const SLUG = 'brevo';

	public static function credential_schema(): array {
		return [
			'api_key' => [
				'type'   => 'string',
				'secret' => true,
			],
		];
	}

	protected function required_creds(): array {
		return [ 'api_key' ];
	}

	protected function endpoint(): string {
		return 'https://api.brevo.com/v3/smtp/email';
	}

	protected function auth_headers(): array {
		return [
			'api-key' => $this->cred( 'api_key' ),
			'accept'  => 'application/json',
		];
	}

	protected function build_payload( Message $message ): array {
		$from    = $this->from( $message );
		$payload = [
			'sender'  => $this->compact_pairs(
				[
					'email' => $from['email'],
					'name'  => $from['name'],
				]
			),
			'to'      => $this->map_emails( $message->to ),
			'subject' => $message->subject,
		];

		if ( [] !== $message->cc ) {
			$payload['cc'] = $this->map_emails( $message->cc );
		}
		if ( [] !== $message->bcc ) {
			$payload['bcc'] = $this->map_emails( $message->bcc );
		}

		$html = $this->html_part( $message );
		$text = $this->text_part( $message );
		if ( '' !== $html ) {
			$payload['htmlContent'] = $html;
		}
		if ( '' !== $text ) {
			$payload['textContent'] = $text;
		}
		if ( '' === $html && '' === $text ) {
			$payload['textContent'] = $message->body;
		}

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$payload['replyTo'] = $this->compact_pairs(
				[
					'email' => $reply['email'],
					'name'  => $reply['name'],
				]
			);
		}

		$attachments = $this->attachments( $message );
		if ( [] !== $attachments ) {
			$payload['attachment'] = array_map(
				static fn ( array $a ): array => [
					'content' => $a['content'],
					'name'    => $a['filename'],
				],
				$attachments
			);
		}

		return $payload;
	}
}
