<?php

declare(strict_types=1);

namespace Flexa\Smtp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal AWS Signature Version 4 signer for a single POST request, enough to
 * authenticate the Amazon SES v2 HTTP API without pulling in the AWS SDK (which
 * would bloat the plugin zip). Returns the headers to attach to the request.
 */
final class AwsV4Signer {
	/**
	 * Sign a request and return the full header set (including Authorization,
	 * Host and X-Amz-Date) to send.
	 *
	 * @param array<string, string> $headers Request headers that participate in
	 *                                        signing, notably Content-Type.
	 * @return array<string, string>
	 */
	public static function sign(
		string $method,
		string $url,
		string $region,
		string $service,
		string $access_key,
		string $secret_key,
		string $payload,
		array $headers = []
	): array {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$query = is_array( $parts ) && isset( $parts['query'] ) ? (string) $parts['query'] : '';

		$amz_date  = gmdate( 'Ymd\THis\Z' );
		$datestamp = gmdate( 'Ymd' );

		// Canonical headers must include host and x-amz-date; merge in the
		// caller's headers (e.g. content-type), lowercased and sorted.
		$canonical_headers        = [];
		$canonical_headers['host']       = $host;
		$canonical_headers['x-amz-date'] = $amz_date;
		foreach ( $headers as $name => $value ) {
			$canonical_headers[ strtolower( $name ) ] = trim( (string) $value );
		}
		ksort( $canonical_headers );

		$canonical_header_str = '';
		foreach ( $canonical_headers as $name => $value ) {
			$canonical_header_str .= $name . ':' . $value . "\n";
		}
		$signed_headers = implode( ';', array_keys( $canonical_headers ) );

		$payload_hash = hash( 'sha256', $payload );

		$canonical_request = implode(
			"\n",
			[
				$method,
				$path,
				$query,
				$canonical_header_str,
				$signed_headers,
				$payload_hash,
			]
		);

		$algorithm        = 'AWS4-HMAC-SHA256';
		$credential_scope = sprintf( '%s/%s/%s/aws4_request', $datestamp, $region, $service );
		$string_to_sign   = implode(
			"\n",
			[
				$algorithm,
				$amz_date,
				$credential_scope,
				hash( 'sha256', $canonical_request ),
			]
		);

		$signing_key = self::signing_key( $secret_key, $datestamp, $region, $service );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authorization = sprintf(
			'%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$algorithm,
			$access_key,
			$credential_scope,
			$signed_headers,
			$signature
		);

		return array_merge(
			$headers,
			[
				'Host'          => $host,
				'X-Amz-Date'    => $amz_date,
				'Authorization' => $authorization,
			]
		);
	}

	private static function signing_key( string $secret_key, string $datestamp, string $region, string $service ): string {
		$k_date    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}
}
