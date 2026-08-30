<?php

declare(strict_types=1);

namespace Flexa\Smtp\Mailer\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A mailer that declares its own credential fields, so the provider catalog can
 * merge them into {@see \Flexa\Smtp\Support\Settings::mailer_schema()} without
 * the core Settings class knowing about any specific provider.
 */
interface ProvidesCredentialSchema {
	/**
	 * Credential field definitions for this mailer, in the shape Settings uses:
	 * field => ['type' => 'string'|'int'|'bool'|'enum', 'secret'? => bool, 'enum'? => list<string>].
	 *
	 * @return array<string, array{type:string, secret?:bool, enum?:list<string>}>
	 */
	public static function credential_schema(): array;
}
