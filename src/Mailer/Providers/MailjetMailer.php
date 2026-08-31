<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Mailjet Send API v3.1. Authenticates with HTTP Basic (api_key:secret_key).
 */
final class MailjetMailer extends AbstractApiMailer {
	protected const SLUG = 'mailjet';

	public static function credential_schema(): array {
		return [
			'api_key'    => [ 'type' => 'string' ],
			'secret_key' => [
				'type'   => 'string',
				'secret' => true,
			],
		];
	}

	protected function required_creds(): array {
		return [ 'api_key', 'secret_key' ];
	}

	protected function endpoint(): string {
		return 'https://api.mailjet.com/v3.1/send';
	}

	protected function auth_headers(): array {
		$token = base64_encode( $this->cred( 'api_key' ) . ':' . $this->cred( 'secret_key' ) );

		return [ 'Authorization' => 'Basic ' . $token ];
	}

	protected function build_payload( Message $message ): array {
		$from = $this->from( $message );

		$msg = [
			'From'    => $this->compact_pairs(
				[
					'Email' => $from['email'],
					'Name'  => $from['name'],
				]
			),
			'To'      => $this->mailjet_recipients( $message->to ),
			'Subject' => $message->subject,
		];

		if ( [] !== $message->cc ) {
			$msg['Cc'] = $this->mailjet_recipients( $message->cc );
		}
		if ( [] !== $message->bcc ) {
			$msg['Bcc'] = $this->mailjet_recipients( $message->bcc );
		}

		$html = $this->html_part( $message );
		$text = $this->text_part( $message );
		if ( '' !== $html ) {
			$msg['HTMLPart'] = $html;
		}
		if ( '' !== $text ) {
			$msg['TextPart'] = $text;
		}
		if ( '' === $html && '' === $text ) {
			$msg['TextPart'] = $message->body;
		}

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$msg['ReplyTo'] = $this->compact_pairs(
				[
					'Email' => $reply['email'],
					'Name'  => $reply['name'],
				]
			);
		}

		$attachments = $this->attachments( $message );
		if ( [] !== $attachments ) {
			$msg['Attachments'] = array_map(
				static fn ( array $a ): array => [
					'ContentType'   => $a['type'],
					'Filename'      => $a['filename'],
					'Base64Content' => $a['content'],
				],
				$attachments
			);
		}

		return [ 'Messages' => [ $msg ] ];
	}

	/**
	 * @param list<array{address:string, name:string}> $list
	 * @return list<array{Email:string, Name:string}>
	 */
	private function mailjet_recipients( array $list ): array {
		$out = [];
		foreach ( $list as $row ) {
			$out[] = [
				'Email' => (string) $row['address'],
				'Name'  => (string) $row['name'],
			];
		}

		return $out;
	}
}
