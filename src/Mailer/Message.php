<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer;

use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, transport-agnostic view of an outgoing email. Built from the live
 * PHPMailer instance once wp_mail() has fully populated it, so API providers
 * (WP3) get a clean value object instead of poking at PHPMailer internals.
 */
final class Message {
	/**
	 * @param list<array{address:string, name:string}> $to
	 * @param list<array{address:string, name:string}> $cc
	 * @param list<array{address:string, name:string}> $bcc
	 * @param list<array{address:string, name:string}> $reply_to
	 * @param list<string>                             $headers      Raw custom header lines.
	 * @param list<array<int, mixed>>                  $attachments  PHPMailer attachment rows.
	 */
	public function __construct(
		public readonly array $to,
		public readonly array $cc,
		public readonly array $bcc,
		public readonly array $reply_to,
		public readonly string $from_email,
		public readonly string $from_name,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $alt_body,
		public readonly string $content_type,
		public readonly string $charset,
		public readonly array $headers,
		public readonly array $attachments
	) {}

	public static function from_phpmailer( PHPMailer $php ): self {
		return new self(
			self::map_addresses( $php->getToAddresses() ),
			self::map_addresses( $php->getCcAddresses() ),
			self::map_addresses( $php->getBccAddresses() ),
			self::map_addresses( $php->getReplyToAddresses() ),
			(string) $php->From,
			(string) $php->FromName,
			(string) $php->Subject,
			(string) $php->Body,
			(string) $php->AltBody,
			(string) $php->ContentType,
			(string) $php->CharSet,
			self::map_headers( $php->getCustomHeaders() ),
			array_values( $php->getAttachments() )
		);
	}

	/**
	 * @param array<int, array<int, string>> $addresses PHPMailer [address, name] rows.
	 * @return list<array{address:string, name:string}>
	 */
	private static function map_addresses( array $addresses ): array {
		$out = [];
		foreach ( $addresses as $row ) {
			$out[] = [
				'address' => (string) ( $row[0] ?? '' ),
				'name'    => (string) ( $row[1] ?? '' ),
			];
		}

		return $out;
	}

	/**
	 * PHPMailer::getCustomHeaders() returns [name, value] pairs; flatten to lines.
	 *
	 * @param array<int, array<int, string>> $headers
	 * @return list<string>
	 */
	private static function map_headers( array $headers ): array {
		$out = [];
		foreach ( $headers as $row ) {
			$name  = (string) ( $row[0] ?? '' );
			$value = (string) ( $row[1] ?? '' );
			if ( '' !== $name ) {
				$out[] = $name . ': ' . $value;
			}
		}

		return $out;
	}
}
