<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * A PHPMailer subclass installed as WordPress's global `$phpmailer`. Because it
 * IS a PHPMailer, wp_mail() keeps and populates it as usual; we only override
 * send() so every outgoing email flows through {@see MailerManager}, which owns
 * mailer selection, fallback, dev-mode, and the send hooks.
 */
final class PhpMailerBridge extends PHPMailer {
	private ?MailerManager $manager = null;

	public function set_manager( MailerManager $manager ): void {
		$this->manager = $manager;
	}

	public function send(): bool {
		if ( null === $this->manager ) {
			return parent::send();
		}

		return $this->manager->dispatch( $this );
	}

	/**
	 * Run PHPMailer's real send (SMTP/native). Used by the manager for transport
	 * mailers after they have configured this instance.
	 *
	 * @throws Exception When PHPMailer fails and exceptions are enabled.
	 */
	public function send_native(): bool {
		return parent::send();
	}

	/**
	 * Serialize everything the queue needs to rebuild and send this message later:
	 * sender, recipients, subject, body, headers, and attachments. Called after the
	 * message is fully populated (and after tracking has rewritten the body), so the
	 * stored copy matches what a synchronous send would have delivered. String
	 * attachments are base64-encoded; file attachments keep their path (which must
	 * still exist when the worker runs).
	 *
	 * @return array<string, mixed>
	 */
	public function to_payload(): array {
		$attachments = [];
		foreach ( $this->getAttachments() as $item ) {
			$is_string     = (bool) ( $item[5] ?? false );
			$attachments[] = [
				'is_string'   => $is_string,
				'content'     => $is_string ? base64_encode( (string) ( $item[0] ?? '' ) ) : '',
				'path'        => $is_string ? '' : (string) ( $item[0] ?? '' ),
				'filename'    => (string) ( $item[2] ?? ( $item[1] ?? '' ) ),
				'encoding'    => (string) ( $item[3] ?? 'base64' ),
				'type'        => (string) ( $item[4] ?? '' ),
				'disposition' => (string) ( $item[6] ?? 'attachment' ),
			];
		}

		return [
			'from'         => [
				'address' => (string) $this->From,
				'name'    => (string) $this->FromName,
			],
			'subject'      => (string) $this->Subject,
			'body'         => (string) $this->Body,
			'alt_body'     => (string) $this->AltBody,
			'content_type' => (string) $this->ContentType,
			'charset'      => (string) $this->CharSet,
			'encoding'     => (string) $this->Encoding,
			'to'           => self::export_addresses( $this->getToAddresses() ),
			'cc'           => self::export_addresses( $this->getCcAddresses() ),
			'bcc'          => self::export_addresses( $this->getBccAddresses() ),
			'reply_to'     => self::export_addresses( array_values( $this->getReplyToAddresses() ) ),
			'headers'      => array_map(
				static fn ( array $h ): array => [
					'name'  => (string) ( $h[0] ?? '' ),
					'value' => (string) ( $h[1] ?? '' ),
				],
				$this->getCustomHeaders()
			),
			'attachments'  => $attachments,
		];
	}

	/**
	 * Rebuild a bridge from a {@see to_payload()} array so the queue worker can send
	 * it. The From/addresses were already resolved through the wp_mail filters when
	 * the payload was captured, so no re-filtering happens here. Invalid addresses
	 * are skipped rather than aborting the whole message.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function from_payload( array $payload ): self {
		$php = new self( true );

		$charset       = (string) ( $payload['charset'] ?? '' );
		$php->CharSet  = '' !== $charset ? $charset : 'UTF-8';
		$encoding      = (string) ( $payload['encoding'] ?? '' );
		$php->Encoding = '' !== $encoding ? $encoding : '8bit';

		$from = is_array( $payload['from'] ?? null ) ? $payload['from'] : [];
		try {
			$php->setFrom( (string) ( $from['address'] ?? '' ), (string) ( $from['name'] ?? '' ), false );
		} catch ( Exception $e ) {
			unset( $e );
			$php->From     = (string) ( $from['address'] ?? '' );
			$php->FromName = (string) ( $from['name'] ?? '' );
		}

		self::import_addresses( $payload['to'] ?? [], [ $php, 'addAddress' ] );
		self::import_addresses( $payload['cc'] ?? [], [ $php, 'addCC' ] );
		self::import_addresses( $payload['bcc'] ?? [], [ $php, 'addBCC' ] );
		self::import_addresses( $payload['reply_to'] ?? [], [ $php, 'addReplyTo' ] );

		$php->Subject = (string) ( $payload['subject'] ?? '' );
		$content_type = (string) ( $payload['content_type'] ?? '' );
		$body         = (string) ( $payload['body'] ?? '' );
		$alt          = (string) ( $payload['alt_body'] ?? '' );

		if ( false !== stripos( $content_type, 'html' ) ) {
			$php->isHTML( true );
			$php->Body = $body;
			if ( '' !== $alt ) {
				$php->AltBody = $alt;
			}
		} else {
			$php->isHTML( false );
			$php->Body = $body;
			if ( '' !== $content_type && 'text/plain' !== $content_type ) {
				$php->ContentType = $content_type;
			}
		}

		foreach ( is_array( $payload['headers'] ?? null ) ? $payload['headers'] : [] as $header ) {
			if ( ! is_array( $header ) ) {
				continue;
			}
			try {
				$php->addCustomHeader( (string) ( $header['name'] ?? '' ), (string) ( $header['value'] ?? '' ) );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}

		foreach ( is_array( $payload['attachments'] ?? null ) ? $payload['attachments'] : [] as $att ) {
			if ( ! is_array( $att ) ) {
				continue;
			}
			try {
				if ( ! empty( $att['is_string'] ) ) {
					$php->addStringAttachment(
						(string) base64_decode( (string) ( $att['content'] ?? '' ), true ),
						(string) ( $att['filename'] ?? '' ),
						(string) ( $att['encoding'] ?? 'base64' ),
						(string) ( $att['type'] ?? '' ),
						(string) ( $att['disposition'] ?? 'attachment' )
					);
				} else {
					$path = (string) ( $att['path'] ?? '' );
					if ( '' !== $path && is_file( $path ) ) {
						$php->addAttachment(
							$path,
							(string) ( $att['filename'] ?? '' ),
							(string) ( $att['encoding'] ?? 'base64' ),
							(string) ( $att['type'] ?? '' ),
							(string) ( $att['disposition'] ?? 'attachment' )
						);
					}
				}
			} catch ( Exception $e ) {
				unset( $e );
			}
		}

		return $php;
	}

	/**
	 * @param array<int, mixed> $addresses PHPMailer [ [address, name], ... ]
	 * @return list<array{address:string, name:string}>
	 */
	private static function export_addresses( array $addresses ): array {
		$out = [];
		foreach ( $addresses as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = [
				'address' => (string) ( $entry[0] ?? '' ),
				'name'    => (string) ( $entry[1] ?? '' ),
			];
		}

		return $out;
	}

	/**
	 * @param mixed    $addresses list<array{address:string, name:string}>
	 * @param callable $add       one of addAddress/addCC/addBCC/addReplyTo
	 */
	private static function import_addresses( mixed $addresses, callable $add ): void {
		if ( ! is_array( $addresses ) ) {
			return;
		}
		foreach ( $addresses as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			try {
				$add( (string) ( $entry['address'] ?? '' ), (string) ( $entry['name'] ?? '' ) );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}
	}
}
