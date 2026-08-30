# Flexa SMTP — Code Style & UI Guide

> Derived from the canonical Flexa lineage plugins (**flexa-cache**,
> **flexa-media-folders-pro**). Authoritative style/UI spec for flexa-smtp; the
> phased build (`docs/IMPLEMENTATION_PLAN.md`) targets it. All examples use
> flexa-smtp's naming already substituted (§6). Copy patterns verbatim.

## 1. Lineage — new (de-branded) generation

flexa-smtp uses the **newer generation** of the Flexa scaffold (chosen 2026-08-30):
`Plugin::boot()`, `Concerns\HasInstance` (`::instance()`), `Api\Router`,
`Api\Endpoint`, `Support\Settings`, `Support\Capabilities`, `Maintenance\Eraser`,
`Database\Schema`, `Setup\Activator`/`Setup\Deactivator`, the `define()` constant
block, and (from WP9) the Vite/Tailwind/TanStack stack under `client/`.

It does NOT use the canonical names (`Rest\RegisterFacade`, `Support\SingletonTrait`,
`Install\Migrator`). Hooks are **dot-separated** (`flexa_smtp.settings.updated`),
not slash. `templates/` (not `views/`), `client/` (not `apps/admin/`).

## 2. PHP conventions

| Aspect | Rule |
|---|---|
| Open tag | `<?php`, blank line, `declare(strict_types=1);` |
| Guard | `defined( 'ABSPATH' ) || exit;` after namespace+uses, before class. `uninstall.php` uses `WP_UNINSTALL_PLUGIN` |
| Namespace | one class per file, PSR-4, `Flexa\Smtp\` → `src/` (Pro: `Flexa\SmtpPro\` → `src-pro/`) |
| Classes | every concrete class `final`; bases `abstract`; services `use HasInstance` (`::instance()`) |
| Properties | all typed; value objects `public readonly` w/ constructor promotion |
| Methods | explicit return + param types (phpstan L6); `unset( $request );` to silence required-but-unused params |
| Docblocks | **sparse** — only for non-trivial logic or array shapes (`@param array<string,mixed>`, `@return list<array{...}>`); file/class docblocks usually omitted |
| Constants | `UPPER_SNAKE`; bootstrap `define()` block `FLEXA_SMTP_*` (`VERSION`, `FILE`, `PATH`, `URL`, `BASENAME`, `REST_NAMESPACE`, `TEXT_DOMAIN`) |
| Hooks | dot-separated: `flexa_smtp.rest.register_routes`, `flexa_smtp.settings.updated`, `flexa_smtp.data_reset`, `flexa_smtp.capabilities.*`, `flexa_smtp.mail.before_send`, `flexa_smtp.mail.sent`, `flexa_smtp.mail.failed`, `flexa_smtp.pro.is_licensed` |
| Options | `flexa_smtp_*`; the ONE settings option is `flexa_smtp_settings` |
| REST | namespace `flexa-smtp/v1` via `Api\Endpoint::NAMESPACE`; **every route has `permission_callback`**; per-arg `sanitize_callback`; return `WP_REST_Response\|WP_Error` with explicit status; endpoints registered in `Api\Router::register_routes()` which ends with `do_action( 'flexa_smtp.rest.register_routes' )` |
| Permissions | via `Support\Capabilities` (`can_manage()` = `manage_options`, `can_manage_settings()` = `manage_options`), each wrapped in a `flexa_smtp.capabilities.*` filter |
| Secrets | provider API keys / SMTP passwords encrypted via `Support\Encryption` (AES-256-GCM, authenticated) before persisting into `flexa_smtp_settings` — never store plaintext |
| DB | all SQL in repositories under `Domain/`; `$wpdb->prepare()` always; class-level `// phpcs:disable WordPress.DB...` with a why-comment, on data-access classes only |
| Domain | value object: `public readonly` props + `public static function from_row( array $row ): self` (named args, `(int)`/`(string)` coercion, `?? default`) + `to_array()` |
| Install | `Setup\Activator::activate()` runs `Database\Schema::migrate()` (guarded by `class_exists`) then seeds the option; `Database\Schema` has `DB_VERSION` + `maybe_upgrade()` on `admin_init` via `dbDelta()`; every table listed in `Database\Schema::TABLES` |
| Destructive | one `Maintenance\Eraser::erase_all()` used by BOTH the REST danger-zone and WP-CLI; fires `flexa_smtp.data_reset` |
| CLI | `Cli\*Command::register()` (guard `class_exists( WP_CLI::class )`); public methods are commands; `@when after_wp_load`; format via `\WP_CLI\Utils\format_items()` |
| Tooling | phpstan **level 6** clean (`--memory-limit=1G`); phpcs parity is a release-gate concern, reconcile codebase-wide before release |

## 3. TypeScript / React conventions (from WP9 — see flexa-plugin-ui)

- Strict TS, no `any`; ambient types in `client/src/types/`.
- **Server state → TanStack Query**, **UI/ephemeral → Zustand**. Never mix.
- Query keys arrays, resource-first: `["settings"]`, `["logs",{filter,page}]`, `["stats",{range}]`.
- Mutations: optimistic `onMutate` → `onError` rollback → `onSettled` invalidate.
- i18n: `__`, `_x`, `_n`, `sprintf` from `@/lib/i18n` (text domain `flexa-smtp`), **string literals only**.
- `cn()` from `@/lib/cn`; every Tailwind class carries the `fs:` prefix.
- shadcn primitives hand-vendored in `components/ui/` (no CLI dep).
- Every mount wrapped in `<AppProviders>` (module-singleton `queryClient` + theme). Root id `flexa-smtp-admin-root`. JS global `window.flexaSmtp`.

## 4. Mailer provider contract (WP2+)

Every provider implements `Flexa\Smtp\Mailer\Contracts\MailerInterface`
(`slug()`, `is_configured()`, `send( Message ): Result`) and registers itself in
`Mailer\MailerRegistry`. Providers **never** write logs — `Mailer\MailerManager`
owns the log lifecycle so success/failure paths cannot drift.

## 5. Toolchain debt (known)

- phpstan: always `--memory-limit=1G`; `assets/dist (?)` in `excludePaths` (dir absent pre-build).
- WP stubs: use `(array) $request->get_json_params()`; write a local `to_bool()` (already in `Support\Settings`) instead of `rest_sanitize_boolean()`.
- phpcs `WordPress` standard has scaffold-wide debt — match the existing style, reconcile once before a release tag; do not reformat only your files.

## 6. Substitution mapping

slug/text-domain `flexa-smtp` · namespace `Flexa\Smtp\` · const `FLEXA_SMTP_` ·
REST `flexa-smtp/v1` · hook prefix `flexa_smtp.` · option `flexa_smtp_settings` ·
JS global `flexaSmtp` · mount `flexa-smtp-admin-root` · tw prefix `fs:` ·
zustand key `flexa-smtp:ui`.
