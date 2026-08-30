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
}
