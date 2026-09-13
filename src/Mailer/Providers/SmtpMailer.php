<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Contracts\PhpMailerConfigurator;
use Flexa\Smtp\Mailer\Contracts\MailerInterface;
use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;
use Flexa\Smtp\Support\Settings;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Custom SMTP transport. Configures the live PHPMailer with the stored host /
 * port / encryption / auth credentials (decrypted via {@see Settings::mailer()})
 * and lets PHPMailer perform the SMTP conversation, so {@see send()} is never
 * reached through the manager.
 */
final class SmtpMailer implements MailerInterface, PhpMailerConfigurator {
	public function slug(): string {
		return 'smtp';
	}

	public function is_configured(): bool {
		$creds = Settings::mailer( 'smtp' );

		return '' !== (string) ( $creds['host'] ?? '' ) && (int) ( $creds['port'] ?? 0 ) > 0;
	}

	public function configure( PHPMailer $php ): void {
		$creds = Settings::mailer( 'smtp' );

		$php->isSMTP();
		$php->Host = (string) ( $creds['host'] ?? '' );
		$php->Port = (int) ( $creds['port'] ?? 587 );

		$encryption = (string) ( $creds['encryption'] ?? 'none' );
		if ( PHPMailer::ENCRYPTION_SMTPS === $encryption || PHPMailer::ENCRYPTION_STARTTLS === $encryption ) {
			$php->SMTPSecure = $encryption;
		} else {
			$php->SMTPSecure  = '';
			$php->SMTPAutoTLS = false;
		}

		$auth          = ! empty( $creds['auth'] );
		$php->SMTPAuth = $auth;
		if ( $auth ) {
			$php->Username = (string) ( $creds['user'] ?? '' );
			$php->Password = (string) ( $creds['pass'] ?? '' );
		}
	}

	public function send( Message $message ): Result {
		unset( $message );

		return Result::error( 'smtp mailer sends through the PHPMailer transport' );
	}
}
