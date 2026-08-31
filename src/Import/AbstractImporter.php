<?php

declare(strict_types=1);

namespace Flexa\Smtp\Import;

use Flexa\Smtp\Domain\ClickEventRepository;
use Flexa\Smtp\Domain\EmailLog;
use Flexa\Smtp\Domain\EmailLogRepository;
use Flexa\Smtp\Domain\OpenEventRepository;
use Flexa\Smtp\Import\Contracts\ImporterInterface;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
// Importers read foreign plugins' own tables/options; those table names are
// fixed literals, and every dynamic value passed to a query is bound with
// $wpdb->prepare(). Writes go through the Domain repositories, never raw SQL.

/**
 * Shared plumbing for every source importer: settings mapping onto Flexa's
 * schema, log/event insertion via the Domain repositories, and the mailer/
 * encryption translation tables. Concrete importers only describe where their
 * source keeps data and hand raw rows to these helpers.
 */
abstract class AbstractImporter implements ImporterInterface {
	/**
	 * Source mailer identifier => Flexa mailer slug. Anything not listed is left
	 * unchanged (we never point current_mailer at a mailer Flexa can't drive).
	 *
	 * @var array<string, string>
	 */
	protected const MAILER_MAP = [
		'mail'       => 'mail',
		'smtp'       => 'smtp',
		'sendgrid'   => 'sendgrid',
		'smtpcom'    => 'smtpcom',
		'sendinblue' => 'brevo',
		'brevo'      => 'brevo',
		'mailgun'    => 'mailgun',
		'amazonses'  => 'amazonses',
		'postmark'   => 'postmark',
		'sparkpost'  => 'sparkpost',
		'mailjet'    => 'mailjet',
		'gmail'      => 'gmail',
		'outlook'    => 'outlook',
		'zoho'       => 'zoho',
	];

	/**
	 * WP-Mail-SMTP-style provider sub-array => [ Flexa slug, source field => Flexa
	 * field ]. Used by the two importers whose option mirrors WP Mail SMTP's
	 * layout. Secrets are handed to Settings::save() in plaintext, which encrypts.
	 *
	 * @var array<string, array{slug:string, fields:array<string,string>, lower?:list<string>}>
	 */
	protected const PROVIDER_MAP = [
		'sendgrid'   => [
			'slug'   => 'sendgrid',
			'fields' => [ 'api_key' => 'api_key' ],
		],
		'smtpcom'    => [
			'slug'   => 'smtpcom',
			'fields' => [
				'api_key' => 'api_key',
				'channel' => 'channel',
			],
		],
		'sendinblue' => [
			'slug'   => 'brevo',
			'fields' => [ 'api_key' => 'api_key' ],
		],
		'mailgun'    => [
			'slug'   => 'mailgun',
			'fields' => [
				'api_key' => 'api_key',
				'domain'  => 'domain',
				'region'  => 'region',
			],
			'lower'  => [ 'region' ],
		],
		'amazonses'  => [
			'slug'   => 'amazonses',
			'fields' => [
				'client_id'     => 'access_key',
				'client_secret' => 'secret_key',
				'region'        => 'region',
			],
		],
		'postmark'   => [
			'slug'   => 'postmark',
			'fields' => [ 'server_api_token' => 'server_token' ],
		],
		'sparkpost'  => [
			'slug'   => 'sparkpost',
			'fields' => [
				'api_key' => 'api_key',
				'region'  => 'region',
			],
			'lower'  => [ 'region' ],
		],
		'mailjet'    => [
			'slug'   => 'mailjet',
			'fields' => [
				'api_key'    => 'api_key',
				'secret_key' => 'secret_key',
			],
		],
		'zoho'       => [
			'slug'   => 'zoho',
			'fields' => [
				'client_id'     => 'client_id',
				'client_secret' => 'client_secret',
			],
		],
		'gmail'      => [
			'slug'   => 'gmail',
			'fields' => [
				'client_id'     => 'client_id',
				'client_secret' => 'client_secret',
			],
		],
		'outlook'    => [
			'slug'   => 'outlook',
			'fields' => [
				'client_id'     => 'client_id',
				'client_secret' => 'client_secret',
			],
		],
	];

	protected function logs(): EmailLogRepository {
		return new EmailLogRepository();
	}

	protected function opens(): OpenEventRepository {
		return new OpenEventRepository();
	}

	protected function clicks(): ClickEventRepository {
		return new ClickEventRepository();
	}

	/**
	 * Persist a settings payload through the canonical sanitizer (encrypts
	 * secrets, drops unknown keys, merges over stored). Returns true when the
	 * payload carried anything to apply.
	 *
	 * @param array<string, mixed> $payload
	 */
	protected function apply_settings( array $payload ): bool {
		if ( [] === $payload ) {
			return false;
		}

		Settings::save( $payload );

		return true;
	}

