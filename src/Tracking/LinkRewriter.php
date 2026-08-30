<?php

declare(strict_types=1);

namespace Flexa\Smtp\Tracking;

use DOMDocument;
use Flexa\Smtp\Api\TrackingEndpoint;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites every trackable <a href> in an HTML email so it points at the click
 * endpoint (carrying a signed token that encodes the original URL). Uses
 * DOMDocument rather than a regex so malformed markup, quoting, and attribute
 * order can't corrupt the email or slip a link past the rewrite.
 *
 * Only http(s) links are rewritten — mailto:, tel:, anchors, and relative URLs
 * are left untouched, as are links that already point at our own endpoints.
 */
final class LinkRewriter {
	public static function rewrite( string $html, int $log_id ): string {
		if ( '' === trim( $html ) || false === stripos( $html, '<a' ) ) {
			return $html;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		// Wrap in our own <body> and force UTF-8 so DOMDocument neither mangles
		// multibyte text nor injects a doctype/<html>/<head>; NOIMPLIED keeps our
		// wrapper as the single root so we can extract exactly what we were given.
		$loaded = $doc->loadHTML(
			'<?xml encoding="UTF-8"?><body>' . $html . '</body>',
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $loaded ) {
			return $html;
		}

		$changed = false;
		$anchors = $doc->getElementsByTagName( 'a' );
		foreach ( $anchors as $anchor ) {
			$href = (string) $anchor->getAttribute( 'href' );
			if ( ! self::trackable( $href ) ) {
				continue;
			}
			$anchor->setAttribute( 'href', TrackingEndpoint::click_url( $log_id, $href ) );
			$changed = true;
		}

		if ( ! $changed ) {
			return $html;
		}

		$wrapper = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( null === $wrapper ) {
			return $html;
		}

		$out = '';
		foreach ( $wrapper->childNodes as $child ) {
			$out .= (string) $doc->saveHTML( $child );
		}

		return $out;
	}

	private static function trackable( string $href ): bool {
		$href = trim( $href );
		if ( '' === $href ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}

		// Never re-wrap our own tracking URLs (idempotent across fallback retries).
		return false === strpos( $href, '/track/click/' ) && false === strpos( $href, '/track/open/' );
	}
}
