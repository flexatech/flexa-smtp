<?php

declare(strict_types=1);

namespace Flexa\Smtp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Capability helpers. Extensions (e.g. the Pro build) can hook the filters
 * below to re-gate access per role.
 */
final class Capabilities {
	public const MANAGE   = 'manage_options';
	public const SETTINGS = 'manage_options';

	public static function can_manage(): bool {
		return current_user_can( apply_filters( 'flexa_smtp.capabilities.manage', self::MANAGE ) );
	}

	public static function can_manage_settings(): bool {
		return current_user_can( apply_filters( 'flexa_smtp.capabilities.settings', self::SETTINGS ) );
	}
}
