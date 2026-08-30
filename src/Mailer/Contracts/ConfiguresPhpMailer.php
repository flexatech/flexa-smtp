<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Contracts;

use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by transport mailers that send through PHPMailer itself (SMTP and
 * native PHP mail). {@see \Flexa\Smtp\Mailer\MailerManager} calls
 * {@see configure()} on the live PHPMailer instance right before letting it send,
 * instead of routing the message through an HTTP API.
 */
interface ConfiguresPhpMailer {
	public function configure( PHPMailer $php ): void;
}
