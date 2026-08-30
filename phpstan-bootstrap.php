<?php
/**
 * PHPStan analysis bootstrap (NOT loaded at runtime).
 *
 * The plugin's FLEXA_SMTP_* constants are defined at runtime in flexa-smtp.php
 * via define() inside a PHP-version guard, which static analysis cannot follow
 * across files. Declaring them here lets PHPStan resolve every FLEXA_SMTP_*
 * reference instead of reporting constant.notFound.
 *
 * @package Flexa\Smtp
 */

declare(strict_types=1);

defined( 'FLEXA_SMTP_VERSION' ) || define( 'FLEXA_SMTP_VERSION', '0.1.0' );
defined( 'FLEXA_SMTP_FILE' ) || define( 'FLEXA_SMTP_FILE', __DIR__ . '/flexa-smtp.php' );
defined( 'FLEXA_SMTP_PATH' ) || define( 'FLEXA_SMTP_PATH', __DIR__ . '/' );
defined( 'FLEXA_SMTP_URL' ) || define( 'FLEXA_SMTP_URL', 'https://example.test/wp-content/plugins/flexa-smtp/' );
defined( 'FLEXA_SMTP_BASENAME' ) || define( 'FLEXA_SMTP_BASENAME', 'flexa-smtp/flexa-smtp.php' );
defined( 'FLEXA_SMTP_REST_NAMESPACE' ) || define( 'FLEXA_SMTP_REST_NAMESPACE', 'flexa-smtp/v1' );
defined( 'FLEXA_SMTP_TEXT_DOMAIN' ) || define( 'FLEXA_SMTP_TEXT_DOMAIN', 'flexa-smtp' );
