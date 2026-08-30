<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Mailgun Messages API. Unlike the JSON providers this posts an
 * application/x-www-form-urlencoded body and authenticates with HTTP Basic
 * ("api:<key>"). Supports the US and EU regions; the domain is part of the URL.
 */
final class MailgunMailer extends AbstractApiMailer {
	protected const SLUG = 'mailgun';

	public static function credential_schema(): array {
		return [
			'api_key' => [ 'type' => 'string', 'secret' => true ],
			'domain'  => [ 'type' => 'string' ],
			'region'  => [ 'type' => 'enum', 'enum' => [ 'us', 'eu' ] ],
		];
	}

	protected function required_creds(): array {
		return [ 'api_key', 'domain' ];
	}

	protected function endpoint(): string {
		$host = 'eu' === $this->cred( 'region' ) ? 'api.eu.mailgun.net' : 'api.mailgun.net';

		return sprintf( 'https://%s/v3/%s/messages', $host, rawurlencode( $this->cred( 'domain' ) ) );
	}

	protected function auth_headers(): array {
		return [ 'Authorization' => 'Basic ' . base64_encode( 'api:' . $this->cred( 'api_key' ) ) ];
	}

	protected function build_payload( Message $message ): array {
		// Not used — Mailgun sends a form body, see send().
		unset( $message );

		return [];
	}

	public function send( Message $message ): Result {
		if ( ! $this->is_configured() ) {
			return Result::error( sprintf( '%s is not fully configured.', $this->slug() ), [ 'mailer' => $this->slug() ] );
		}

		$from = $this->from( $message );

		$fields = [
			'from'    => '' !== $from['name'] ? sprintf( '%s <%s>', $from['name'], $from['email'] ) : $from['email'],
			'to'      => implode( ',', $this->map_addresses( $message->to ) ),
			'subject' => $message->subject,
		];

		if ( [] !== $message->cc ) {
			$fields['cc'] = implode( ',', $this->map_addresses( $message->cc ) );
		}
		if ( [] !== $message->bcc ) {
			$fields['bcc'] = implode( ',', $this->map_addresses( $message->bcc ) );
		}

		$html = $this->html_part( $message );
		$text = $this->text_part( $message );
		if ( '' !== $html ) {
			$fields['html'] = $html;
		}
		if ( '' !== $text ) {
			$fields['text'] = $text;
		}
		if ( '' === $html && '' === $text ) {
			$fields['text'] = $message->body;
		}

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$fields['h:Reply-To'] = $reply['email'];
		}

		return $this->execute(
			$this->endpoint(),
			array_merge( [ 'Content-Type' => 'application/x-www-form-urlencoded' ], $this->auth_headers() ),
			$fields
		);
	}
}
