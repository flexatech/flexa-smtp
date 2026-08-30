<?php

declare(strict_types=1);

namespace Flexa\Smtp\Tracking;

use Flexa\Smtp\Api\TrackingEndpoint;

defined( 'ABSPATH' ) || exit;

/**
 * Appends a 1x1 tracking pixel to an HTML email body. The pixel points at the
 * open endpoint with a signed token bound to this log row; a GET on it (when the
 * client loads remote images) records the open.
 */
final class PixelInjector {
	public static function inject( string $html, int $log_id ): string {
		$img = sprintf(
			'<img src="%s" alt="" width="1" height="1" style="display:none;max-height:0;overflow:hidden;" />',
			esc_url( TrackingEndpoint::open_url( $log_id ) )
		);

		// Place it just before </body> when there is one, else append.
		if ( false !== stripos( $html, '</body>' ) ) {
			$out = preg_replace( '/<\/body>/i', $img . '</body>', $html, 1 );

			return is_string( $out ) ? $out : $html . $img;
		}

		return $html . $img;
	}
}
