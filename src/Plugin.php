<?php

declare(strict_types=1);

namespace Flexa\Smtp;

use Flexa\Smtp\Concerns\HasInstance;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	use HasInstance;

	private bool $booted = false;

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Translations load automatically: WordPress.org-hosted plugins have
		// had just-in-time textdomain loading since WP 4.6, so no explicit
		// load_plugin_textdomain() call is needed here.

		if ( class_exists( Database\Schema::class ) ) {
			add_action( 'admin_init', [ Database\Schema::class, 'maybe_upgrade' ] );
		}

		if ( class_exists( Api\Router::class ) ) {
			Api\Router::instance()->register();
		}

		if ( class_exists( Mailer\Providers\ProviderCatalog::class ) ) {
			Mailer\Providers\ProviderCatalog::instance()->register();
		}

		if ( class_exists( Mailer\MailerManager::class ) ) {
			Mailer\MailerManager::instance()->register();
		}

		if ( class_exists( Logging\MailLogger::class ) ) {
			Logging\MailLogger::instance()->register();
		}

		if ( class_exists( Logging\Retention::class ) ) {
			Logging\Retention::instance()->register();
		}

		if ( class_exists( Queue\Queue::class ) ) {
			Queue\Queue::instance()->register();
		}

		if ( class_exists( Health\HealthChecker::class ) ) {
			Health\HealthChecker::instance()->register();
		}

		if ( class_exists( Tracking\Tracker::class ) ) {
			Tracking\Tracker::instance()->register();
		}

		if ( class_exists( Reports\Scheduler::class ) ) {
			Reports\Scheduler::instance()->register();
		}

		if ( is_admin() ) {
			if ( class_exists( Admin\Menu::class ) ) {
				Admin\Menu::instance()->register();
			}
			if ( class_exists( Admin\Enqueue::class ) ) {
				Admin\Enqueue::instance()->register();
			}
			if ( class_exists( Admin\DashboardWidget::class ) ) {
				Admin\DashboardWidget::instance()->register();
			}
			if ( class_exists( Admin\ImportNotice::class ) ) {
				Admin\ImportNotice::instance()->register();
			}
		}

		// WP7+ services (Reports\Scheduler, …) register here behind
		// class_exists() guards as each work package lands.

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			if ( class_exists( Cli\LogsCommand::class ) ) {
				Cli\LogsCommand::register();
			}
			if ( class_exists( Cli\SendTestCommand::class ) ) {
				Cli\SendTestCommand::register();
			}
			if ( class_exists( Cli\ResetCommand::class ) ) {
				Cli\ResetCommand::register();
			}
		}
	}
}
