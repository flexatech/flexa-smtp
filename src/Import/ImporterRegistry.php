<?php

declare(strict_types=1);

namespace Flexa\Smtp\Import;

use Flexa\Smtp\Concerns\HasInstance;
use Flexa\Smtp\Import\Contracts\ImporterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The catalog of migration sources. Endpoint, admin notice, and CLI all resolve
 * importers through here so the supported-source list lives in one place.
 */
final class ImporterRegistry {
	use HasInstance;

	/**
	 * Option storing the slugs whose logs have already been imported, so a second
	 * run doesn't duplicate rows unless the caller explicitly forces it.
	 */
	public const IMPORTED_LOGS_OPTION = 'flexa_smtp_imported_log_sources';

	/**
	 * @var list<class-string<ImporterInterface>>
	 */
	private const IMPORTERS = [
		WpMailSmtp::class,
		EasyWpSmtp::class,
		SmtpMailer::class,
		WpSmtp::class,
		Mailbank::class,
	];

	/**
	 * @var array<string, ImporterInterface>|null
	 */
	private ?array $cache = null;

	/**
	 * All importers keyed by slug.
	 *
	 * @return array<string, ImporterInterface>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$this->cache = [];
			foreach ( self::IMPORTERS as $class ) {
				$importer                         = new $class();
				$this->cache[ $importer->slug() ] = $importer;
			}
		}

		return $this->cache;
	}

	public function get( string $slug ): ?ImporterInterface {
		return $this->all()[ $slug ] ?? null;
	}

	/**
	 * Importers whose source has data on this site.
	 *
	 * @return array<string, ImporterInterface>
	 */
	public function available(): array {
		return array_filter( $this->all(), static fn ( ImporterInterface $i ): bool => $i->is_available() );
	}

	/**
	 * Slugs whose logs have already been imported.
	 *
	 * @return list<string>
	 */
	public function imported_log_sources(): array {
		$stored = get_option( self::IMPORTED_LOGS_OPTION, [] );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : [];
	}

	public function mark_logs_imported( string $slug ): void {
		$stored = $this->imported_log_sources();
		if ( ! in_array( $slug, $stored, true ) ) {
			$stored[] = $slug;
			update_option( self::IMPORTED_LOGS_OPTION, $stored );
		}
	}
}
