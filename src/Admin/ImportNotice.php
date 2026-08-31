<?php

declare(strict_types=1);

namespace Flexa\Smtp\Admin;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Import\ImporterRegistry;
use Flexa\Smtp\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * One-time admin notice shown when another SMTP plugin's data is detected,
 * offering to migrate it into Flexa SMTP. Dismissible; the choice is stored so
 * the notice never nags again.
 */
final class ImportNotice {
	use HasInstance;

	private const DISMISSED_OPTION = 'flexa_smtp_import_notice_dismissed';
	private const DISMISS_ACTION   = 'flexa_smtp_dismiss_import';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_dismiss' ] );
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	public function maybe_dismiss(): void {
		if ( empty( $_GET[ self::DISMISS_ACTION ] ) || ! Capabilities::can_manage() ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ACTION ) ) {
			return;
		}

		update_option( self::DISMISSED_OPTION, 1 );
	}

	public function render(): void {
		if ( ! Capabilities::can_manage() || get_option( self::DISMISSED_OPTION ) ) {
			return;
		}

		$available = ImporterRegistry::instance()->available();
		if ( [] === $available ) {
			return;
		}

		$labels = array_map(
			static fn ( $importer ): string => $importer->label(),
			array_values( $available )
		);

		$page_url    = admin_url( 'admin.php?page=' . Menu::SLUG );
		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_ACTION, '1', $page_url ),
			self::DISMISS_ACTION
		);

		printf(
			'<div class="notice notice-info"><p><strong>%s</strong></p><p>%s</p><p><a href="%s" class="button button-primary">%s</a> <a href="%s" class="button">%s</a></p></div>',
			esc_html__( 'Flexa SMTP: import your existing configuration', 'flexa-smtp' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of detected plugin names. */
					__( 'We detected settings from: %s. You can import them (and their email logs) into Flexa SMTP.', 'flexa-smtp' ),
					implode( ', ', $labels )
				)
			),
			esc_url( $page_url ),
			esc_html__( 'Go to Import', 'flexa-smtp' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'flexa-smtp' )
		);
	}
}
