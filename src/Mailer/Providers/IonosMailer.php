<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Contracts\ConfiguresPhpMailer;
use Flexa\Smtp\Mailer\Contracts\MailerInterface;
use Flexa\Smtp\Mailer\Contracts\ProvidesCredentialSchema;
use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;
use Flexa\Smtp\Support\Settings;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * IONOS mail. IONOS delivers over authenticated SMTP (there is no public
 * transactional HTTP API), so this is a transport mailer: it presets the correct
 * regional SMTP host and lets PHPMailer do the send, exactly like the generic
 * SMTP mailer. {@see send()} is therefore never reached through the manager.
 */
final class IonosMailer implements MailerInterface, ConfiguresPhpMailer, ProvidesCredentialSchema {
	private const SLUG = 'ionos';

	/**
	 * @var array<string, string>
	 */
	private const HOSTS = [
		'com'   => 'smtp.ionos.com',
		'de'    => 'smtp.ionos.de',
		'es'    => 'smtp.ionos.es',
		'fr'    => 'smtp.ionos.fr',
		'co-uk' => 'smtp.ionos.co.uk',
	];

	public static function credential_schema(): array {
		return [
			'user'       => [ 'type' => 'string' ],
			'pass'       => [ 'type' => 'string', 'secret' => true ],
			'region'     => [ 'type' => 'enum', 'enum' => [ 'com', 'de', 'es', 'fr', 'co-uk' ] ],
			'encryption' => [ 'type' => 'enum', 'enum' => [ 'tls', 'ssl' ] ],
		];
	}

	public function slug(): string {
		return self::SLUG;
	}

	public function is_configured(): bool {
		$creds = Settings::mailer( self::SLUG );

		return '' !== (string) ( $creds['user'] ?? '' ) && '' !== (string) ( $creds['pass'] ?? '' );
	}

	public function configure( PHPMailer $php ): void {
		$creds  = Settings::mailer( self::SLUG );
		$region = (string) ( $creds['region'] ?? 'com' );
		$host   = self::HOSTS[ $region ] ?? self::HOSTS['com'];

		$encryption = 'ssl' === (string) ( $creds['encryption'] ?? 'tls' ) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

		$php->isSMTP();
		$php->Host       = $host;
		$php->Port       = PHPMailer::ENCRYPTION_SMTPS === $encryption ? 465 : 587;
		$php->SMTPSecure = $encryption;
		$php->SMTPAuth   = true;
		$php->Username   = (string) ( $creds['user'] ?? '' );
		$php->Password   = (string) ( $creds['pass'] ?? '' );
	}

	public function send( Message $message ): Result {
		unset( $message );

		return Result::error( 'ionos mailer sends through the PHPMailer transport' );
	}
}
