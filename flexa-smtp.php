<?php
/**
 * Plugin Name:       Flexa SMTP
 * Description:       WP Mail SMTP with email logs, open/click tracking, and reports.
 * Version:           0.1.0
 * Requires at least: 5.9
 * Requires PHP:      8.2
 * Author:            FlexaTech
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flexa-smtp
 * Domain Path:       /i18n/languages
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Flexa SMTP requires PHP 8.2 or higher. The plugin has been disabled.', 'flexa-smtp' );
			echo '</p></div>';
		}
	);
	return;
}

define( 'FLEXA_SMTP_VERSION', '0.1.0' );
define( 'FLEXA_SMTP_FILE', __FILE__ );
define( 'FLEXA_SMTP_PATH', plugin_dir_path( __FILE__ ) );
define( 'FLEXA_SMTP_URL', plugin_dir_url( __FILE__ ) );
define( 'FLEXA_SMTP_BASENAME', plugin_basename( __FILE__ ) );
define( 'FLEXA_SMTP_REST_NAMESPACE', 'flexa-smtp/v1' );
define( 'FLEXA_SMTP_TEXT_DOMAIN', 'flexa-smtp' );

if ( file_exists( FLEXA_SMTP_PATH . 'vendor/autoload.php' ) ) {
	require_once FLEXA_SMTP_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'Flexa\Smtp\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = FLEXA_SMTP_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

register_activation_hook( __FILE__, [ \Flexa\Smtp\Setup\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Flexa\Smtp\Setup\Deactivator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		\Flexa\Smtp\Plugin::instance()->boot();
	}
);
