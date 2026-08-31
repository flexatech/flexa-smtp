<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * SendGrid v3 Mail Send API. Bearer-authenticated JSON; a successful send
 * returns 202 with an empty body.
 */
final class SendGridMailer extends AbstractApiMailer {
	protected const SLUG = 'sendgrid';

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
		return 'https://api.sendgrid.com/v3/mail/send';
	}

	protected function auth_headers(): array {
		return [ 'Authorization' => 'Bearer ' . $this->cred( 'api_key' ) ];
	}

	protected function build_payload( Message $message ): array {
		$personalization = [ 'to' => $this->map_emails( $message->to ) ];
		if ( [] !== $message->cc ) {
			$personalization['cc'] = $this->map_emails( $message->cc );
		}
		if ( [] !== $message->bcc ) {
			$personalization['bcc'] = $this->map_emails( $message->bcc );
		}

		$content = [];
		$text    = $this->text_part( $message );
		$html    = $this->html_part( $message );
		if ( '' !== $text ) {
			$content[] = [
				'type'  => 'text/plain',
				'value' => $text,
			];
		}
		if ( '' !== $html ) {
			$content[] = [
				'type'  => 'text/html',
				'value' => $html,
			];
		}
		if ( [] === $content ) {
			$content[] = [
				'type'  => 'text/plain',
				'value' => $message->body,
			];
		}

		$payload = [
			'personalizations' => [ $personalization ],
			'from'             => $this->from( $message ),
			'subject'          => $message->subject,
			'content'          => $content,
		];

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$payload['reply_to'] = $reply;
		}

		$attachments = $this->attachments( $message );
		if ( [] !== $attachments ) {
			$payload['attachments'] = array_map(
				static fn ( array $a ): array => [
					'content'  => $a['content'],
					'filename' => $a['filename'],
					'type'     => $a['type'],
				],
				$attachments
			);
		}

		return $payload;
	}
}
