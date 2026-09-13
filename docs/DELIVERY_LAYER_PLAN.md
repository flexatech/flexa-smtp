# Flexa SMTP → Email Delivery Layer — Plan (evolve, không rewrite)

> Kế hoạch tiến hóa `flexa-smtp` từ "cấu hình SMTP + gửi mail" thành **lớp
> reliability / observability / diagnostics / queue / recovery** giữa WordPress
> và nhà cung cấp email.
>
> Tài liệu này bổ sung cho `IMPLEMENTATION_PLAN.md` (đã hoàn tất WP0–WP11, bản
> 1.0.0). Nguyên tắc bất biến, contract, style vẫn theo `IMPLEMENTATION_PLAN.md`
> mục 1 + `flexa-plugin-conventions` + `flexa-plugin-ui`.
>
> **Luật vàng:** không rewrite, không phá settings cũ, không đổi hành vi khi
> không cần, không đụng semantics `wp_mail()` mặc định, không remote call trong
> request frontend, không retry lỗi vĩnh viễn, không coi timeout = fail.
>
> **100% FREE — không license/pro trong plugin này** (WP.org cấm). Pro = plugin
> riêng biệt hook vào seam công khai. Xem mục 6.

---

## 1. Phase 0 — Báo cáo kiến trúc (audit đã thực hiện trên codebase 1.0.0)

### 1.1 Kiến trúc hiện tại
PSR-4 `Flexa\Smtp\` → `src/`, singleton qua `Concerns\HasInstance`, boot tập
trung ở `Plugin::boot()` (mọi service sau guard `class_exists`). Admin là React
18 + Vite 6 + Tailwind v4 + TanStack Query + Zustand, mount 1 top-level menu
`flexa-smtp`. REST namespace `flexa-smtp/v1`, mọi route có `permission_callback`
thật + per-arg `sanitize_callback`.

### 1.2 Email flow hiện tại (điểm tích hợp trung tâm)
```
wp_mail()
  └─ PhpMailerBridge (subclass PHPMailer cài vào global $phpmailer @ plugins_loaded:100)
       └─ MailerManager::dispatch( $php )
            ├─ dev-mode short-circuit (disable_delivery)
            ├─ mailer_chain(): primary → fallback (dedup)
            ├─ do_action flexa_smtp.mail.before_send  ($php, $slug)
            ├─ attempt(): ConfiguresPhpMailer->configure + send_native()  |  API mailer->send(Message): Result
            ├─ ok  → do_action flexa_smtp.mail.sent   ($php, $slug, meta)
            └─ fail→ do_action flexa_smtp.mail.failed ($php, $slug, error, meta)  → throw → wp_mail_failed
