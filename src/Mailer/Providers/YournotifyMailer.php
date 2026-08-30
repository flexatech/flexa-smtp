<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Yournotify transactional email API. Bearer-authenticated JSON POST to the
 * transactional endpoint.
 */
final class YournotifyMailer extends AbstractApiMailer {
	protected const SLUG = 'yournotify';

	public static function credential_schema(): array {
		return [
			'api_key' => [ 'type' => 'string', 'secret' => true ],
		];
	}

	protected function required_creds(): array {
		return [ 'api_key' ];
	}

	protected function endpoint(): string {
		return 'https://api.yournotify.com/transactional/emails';
	}

	protected function auth_headers(): array {
		return [ 'Authorization' => 'Bearer ' . $this->cred( 'api_key' ) ];
	}

	protected function build_payload( Message $message ): array {
		$from = $this->from( $message );

		$payload = [
			'from'    => '' !== $from['name'] ? sprintf( '%s <%s>', $from['name'], $from['email'] ) : $from['email'],
			'to'      => $this->map_addresses( $message->to ),
			'subject' => $message->subject,
		];

		if ( [] !== $message->cc ) {
			$payload['cc'] = $this->map_addresses( $message->cc );
		}
		if ( [] !== $message->bcc ) {
			$payload['bcc'] = $this->map_addresses( $message->bcc );
		}

		$html = $this->html_part( $message );
		$text = $this->text_part( $message );
		if ( '' !== $html ) {
			$payload['html'] = $html;
		}
		if ( '' !== $text ) {
			$payload['text'] = $text;
		}
		if ( '' === $html && '' === $text ) {
			$payload['text'] = $message->body;
		}

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$payload['reply_to'] = $reply['email'];
		}

		return $payload;
	}
}