	/**
	 * Map a source mailer identifier onto a Flexa slug, or '' if unknown.
	 */
	protected function map_mailer( string $source ): string {
		return self::MAILER_MAP[ $source ] ?? '';
	}

	/**
	 * Normalize an encryption value to Flexa's enum (none|ssl|tls).
	 */
	protected function map_encryption( mixed $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return in_array( $value, [ 'ssl', 'tls' ], true ) ? $value : 'none';
	}

	protected function to_bool( mixed $value ): bool {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, [ '', '0', 'false', 'no', 'off' ], true ) ) {
				return false;
			}
		}

		return (bool) $value;
	}

	protected function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal table name, no user input.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return is_string( $found ) && '' !== $found;
	}

	/**
	 * Map a WP-Mail-SMTP-style option array onto a Flexa settings payload. Shared
	 * by {@see WpMailSmtp} and {@see EasyWpSmtp} (Easy WP SMTP v2 mirrors the WP
	 * Mail SMTP option layout). Only keys actually present are emitted.
	 *
	 * @param array<string, mixed> $all
	 * @return array<string, mixed>
	 */
	protected function map_wpms_settings( array $all ): array {
		$payload = [];

		$mail = isset( $all['mail'] ) && is_array( $all['mail'] ) ? $all['mail'] : [];
		if ( isset( $mail['mailer'] ) && is_string( $mail['mailer'] ) ) {
			$slug = $this->map_mailer( $mail['mailer'] );
			if ( '' !== $slug ) {
				$payload['current_mailer'] = $slug;
			}
		}
		if ( ! empty( $mail['from_email'] ) && is_string( $mail['from_email'] ) ) {
			$payload['from_email'] = $mail['from_email'];
		}
		if ( ! empty( $mail['from_name'] ) && is_string( $mail['from_name'] ) ) {
			$payload['from_name'] = $mail['from_name'];
		}
		if ( array_key_exists( 'from_email_force', $mail ) ) {
			$payload['force_from_email'] = $this->to_bool( $mail['from_email_force'] );
		}
		if ( array_key_exists( 'from_name_force', $mail ) ) {
			$payload['force_from_name'] = $this->to_bool( $mail['from_name_force'] );
		}

		$mailers = [];

		$smtp = isset( $all['smtp'] ) && is_array( $all['smtp'] ) ? $all['smtp'] : [];
		if ( [] !== $smtp ) {
			$creds = [];
			if ( ! empty( $smtp['host'] ) && is_string( $smtp['host'] ) ) {
				$creds['host'] = $smtp['host'];
			}
			if ( ! empty( $smtp['port'] ) ) {
				$creds['port'] = (int) $smtp['port'];
			}
			if ( isset( $smtp['encryption'] ) ) {
				$creds['encryption'] = $this->map_encryption( $smtp['encryption'] );
			}
			if ( array_key_exists( 'auth', $smtp ) ) {
				$creds['auth'] = $this->to_bool( $smtp['auth'] );
			}
			if ( ! empty( $smtp['user'] ) && is_string( $smtp['user'] ) ) {
				$creds['user'] = $smtp['user'];
			}
			if ( ! empty( $smtp['pass'] ) && is_string( $smtp['pass'] ) ) {
				$creds['pass'] = $smtp['pass'];
			}
			if ( [] !== $creds ) {
				$mailers['smtp'] = $creds;
			}
		}

		foreach ( self::PROVIDER_MAP as $source_key => $spec ) {
			$sub = isset( $all[ $source_key ] ) && is_array( $all[ $source_key ] ) ? $all[ $source_key ] : [];
			if ( [] === $sub ) {
				continue;
			}
			$creds = [];
			foreach ( $spec['fields'] as $from => $to ) {
				if ( empty( $sub[ $from ] ) || ! is_scalar( $sub[ $from ] ) ) {
					continue;
				}
				$val = (string) $sub[ $from ];
				if ( in_array( $to, $spec['lower'] ?? [], true ) ) {
					$val = strtolower( $val );
				}
				$creds[ $to ] = $val;
			}
			if ( [] !== $creds ) {
				$mailers[ $spec['slug'] ] = $creds;
			}
		}

		if ( [] !== $mailers ) {
			$payload['mailers'] = $mailers;
		}

		return $payload;
	}

	/**
	 * Import logs from a WP-Mail-SMTP-style pair of tables (an emails-log table
	 * plus an optional tracking-events table). Returns the row count imported.
	 */
	protected function import_wpms_logs( string $logs_table, string $events_table ): int {
		global $wpdb;

		if ( ! $this->table_exists( $logs_table ) ) {
			return 0;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $logs_table ), ARRAY_A );
		if ( ! is_array( $rows ) || [] === $rows ) {
			return 0;
		}

		$has_events = '' !== $events_table && $this->table_exists( $events_table );
		$count      = 0;

		foreach ( $rows as $row ) {
			$people = isset( $row['people'] ) ? $this->decode_people( $row['people'] ) : [
				'from' => '',
				'to'   => [],
			];
			$html   = isset( $row['content_html'] ) && '' !== (string) $row['content_html'];
			$mailer = $this->map_mailer( (string) ( $row['mailer'] ?? 'smtp' ) );

			$log_id = $this->logs()->create(
				[
					'subject'      => (string) ( $row['subject'] ?? '' ),
					'email_from'   => $people['from'],
					'email_to'     => $people['to'],
					'mailer'       => '' !== $mailer ? $mailer : (string) ( $row['mailer'] ?? '' ),
					'status'       => (int) ( $row['status'] ?? 0 ) !== 0 ? EmailLog::STATUS_SENT : EmailLog::STATUS_FAILED,
					'content_type' => $html ? 'text/html' : 'text/plain',
					'body_content' => $html ? (string) $row['content_html'] : (string) ( $row['content_plain'] ?? '' ),
					'reason_error' => (string) ( $row['error_text'] ?? '' ),
					'source'       => (string) ( $row['initiator_name'] ?? '' ),
					'extra_info'   => [ 'imported_from' => $this->slug() ],
					'date_time'    => (string) ( $row['date_sent'] ?? current_time( 'mysql' ) ),
				]
			);

			if ( $log_id <= 0 ) {
				continue;
			}
			++$count;

			if ( $has_events && isset( $row['id'] ) ) {
				$this->import_wpms_events( $events_table, (int) $row['id'], $log_id );
			}
		}

		return $count;
	}

	/**
	 * Copy the open/click flags for one source log id onto its new Flexa log.
	 */
	private function import_wpms_events( string $events_table, int $source_log_id, int $log_id ): void {
		global $wpdb;

		$opened = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email_log_id = %d AND event_type = %s', $events_table, $source_log_id, 'open-email' ) );
		if ( (int) $opened > 0 ) {
			$this->opens()->record( $log_id, [ 'imported_from' => $this->slug() ] );
		}

		$clicked = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email_log_id = %d AND event_type = %s', $events_table, $source_log_id, 'click-link' ) );
		if ( (int) $clicked > 0 ) {
			$this->clicks()->record( $log_id, '', [ 'imported_from' => $this->slug() ] );
		}
	}

	/**
	 * Decode WP Mail SMTP's `people` JSON ({from:[[email,name]], to:[[email,name]]})
	 * into a from-address string and a Flexa recipient list.
	 *
	 * @return array{from:string, to:list<array{address:string, name:string}>}
	 */
	protected function decode_people( mixed $people ): array {
		$decoded = is_string( $people ) ? json_decode( $people, true ) : ( is_array( $people ) ? $people : [] );
		if ( ! is_array( $decoded ) ) {
			$decoded = [];
		}

		$from_raw = isset( $decoded['from'] ) && is_array( $decoded['from'] ) ? $decoded['from'] : [];
		$from     = '';
		foreach ( $from_raw as $entry ) {
			$pair = $this->recipient_pair( $entry );
			if ( '' !== $pair['address'] ) {
				$from = $pair['address'];
				break;
			}
		}

		$to_raw = isset( $decoded['to'] ) && is_array( $decoded['to'] ) ? $decoded['to'] : [];
		$to     = [];
		foreach ( $to_raw as $entry ) {
			$pair = $this->recipient_pair( $entry );
			if ( '' !== $pair['address'] ) {
				$to[] = $pair;
			}
		}

		return [
			'from' => $from,
			'to'   => $to,
		];
	}

	/**
	 * @return array{address:string, name:string}
	 */
	private function recipient_pair( mixed $entry ): array {
		if ( is_array( $entry ) ) {
			$address = (string) ( $entry[0] ?? $entry['address'] ?? '' );
			$name    = (string) ( $entry[1] ?? $entry['name'] ?? '' );
		} else {
			$address = is_string( $entry ) ? $entry : '';
			$name    = '';
		}

		return [
			'address' => sanitize_email( $address ),
			'name'    => sanitize_text_field( $name ),
		];
	}

	/**
	 * Extract the first email address found in a free-form header string.
	 *
	 * @return list<array{address:string, name:string}>
	 */
	protected function emails_from_string( string $string ): array {
		if ( ! preg_match_all( '/[._a-zA-Z0-9-]+@[._a-zA-Z0-9-]+/', $string, $matches ) ) {
			return [];
		}

		return array_map(
			static fn ( string $email ): array => [
				'address' => sanitize_email( $email ),
				'name'    => '',
			],
			$matches[0]
		);
	}
}
