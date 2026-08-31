<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Outlook.com / Microsoft 365 via Microsoft Graph (me/sendMail). Authenticates
 * with an Azure AD OAuth2 bearer token; the message is sent as the mailbox of the
 * connected account. Graph answers 202 Accepted on success.
 */
final class OutlookMailer extends AbstractOAuthMailer {
	protected const SLUG = 'outlook';

	public static function credential_schema(): array {
		return self::oauth_fields();
	}

	public function authorize_endpoint(): string {
		return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
	}

	public function token_endpoint(): string {
		return 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
	}

	public function scopes(): array {
		return [ 'openid', 'offline_access', 'https://graph.microsoft.com/Mail.Send' ];
	}

	public function extra_authorize_params(): array {
		return [
			'response_mode' => 'query',
			'prompt'        => 'consent',
		];
	}

	protected function endpoint(): string {
		return 'https://graph.microsoft.com/v1.0/me/sendMail';
	}

	protected function build_payload( Message $message ): array {
		$graph = [
			'subject'      => $message->subject,
			'body'         => [
				'contentType' => $this->is_html( $message ) ? 'HTML' : 'Text',
				'content'     => '' !== $message->body ? $message->body : $this->text_part( $message ),
			],
			'toRecipients' => $this->recipients( $message->to ),
		];

		if ( [] !== $message->cc ) {
			$graph['ccRecipients'] = $this->recipients( $message->cc );
		}
		if ( [] !== $message->bcc ) {
			$graph['bccRecipients'] = $this->recipients( $message->bcc );
		}

		$reply = $this->reply_to( $message );
		if ( '' !== $reply['email'] ) {
			$graph['replyTo'] = $this->recipients(
				[
					[
						'address' => $reply['email'],
						'name'    => $reply['name'],
					],
				]
			);
		}

		$attachments = $this->attachments( $message );
		if ( [] !== $attachments ) {
			$graph['attachments'] = array_map(
				static fn ( array $a ): array => [
					'@odata.type'  => '#microsoft.graph.fileAttachment',
					'name'         => $a['filename'],
					'contentType'  => $a['type'],
					'contentBytes' => $a['content'],
				],
				$attachments
			);
		}

		// Graph returns 202 Accepted (empty body) on success, which the base
		// interpret() already treats as a success.
		return [
			'message'         => $graph,
			'saveToSentItems' => true,
		];
	}

	/**
	 * Map a Message address list to Graph's {emailAddress:{address,name}} shape.
	 *
	 * @param list<array{address:string, name:string}> $list
	 * @return list<array{emailAddress:array{address:string, name:string}}>
	 */
	private function recipients( array $list ): array {
		$out = [];
		foreach ( $list as $row ) {
			$address = $row['address'];
			if ( '' === $address ) {
				continue;
			}
			$out[] = [
				'emailAddress' => [
					'address' => $address,
					'name'    => $row['name'],
				],
			];
		}

		return $out;
	}
}
