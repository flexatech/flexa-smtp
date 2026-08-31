=== Flexa SMTP ===
Contributors: flexatech
Tags: smtp, wp mail smtp, email log, email tracking, mailer
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress email reliably through your preferred SMTP or API mailer, with email logs, open/click tracking, and delivery reports.

== Description ==

Flexa SMTP reroutes every WordPress email (`wp_mail()`) through the mailer you choose so your messages actually get delivered instead of landing in spam. It logs what you send, tracks opens and clicks, and emails you periodic delivery reports.

= Mailers =

Configure the From name/address (with optional force overrides), pick a primary mailer, and set a fallback mailer that takes over automatically if the primary fails. Eighteen transports are supported:

* Custom SMTP (any host)
* SendGrid, Mailgun, Brevo (Sendinblue), Postmark, Mailjet, SparkPost, SMTP.com, SendPulse, Pepipost, Mandrill, Amazon SES, Yournotify
* IONOS
* Gmail / Google Workspace, Outlook / Microsoft 365, and Zoho Mail (OAuth — connect with a click, no password stored)
* WordPress default (`mail()`)

API credentials and OAuth tokens are encrypted at rest (AES-256-GCM) and are never returned to the browser in plain text.

= Email logs =

Every message is logged with its status (sent / failed / pending), mailer, recipients, subject, source, and the exact error when a send fails. Browse, search, and filter the log, open any entry to see its full body and engagement, and export the current view to CSV. Set a retention window to prune old logs automatically.

= Open & click tracking =

Optionally embed a tracking pixel and rewrite links so you can see which emails were opened and which links were clicked, per message.

= Reports =

A dashboard widget and a Reports tab summarise delivery trends (sent vs. failed, open and click rates, busiest mailers, top links) over 7/30/90 days, with optional weekly and monthly digest emails.

= WP-CLI =

* `wp flexa-smtp log list` — list/filter the email log (status, mailer, search, date range; table/csv/json output)
* `wp flexa-smtp log get <id>` — show one entry in full
* `wp flexa-smtp test <to>` — send a test email through the active mailer
* `wp flexa-smtp reset` — wipe all Flexa SMTP data

= Import =

Migrate settings and logs from WP Mail SMTP, Easy WP SMTP, SMTP Mailer, WP SMTP, or WP Mail Bank. Secrets are re-encrypted on import.

== Installation ==

1. Upload the `flexa-smtp` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Flexa SMTP** in the admin menu, choose your mailer, enter its credentials (or connect via OAuth), and send a test email.

== Frequently Asked Questions ==

= Are my API keys and passwords safe? =

Yes. Every secret credential and OAuth token is encrypted with AES-256-GCM before it is stored, and secrets are masked (never sent in plain text) when the settings screen loads.

= Does tracking require logging? =

Yes. Open/click tracking is recorded against the email log, so email logging must be enabled for tracking to work.

= Can I use it with WP-CLI? =

Yes — see the WP-CLI commands listed in the description.

== Changelog ==

= 1.0.0 =
* First stable release.
* 18 mailers: custom SMTP, 13 API providers, 3 OAuth providers (Gmail/Outlook/Zoho), IONOS, and WordPress default, with a configurable fallback mailer.
* Email logging with search/filter, per-message detail, CSV export, and automatic retention.
* Open and click tracking.
* Delivery reports (dashboard widget, Reports tab, weekly/monthly digests).
* Import of settings and logs from five other SMTP plugins.
* WP-CLI: `log list`, `log get`, `test`, `reset`.
* All secrets encrypted at rest (AES-256-GCM); OAuth and tracking endpoints use HMAC-signed tokens.
