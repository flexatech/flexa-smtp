<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Mandrill (Mailchimp Transactional) messages/send API. The API key travels in
 * the JSON body, not a header. A 200 returns a per-recipient status array; any
 * "rejected"/"invalid" status is a failure.
 */
final class MandrillMailer extends AbstractApiMailer {
	protected const SLUG = 'mandrill';

	public static function credential_schema(): array {
		return [
			'api_key' => [ 'type' => 'string', 'secret' => true ],
		];
	}

	protected function required_creds(): array {
		return [ 'api_key' ];
	}

	protected function endpoint(): string {
		return 'https://mandrillapp.com/api/1.0/messages/send.json';
	}

	protected function auth_headers(): array {
		return [];
	}

	protected function build_payload( Message $message ): array {
		$from = $this->from( $message );

		$to = [];
		foreach ( $message->to as $r ) {
			$to[] = array_filter(
				[ 'email' => (string) $r['address'], 'name' => (string) $r['name'], 'type' => 'to' ],
				static fn ( string $v ): bool => '' !== $v
			);
		}
		foreach ( $message->cc as $r ) {
			$to[] = [ 'email' => (string) $r['address'], 'type' => 'cc' ];
		}
		foreach ( $message->bcc as $r ) {
			$to[] = [ 'email' => (string) $r['address'], 'type' => 'bcc' ];
		}

		$msg = [
			'subject'    => $message->subject,
			'from_email' => $from['email'],
			'from_name'  => $from['name'],
			'to'         => $to,
		];

		$html = $this->html_part( $message );
		$text = $this->text_part( $message );
		if ( '' !== $html ) {
			$msg['html'] = $html;
		}
		if ( '' !== $text ) {
			$msg['text'] = $text;
		}
		if ( '' === $html && '' === $text ) {
			$msg['text'] = $message->body;
		}

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$msg['headers'] = [ 'Reply-To' => $reply['email'] ];
		}

		$attachments = $this->attachments( $message );
		if ( [] !== $attachments ) {
			$msg['attachments'] = array_map(
				static fn ( array $a ): array => [
					'type'    => $a['type'],
					'name'    => $a['filename'],
					'content' => $a['content'],
				],
				$attachments
			);
		}

		return [
			'key'     => $this->cred( 'api_key' ),
			'message' => $msg,
		];
	}

	protected function interpret( int $code, string $raw ): Result {
		if ( $code < 200 || $code >= 300 ) {
			return parent::interpret( $code, $raw );
		}

		$data = json_decode( $raw, true );

		// A top-level object with a "status":"error" is an API-level failure.
		if ( is_array( $data ) && isset( $data['status'] ) && 'error' === $data['status'] ) {
			$msg = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : 'Mandrill API error.';

			return Result::error( $msg, [ 'mailer' => $this->slug(), 'code' => $code ] );
		}

		// Otherwise it is a list of per-recipient results.
		if ( is_array( $data ) ) {
			foreach ( $data as $entry ) {
				if ( is_array( $entry ) && isset( $entry['status'] ) && in_array( $entry['status'], [ 'rejected', 'invalid' ], true ) ) {
					$reason = isset( $entry['reject_reason'] ) && is_string( $entry['reject_reason'] ) ? $entry['reject_reason'] : (string) $entry['status'];

					return Result::error(
						sprintf( 'Mandrill %s: %s', (string) $entry['status'], $reason ),
						[ 'mailer' => $this->slug(), 'code' => $code ]
					);
				}
			}
		}

		return Result::success( [ 'mailer' => $this->slug(), 'code' => $code ] );
	}
}
