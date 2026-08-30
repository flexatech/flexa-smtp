<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * PepiPost / Netcore Email API v5. Shares SendGrid's personalizations shape but
 * authenticates with an `api_key` header instead of a bearer token.
 */
final class PepiPostMailer extends AbstractApiMailer {
	protected const SLUG = 'pepipost';

	public static function credential_schema(): array {
		return [
			'api_key' => [ 'type' => 'string', 'secret' => true ],
		];
	}

	protected function required_creds(): array {
		return [ 'api_key' ];
	}

	protected function endpoint(): string {
		return 'https://api.pepipost.com/v5/mail/send';
	}

	protected function auth_headers(): array {
		return [ 'api_key' => $this->cred( 'api_key' ) ];
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
			$content[] = [ 'type' => 'text/plain', 'value' => $text ];
		}
		if ( '' !== $html ) {
			$content[] = [ 'type' => 'text/html', 'value' => $html ];
		}
		if ( [] === $content ) {
			$content[] = [ 'type' => 'text/plain', 'value' => $message->body ];
		}

		$payload = [
			'from'             => $this->from( $message ),
			'subject'          => $message->subject,
			'content'          => $content,
			'personalizations' => [ $personalization ],
		];

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$payload['reply_to'] = $reply;
		}

		return $payload;
	}
}
