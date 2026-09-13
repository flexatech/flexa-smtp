<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Contracts\PhpMailerConfigurator;
use Flexa\Smtp\Mailer\Contracts\MailerInterface;
use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress default: hand the message to PHP's mail() via PHPMailer. Always
 * "configured" — it needs no credentials. Sending is done by PHPMailer, so
 * {@see send()} is never reached through the manager.
 */
final class NativeMailer implements MailerInterface, PhpMailerConfigurator {
	public function slug(): string {
		return 'mail';
	}

	public function is_configured(): bool {
		return true;
	}

	public function configure( PHPMailer $php ): void {
		$php->isMail();
	}

	public function send( Message $message ): Result {
		unset( $message );

		return Result::error( 'native mailer sends through the PHPMailer transport' );
	}
}
