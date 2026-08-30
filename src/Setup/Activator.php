<?php

declare(strict_types=1);

namespace Flexa\Smtp\Setup;

use Flexa\Smtp\Database\Schema;
use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Activator {
	public static function activate(): void {
		if ( class_exists( Schema::class ) ) {
			Schema::migrate();
		}

		if ( get_option( Settings::OPTION_KEY, null ) === null ) {
			add_option( Settings::OPTION_KEY, Settings::defaults() );
		}
	}
}
