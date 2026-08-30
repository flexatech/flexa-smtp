<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Postmark email API. Authenticates with the X-Postmark-Server-Token header.
 * Postmark returns HTTP 200 even for some failures, signalling the real outcome
 * via the JSON `ErrorCode` (0 = success), so {@see interpret()} checks it.
 */
final class PostmarkMailer extends AbstractApiMailer {
	protected const SLUG = 'postmark';

	public static function credential_schema(): array {
		return [
			'server_token'   => [ 'type' => 'string', 'secret' => true ],
			'message_stream' => [ 'type' => 'string' ],
		];
	}

	protected function required_creds(): array {
		return [ 'server_token' ];
	}

	protected function endpoint(): string {
		return 'https://api.postmarkapp.com/email';
	}

	protected function auth_headers(): array {
		return [
			'X-Postmark-Server-Token' => $this->cred( 'server_token' ),
			'Accept'                  => 'application/json',
		];
	}

	protected function build_payload( Message $message ): array {
		$from    = $this->from( $message );
		$payload = [
			'From'    => '' !== $from['name'] ? sprintf( '%s <%s>', $from['name'], $from['email'] ) : $from['email'],
			'To'      => implode( ',', $this->map_addresses( $message->to ) ),
			'Subject' => $message->subject,
		];

		if ( [] !== $message->cc ) {
			$payload['Cc'] = implode( ',', $this->map_addresses( $message->cc ) );
		}
		if ( [] !== $message->bcc ) {
			$payload['Bcc'] = implode( ',', $this->map_addresses( $message->bcc ) );
		}

		$html = $this->html_part( $message );
		$text = $this->text_part( $message );
		if ( '' !== $html ) {
			$payload['HtmlBody'] = $html;
		}
		if ( '' !== $text ) {
			$payload['TextBody'] = $text;
		}
		if ( '' === $html && '' === $text ) {
			$payload['TextBody'] = $message->body;
		}

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$payload['ReplyTo'] = $reply['email'];
		}

		$stream = $this->cred( 'message_stream' );
		if ( '' !== $stream ) {
			$payload['MessageStream'] = $stream;
		}

		$attachments = $this->attachments( $message );
		if ( [] !== $attachments ) {
			$payload['Attachments'] = array_map(
				static fn ( array $a ): array => [
					'Name'        => $a['filename'],
					'Content'     => $a['content'],
					'ContentType' => $a['type'],
				],
				$attachments
			);
		}

		return $payload;
	}

	protected function interpret( int $code, string $raw ): Result {
		$data = json_decode( $raw, true );
		if ( is_array( $data ) && isset( $data['ErrorCode'] ) && 0 !== (int) $data['ErrorCode'] ) {
			$msg = isset( $data['Message'] ) && is_string( $data['Message'] ) ? $data['Message'] : 'Postmark rejected the message.';

			return Result::error( $msg, [ 'mailer' => $this->slug(), 'code' => $code ] );
		}

		return parent::interpret( $code, $raw );
	}
}
