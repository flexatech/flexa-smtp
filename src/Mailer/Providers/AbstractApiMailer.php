<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Providers;

use Flexa\Smtp\Mailer\Contracts\MailerInterface;
use Flexa\Smtp\Mailer\Contracts\ProvidesCredentialSchema;
use Flexa\Smtp\Mailer\Message;
use Flexa\Smtp\Mailer\Result;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shared machinery for HTTP-API mailers. Concrete providers describe *what* to
 * send (endpoint, auth headers, JSON payload) and this base owns *how* — the
 * wp_remote_post call, network-error handling, and turning the HTTP response
 * into a {@see Result}. Providers never touch $wpdb or write logs; they return a
 * Result and MailerManager owns the lifecycle.
 *
 * Non-JSON providers (form bodies, signed requests, OAuth token exchange)
 * override {@see send()} and call {@see execute()} with their own request.
 */
abstract class AbstractApiMailer implements MailerInterface, ProvidesCredentialSchema {
	protected const SLUG = '';

	/**
	 * Slug used both as the mailer id and the Settings::mailer() credential key.
	 */
	public function slug(): string {
		return static::SLUG;
	}

	/**
	 * Credential field names that must all be non-empty for a send to be
	 * attempted.
	 *
	 * @return list<string>
	 */
	abstract protected function required_creds(): array;

