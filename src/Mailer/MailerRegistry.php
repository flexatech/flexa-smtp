<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Mailer\Contracts\MailerInterface;
use Flexa\Smtp\Mailer\Providers\NativeMailer;
use Flexa\Smtp\Mailer\Providers\SmtpMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a mailer slug to a factory that builds its {@see MailerInterface}. This
 * is the single seam for adding mailers: WP2 seeds the transport mailers, and
 * WP3/WP4 providers register through the `flexa_smtp.mailers.register` action so
 * new mailers never touch the core send path.
 */
final class MailerRegistry {
	use HasInstance;

	/**
	 * @var array<string, callable():MailerInterface>
	 */
	private array $factories = [];

	private bool $booted = false;

	/**
	 * @param callable():MailerInterface $factory
	 */
	public function register_mailer( string $slug, callable $factory ): void {
		$this->factories[ $slug ] = $factory;
	}

	public function make( string $slug ): ?MailerInterface {
		$this->boot();
		if ( ! isset( $this->factories[ $slug ] ) ) {
			return null;
		}

		$mailer = ( $this->factories[ $slug ] )();

		return $mailer instanceof MailerInterface ? $mailer : null;
	}

	public function has( string $slug ): bool {
		$this->boot();

		return isset( $this->factories[ $slug ] );
	}

	/**
	 * @return list<string>
	 */
	public function slugs(): array {
		$this->boot();

		return array_keys( $this->factories );
	}

	private function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->register_mailer( 'mail', static fn (): MailerInterface => new NativeMailer() );
		$this->register_mailer( 'smtp', static fn (): MailerInterface => new SmtpMailer() );

		/**
		 * Register additional mailers (API/OAuth providers). Handlers call
		 * {@see register_mailer()} on the passed registry.
		 *
		 * @param MailerRegistry $registry
		 */
		do_action( 'flexa_smtp.mailers.register', $this );
	}
}
