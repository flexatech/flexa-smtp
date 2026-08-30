<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Gmail / Google Workspace via the Gmail API. The message is sent as a single
 * base64url-encoded RFC 5322 blob to users/me/messages/send, authenticated with a
 * Google OAuth2 bearer token.
 *
 * The MIME is taken from the live PHPMailer the manager already fully prepared
 * (preSend() ran before send()), so attachments and multipart bodies are exactly
 * what any other transport would have produced — no hand-rolled MIME assembly.
 */
final class GmailMailer extends AbstractOAuthMailer {
	protected const SLUG = 'gmail';

	public static function credential_schema(): array {
		return self::oauth_fields();
	}

	public function authorize_endpoint(): string {
		return 'https://accounts.google.com/o/oauth2/v2/auth';
	}

	public function token_endpoint(): string {
		return 'https://oauth2.googleapis.com/token';
	}

	public function scopes(): array {
		return [ 'https://www.googleapis.com/auth/gmail.send' ];
	}

	public function extra_authorize_params(): array {
		// offline + consent guarantees Google returns a refresh token.
		return [
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
		];
	}

	protected function endpoint(): string {
		return 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
	}

	protected function build_payload( Message $message ): array {
		return [ 'raw' => $this->raw_mime( $message ) ];
	}

	/**
	 * base64url of the full MIME message. Prefers the MIME PHPMailer already built
	 * (complete with attachments); falls back to a minimal message for the rare
	 * direct-call path (e.g. tests) where no global PHPMailer is prepared.
	 */
	private function raw_mime( Message $message ): string {
		$mime = '';

		global $phpmailer;
		if ( $phpmailer instanceof PHPMailer ) {
			try {
				$mime = (string) $phpmailer->getSentMIMEMessage();
			} catch ( \Throwable $e ) {
				$mime = '';
			}
		}

		if ( '' === $mime ) {
			$mime = $this->fallback_mime( $message );
		}

		// getSentMIMEMessage() strips Bcc from the headers (correct for a wire
		// message), but Gmail delivers to the header recipients — so re-add Bcc.
		$bcc = $this->map_addresses( $message->bcc );
		if ( [] !== $bcc ) {
			$mime = 'Bcc: ' . implode( ', ', $bcc ) . "\r\n" . $mime;
		}

		return rtrim( strtr( base64_encode( $mime ), '+/', '-_' ), '=' );
	}

	/**
	 * Minimal RFC 5322 message from the value object. Attachments are omitted on
	 * this path; the normal wp_mail flow always uses the prepared PHPMailer above.
	 */
	private function fallback_mime( Message $message ): string {
		$from = $this->from( $message );
		$to   = $this->map_addresses( $message->to );

		$lines = [];
		$lines[] = 'From: ' . ( '' !== $from['name'] ? sprintf( '%s <%s>', $from['name'], $from['email'] ) : $from['email'] );
		$lines[] = 'To: ' . implode( ', ', $to );

		$cc = $this->map_addresses( $message->cc );
		if ( [] !== $cc ) {
			$lines[] = 'Cc: ' . implode( ', ', $cc );
		}

		$lines[] = 'Subject: ' . $message->subject;
		$lines[] = 'MIME-Version: 1.0';
		$content = '' !== $message->content_type ? $message->content_type : 'text/plain';
		$charset = '' !== $message->charset ? $message->charset : 'UTF-8';
		$lines[] = sprintf( 'Content-Type: %s; charset=%s', $content, $charset );

		return implode( "\r\n", $lines ) . "\r\n\r\n" . $message->body;
	}
}
