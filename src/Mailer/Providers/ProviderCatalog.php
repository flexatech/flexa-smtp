<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Mailer\Contracts\ProvidesCredentialSchema;
use Flexa\Smtp\Mailer\MailerRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every WP3 provider through the two extension seams built in WP2:
 * it merges each provider's credential fields into the settings schema
 * (`flexa_smtp.settings.mailer_schema`) and registers each mailer factory
 * (`flexa_smtp.mailers.register`). Adding a provider is a single line here — the
 * core send path and Settings never change.
 */
final class ProviderCatalog {
	use HasInstance;

	/**
	 * slug => provider class. Every class implements MailerInterface and
	 * ProvidesCredentialSchema.
	 *
	 * @var array<string, class-string>
	 */
	public const PROVIDERS = [
		'sendgrid'   => SendGridMailer::class,
		'mailgun'    => MailgunMailer::class,
		'brevo'      => BrevoMailer::class,
		'amazonses'  => AmazonSesMailer::class,
		'postmark'   => PostmarkMailer::class,
		'mailjet'    => MailjetMailer::class,
		'sparkpost'  => SparkPostMailer::class,
		'smtpcom'    => SmtpComMailer::class,
		'pepipost'   => PepiPostMailer::class,
		'sendpulse'  => SendPulseMailer::class,
		'mandrill'   => MandrillMailer::class,
		'yournotify' => YournotifyMailer::class,
		'ionos'      => IonosMailer::class,
		'gmail'      => GmailMailer::class,
		'outlook'    => OutlookMailer::class,
		'zoho'       => ZohoMailer::class,
	];

	public function register(): void {
		add_filter( 'flexa_smtp.settings.mailer_schema', [ $this, 'add_schema' ] );
		add_action( 'flexa_smtp.mailers.register', [ $this, 'register_mailers' ] );
	}

	/**
	 * @param array<string, array<string, array{type:string, secret?:bool, enum?:list<string>}>> $schema
	 * @return array<string, array<string, array{type:string, secret?:bool, enum?:list<string>}>>
	 */
	public function add_schema( array $schema ): array {
		foreach ( self::PROVIDERS as $slug => $class ) {
			if ( is_a( $class, ProvidesCredentialSchema::class, true ) ) {
				$schema[ $slug ] = $class::credential_schema();
			}
		}

		return $schema;
	}

	public function register_mailers( MailerRegistry $registry ): void {
		foreach ( self::PROVIDERS as $slug => $class ) {
			$registry->register_mailer( $slug, static fn () => new $class() );
		}
	}
}
