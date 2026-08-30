<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * SparkPost Transmissions API. The API key goes in the Authorization header
 * (no "Bearer" prefix). Supports the US and EU regional endpoints.
 */
final class SparkPostMailer extends AbstractApiMailer {
	protected const SLUG = 'sparkpost';

	public static function credential_schema(): array {
		return [
			'api_key' => [ 'type' => 'string', 'secret' => true ],
			'region'  => [ 'type' => 'enum', 'enum' => [ 'us', 'eu' ] ],
		];
	}

	protected function required_creds(): array {
		return [ 'api_key' ];
	}

	protected function endpoint(): string {
		$host = 'eu' === $this->cred( 'region' ) ? 'api.eu.sparkpost.com' : 'api.sparkpost.com';

		return sprintf( 'https://%s/api/v1/transmissions', $host );
	}

	protected function auth_headers(): array {
		return [ 'Authorization' => $this->cred( 'api_key' ) ];
	}

	protected function build_payload( Message $message ): array {
		$recipients = array_map(
			static fn ( array $r ): array => [ 'address' => array_filter( [ 'email' => (string) $r['address'], 'name' => (string) $r['name'] ], static fn ( string $v ): bool => '' !== $v ) ],
			$message->to
		);
		// Cc/Bcc are delivered by adding them as recipients; Cc is also surfaced
		// in the message headers so it shows in the client.
		foreach ( array_merge( $message->cc, $message->bcc ) as $extra ) {
			$recipients[] = [ 'address' => [ 'email' => (string) $extra['address'] ] ];
		}

		$content = [
			'from'    => array_filter( $this->from( $message ), static fn ( string $v ): bool => '' !== $v ),
			'subject' => $message->subject,
		];

		$html = $this->html_part( $message );
		$text = $this->text_part( $message );
		if ( '' !== $html ) {
			$content['html'] = $html;
		}
		if ( '' !== $text ) {
			$content['text'] = $text;
		}
		if ( '' === $html && '' === $text ) {
			$content['text'] = $message->body;
		}

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$content['reply_to'] = $reply['email'];
		}

		if ( [] !== $message->cc ) {
			$content['headers'] = [ 'CC' => implode( ',', $this->map_addresses( $message->cc ) ) ];
		}

		$attachments = $this->attachments( $message );
		if ( [] !== $attachments ) {
			$content['attachments'] = array_map(
				static fn ( array $a ): array => [
					'type' => $a['type'],
					'name' => $a['filename'],
					'data' => $a['content'],
				],
				$attachments
			);
		}

		return [
			'recipients' => $recipients,
			'content'    => $content,
		];
	}
}
