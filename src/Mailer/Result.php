<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer;

use Flexa\Smtp\Diagnostics\Diagnosis;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable outcome of a single send attempt — the plugin's normalised
 * DeliveryResult. Providers return this; {@see MailerManager} enriches it (timing,
 * diagnosis) and turns it into hooks + log rows.
 *
 * The four extra fields (response_code, provider_message_id, error_category,
 * retryable) are optional and default to null so the original success()/error()
 * factories stay backward compatible with existing providers.
 */
final class Result {
	/**
	 * @param array<string, mixed> $meta
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly ?string $error,
		public readonly array $meta,
		public readonly ?int $response_code = null,
		public readonly ?string $provider_message_id = null,
		public readonly ?string $error_category = null,
		public readonly ?bool $retryable = null,
	) {}

	/**
	 * @param array<string, mixed> $meta
	 */
	public static function success( array $meta = [], ?string $provider_message_id = null, ?int $response_code = null ): self {
		return new self( true, null, $meta, $response_code, $provider_message_id );
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	public static function error( ?string $error, array $meta = [], ?int $response_code = null ): self {
		return new self( false, '' !== (string) $error ? (string) $error : 'unknown error', $meta, $response_code );
	}

	/**
	 * Return a copy carrying the classification from a {@see Diagnosis}. Used by
	 * MailerManager after a failed attempt so downstream consumers (logging, the
	 * future retry engine) can read error_category + retryable off the Result.
	 */
	public function with_diagnosis( Diagnosis $diagnosis ): self {
		return new self(
			$this->ok,
			$this->error,
			$this->meta,
			$this->response_code,
			$this->provider_message_id,
			$diagnosis->category,
			$diagnosis->retryable,
		);
	}

	/**
	 * Return a copy stamped with transport-level facts discovered after a provider's
	 * interpret() ran: the HTTP/SMTP response code (authoritative, always applied
	 * when known) and a provider message id (applied only when found, never
	 * clobbering one interpret() already set).
	 */
	public function with_transport( ?int $code, ?string $message_id ): self {
		return new self(
			$this->ok,
			$this->error,
			$this->meta,
			$code ?? $this->response_code,
			$message_id ?? $this->provider_message_id,
			$this->error_category,
			$this->retryable,
		);
	}

	/**
	 * Return a copy with the response time recorded in meta. Non-destructive so the
	 * value object stays immutable.
	 */
	public function with_duration( int $duration_ms ): self {
		return new self(
			$this->ok,
			$this->error,
			[ 'duration_ms' => $duration_ms ] + $this->meta,
			$this->response_code,
			$this->provider_message_id,
			$this->error_category,
			$this->retryable,
		);
	}

	/**
	 * Flatten the first-class fields into the meta array for the send lifecycle
	 * hooks, so subscribers (e.g. MailLogger) read one consistent shape while older
	 * listeners keep seeing the legacy `mailer`/`code` keys.
	 *
	 * @return array<string, mixed>
	 */
	public function to_meta(): array {
		$meta = $this->meta;

		if ( null !== $this->response_code ) {
			$meta['response_code'] = $this->response_code;
		}
		if ( null !== $this->provider_message_id ) {
			$meta['provider_message_id'] = $this->provider_message_id;
		}
		if ( null !== $this->error_category ) {
			$meta['error_category'] = $this->error_category;
		}
		if ( null !== $this->retryable ) {
			$meta['retryable'] = $this->retryable;
		}

		return $meta;
	}
}