	public function is_configured(): bool {
		$creds = $this->creds();
		foreach ( $this->required_creds() as $field ) {
			if ( '' === (string) ( $creds[ $field ] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	public function send( Message $message ): Result {
		if ( ! $this->is_configured() ) {
			return Result::error(
				sprintf( '%s is not fully configured.', $this->slug() ),
				[ 'mailer' => $this->slug() ]
			);
		}

		$payload = wp_json_encode( $this->build_payload( $message ) );
		if ( false === $payload ) {
			return Result::error( 'could not encode the message payload', [ 'mailer' => $this->slug() ] );
		}

		$headers = array_merge(
			[ 'Content-Type' => 'application/json' ],
			$this->auth_headers()
		);

		return $this->execute( $this->endpoint(), $headers, $payload );
	}

	/**
	 * The HTTP endpoint to POST to.
	 */
	abstract protected function endpoint(): string;

	/**
	 * Authentication (and any provider-specific) headers.
	 *
	 * @return array<string, string>
	 */
	abstract protected function auth_headers(): array;

	/**
	 * JSON-serialisable request body.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function build_payload( Message $message ): array;

	/**
	 * Perform the POST and interpret the response. Shared by the default JSON
	 * send() and by providers that build their own request.
	 *
	 * @param array<string, string>       $headers
	 * @param string|array<string, mixed> $body    A raw string body, or an array
	 *                                             for a form-encoded request.
	 */
	protected function execute( string $url, array $headers, string|array $body, string $method = 'POST' ): Result {
		$response = wp_remote_request(
			$url,
			[
				'method'  => $method,
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return Result::error( $response->get_error_message(), [ 'mailer' => $this->slug() ] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		return $this->interpret( $code, $raw );
	}

	/**
	 * Default response handling: any 2xx is success. Providers whose API returns
	 * 200 with an in-body error (Mandrill, Postmark) override this.
	 */
	protected function interpret( int $code, string $raw ): Result {
		if ( $code >= 200 && $code < 300 ) {
			return Result::success(
				[
					'mailer' => $this->slug(),
					'code'   => $code,
				]
			);
		}

		return Result::error(
			$this->extract_error( $raw, $code ),
			[
				'mailer' => $this->slug(),
				'code'   => $code,
			]
		);
	}

	/**
	 * Pull a human message out of a JSON error body, falling back to the status
	 * code. Recognises the common shapes ({message}, {error}, {errors:[...]}).
	 */
	protected function extract_error( string $raw, int $code ): string {
		$data = json_decode( $raw, true );

		if ( is_array( $data ) ) {
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				return $data['message'];
			}
			if ( isset( $data['error'] ) ) {
				if ( is_string( $data['error'] ) ) {
					return $data['error'];
				}
				if ( is_array( $data['error'] ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
					return $data['error']['message'];
				}
			}
			if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$first = reset( $data['errors'] );
				if ( is_array( $first ) && isset( $first['message'] ) && is_string( $first['message'] ) ) {
					return $first['message'];
				}
				if ( is_string( $first ) ) {
					return $first;
				}
			}
		}

		$trimmed = trim( wp_strip_all_tags( $raw ) );

		return '' !== $trimmed
			? sprintf( 'HTTP %d: %s', $code, mb_substr( $trimmed, 0, 300 ) )
			: sprintf( 'HTTP %d', $code );
	}

	// Helpers for building payloads from a Message.

	/**
	 * Decrypted credentials for this mailer.
	 *
	 * @return array<string, mixed>
	 */
	protected function creds(): array {
		return Settings::mailer( $this->slug() );
	}

	protected function cred( string $field, string $default = '' ): string {
		return (string) ( $this->creds()[ $field ] ?? $default );
	}

	/**
	 * Drop empty-string entries from a key/value map (e.g. an address pair
	 * whose name is blank). Kept as a helper so callers pass a plain array
	 * argument instead of inlining an array_filter with an arrow function.
	 *
	 * @param array<string, string> $pairs
	 * @return array<string, string>
	 */
	protected function compact_pairs( array $pairs ): array {
		return array_filter( $pairs, static fn ( string $v ): bool => '' !== $v );
	}

	protected function is_html( Message $message ): bool {
		return false !== stripos( $message->content_type, 'html' );
	}

	protected function html_part( Message $message ): string {
		return $this->is_html( $message ) ? $message->body : '';
	}

	protected function text_part( Message $message ): string {
		if ( $this->is_html( $message ) ) {
			return $message->alt_body;
		}

		return $message->body;
	}

	/**
	 * Map a Message address list ({address,name}) to the {email,name} shape most
	 * JSON APIs expect.
	 *
	 * @param list<array{address:string, name:string}> $list
	 * @return list<array{email:string, name:string}>
	 */
	protected function map_emails( array $list ): array {
		$out = [];
		foreach ( $list as $row ) {
			$out[] = [
				'email' => (string) $row['address'],
				'name'  => (string) $row['name'],
			];
		}

		return $out;
	}

	/**
	 * Flatten a Message address list to bare email strings.
	 *
	 * @param list<array{address:string, name:string}> $list
	 * @return list<string>
	 */
	protected function map_addresses( array $list ): array {
		return array_values(
			array_filter(
				array_map( static fn ( array $r ): string => (string) $r['address'], $list )
			)
		);
	}

	/**
	 * From address as a single associative pair.
	 *
	 * @return array{email:string, name:string}
	 */
	protected function from( Message $message ): array {
		return [
			'email' => $message->from_email,
			'name'  => $message->from_name,
		];
	}

	/**
	 * Normalise PHPMailer attachment rows to a portable shape. Content is always
	 * base64-encoded. Unreadable file attachments are skipped.
	 *
	 * @return list<array{filename:string, type:string, content:string}>
	 */
	protected function attachments( Message $message ): array {
		$out = [];
		foreach ( $message->attachments as $row ) {
			$is_string = ! empty( $row[5] );
			$source    = (string) ( $row[0] ?? '' );
			$name      = (string) ( $row[2] ?? '' );
			if ( '' === $name ) {
				$name = (string) ( $row[1] ?? 'attachment' );
			}
			$type = (string) ( $row[4] ?? '' );

			if ( $is_string ) {
				$data = $source;
			} else {
				if ( '' === $source || ! is_readable( $source ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local attachment file, exactly as PHPMailer does.
				$data = (string) file_get_contents( $source );
				if ( '' === $data ) {
					continue;
				}
			}

			$out[] = [
				'filename' => '' !== $name ? $name : 'attachment',
				'type'     => '' !== $type ? $type : 'application/octet-stream',
				'content'  => base64_encode( $data ),
			];
		}

		return $out;
	}

	/**
	 * The first Reply-To address, or empty strings when none was set.
	 *
	 * @return array{email:string, name:string}
	 */
	protected function reply_to( Message $message ): array {
		$first = $message->reply_to[0] ?? null;
		if ( is_array( $first ) ) {
			return [
				'email' => (string) $first['address'],
				'name'  => (string) $first['name'],
			];
		}

		return [
			'email' => '',
			'name'  => '',
		];
	}
}