```
Gửi **100% đồng bộ** trong 1 request. Provider **không tự log**; `MailerManager`
sở hữu vòng đời. Đây là seam ta gắn mọi feature mới vào (subscribe hook, mở rộng
`Result`), **không phải sửa lõi dispatch**.

### 1.3 Logging đã có
`Logging\MailLogger` subscribe `before_send/sent/failed` + `wp_mail_failed`, ghi
**đúng 1 row/wp_mail**: insert PENDING ở `before_send` (có `log_id`) → fire
`flexa_smtp.log.created($id,$php)` → settle SENT/FAILED lúc có kết quả. Snapshot
body **trước** khi Tracker rewrite nên bản lưu là bản sạch. `SourceDetector`
attribution nguồn (plugin/theme/mu slug, **không lộ path tuyệt đối**). Statuses:
`FAILED=0`, `SENT=1`, `PENDING=2`.

### 1.4 Provider architecture đã có
`MailerInterface::send(Message): Result`. 18 mailer: SMTP + native (transport,
`ConfiguresPhpMailer`) + 13 API (`AbstractApiMailer`: build payload →
`wp_remote_request` → `interpret()` → `Result`) + 3 OAuth (`AbstractOAuthMailer`,
token AES-256-GCM, auto-refresh). Đăng ký qua `ProviderCatalog` + 2 seam
(`flexa_smtp.settings.mailer_schema`, `flexa_smtp.mailers.register`). **Đã
provider-agnostic** đúng yêu cầu.

### 1.5 DB tables / options
Bảng (`Database\Schema`, DB_VERSION `0.1.0`):
- `flexa_smtp_email_logs` — id, subject, email_from, email_to (serialized),
  mailer, status (tinyint), content_type, body_content (longtext), reason_error,
  source, extra_info (longtext JSON), flag_delete, date_time. Index: `status`,
  `mailer`, `date_time`.
- `flexa_smtp_open_events`, `flexa_smtp_click_events` — log_id (idx), count, ...

Options: `flexa_smtp_settings` (1 option duy nhất, schema/defaults/sanitize/merge
trong `Support\Settings`, secret AES-256-GCM, 3 view raw/all/for_rest),
`flexa_smtp_db_version`, `flexa_smtp_oauth_tokens`,
`flexa_smtp_imported_log_sources`, `flexa_smtp_import_notice_dismissed`.

Repository: `EmailLogRepository` có `create/update/find/query(filter
status·mailer·search·date + paginate)/count/status_counts/daily_status/
mailer_breakdown/delete/delete_older_than` — mọi giá trị bind `$wpdb->prepare`,
ORDER BY whitelist, LIMIT cap 1000.

### 1.6 Cron / background hiện tại
**Chỉ WP-Cron native.** `flexa_smtp_retention` (daily), `flexa_smtp_report_weekly`
(weekly), `flexa_smtp_report_monthly` (custom 30d). `Scheduler::sync()` reconcile
theo setting, unschedule ở Deactivator. **Không có Action Scheduler, không queue,
không background send.** Transient chỉ dùng cache OAuth token (SendPulse).

### 1.7 Security đã có
AES-256-GCM cho secret; HMAC token cho tracking (không `__return_true`);
prepared SQL toàn bộ; `permission_callback` mọi route (`manage_options` qua
`Support\Capabilities`, bọc filter); chống CSV formula-injection; `strict_types`
+ `ABSPATH` guard mọi file. phpstan L6 sạch, phpcs sạch 86 file. **Sẵn sàng WP.org.**

### 1.8 Component tái dùng được (không làm lại)
`Result` (mầm DeliveryResult) · hook lifecycle · `MailLogger` + PENDING/SENT/
FAILED · `SourceDetector` · `EmailLogRepository` (+ 3 method aggregation cho
monitoring) · `Database\Schema::maybe_upgrade()` (đường migration) · `Settings`
schema + encryption · REST `Endpoint` base + `Capabilities` · React admin shell.

### 1.9 Rủi ro kiến trúc
1. **Semantics `wp_mail()`**: đổi sang queue → return `true` trước khi gửi thật,
   phá plugin dựa vào giá trị trả về. → queue phải opt-in, giữ đồng bộ mặc định.
2. **Timeout ≠ fail**: request có thể timeout dù provider đã nhận. → cần
   idempotency key, không mù quáng retry.
3. **DNS trong request**: SPF/DKIM/DMARC lookup chậm/bị chặn trên shared host.
   → chỉ chạy async/cron + cache, không bao giờ trong frontend.
4. **DKIM generic không kiểm được**: không biết selector nếu provider không công
   bố. → trả NOT CHECKED trung thực.
5. **status là tinyint 0/1/2**: thêm QUEUED/RETRYING/CANCELLED cần bump enum +
   migration cẩn thận (giữ nguyên giá trị cũ).
6. **Log table phình**: cần index cho query mới + retention tin cậy.

### 1.10 Điểm tích hợp khuyến nghị
- Mở rộng `Result` (thêm field, giữ tương thích factory cũ).
- Provider `interpret()` điền `response_code` + `provider_message_id`.
- `MailerManager::attempt()` đo `duration_ms`, gắn vào meta.
- Feature mới **subscribe hook**, không sửa `dispatch()`.
- Migration qua `Schema` (bump DB_VERSION, dbDelta thêm cột — an toàn với dữ liệu cũ).
- Diagnostics/Health/Monitoring là **service đọc dữ liệu**, đăng ký trong `Plugin::boot()` sau guard.

---

## 2. Product positioning
```
WordPress → [ Capture · Validate · Queue · Deliver · Retry · Monitor · Diagnose · Report ] → Provider → Recipient
```
Mục tiêu: **reliability + observability layer**, không phải thêm một trang cấu
hình SMTP. Free giữ nền tảng đầy đủ; Pro thêm phần nâng cao (mục 6).

---

## 3. Roadmap đã điều chỉnh (2 nhịp release)

Đảo thứ tự so với prompt gốc: dồn phần rủi ro-0 (đọc/suy ra/UI, tái dùng dữ liệu
có sẵn) lên trước để ship giá trị nhanh; dồn phần đổi semantics (queue/retry) về
cuối, sau khi diagnostics/retryability đã vững.

| DL | Tên | Nội dung | Rủi ro BC | Phụ thuộc |
|----|-----|----------|-----------|-----------|
| **DL1** | Delivery Result & Diagnostics | Mở rộng `Result` → DeliveryResult; `Diagnostics` engine (classify + severity + retryable + action); provider `interpret()` điền response_code/provider_message_id; migration schema log (cột mới + enum status). | ~0 | — |
| **DL2** | Health Check + Provider Monitoring | Health checks plugin-based (WP/Transport/DNS, timeout+cache, async); score PASS/WARN/ERROR/NOT CHECKED/N/A; Monitoring aggregation trên log + UI. | ~0 | DL1 |
| **DL3** | Advanced Log UI + Overview | Detail view (diagnostics + technical), enrich source (hook/class khi tin cậy), filter theo category; Overview dashboard 3-câu-hỏi. | ~0 | DL1 |
| **DL4** | Queue + Retry (opt-in) | Queue table + worker (claim/lock/backoff/idempotency/stuck detection); Retry chỉ lỗi retryable; trigger Action-Scheduler-nếu-có, fallback WP-Cron. | Cao → opt-in | DL1 |

**Nhịp 1 = DL1+DL2+DL3** (một bản minor, khác biệt rõ, an toàn).
**Nhịp 2 = DL4** (bản minor sau, queue mặc định OFF).

---

## 4. Spec chi tiết Phase 1 (DL1)

### 4.1 DeliveryResult (mở rộng `Result`, không thay thế)
Giữ nguyên factory `Result::success()/error()` cũ (backward compat). Thêm field
optional readonly, mặc định null, và factory mới nhận payload đầy đủ.

```php
final class Result {
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error,
        public readonly array $meta,
        public readonly ?int $response_code = null,        // 250, 421, 535, HTTP 202/401...
        public readonly ?string $provider_message_id = null,
        public readonly ?string $error_category = null,    // Diagnostics::CATEGORY_*
        public readonly ?bool $retryable = null,           // null = chưa phân loại
    ) {}

    public static function success( array $meta = [], ?string $provider_message_id = null, ?int $response_code = null ): self;
    public static function error( ?string $error, array $meta = [], ?int $response_code = null ): self;
    // Diagnostics::classify() sẽ trả bản Result đã điền error_category + retryable (with_diagnosis()).
}
```

Provider điền thêm ở `interpret()`:
- API mailer: `response_code` = HTTP status; `provider_message_id` từ response
  (SES `MessageId`, SendGrid header `X-Message-Id`, Postmark `MessageID`,
  Mailgun `id`, ...). Bổ sung trong từng `interpret()`/`extract_*`, không đụng base flow.
- SMTP: `response_code` parse từ `$php->ErrorInfo` / SMTP reply khi có.

`MailerManager::attempt()` đo `$t0 = microtime(true)` → gắn `duration_ms` vào meta.

### 4.2 Diagnostics engine
Thư mục mới `src/Diagnostics/`. Bảng tra **bảo thủ**, không đoán liều.

```
src/Diagnostics/
├─ Diagnostics.php         # classify( ?int $code, string $raw, string $transport ): Diagnosis
├─ Diagnosis.php           # readonly VO: category, severity, retryable, explanation, action, technical
└─ Rules.php               # bảng map code/pattern → category (data, không logic rải rác)
```

Category (const): `AUTH`, `CONNECTION`, `TIMEOUT`, `TLS`, `DNS`, `RATE_LIMIT`,
`PROVIDER_REJECTION`, `INVALID_RECIPIENT`, `CONFIGURATION`, `SERVER`, `UNKNOWN`.

Map mẫu (bảo thủ):

| Code / pattern | Category | Retryable |
|---|---|---|
| 421, 450, 451, temp 4xx, HTTP 502/503/504 | PROVIDER_REJECTION / SERVER | **Yes** |
| 429, "rate limit", "too many" | RATE_LIMIT | **Yes** |
| timeout, connection reset, cURL 28/7 | TIMEOUT / CONNECTION | **Yes (ambiguous → cần idempotency)** |
| 535, 530, HTTP 401/403, "auth" | AUTH | **No** |
| 550, 551, 553, "user unknown", "no such user" | INVALID_RECIPIENT | **No** |
| 554, permanent 5xx, "domain not verified" | PROVIDER_REJECTION / CONFIGURATION | **No** |
| không map được | UNKNOWN | **No** (mặc định an toàn: không retry) |

`Diagnosis` cho UI: `explanation` (ngôn ngữ thường, i18n), `action` (khuyến nghị),
`technical` (raw response, giữ cho dev). **Không** chứa credential/secret.

`MailLogger` gọi `Diagnostics::classify()` khi settle FAILED → lưu
`error_category` + set `retryable` (chuẩn bị cho DL4).

### 4.3 Migration schema (bump DB_VERSION `0.1.0` → `0.2.0`)
Thêm cột vào `flexa_smtp_email_logs` (dbDelta an toàn, dữ liệu cũ nhận default):

| Cột mới | Kiểu | Ghi chú |
|---|---|---|
| `error_category` | varchar(32) NOT NULL DEFAULT '' | index để filter |
| `response_code` | varchar(16) NOT NULL DEFAULT '' | text vì có cả HTTP + SMTP |
| `provider_message_id` | varchar(190) NOT NULL DEFAULT '' | tra ngược khi debug |
| `duration_ms` | int unsigned NOT NULL DEFAULT 0 | |
| `retry_count` | tinyint unsigned NOT NULL DEFAULT 0 | phục vụ DL4 |
| `idempotency_key` | char(64) NOT NULL DEFAULT '' | hash chống trùng (DL4), ghi sớm từ DL1 |

Index thêm: `KEY error_category (error_category)`, `KEY idempotency_key
(idempotency_key)`.

Enum status (mở rộng, **giữ nguyên 0/1/2 cũ**): `QUEUED=3`, `RETRYING=4`,
`CANCELLED=5`. Chỉ dùng thật từ DL4; DL1 chỉ khai hằng số.

Cập nhật đồng bộ: `EmailLog` VO (+ from_row/to_array field mới),
`EmailLogRepository::create/update` (whitelist cột mới), `uninstall.php` không
đổi (drop cả bảng). Idempotency key = `hash('sha256', from|to|subject|body_hash)`
tính ở `MailLogger::snapshot()`.

### 4.4 REST
- `GET /logs/{id}` mở rộng response: thêm `diagnosis` (từ `error_category` +
  `Diagnostics` lookup), `response_code`, `provider_message_id`, `duration_ms`.
- `GET /diagnostics/{id}` (mới, `can_manage`): trả full Diagnosis cho 1 log fail.
- `/logs` filter thêm arg `error_category` (sanitize_key, whitelist).

Không đổi route cũ, chỉ thêm field/endpoint.

---

## 5. Testing (DL1)
- **Diagnostics**: auth (535/401) → No; timeout → Yes+ambiguous; 429 → RATE_LIMIT
  Yes; 550 → INVALID_RECIPIENT No; unknown → UNKNOWN No.
- **Result**: factory cũ vẫn chạy (BC); with_diagnosis điền đúng field.
- **Migration**: cài mới tạo đủ cột; upgrade từ 0.1.0 thêm cột, dữ liệu cũ nhận
  default, không mất row; chạy `maybe_upgrade` 2 lần idempotent.
- **Provider interpret**: SES/SendGrid/Postmark/Mailgun điền provider_message_id
  đúng từ response mock (`pre_http_request`).
- **Logger**: FAILED lưu error_category + response_code; SENT lưu
  provider_message_id + duration_ms; idempotency_key ổn định cùng input.
- **Security**: `/diagnostics/{id}` chặn user thiếu cap; technical details không
  chứa secret; SQL cột mới prepared.

---

## 5b. Spec Phase 2 (DL2 — Health Check + Provider Monitoring)

### Health Check (`src/Health/`)
- **Nguyên tắc mạng:** check có I/O (DNS lookup, socket connect) **chỉ chạy trong
  cron `flexa_smtp_health` (daily) hoặc refresh admin** (`POST /health/run`).
  `GET /health` **chỉ đọc cache** (option `flexa_smtp_health_report`) → mở màn
  hình không bao giờ trigger mạng. Đây là điều thực thi luật "không remote call
  trong request frontend" cho cả tính năng.
- **Vocabulary:** `PASS / WARN / ERROR / NOT_CHECKED / NA`. Overall = status tệ
  nhất; NOT_CHECKED/NA trung tính (không kéo tụt). Không đoán: cái gì không xác
  minh được → NOT_CHECKED, không tô xanh/đỏ giả.
- **Checks:**
  - `EnvironmentCheck` (local, no network): mailer đang chọn có sẵn + cấu hình?;
    from_email set + hợp lệ?; dev-mode (disable_delivery); phát hiện plugin khác
    override `wp_mail()` (Reflection → nếu không nằm trong `/wp-includes/` thì
    WARN, path rút gọn tương đối, không lộ layout server).
  - `TransportCheck`: SMTP → `fsockopen(host,port,timeout=5)` chỉ dò TCP,
    **không gửi credential**; API → xác nhận creds có mặt (call thật để "Send
    test email" tránh tốn rate-limit); PHP `mail()` → NA.
  - `DnsCheck`: SPF + DMARC qua `dns_get_record` → **advisory WARN** (thiếu record
    không chặn gửi, nên không ERROR); **DKIM = NOT_CHECKED** (selector
    provider-specific, không dò generic được — đúng luật vàng); domain
    localhost/không public → NA.
- `HealthChecker`: `run()` (chạy + cache), `run_scheduled()` (cron void),
  `cached()` (đọc option, an toàn mọi request), `maybe_schedule()` (admin_init),
  seam `flexa_smtp.health.checks` cho plugin ngoài thêm check.

### Provider Monitoring (`src/Monitoring/Monitor.php`)
- `snapshot(days)` tổng hợp **trên log site** (không remote): per-provider
  sent/failed/failure_rate/avg_duration_ms/last_sent_at/last_failed_at + **health
  signal** (healthy <10%, degraded 10–40%, failing ≥40% failure-rate; **< 5 mẫu →
  insufficient_data**, không xanh/đỏ giả), category breakdown, và `scope: local`
  để client luôn ghi rõ **"metrics site bạn ≠ trạng thái provider-wide"**.
- Repo thêm `provider_stats()` + `category_counts()` (prepared, GROUP BY, tái
  dùng cột DL1 `error_category`/`duration_ms`). **Không đổi schema** → BC ~0.

### REST (thêm, không đổi route cũ)
`GET /health` (cache), `POST /health/run` (chạy + cache, settings cap),
`GET /monitoring?days=N` (manage cap).

---

## 5c. Spec Phase 3 (DL3 — Advanced Log UI + Overview)

**Toàn bộ là frontend React** (`apps/admin/`), tái dùng REST của DL1/DL2. Không
thêm/đổi route, không đổi schema, không chạm PHP → BC ~0.

### Overview dashboard 3-câu-hỏi (`features/overview/`)
Section mới đứng đầu nav, mặc định mở khi vào trang. Ba thẻ, mỗi thẻ một câu hỏi
đời thường:
1. **"WordPress gửi được email không?"** đọc `GET /health` (chỉ cache, mở màn hình
   không I/O). Hiện overall status pill + danh sách check (label, message,
   remediation), sắp theo mức nặng. Nút "Run checks" gọi `POST /health/run` (cần
   quyền settings), kèm mốc "last checked" và cảnh báo khi báo cáo stale.
2. **"Email có thật sự đến không?"** đọc `GET /monitoring?days=30`: totals
   sent/failed/failure_rate (tô màu theo ngưỡng 10%/40%), signal từng provider
   (healthy/degraded/failing/insufficient_data). Có dòng ghi rõ **đây là kết quả
   gửi của site bạn, không phải uptime provider công bố**.
3. **"Hỏng thì vì sao?"** xếp hạng category lỗi từ `monitoring.categories`. Bấm một
   dòng → set `logCategory` trong store rồi chuyển sang tab Logs đã lọc sẵn category
   đó (deep-link).

### Log UI nâng cao (`tabs/LogsTab.tsx`)
- **Lọc theo category:** nguồn sự thật đặt ở store (`logCategory`) để Overview
  deep-link được; đổi category thì reset page; đưa `error_category` vào cả query
  danh sách lẫn URL export CSV.
- **Detail dialog** thêm 2 khối cho email fail: **"Why it failed"** (diagnosis
  explanation + action + badge category + cờ retryable, lấy từ `d.diagnosis` mà
  `/logs/{id}` trả về khi FAILED) và **"Technical details"** (response_code,
  provider_message_id, duration_ms, retry_count, source, content_type, cùng chuỗi
  `technical` hoặc raw error). Không lộ secret (chỉ hiện cột đã sanitize từ DL1).

### Plumbing
`useOverview.ts` (hook health/run-health/monitoring), `useLogs.ts` (type +6 cột
DL1, `diagnosis?`, filter `error_category`), `types.ts` (`CATEGORY_LABELS` +
`categoryLabel`), `store.ts` (`logCategory` + default section `overview`).

---

## 5d. Spec Phase 4 (DL4 — Queue + Retry, opt-in)

Phần rủi ro cao nhất nên **mặc định OFF** và **giữ đường gửi đồng bộ**: khi cả hai
toggle tắt, `wp_mail()` chạy y như trước (trả `true` sau khi gửi thật). Chỉ khi
admin bật, plugin mới đổi hành vi.

### Bảng queue (`flexa_smtp_email_queue`, migration 0.2.0 → 0.3.0)
Bảng mới hoàn toàn, không đụng bảng cũ (BC ~0). Cột chính: `payload` (message
serialize đầy đủ), `status` (pending/claimed/failed), `attempts`/`max_attempts`,
`claim` (token khoá), `available_at`/`reserved_at` (backoff + phát hiện stuck),
`log_id` (link log row), `idempotency_key`. Row done bị xoá (log row đã giữ bản
ghi); row failed giữ lại để admin xem/retry. Uninstall + Eraser xoá bảng theo
`Schema::TABLES`.

### Nguyên tắc gửi
- **Async (enable_queue):** `dispatch()` fire `before_send` (tạo log row + tracking
  rewrite body) rồi `enqueue()` snapshot payload sau rewrite, settle log → QUEUED,
  return `true`. Worker gửi sau. Enqueue lỗi → fallback gửi sync để không mất mail.
- **Retry (enable_retry):** dùng được cả khi queue tắt. Gửi sync thất bại với lỗi
  **retryable** (đọc `Result->retryable` từ Diagnostics DL1) → enqueue cho lần sau,
  settle log → RETRYING, return `true`. Lỗi không-retryable (auth, recipient,
  permanent 5xx) → throw như cũ (`wp_mail_failed`, FAILED).
- **Worker** (`Queue::run_worker`): reclaim row stuck > 5', claim atomic một batch
  (UPDATE ... ORDER BY available_at LIMIT + claim token → không hai worker giành
  cùng row), rebuild PHPMailer từ payload, gọi `MailerManager::deliver()` (chạy
  chain, **không** fire hook, **không** re-queue), settle log + queue row. Thành
  công → xoá row + log SENT; retryable còn lượt → reschedule backoff [1',5',30',
  2h,6h] + log RETRYING; hết lượt / không retryable → queue failed + log FAILED.
  Có transient lock chống chồng run; tự lên lịch run kế đúng giờ backoff, cộng tick
  an toàn mỗi 15'.
- **Idempotency:** key = hash(from|recipients|subject|md5(body)). Dedupe best-effort:
  bỏ enqueue nếu đã có row pending/claimed cùng key (settle log → CANCELLED). Timeout
  vẫn là retryable (theo Rules DL1) nên **về lý thuyết có thể gửi trùng** khi provider
  đã nhận nhưng phản hồi timeout; ghi rõ cảnh báo này trong UI, ai cần tuyệt đối
  không trùng thì để retry OFF (hoặc dùng filter `flexa_smtp.queue.should_retry`).

### Trigger: Action Scheduler nếu có, fallback WP-Cron
Site WooCommerce thường có Action Scheduler → dùng `as_enqueue_async_action`
(chạy gần như tức thì) + `as_schedule_single_action` cho retry đúng giờ. Không có
AS → `wp_schedule_single_event` + custom schedule 15' làm lưới an toàn. **Queue
table là nguồn sự thật bất kể trigger nào chạy.**

### REST + Settings + Frontend
- `GET /queue` (stats + engine info, read-only, không gửi mail), `POST /queue/run`
  (chạy worker ngay), `POST /queue/retry-failed`, `POST /queue/clear-failed`
  (đều settings cap).
- Settings: `enable_queue`, `enable_retry` (default false), `queue_max_attempts`
  (default 3, clamp 1..10).
- Section "Delivery Queue": 2 toggle + max-attempts, panel status live (waiting /
  in-progress / failed / due, runner AS-hay-WPCron, next run) + nút Process now /
  Retry failed / Clear failed. Ghi rõ đây là opt-in và cảnh báo semantics `wp_mail()`.

### Seam cho Pro (không có nhánh "nếu có Pro" nào trong free)
Action: `flexa_smtp.queue.sent`, `flexa_smtp.queue.retry_scheduled`,
`flexa_smtp.queue.failed`. Filter: `flexa_smtp.queue.dedupe`,
`flexa_smtp.queue.backoff`, `flexa_smtp.queue.batch_size`,
`flexa_smtp.queue.time_budget`, `flexa_smtp.queue.should_retry`,
`flexa_smtp.queue.use_action_scheduler`, `flexa_smtp.mail.bypass_queue`.

---

## 6. Phạm vi (100% free — KHÔNG license/pro trong plugin này)
Plugin này là **hoàn toàn miễn phí**. WordPress.org cấm license-gating /
upsell-code trong plugin, nên **tuyệt đối không** có `flexa_smtp.pro.is_licensed`,
không check license, không "code disabled chờ mở khoá", không upsell UI. Mọi
feature ở tài liệu này (Health, Log, Diagnostics, Monitoring, Retry, Queue) đều
ship đầy đủ và dùng được ngay trong bản free.

Bản Pro (nếu có) sẽ là **một plugin RIÊNG BIỆT**, cài thêm, hook vào các seam
mở rộng công khai của plugin này (action/filter dot-prefixed). Đó là kiến trúc
extension bình thường và hợp lệ WP.org: plugin free không biết gì về Pro, chỉ
cung cấp hook; plugin Pro tự đứng độc lập. Vì vậy khi làm DL1–DL4 chỉ cần **giữ
hook/filter sạch và ổn định** để plugin ngoài có thể mở rộng, không viết bất kỳ
nhánh điều kiện "nếu có Pro" nào.

---

## 7. Quyết định chốt (khác/rõ hơn prompt gốc)
1. Thứ tự impl: **DeliveryResult + Diagnostics trước** (là tiền đề của Retry &
   Monitoring), không theo thứ tự liệt kê trong prompt.
2. **Queue opt-in, giữ gửi đồng bộ mặc định** — bảo toàn semantics `wp_mail()`.
3. **DKIM generic → NOT CHECKED** trung thực; chỉ verify provider có selector công bố.
4. **DNS check chỉ async + cache**, không bao giờ trong frontend request.
5. **Action Scheduler: detect-và-dùng-nếu-có** (site WooCommerce thường có),
   fallback WP-Cron; queue table là source-of-truth bất kể trigger.
6. **Idempotency key ghi từ DL1** (rẻ) để DL4 chống gửi trùng khi retry/timeout.
7. Monitoring luôn ghi rõ **"metrics của site bạn" ≠ "provider-wide status"**.

---

## 8. Progress tracker
| DL | Trạng thái | Ghi chú |
|----|-----------|---------|
| DL1 Delivery Result & Diagnostics | ☑ xong | **DeliveryResult**: `Mailer\Result` mở rộng 4 field readonly (response_code/provider_message_id/error_category/retryable, mặc định null → factory cũ `success()/error()` giữ BC) + wither `with_transport/with_duration/with_diagnosis` + `to_meta()` (flatten cho hook, listener cũ vẫn thấy mailer/code). **Diagnostics** (`src/Diagnostics/`): `Diagnosis` VO, `Rules` (bảng CODE_MAP + TEXT_RULES thuần data), `Diagnostics::classify(?code,raw,transport)` — code quyết định retryability; text refine category **chỉ khi không mâu thuẫn** retryable của code (nên "timed out" trong 550 không lật thành retryable/timeout); mặc định UNKNOWN + not-retryable; copy explanation/action i18n; `technical` cắt 500 ký tự, không secret; `for_category()` cho read path. **Provider**: `AbstractApiMailer::execute()` capture headers + finalize response_code (HTTP status authoritative) + `extract_message_id()` (header X-Message-Id/UUID + body message_id/MessageId/id, overridable) — không đụng chữ ký `interpret()` nên 13 provider override an toàn. **MailerManager**: `attempt()` đo duration_ms + chạy Diagnostics khi fail (parse SMTP code 4xx/5xx từ ErrorInfo) → enrich Result; fire hook với `to_meta()`; dev-mode path giữ nguyên. **Schema** `0.1.0→0.2.0`: +6 cột (error_category/response_code/provider_message_id/duration_ms/retry_count/idempotency_key) + 2 index (error_category, idempotency_key) qua dbDelta (thêm cột, dữ liệu cũ nhận default); `EmailLog` +3 status hằng (QUEUED/RETRYING/CANCELLED, chưa dùng ở path đồng bộ) + field mới trong constructor/from_row/to_array (idempotency_key **không** lộ ra to_array). `EmailLogRepository::create/update` whitelist cột mới; `build_where` +filter error_category. `MailLogger`: tính idempotency_key (sha256 from|recipients|subject|md5(body)) ở snapshot, ghi lúc create; capture meta fail cuối chain; `delivery_fields()` map meta→cột (chỉ key có mặt); settle ghi category/response_code/provider_message_id/duration_ms. **REST**: `/logs/{id}` +`diagnosis` khi FAILED, `/logs` +filter error_category; **`DiagnosticsEndpoint` `GET /diagnostics/{id}`** (can_manage, 404/không-fail-thì-null) đăng ký trong Router. **Verify**: php -l sạch 13 file; **phpstan L6 sạch**; **phpcs sạch** (phpcbf auto-fix alignment); **test classify 14 case PASS** (auth/timeout/rate-limit/recipient/server/dns/tls/connection/config/unknown + 550-không-bị-lật). Chưa live-migrate được (shell không vào MySQL socket của Local — verify tĩnh: dbDelta chuẩn, idempotent theo version). |
| DL2 Health + Monitoring | ☑ xong | **Health** (`src/Health/`): `CheckResult` VO (status PASS/WARN/ERROR/NOT_CHECKED/NA + group/id/label/message/remediation/context, context chỉ chứa dữ liệu không nhạy cảm) · `HealthReport` (overall = status tệ nhất; NOT_CHECKED/NA trung tính, không kéo tụt) · `Contracts\HealthCheck` (id/label/run → list CheckResult) · 3 check: `EnvironmentCheck` (mailer đang chọn có cấu hình?, from_email hợp lệ?, dev-mode, phát hiện plugin khác override `wp_mail()` qua ReflectionFunction → path rút gọn tương đối, không lộ layout server), `TransportCheck` (SMTP: fsockopen host:port timeout 5s, **không gửi credential**; API: kiểm creds có mặt, connectivity thật để "Send test email"; PHP mail() → N/A), `DnsCheck` (SPF/DMARC tra `dns_get_record` → advisory WARN không phải ERROR; **DKIM = NOT_CHECKED** vì selector provider-specific; domain private/localhost → N/A). `HealthChecker` service: chạy check **chỉ trong cron `flexa_smtp_health` (daily) hoặc refresh admin**, cache vào option `flexa_smtp_health_report`, `cached()` đọc option (an toàn mọi request, không I/O); seam filter `flexa_smtp.health.checks` cho Pro. **Monitoring** (`src/Monitoring/Monitor.php`): `snapshot(days)` tổng hợp trên log — per-provider sent/failed/failure_rate/avg_duration_ms/last_sent/last_failed + health signal (healthy/degraded/failing, dưới 5 mẫu → insufficient_data), category breakdown, `scope: local` đánh dấu rõ "metrics site bạn ≠ provider-wide". `EmailLogRepository` +`provider_stats()` +`category_counts()` (prepared, GROUP BY, dùng cột DL1 error_category/duration_ms). **REST**: `GET /health` (cache, không chạy check), `POST /health/run` (chạy + cache, settings cap), `GET /monitoring?days=N`; đăng ký trong Router. **Lifecycle**: `Plugin::boot` +HealthChecker; Deactivator +unschedule `flexa_smtp_health`; Eraser + uninstall.php +xoá option health. **Không đổi schema** (BC ~0). **Verify**: php -l sạch 16 file; **phpstan L6 sạch**; **phpcs sạch**; test overall-status 6 case + shape PASS; autoload PSR-4 resolve 10 class mới OK. Không remote call nào trong request frontend (check chỉ ở cron/refresh; GET đọc cache). |
| DL3 Advanced Log UI + Overview | ☑ xong | **Chỉ frontend, không đổi backend/schema** (tái dùng REST DL1/DL2 có sẵn). **Overview dashboard 3-câu-hỏi** (`apps/admin/src/features/overview/`): section mới đứng đầu nav (mặc định mở), 3 thẻ hỏi thẳng: (1) *"WordPress gửi được email không?"* đọc `GET /health` (chỉ cache, không I/O), hiện overall pill + từng check (label/message/remediation, sắp theo mức nặng), nút "Run checks" (`POST /health/run`, cần cap settings) + mốc "last checked" và cờ stale; (2) *"Email có thật sự đến không?"* đọc `GET /monitoring?days=30`: totals sent/failed/failure_rate (tô màu theo ngưỡng), per-provider signal (healthy/degraded/failing/insufficient_data) + **ghi rõ "kết quả gửi của site bạn, không phải uptime provider"**; (3) *"Hỏng thì vì sao?"* xếp hạng category từ `monitoring.categories`, mỗi dòng bấm được → set `logCategory` trong store rồi nhảy sang tab Logs đã lọc sẵn. `useOverview.ts`: hook `useHealth`/`useRunHealth` (ghi cache qua queryClient)/`useMonitoring`. **Log UI nâng cao** (`LogsTab.tsx`): thêm bộ lọc **category** (nguồn sự thật ở store `logCategory` để Overview deep-link được; đổi category reset page) + truyền `error_category` vào query & export URL; **detail dialog** thêm khối **"Why it failed"** (diagnosis explanation/action + badge category + cờ retryable, đọc `d.diagnosis` mà `/logs/{id}` trả khi FAILED) và khối **"Technical details"** (response_code/provider_message_id/duration_ms/retry_count/source/content_type + `technical` hoặc raw error). `useLogs.ts`: type `LogItem` +6 cột DL1, `LogDetail` +`diagnosis?`, `LogQuery` +`error_category`; `types.ts` +`CATEGORY_LABELS`/`categoryLabel`; store +`logCategory`/`setLogCategory`, mặc định section = `overview`. **Verify**: `pnpm type-check` sạch; `pnpm build` sạch (manifest cập nhật, asset cũ bị dọn). Không chạm PHP nên phpstan/phpcs DL2 giữ nguyên trạng thái sạch. |
| DL4 Queue + Retry (opt-in) | ☑ xong | **Mặc định OFF, giữ nguyên semantics `wp_mail()` đồng bộ.** **Schema** `0.2.0→0.3.0`: bảng mới `flexa_smtp_email_queue` (payload longtext = message serialize đầy đủ, status pending/claimed/failed, attempts/max_attempts, claim token, available_at/reserved_at cho backoff + stuck, log_id link tới log row; index status_available/claim/idempotency_key/log_id). Bảng cũ không đổi → BC ~0. **Payload** (`PhpMailerBridge::to_payload()/from_payload()`): serialize from/to/cc/bcc/reply-to/subject/body(đã tracking-rewrite)/alt/content_type/charset/headers/attachments (string → base64, file → path), rebuild lại PHPMailer để worker gửi; địa chỉ lỗi bị skip chứ không abort. **Queue engine** (`src/Queue/`): `QueueItem` VO, `QueueRepository` (insert, claim atomic `UPDATE ... ORDER BY available_at LIMIT` + claim token → không hai worker giành cùng row, reclaim_stuck 5', reschedule/mark_failed/mark_done xoá row done, dedupe active_key_exists, requeue/clear failed, stats, prepared toàn bộ), `Queue` service (enqueue, worker claim→gửi→settle, backoff [1',5',30',2h,6h], lock transient chống chồng run, tự lên lịch run kế cho retry đúng giờ backoff + tick an toàn 15'). **Trigger**: Action Scheduler nếu có (`as_enqueue_async_action`/`as_schedule_single_action`, deduped), fallback WP-Cron; queue table là nguồn sự thật bất kể trigger. **Retry chỉ lỗi retryable** (đọc `Result->retryable` từ Diagnostics DL1); auth/recipient/permanent → không retry. **MailerManager**: thêm nhánh queue trong `dispatch()` (fire before_send để có log row + tracking rồi enqueue, return true; enqueue fail → fallback gửi sync để không mất mail), nhánh retry-sync (lỗi retryable + retry ON → enqueue rồi return true), `deliver()` cho worker (chạy chain, không fire hook, không re-queue), `should_queue()`/`should_retry()` + filter bypass `flexa_smtp.mail.bypass_queue` (test email luôn gửi sync). **MailLogger**: `current_log_id()` + listener `flexa_smtp.mail.queued` settle QUEUED/RETRYING/CANCELLED (dedupe → CANCELLED). Worker settle log row trực tiếp → SENT/FAILED/RETRYING + retry_count. **Settings**: `enable_queue`/`enable_retry` (bool, default false), `queue_max_attempts` (int, default 3, clamp 1..10). **Lifecycle**: `Plugin::boot` +Queue; Deactivator `wp_clear_scheduled_hook` cho queue_run/queue_tick + `as_unschedule_all_actions`; Schema::TABLES + uninstall.php +bảng queue (Eraser drop tự động). **REST**: `GET /queue` (stats + engine info, read-only), `POST /queue/run|retry-failed|clear-failed` (settings cap). **Frontend**: section "Delivery Queue" (toggles queue/retry + max attempts + panel status live: waiting/in-progress/failed/due, runner AS-vs-WPCron, next run, nút Process now/Retry failed/Clear failed) qua `useQueue.ts`; `SettingsData` +3 field. **Seam cho Pro**: `flexa_smtp.queue.sent/retry_scheduled/failed`, filter `flexa_smtp.queue.dedupe/backoff/batch_size/time_budget/should_retry/use_action_scheduler`. **Verify**: php -l sạch; **phpstan L6 sạch**; **phpcs sạch 104 file** (tick 15' tránh cảnh báo CronInterval, retry đúng giờ nhờ single-event chính xác); `pnpm type-check` + `pnpm build` sạch (manifest cập nhật); **test round-trip payload 18/18 PASS** (from/to/cc/bcc/reply-to/subject/html/header/attachment string + rebuild + preSend dựng MIME đủ attachment/header/cc + plain-text). Không remote call trên request frontend (chỉ insert enqueue rẻ; gửi thật trong worker async). Chưa live-migrate được (shell không vào MySQL socket của Local; CREATE TABLE theo đúng style dbDelta hiện có, idempotent theo version). |

*Cập nhật (☐ chưa / ◐ đang làm / ☑ xong) mỗi khi hoàn thành một DL.*
