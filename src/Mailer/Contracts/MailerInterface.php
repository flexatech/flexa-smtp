<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Contracts;

use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Every mailer (transport or API provider) implements this. Transport mailers
 * (SMTP, native PHP mail) also implement {@see ConfiguresPhpMailer} and let
 * PHPMailer perform the actual send, so their {@see send()} is never invoked by
 * {@see \Flexa\Smtp\Mailer\MailerManager}. API providers (WP3) do the real work
 * inside {@see send()}.
 *
 * Providers MUST NOT write email logs themselves — MailerManager owns the log
 * and hook lifecycle so the success and failure paths can never drift.
 */
interface MailerInterface {
	public function slug(): string;

	/**
	 * Whether this mailer has enough configuration to attempt a send.
	 */
	public function is_configured(): bool;

	public function send( Message $message ): Result;
}
