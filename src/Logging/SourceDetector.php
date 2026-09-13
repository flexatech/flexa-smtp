<?php

declare(strict_types=1);

namespace Flexa\Smtp\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Best-effort attribution of "what triggered this email" for the log. It walks
 * the call stack and reports the first plugin/theme above wp_mail() — but only
 * ever a short slug label ("plugin: woocommerce"), never an absolute filesystem
 * path, so the log cannot leak the server layout.
 */
final class SourceDetector {
	/**
	 * @return string A short label, e.g. "plugin: woocommerce", "theme: astra",
	 *                "mu-plugin: my-mu", or "WordPress core".
	 */
	public static function detect(): string {
		if ( ! function_exists( 'wp_normalize_path' ) ) {
			return '';
		}

		$self       = wp_normalize_path( FLEXA_SMTP_PATH );
		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
		$mu_dir     = defined( 'WPMU_PLUGIN_DIR' ) ? wp_normalize_path( WPMU_PLUGIN_DIR ) : '';
		$theme_root = wp_normalize_path( get_theme_root() );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- inspects the call stack to attribute the outgoing email to the plugin/theme that triggered it; not debug logging.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( $frame['file'] );

			// Skip our own plugin's frames — we want the originating caller.
			if ( '' !== $self && str_starts_with( $file, $self ) ) {
				continue;
			}

			if ( '' !== $mu_dir && str_starts_with( $file, $mu_dir ) ) {
				return 'mu-plugin: ' . self::top_segment( $file, $mu_dir );
			}

			if ( str_starts_with( $file, $plugin_dir ) ) {
				return 'plugin: ' . self::top_segment( $file, $plugin_dir );
			}

			if ( str_starts_with( $file, $theme_root ) ) {
				return 'theme: ' . self::top_segment( $file, $theme_root );
			}
		}

		return 'WordPress core';
	}

	/**
	 * The first path segment below $base (the plugin/theme slug), sanitized. If
	 * the file sits directly in $base (a single-file plugin) the file stem is used.
	 */
	private static function top_segment( string $file, string $base ): string {
		$relative = ltrim( substr( $file, strlen( $base ) ), '/' );
		$segment  = strtok( $relative, '/' );
		if ( false === $segment ) {
			$segment = $relative;
		}

		// A single-file plugin has no directory — drop the .php extension.
		if ( str_ends_with( $segment, '.php' ) ) {
			$segment = substr( $segment, 0, -4 );
		}

		return sanitize_text_field( $segment );
	}
}
