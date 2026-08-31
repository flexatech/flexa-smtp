# Flexa SMTP — Implementation Plan (phased, core-first)

> Kế hoạch xây dựng `flexa-smtp` — plugin WP Mail SMTP + Email Logs + Tracking +
> Reports, tái thiết kế từ phân tích YaySMTP 2.7.6 theo **kiến trúc Flexa lineage
> (generation mới, de-branded)**.
>
> Tài liệu này là nguồn sự thật cho việc build. Style/UI bám theo
> `flexa-plugin-conventions` + `flexa-plugin-ui`. Trước mỗi release chạy
> `wp-plugin-review`.

---

## 0. Substitution vocabulary (đã chốt — không được drift)

| Token | Giá trị |
|---|---|
| `<plugin-slug>` / text-domain | `flexa-smtp` |
| PHP namespace | `Flexa\Smtp\` → `src/` (Pro: `Flexa\SmtpPro\` → `src-pro/`) |
| `<CONST>` prefix | `FLEXA_SMTP_` — `_VERSION/_FILE/_PATH/_URL/_BASENAME/_REST_NAMESPACE/_TEXT_DOMAIN` |
| REST namespace | `flexa-smtp/v1` |
| Hook prefix | `flexa_smtp.` (**dot-separated** — generation mới) |
| Option prefix | `flexa_smtp_` (option cấu hình duy nhất: `flexa_smtp_settings`) |
| JS global | `flexaSmtp` (`window.flexaSmtp`) |
| React mount id | `flexa-smtp-admin-root` |
| Tailwind prefix | `fs:` |
| Zustand persist key | `flexa-smtp:ui` |

### Generation mới (de-branded) — mapping tên so với canonical
- `Rest\RegisterFacade` → **`Api\Router`**
- `Rest\BaseRestController` → **`Api\Endpoint`**
- `Support\SingletonTrait` → **`Concerns\HasInstance`**
- `Support\Resetter::reset_all()` → **`Maintenance\Eraser::erase_all()`**
- `Install\Migrator` → **`Database\Schema`**
- `Install\Activator` → **`Setup\Activator`**
- Hooks **dot-separated**: `flexa_smtp.settings.updated` (không dùng slash)
- `views/` → **`templates/`**, `apps/admin/` → **`client/`**

---

## 1. Nguyên tắc bất biến (mọi WP code phải theo)

**PHP**
- `<?php` · dòng trắng · `declare(strict_types=1);`
- `defined( 'ABSPATH' ) || exit;` sau `namespace`+`use`, trước class. `uninstall.php` dùng `WP_UNINSTALL_PLUGIN`.
- Class cụ thể `final`, base `abstract`; service stateless `use Concerns\HasInstance` truy cập qua `::instance()`.
- Mọi property có type; value object `public readonly` + constructor promotion.
- Method luôn có type param + return; `unset( $request );` để câm param interface bắt buộc-mà-không-dùng.
- Docblock **thưa** — chỉ cho logic khó hoặc array shape (`@param array<string,mixed>`).
- **Mọi SQL nằm trong `Domain/*Repository`**, luôn `$wpdb->prepare()`; `// phpcs:disable WordPress.DB...` scoped kèm lý do.
- Value object: `public readonly` + `from_row(array): self` (named args, ép `(int)/(string)`, `?? default`) + `to_array()` (bắn hook mở rộng, trả mảng JS-friendly).
- Repo: `find(): ?T`, `all(): array`, `create(): int`, `update(): bool`, `delete(): int`, `exists(): bool`.
- **REST**: namespace qua `Api\Endpoint::NAMESPACE`; **mọi route có `permission_callback` thật** + per-arg `sanitize_callback`; trả `WP_REST_Response|WP_Error` kèm status; đăng ký **mọi** controller trong `Api\Router::register_routes()` (kết bằng `do_action('flexa_smtp.rest.register_routes')`). Controller không add vào Router = chết thầm.
- **Settings**: một option `flexa_smtp_settings`, schema/defaults/sanitizer trong `Support\Settings` (typed `defaults()`, type-coercing `all()/get()`, `sanitize()` drop key lạ). Update **merge-over-stored** (partial update), bắn `flexa_smtp.settings.updated` ($new,$old).
- **Permissions**: chỉ qua `Support\Capabilities` (`can_manage()`≈`manage_options` cho SMTP settings), mỗi cái bọc filter `flexa_smtp.capabilities.*`.
- **Destructive**: chỉ một `Maintenance\Eraser::erase_all()` dùng chung REST danger-zone + WP-CLI; bắn `flexa_smtp.data_reset`.
- **Install**: `Setup\Activator::activate()` → `Database\Schema::migrate()` (guard `class_exists`) rồi seed option = `Support\Settings::defaults()`; `Database\Schema` có `DB_VERSION` + `maybe_upgrade()` trên `admin_init` qua `dbDelta()`.
- **CLI**: `Cli\*Command::register()` (guard `class_exists(WP_CLI::class)`); method public = command; `@when after_wp_load`; format bằng `\WP_CLI\Utils\format_items()`.
- **phpstan level 6 sạch** (`--memory-limit=1G`); phpcs parity là release-gate, không block per-file.

**TS/React** (chi tiết ở `flexa-plugin-ui`)
- Strict TS, không `any`. Server state → TanStack Query; UI/ephemeral → Zustand (không trộn).
- Query key mảng resource-first: `["settings"]`, `["logs",{filter,page}]`, `["stats",{range}]`.
- Mutation optimistic: `onMutate` → `onError` rollback → `onSettled` invalidate.
- i18n qua `@/lib/i18n` (text-domain `flexa-smtp`), **literal string** để makepot trích tĩnh.
- `cn()` từ `@/lib/cn`; mọi class Tailwind mang prefix `fs:`. shadcn hand-vendored trong `components/ui/`.
- Mọi mount bọc `<AppProviders>` (queryClient singleton + theme); root id `flexa-smtp-admin-root`.

---

## 2. Cây thư mục đích (`src/`)

```
flexa-smtp.php                 # bootstrap: define(), autoloader fallback, boot
uninstall.php                  # WP_UNINSTALL_PLUGIN guard → Maintenance\Eraser
readme.txt
src/
├─ Plugin.php                  # Plugin::boot() — wire mọi thứ
├─ Concerns/HasInstance.php
├─ Support/
│  ├─ Settings.php             # option flexa_smtp_settings (schema/defaults/sanitize/merge)
│  ├─ Capabilities.php
│  ├─ Encryption.php           # AES-256-GCM (authenticated) cho secret/password
│  └─ Logger.php               # ghi lỗi nội bộ
├─ Maintenance/Eraser.php      # erase_all() — path xoá dữ liệu duy nhất
├─ Setup/Activator.php  Setup/Deactivator.php
├─ Database/Schema.php         # DB_VERSION, TABLES, migrate(), maybe_upgrade()
├─ Mailer/
│  ├─ Contracts/MailerInterface.php   # send(Message): Result
│  ├─ Message.php  Result.php          # value objects readonly
│  ├─ MailerRegistry.php               # slug → factory (thay getSMTPerObj)
│  ├─ MailerManager.php                # hook phpmailer_init, from-override, fallback, dev-mode
│  ├─ PhpMailerBridge.php              # subclass PHPMailer, route send()
│  └─ Providers/
│     ├─ SmtpMailer.php  NativeMailer.php
│     ├─ Api/  SendGrid Mailgun Brevo AmazonSes Postmark Mailjet
│     │        SparkPost SmtpCom PepiPost SendPulse Mandrill Yournotify Ionos
│     └─ OAuth/ Gmail Outlook Zoho  (+ OAuth token store)
├─ Domain/
│  ├─ EmailLog.php  EmailLogRepository.php
│  ├─ OpenEvent.php OpenEventRepository.php
│  └─ ClickEvent.php ClickEventRepository.php
├─ Tracking/
│  ├─ PixelInjector.php  LinkRewriter.php  TokenSigner.php
├─ Reports/ Scheduler.php  WeeklyReport.php  MonthlyReport.php  Retention.php
├─ Import/  Contracts/ImporterInterface.php + WpMailSmtp EasyWpSmtp SmtpMailer WpSmtp Mailbank
├─ Api/     Router.php  Endpoint.php
│           SettingsEndpoint.php  LogsEndpoint.php  TrackingEndpoint.php
│           TestMailEndpoint.php  ImportEndpoint.php  StatsEndpoint.php  DangerZoneEndpoint.php
├─ Admin/   Menu.php  Enqueue.php  DashboardWidget.php
├─ Cli/     LogsCommand.php  SendTestCommand.php  ResetCommand.php
templates/  (email report + tracking templates)
client/     (React admin app — theo flexa-plugin-ui)
assets/dist/ (bundle build, tracked trong git)
```

---

## 3. Dependency graph (thứ tự build)

```
WP0 (Foundation) ──► WP1 (Settings) ──► WP2 (Mailer core) ──► WP3 (Providers API)
                                   │                      └─► WP4 (OAuth providers)
                                   └─► WP5 (Logs) ──► WP6 (Tracking) ──► WP7 (Reports)
                                                 └─► WP8 (Import) 
WP9 (Admin UI) song song sau WP1  ·  WP10 (CLI) sau WP5  ·  WP11 (Release) cuối
```

---

## 4. Work packages

| WP | Tên | Nội dung | Phụ thuộc | DoD |
|----|-----|----------|-----------|-----|
| **WP0** | Foundation | `flexa-smtp.php` bootstrap (define block, autoloader fallback), `Plugin::boot()`, `Concerns\HasInstance`, `Api\Endpoint` base + `Api\Router` rỗng, `Setup\Activator/Deactivator`, `Database\Schema` (khung DB_VERSION), `.gitignore`/`.distignore`/`release.sh`, `docs/CODE_STYLE_AND_UI.md`. | — | Plugin activate không lỗi, phpstan L6 sạch |
| **WP1** | Settings | `Support\Settings` (defaults typed, sanitize merge), `Support\Capabilities`, `Support\Encryption` (AES-256-GCM), `SettingsEndpoint` (GET/POST partial-merge), seed option lúc activate. | WP0 | Lưu/đọc settings qua REST, secret mã hoá, `flexa_smtp.settings.updated` bắn |
| **WP2** | Mailer core | `MailerInterface/Message/Result`, `MailerRegistry`, `MailerManager` (hook `phpmailer_init`, `wp_mail_from`, `wp_mail_from_name`, force-from, fallback, disable-delivery dev-mode), `PhpMailerBridge`, `SmtpMailer` + `NativeMailer`, `TestMailEndpoint`. | WP1 | Gửi thật qua custom SMTP + test email từ UI |
| **WP3** | Providers API (12) | SendGrid, Mailgun, Brevo, Amazon SES, Postmark, Mailjet, SparkPost, SMTP.com, PepiPost, SendPulse, Mandrill, Yournotify, IONOS — mỗi provider 1 class impl `MailerInterface`, đăng ký vào `MailerRegistry`. Vendor HTTP nặng tách bundle riêng. | WP2 | Mỗi provider gửi + test pass; zip vẫn build |
| **WP4** | OAuth providers (3) | Gmail, Outlook/365, Zoho: OAuth2 flow (authorize/callback), token store mã hoá, auto-refresh. Cân nhắc tách vendor Google/Microsoft ra bundle optional. | WP2 | Kết nối OAuth + gửi + refresh token |
| **WP5** | Email Logs | `Database\Schema` 3 bảng, `EmailLog/OpenEvent/ClickEvent` VO + Repository (`$wpdb->prepare`), ghi log trong `MailerManager` (before/after send), nguồn phát sinh (an toàn, không lộ path), `LogsEndpoint` (list/detail/filter/search), CSV export, retention cron. | WP2 | Log ghi đúng, list/filter/export hoạt động, XSS-safe render |
| **WP6** | Tracking | `PixelInjector` (1x1), `LinkRewriter` (DOMDocument), `TokenSigner` (HMAC) — **endpoint tracking ký token, KHÔNG `__return_true`**, `TrackingEndpoint` cập nhật open/click. | WP5 | Open/click ghi nhận, endpoint từ chối token sai |
| **WP7** | Reports + Stats | `Scheduler` (weekly/monthly cron), `WeeklyReport/MonthlyReport` (template email), `Retention` (xoá log cũ), `StatsEndpoint` + `DashboardWidget` (charts). | WP5 | Digest gửi đúng lịch, chart hiển thị |
| **WP8** | Import | `ImporterInterface` + WP Mail SMTP, Easy WP SMTP, SMTP Mailer, WP SMTP, Mailbank (settings **và** logs), `ImportEndpoint` + admin notice phát hiện. | WP1, WP5 | Import settings+logs từ ≥1 nguồn |
| **WP9** | Admin UI | React app theo `flexa-plugin-ui`: tabs Mailer / Email Logs / Tracking / Reports / Additional / Danger-zone. TanStack Query + Zustand. | WP1+ (theo endpoint) | UI build, mọi tab wired với REST |
| **WP10** | CLI | `LogsCommand` (list filter status/date, format table/csv/json), `SendTestCommand`, `ResetCommand` (gọi `Eraser`). | WP5 | `wp flexa-smtp log list` chạy |
| **WP11** | Hardening & Release | `Maintenance\Eraser` + `DangerZoneEndpoint`, `wp-plugin-review`, regenerate `.pot`, phpcs reconciliation, `readme.txt`, build `release.sh`. | tất cả | Zip sạch (no `*.md`/dev files), review pass |

---

## 5. Contracts bất biến (WP0 tạo file, mọi WP theo)

### 5.1 Mailer
```php
interface MailerInterface {
    public function slug(): string;
    public function is_configured(): bool;
    public function send( Message $message ): Result;   // Result::success()|Result::error(string,?array)
}
```
`Message` = readonly VO (to/from/subject/body/headers/attachments/content_type).
`Result` = readonly VO (bool $ok, ?string $error, array $meta). Provider **không**
tự ghi log — `MailerManager` sở hữu vòng đời log để hai path (thành công/lỗi) không drift.

### 5.2 REST (`flexa-smtp/v1`)
| Method | Route | Endpoint | Cap |
|---|---|---|---|
| GET/POST | `/settings` | SettingsEndpoint | `can_manage()` |
| POST | `/test-mail` | TestMailEndpoint | `can_manage()` |
| GET | `/logs` · GET `/logs/{id}` · GET `/logs/export` | LogsEndpoint | `can_manage()` |
| GET | `/stats` | StatsEndpoint | `can_manage()` |
| GET | `/track/{type}` (open/click) | TrackingEndpoint | **token HMAC** (public + verify) |
| POST | `/import` | ImportEndpoint | `can_manage()` |
| POST | `/danger-zone/reset` | DangerZoneEndpoint | `can_manage()` |
| OAuth | `/oauth/{provider}/callback` | (WP4) | nonce + cap |

### 5.3 DB schema (`Database\Schema::TABLES`)
- `{prefix}flexa_smtp_email_logs` — id, subject, email_from, email_to (serialized), mailer, date_time, status (tinyint), content_type, body_content (longtext), reason_error, source, extra_info (json), flag_delete.
- `{prefix}flexa_smtp_open_events` — id, log_id (idx), count, date_time, extra_info.
- `{prefix}flexa_smtp_click_events` — id, log_id (idx), url, count, date_time, extra_info.

### 5.4 Hooks mở rộng (dot-separated)
`flexa_smtp.rest.register_routes` · `flexa_smtp.settings.updated` · `flexa_smtp.data_reset`
· `flexa_smtp.capabilities.*` · `flexa_smtp.settings.mailer_schema` · `flexa_smtp.mailers.register`
· `flexa_smtp.mail.before_send` · `flexa_smtp.mail.sent` · `flexa_smtp.mail.failed`
· `flexa_smtp.log.to_array` · `flexa_smtp.logs.purged`
· `flexa_smtp.pro.is_licensed`

---

## 6. Cải tiến chủ đích so với YaySMTP
1. **Tracking endpoint ký HMAC token** thay `__return_true` (YaySMTP để public).
2. **AES-256-GCM** (authenticated) thay CBC cho secret/password.
3. Prepared statement + escaping kỷ luật từ đầu — vùng log body là nơi YaySMTP từng dính **SQLi (2.6.6)** và nhiều **XSS**.
4. Provider theo `MailerInterface` → thêm mailer không đụng core.
5. Vendor OAuth/SES nặng tách bundle optional để giữ zip nhẹ.
6. Pro split kiểu Flexa (`Flexa\SmtpPro\` → `src-pro/`, gate bằng file-absence + `flexa_smtp.pro.is_licensed`) — không ship code disabled.

---

## 7. Toolchain debt đã biết (đọc trước khi build)
- phpstan mặc định 256M → OOM; luôn `--memory-limit=1G`. `phpstan.neon` exclude `assets/dist` bằng `(?)` vì thư mục chưa tồn tại trước build.
- WP stubs: `get_json_params()` type `array` → dùng `(array) $request->get_json_params()`; `rest_sanitize_boolean()` khó resolve từ `mixed` → viết `to_bool()` local.
- phpcs `WordPress` standard có debt scaffold-wide (doc comment, slash/dot-hook warning). **Khớp style scaffold hiện có**, reconcile toàn codebase một lần trước release, không reformat riêng file mình.
- `release.sh`: bỏ bước `composer install --no-dev` nếu `require` chỉ có `php` (vendor bị exclude khỏi zip). Guard trên `assets/dist/.vite/manifest.json`, bail nếu thiếu.
- WP.org SVN release build từ **zip của release.sh**, không copy từ working tree. Trước `svn commit`: `find trunk -name "*.md" -o -name "*.sh" -o -name "composer.*"` phải zero (chỉ `readme.txt` được phép).

---

## 8. Progress tracker

| WP | Trạng thái | Ghi chú |
|----|-----------|---------|
| WP0 Foundation | ☑ xong | Bootstrap, Plugin::boot, Api\Router/Endpoint, Concerns\HasInstance, Setup\Activator/Deactivator, Database\Schema (3 bảng), Support\Settings (foundation) + Capabilities, Maintenance\Eraser, uninstall.php, packaging (.gitignore/.distignore/release.sh), toolchain (composer/phpstan), docs. Mọi PHP `php -l` sạch. Live-activate chưa verify được do shell không truy cập MySQL của Local. |
| WP1 Settings | ☑ xong | Support\Encryption (AES-256-GCM, tamper-reject), Support\Settings full (mailer_schema + filter, raw/all/for_rest/mailer/save/sanitize, secret encrypt + mask, partial deep-merge), Api\SettingsEndpoint (GET masked / POST partial-merge) đăng ký trong Router. Verified live trên Local: encryption round-trip, secret lưu ciphertext, mask ở REST, decrypt cho mailer, MASK giữ secret, unknown key/mailer bị drop, route /flexa-smtp/v1/settings đăng ký. Plugin đang active. |
| WP2 Mailer core | ☑ xong | `Mailer\Contracts\MailerInterface` + `ConfiguresPhpMailer`, `Message`/`Result` readonly VO, `MailerRegistry` (seed mail+smtp, hook `flexa_smtp.mailers.register`), `PhpMailerBridge` (subclass PHPMailer, overrides send→manager), `MailerManager` (install bridge as global `$phpmailer` @ plugins_loaded:100, `wp_mail_from`/`wp_mail_from_name` force+non-forced-default overrides, `dispatch()` = dev-mode short-circuit + primary→fallback chain dedup + before/sent/failed hooks + throw-on-all-fail), `Providers\SmtpMailer` (isSMTP + host/port/ssl-tls/auth from decrypted creds) + `NativeMailer` (isMail), `Api\TestMailEndpoint` (POST /test-mail via wp_mail, captures wp_mail_failed) registered in Router; MailerManager booted in Plugin. Verified live (33 checks Y): bridge installed, registry, from-overrides forced/non-forced, smtp/native configure, chain build+dedup, Message::from_phpmailer, disable_delivery dev-mode, fallback fail→ok w/ correct hook order+meta, all-fail throws, routes registered. phpstan L6 still pending (needs composer install). |
| WP3 Providers API | ☑ xong | `Mailer\Contracts\ProvidesCredentialSchema` (mỗi provider tự khai field creds). `Mailer\Providers\AbstractApiMailer` (base: `send()` build payload → `wp_remote_request` → `interpret()`; helpers html/text, from, map_emails/addresses, attachments base64, reply_to; `extract_error` đọc message/error/errors). **13 provider**: JSON — SendGrid, PepiPost (personalizations), Brevo, Postmark (ErrorCode≠0→fail), Mailjet (Basic), SparkPost (region us/eu, Authorization=key), SMTP.com (channel), Mandrill (key trong body, parse per-recipient rejected/invalid), Yournotify; form — Mailgun (x-www-form-urlencoded, Basic api:key, domain trong URL, region us/eu); SigV4 — Amazon SES v2 qua `Support\AwsV4Signer` (không cần AWS SDK → zip nhẹ); OAuth — SendPulse (client_credentials token cache transient); transport preset — IONOS (`ConfiguresPhpMailer`, host smtp.ionos.<region>, 465/587). `Mailer\Providers\ProviderCatalog` đăng ký cả 13 qua 2 seam WP2 (`flexa_smtp.settings.mailer_schema` + `flexa_smtp.mailers.register`); booted trong Plugin trước MailerManager. Verified live (87 checks Y, HTTP mock qua `pre_http_request`, đi qua wp_mail thật): schema+registry đủ 13, is_configured on/off, secret decrypt, mỗi provider đúng endpoint/auth-header/payload, SES ký X-Amz-Date+Host+AWS4-HMAC-SHA256, IONOS configure host/port/ssl/auth, lỗi 401 → wp_mail false + message nổi lên. Ghi chú: SES dùng Simple content (attachment cần raw MIME — để sau); Mailgun chưa gửi attachment (cần multipart). phpstan L6 still pending (needs composer install). |
| WP4 OAuth | ☑ xong | `OAuth\TokenStore` (option riêng `flexa_smtp_oauth_tokens`, access/refresh token AES-256-GCM mã hoá — tách khỏi settings schema; merge giữ refresh cũ khi refresh không trả lại), `OAuth\StateSigner` (state ký HMAC theo provider+uid+exp, TTL 10′ — **thay `__return_true`**: callback là browser-redirect không mang REST nonce nên gate bằng chữ ký state). `Mailer\Contracts\UsesOAuth`. `Mailer\Providers\AbstractOAuthMailer` (kế thừa AbstractApiMailer: creds = client_id/client_secret, `is_configured` = creds + connected; `authorize_url`, `exchange_code`, `access_token()` **auto-refresh** trước khi hết hạn, `store_token_response`, Bearer mặc định). **3 provider**: Gmail (raw MIME lấy từ PHPMailer đã preSend `getSentMIMEMessage` → base64url, thêm lại Bcc; scope gmail.send, access_type=offline+prompt=consent), Outlook/365 (Graph `me/sendMail` JSON, 202, Mail.Send+offline_access), Zoho (region enum com/eu/in/au/jp đổi host OAuth+API, auth `Zoho-oauthtoken`, lookup accountId rồi cache vào token bundle). `Api\OAuthEndpoint`: GET `/oauth/{provider}/authorize` (mint consent URL, can_manage) · GET `/oauth/{provider}/callback` (gate = StateSigner::verify, exchange rồi `wp_safe_redirect` về admin) · POST `/oauth/{provider}/disconnect` · GET `/oauth/status`. ProviderCatalog += 3; OAuthEndpoint đăng ký trong Router. Verified live (67 checks Y, HTTP mock qua `pre_http_request` xuyên wp_mail thật): schema+registry 3 provider, state roundtrip+reject (sai provider/tamper/rác), authorize URL đủ tham số, exchange lưu token **mã hoá at-rest (`fsg1:`)**, Gmail raw MIME+Bearer, Outlook Graph payload+202, Zoho account-lookup+`Zoho-oauthtoken`+cache accountId, **refresh khi token hết hạn → token mới persist**, chưa-connect → wp_mail false, is_configured gating, disconnect, exchange lỗi 400 → Result error. Ghi chú: Gmail Bcc thêm lại vào MIME header; Zoho attachment cần upload API riêng (để sau); redirect_uri = REST callback (phải khớp console provider). phpstan L6 still pending (needs composer install). |
| WP5 Logs | ☑ xong | Settings mở rộng: `enable_email_log` (default true) + `log_retention_days` (default 30, INT_KEYS). Domain: `EmailLog`/`OpenEvent`/`ClickEvent` readonly VO (from_row/to_array, recipients unserialize + extra_info json decode) + `EmailLogRepository` (create/find/query filter status·mailer·search·date + phân trang, count, delete, delete_older_than — mọi giá trị bind `$wpdb->prepare`, ORDER BY whitelist), `OpenEventRepository`/`ClickEventRepository` (record upsert + reads cho WP6). `Logging\MailLogger` (subscribe `flexa_smtp.mail.before_send/sent/failed` + `wp_mail_failed`, snapshot ở before_send, ghi **đúng 1 row/wp_mail**: sent | failed-với-lý-do gộp fallback, dev-mode gắn `delivery_disabled`), `Logging\SourceDetector` (nguồn = slug plugin/theme/mu, **không lộ path tuyệt đối**), `Logging\Retention` (cron daily `flexa_smtp_retention` → purge quá hạn, hook `flexa_smtp.logs.purged`). `Api\LogsEndpoint` GET `/logs` (filter+paginate) · `/logs/{id}` (body + opens/clicks) · `/logs/export` (CSV, chống formula-injection) — `can_manage()`, đăng ký trong Router; MailLogger + Retention booted trong Plugin. Verified live (38 checks Y): defaults, repo CRUD+filter, **malicious search an toàn (table còn nguyên)**, dev-mode log SENT+disabled, fail-path log FAILED với "[faketest] fake boom", list/detail/404, csv_safe, SourceDetector, retention purge xoá cũ giữ mới. phpstan L6 still pending (needs composer install). |
| WP6 Tracking | ☑ xong | `Tracking\TokenSigner` (token HMAC self-contained, **KHÔNG `__return_true`** — recipient không đăng nhập nên pixel/link gate bằng token ký site-secret; open token `{t:o,l}`, click token `{t:c,l,u}` — URL đích nằm trong token đã ký nên không thể tráo (chống open-redirect); token **không hết hạn**). `Tracking\PixelInjector` (chèn `<img>` 1x1 trước `</body>`, else append). `Tracking\LinkRewriter` (**DOMDocument**, bọc `<body>`+`<?xml UTF-8>`+NOIMPLIED/NODEFDTD rồi serialize children → giữ nguyên fragment; chỉ rewrite http(s), bỏ mailto/tel/anchor/relative; idempotent — không bọc lại URL `/track/`). `Tracking\Tracker` (HasInstance, subscribe **`flexa_smtp.log.created`** — hook mới fire sau khi có log row nhưng trước khi transport gửi; chỉ HTML + khi bật open/click; rewrite link rồi chèn pixel vào `$php->Body` tại chỗ). `Api\TrackingEndpoint`: GET `/track/open/{token}` (permission = verify_open, record open, trả GIF 1x1, exit) · GET `/track/click/{token}` (permission = verify_click, record click, `wp_redirect` tới URL gốc, exit) — cả hai public nhưng **gate bằng token, sai token → 403**; `open_url()`/`click_url()` static là single-source route. Settings += `enable_open_tracking`/`enable_click_tracking` (BOOL_KEYS, default false). **MailLogger refactor**: insert row PENDING ở before_send (có log_id) → fire `flexa_smtp.log.created($id,$php)` → settle SENT/FAILED lúc outcome bằng `EmailLogRepository::update()` mới (vẫn **đúng 1 row/wp_mail**; snapshot body **trước** khi Tracker rewrite nên body lưu là bản sạch, recipient nhận bản có tracking). `EmailLog::STATUS_PENDING=2`. Tracker booted trong Plugin; TrackingEndpoint trong Router. Verified live (51 checks Y, qua wp_mail thật, dev-mode + fake mailer, không network): token roundtrip+reject (tamper/forge-reuse-sig/rác/sai-kind), LinkRewriter (rewrite http, chừa mailto/anchor/relative, decode lại đúng URL gốc, idempotent), PixelInjector, e2e HTML → body giao có pixel+click & token pixel khớp log_id, **body lưu sạch không `/track/`**, open/click record + increment, permission accept token đúng/403 token sai, plain-text KHÔNG chèn, tracking off KHÔNG chèn, **WP5 regression: fail-path vẫn FAILED + đúng 1 row + không sót PENDING**. Ghi chú: tracking yêu cầu bật logging (cần row để gắn open/click). phpstan L6 still pending. |
| WP7 Reports | ☑ xong | Aggregation methods thêm vào Domain (mọi giá trị `$wpdb->prepare`, chỉ table name nội suy): `EmailLogRepository::status_counts/daily_status/mailer_breakdown` (GROUP BY DATE(date_time)·status·mailer, flag_delete=0, range BETWEEN), `OpenEventRepository::stats_in_range/daily_opens` + `ClickEventRepository::stats_in_range/daily_clicks/top_urls` (INNER JOIN email_logs, **bucket theo log.date_time = ngày gửi**, SUM(count) + COUNT(DISTINCT log_id)). `Reports\Stats::summary(days)` (clamp 1–365) → `{range, totals(sent/failed/total/opens/clicks/opened_messages/clicked_messages/open_rate/click_rate), series[30/N ngày liên tục fill 0], mailers, top_links}`; rate = messages_opened|clicked / sent ×100 làm tròn 1 số. `Reports\Digest` (build HTML email từ summary + `recipients()` parse `report_recipients` CSV → valid emails, fallback admin_email; `send(days,label)` → wp_mail). `Reports\Scheduler` (HasInstance; hooks `flexa_smtp_report_weekly`(weekly built-in)·`flexa_smtp_report_monthly`(custom `cron_schedules` 30 ngày); `sync()` reconcile lịch theo setting + re-sync ở `flexa_smtp.settings.updated`; mỗi run re-check setting nên tắt là dừng gửi; hook name khớp Deactivator). `Api\ReportsEndpoint`: GET `/reports?days=N` (Stats, can_manage) · POST `/reports/send` (Digest::send ngay, can_manage_settings). `Admin\DashboardWidget` (wp_dashboard_setup, glance 30 ngày). Settings += `enable_weekly_report`/`enable_monthly_report` (BOOL) + `report_recipients` (STRING). **Bug fix**: `Settings::sanitize()` chỉ xử lý string keys hardcode (không loop STRING_KEYS) → thêm nhánh `report_recipients` để lưu được. Frontend: `features/reports/useReports` (query `["reports",days]` + `useSendDigest` mutation), **ReportsTab thay placeholder** = range 7/30/90 + 6 stat card + **SVG chart** (bar Sent + polyline Opens/Clicks, dùng token `--fs-color-brand-500`) + By-mailer/Top-links + section digest (weekly/monthly toggle + recipients input trong form save + nút Send test → POST /reports/send); SettingsPage cho Reports tab được Save. Booted: Scheduler trong Plugin (mọi request để cron chạy), DashboardWidget `is_admin`, ReportsEndpoint trong Router. **Build xanh** (tsc strict + vite, 1707 modules → main.css 32.8KB/js 298KB). Live 26/26 (delta-based vì DB site có log thật: sent/failed/opens/clicks/messages deltas, rate khớp công thức, series 30 ngày sum=totals.sent + loại row 40 ngày ngoài range, mailer_breakdown, top_urls, routes /reports·/reports/send, **report_recipients persist + parse valid-only sau fix**, digest send trả recipients, scheduler enable→scheduled / disable→unscheduled, monthly cron schedule). phpstan L6 still pending. |
| WP8 Import | ☑ xong | `Import\Contracts\ImporterInterface` (slug/label/is_available/import_settings/import_logs) + `Import\AbstractImporter` (map settings qua **`Settings::save()`** nên secret được mã hoá + unknown key drop + merge; `MAILER_MAP` source→flexa slug, `PROVIDER_MAP` sub-array WPMS→creds flexa (sendgrid/smtpcom/sendinblue→brevo/mailgun region-lowercase/amazonses client→access_key/postmark/sparkpost/mailjet/gmail/outlook/zoho); `map_encryption`→none|ssl|tls, `map_wpms_settings`, `import_wpms_logs` (logs + tracking events qua Open/ClickEventRepository::record), `decode_people` JSON `{from,to:[[email,name]]}`→recipient list, `emails_from_string`, `table_exists` bind qua SHOW TABLES LIKE %s; writes chỉ qua Domain repos, đọc bảng ngoài literal-name). **5 nguồn**: `WpMailSmtp` (option `wp_mail_smtp`, logs `wpmailsmtp_emails_log` + tracking `wpmailsmtp_email_tracking_events`), `EasyWpSmtp` (`easy_wp_smtp`, `easywpsmtp_*` — layout giống WPMS nên dùng chung mapper), `SmtpMailer` (`smtp_mailer_options` flat, pass base64-decode, no logs), `WpSmtp` (`wp_smtp_options` flat pass plaintext, logs `wpsmtp_logs`), `Mailbank` (config serialize trong bảng `mail_bank_meta` meta_key=email_configuration, logs `mail_bank_logs`, OAuth suy từ host smtp.live.com→outlook/smtp.gmail.com→gmail). `Import\ImporterRegistry` (HasInstance; `all`/`get`/`available`; option `flexa_smtp_imported_log_sources` chống import logs trùng — `imported_log_sources`/`mark_logs_imported`). `Api\ImportEndpoint`: GET `/import` (list nguồn available + logs_imported flag) · POST `/import` (`source` required, `mode` enum settings|logs|both, `force` bool — dedup logs trừ khi force; nguồn không có data → WP_Error 400) — cả hai `can_manage()`, đăng ký trong Router. `Admin\ImportNotice` (admin_notices phát hiện nguồn → notice có nút Go-to-Import + Dismiss ký nonce → option `flexa_smtp_import_notice_dismissed`; booted `is_admin`). **Eraser** += xoá 2 option import (imported_log_sources + notice_dismissed) để reset về sạch. Frontend: `features/import/useImport` (`useImportSources` query `["import","sources"]` + `useRunImport` mutation invalidate settings/logs/reports/sources), **tab Import mới** (giữa Additional) list nguồn phát hiện + Select mode + nút Import, empty-state "no plugins detected"; SettingsPage `showSave` loại `import`. **Build xanh** (tsc strict + vite, 1709 modules → main.css 32.96KB/js 302.77KB). Live 49/49 (delta-based, snapshot+restore toàn bộ option/table sau test): WPMS settings mapping đủ (current_mailer/from/force/smtp host·port-int·tls·auth·user, **pass lưu ciphertext + decrypt khớp**, sendgrid/mailgun creds mã hoá + region lowercase), WPMS logs +2 (html→text/html sent, plain→text/plain failed+error, email_from/recipients từ people, **open+click event import**), SmtpMailer base64 pass decode + ssl/465, WpSmtp logs table +2, EasyWpSmtp sendinblue→brevo, registry available liệt kê nguồn, endpoint list + run + **dedup skip khi chưa force + force re-import**, unknown source→WP_Error. phpstan L6 still pending. |
| WP9 Admin UI | ☑ xong | React app (Vite 6 + React 18 + TS strict + Tailwind v4 `prefix(fs)` + TanStack Query v5 + Zustand), design-system mirror của flexa-cache re-substitute (`fc→fs`, `flexaCache→flexaSmtp`, `.flexa-smtp-themed`, mount `flexa-smtp-admin-root`, store key `flexa-smtp:ui`). `apps/admin/` (package.json ở **plugin root** như flexa-cache, scripts `-c apps/admin/...`, build → `assets/dist`). Primitives hand-vendored: button/input/label/switch/select/dialog/tooltip + Toaster (activeClaim singleton). Thêm form-chrome reset `.flexa-smtp-control` (WP forms.css unlayered thắng utilities) + `.flexa-smtp-check` vào index.css; Input/Select gắn marker. **6 tab** (nav trái + pane): **Mailer** (from email/name + force toggles, chọn primary mailer, **credential fields sinh động từ `schema` localize** cho đủ 18 mailer — string/int/enum/bool/secret(mask); OAuth mailer gmail/outlook/zoho có nút Connect/Disconnect gọi `/oauth/{slug}/authorize|disconnect` + `/oauth/status`; fallback mailer), **Email Logs** (toggle log + retention days; bảng filter search/status/mailer + phân trang, detail Dialog fetch `/logs/{id}` hiện opens/clicks/body, **Export CSV** link `/logs/export?...&_wpnonce=`), **Tracking** (toggle open/click; cảnh báo nếu logging off), **Reports** (**placeholder tới WP7**), **Additional** (disable_delivery/dev-mode), **Danger Zone** (Dialog gõ `RESET` → `POST /reset`). Server state = Query (`["settings"]`, `["logs",q]`, `["oauth","status"]`), save = partial diff (scalar keys + changed mailer slugs) optimistic. PHP: `Admin\Menu` (add_menu_page `flexa-smtp`, action link), `Admin\Enqueue` (đọc vite manifest, enqueue module + css, localize `flexaSmtp`: restUrl/nonce/namespace/version/urls/theme/canManageSettings/**schema**/secretMask, `wp_set_script_translations`), `templates/admin-app.php` (mount div), `Api\ResetEndpoint` (`POST /reset` → `Maintenance\Eraser::erase_all`, can_manage_settings — path huỷ dữ liệu duy nhất, dùng chung CLI). Booted trong Plugin (Menu/Enqueue chỉ `is_admin`), ResetEndpoint trong Router. **Build xanh**: `pnpm install` + `pnpm build` (tsc strict --noEmit + vite) → `assets/dist/.vite/manifest.json` + main.css(32KB)/main.js(292KB), 1706 modules. Live check 15/15 (classes load, manifest+entry+css+js tồn tại, routes `/reset`·`/settings`·`/logs` đăng ký, schema đủ 18 mailer gồm gmail.client_id + smtp.host, render callback). `.gitignore` sửa `client/`→`apps/admin/`. Ghi chú: dialog chưa stamp `data-theme` trong portal (theo flexa-cache; danger dialog render trên nền trắng vẫn đọc được); `.distignore` packaging (ship `apps/admin/src`) để WP11. phpstan L6 still pending. |
| WP10 CLI | ☑ xong | 3 lớp trong `src/Cli/` (đã được wire sẵn trong `Plugin.php` sau `WP_CLI` guard + `class_exists`): **`LogsCommand`** (`WP_CLI::add_command('flexa-smtp log', self)`; sub `list` filter `--status` (name sent/failed/pending **hoặc** int, unknown→WP_CLI::error) `--mailer` `--search` `--date_from` `--date_to` `--limit`(default 20,max 1000) `--offset`, `--format` table/csv/json/yaml/**ids**/**count**, đọc qua `EmailLogRepository::query/count`, map status→label, recipients join address; sub `get <id>` hiện full row gồm body/content_type/reason_error/source, id không có → `WP_CLI::error`), **`SendTestCommand`** (`flexa-smtp test <to> [--subject] [--html]`, `__invoke`; mirror **verbatim** `Api\TestMailEndpoint` — capture `wp_mail_failed`, `wp_mail`, báo mailer qua `Settings::get('current_mailer')`; email sai → error), **`ResetCommand`** (`flexa-smtp reset [--yes]`, `__invoke`; `WP_CLI::confirm` rồi gọi **`Maintenance\Eraser::erase_all()`** — path huỷ dữ liệu duy nhất dùng chung REST danger-zone, không inline DELETE). Tất cả `@when after_wp_load`, format bằng `\WP_CLI\Utils\format_items`. `php -l` sạch cả 3. **Live wp-cli** (socket Local): 3 subcommand đăng ký đúng (`wp help flexa-smtp`); `log list` chạy (DoD ✓) — table/count(=10)/ids/json/csv, `--status=sent` loại 2 dòng failed, `--status=failed --format=json` đúng 2 dòng, `get 14` hiện full detail + reason_error OAuth, `get 99999`→`Error: No log entry found`, `test not-an-email`→`Error: A valid recipient…`, `reset --help` có `--yes`. Không chạy `test` gửi thật / `reset` thật để giữ DB dev sạch (cả hai là mirror code đã test). phpstan L6 still pending (cần composer/network). |
| WP11 Release | ☑ xong | **Toolchain unblocked**: `composer install` chạy được (network) → phpstan + phcs cài. **phpstan level 6 SẠCH** (đóng DoD treo từ đầu): sửa 25 lỗi tích luỹ WP2–WP10 — thêm `WPINC` vào `phpstan-bootstrap.php`; nới docblock hook-callback (`MailLogger::on_failed/on_sent/map_addresses` → `mixed` giữ guard runtime); bỏ guard thừa (`EmailLogRepository` null-check sau isset + docblock `extra_info:mixed`; `is_array` đã narrowed; `SourceDetector` is_string/strtok; `MailerRegistry` instanceof; `Settings::merge_mailers`; `TokenStore::read` @return `array<string,mixed>` giữ 2 guard; `OutlookMailer`/`LogsEndpoint`/`LogsCommand` bỏ `?? ''` trên `array{address,name}`); `ProviderCatalog::add_schema` bỏ `is_a` (phpstan enforce contract lúc phân tích); `Retention` bọc callback thành void closure; `Stats` đổi `current_time('timestamp')`→`current_datetime()` (giữ semantics local-day); `LogsCommand::resolve_status` thêm fallthrough return. **phpcs SẠCH 86 files** (tạo `phcs.xml.dist` mirror flexa-cache: WordPress std + house-style excludes (short array, dot-hook, sparse docblock, Yoda) + PHPCompatibilityWP + I18n domain=flexa-smtp; domain-inherent excludes: PHPMailer CamelCase properties, base64 (crypto/API/import); path-scoped design exceptions: Schema/uninstall DirectQuery, EmailLogRepository UnfinishedPrepare, SourceDetector debug_backtrace, MailerManager GlobalOverride+ExceptionNotEscaped, LogsEndpoint fopen/fclose CSV stream, Enqueue file_get_contents). phpcbf auto-fix 112+; sửa tay 13 array-spacing + phát hiện & fix **bug arrow-fn ScopeIndent** (multiline array trong `array_filter([...], fn)` làm phcbf mis-indent) bằng helper `AbstractApiMailer::compact_pairs()` (Mailjet/Brevo/SendPulse); gộp `SendPulseMailer::result_meta()`. **Release meta**: version 0.1.0→**1.0.0** (main header + `FLEXA_SMTP_VERSION` + bootstrap + readme Stable tag); viết lại `readme.txt` (18 mailer, logs, tracking, reports, WP-CLI, import, FAQ, changelog 1.0.0); `.distignore` += `/vendor` (thiếu — nguy hiểm), `/apps/admin/node_modules`, apps config, bỏ `/client` stale; **`.pot`** regenerate `i18n/languages/flexa-smtp.pot` (37 chuỗi PHP, stamp 1.0.0) qua `wp i18n make-pot` (JS strings từ apps/admin/src là follow-up — wp_set_script_translations đã wired). DangerZone = `Api\ResetEndpoint` (WP9) → `Maintenance\Eraser::erase_all()` dùng chung CLI `flexa-smtp reset`. **Zip sạch**: `./release.sh` → `build/flexa-smtp-1.0.0.zip` (pnpm build 1709 modules; verify: KHÔNG có `*.md`/vendor/node_modules/docs/tests/composer/package.json/phpstan/phpcs/.git/release.sh/tsconfig/vite.config/.distignore; CÓ main/uninstall/readme.txt/src/templates/assets-dist-manifest/apps-admin-src/pot). Smoke live sau refactor: CLI log count=10, version=1.0.0, các class load, `Stats::summary(30)` OK, `add_schema`=16 provider (mailjet.secret_key.secret=true). |

*Cập nhật cột trạng thái (☐ chưa / ◐ đang làm / ☑ xong) mỗi khi hoàn thành một WP.*
