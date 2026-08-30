=== Flexa SMTP ===
Contributors: flexatech
Tags: smtp, wp mail smtp, email log, email tracking, mailer
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress email reliably through your preferred SMTP or API mailer, with email logs, open/click tracking, and reports.

== Description ==

Flexa SMTP routes WordPress email through your chosen provider (custom SMTP, or API/OAuth mailers) so your messages actually get delivered. It logs every email, tracks opens and clicks, and sends periodic delivery reports.

This is an early foundation build (WP0): plugin bootstrap, settings option, and the email-log database schema. Mailer providers, logging UI, tracking, and reports land in subsequent releases per the implementation plan.

== Installation ==

1. Upload the `flexa-smtp` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.

== Changelog ==

= 0.1.0 =
* Initial foundation: bootstrap, settings option seeding, email-log schema, activation/uninstall lifecycle.
