=== Flexa SMTP ===
Contributors: flexatech
Tags: smtp, wp mail smtp, email log, email tracking, mailer
Requires at least: 6.2
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

== External services ==

Flexa SMTP does not contact any external service on its own. It connects to a mail provider **only when you select and configure that provider as your mailer** (or connect it via OAuth). Each provider below is contacted solely to deliver the WordPress email you send. In every case the request happens when an email is sent, and the data transmitted is the outgoing message (recipients, subject, body, headers, and any attachments) together with the credentials you entered for that provider (sent as an authorization header). No data is sent to the plugin author, and no analytics or telemetry is collected.

For the OAuth mailers (Gmail, Outlook, Zoho) there is an additional one-time authorization step: when you click "Connect", your browser is redirected to the provider's sign-in page, and the plugin then exchanges the returned authorization code for an access token over HTTPS. Tokens are stored encrypted and refreshed with the provider as needed.

The Custom SMTP and IONOS mailers connect over SMTP to the host you configure (for IONOS, one of `smtp.ionos.com` / `.de` / `.es` / `.fr` / `.co.uk`); no third-party HTTP API is involved.

API mailers (contacted at send time):

* **SendGrid** — `https://api.sendgrid.com`. Terms: https://www.twilio.com/en-us/legal/tos — Privacy: https://www.twilio.com/en-us/legal/privacy
* **Mailgun** — `https://api.mailgun.net` (or `https://api.eu.mailgun.net`). Terms: https://www.mailgun.com/legal/terms/ — Privacy: https://www.mailgun.com/legal/privacy-policy/
* **Brevo** — `https://api.brevo.com`. Terms: https://www.brevo.com/legal/termsofuse/ — Privacy: https://www.brevo.com/legal/privacypolicy/
* **Postmark** — `https://api.postmarkapp.com`. Terms: https://postmarkapp.com/terms-of-service — Privacy: https://postmarkapp.com/privacy-policy
* **Mailjet** — `https://api.mailjet.com`. Terms: https://www.mailjet.com/legal/terms/ — Privacy: https://www.mailjet.com/legal/privacy-policy/
* **SparkPost** (now part of Bird) — `https://api.sparkpost.com` (or `https://api.eu.sparkpost.com`). Terms: https://bird.com/en-us/legal/terms — Privacy: https://bird.com/en-us/legal/privacy
* **SMTP.com** — `https://api.smtp.com`. Terms: https://www.smtp.com/policies/terms-conditions/ — Privacy: https://www.smtp.com/policies/privacy-policy/
* **SendPulse** — `https://api.sendpulse.com` (an OAuth token is fetched from the same host before sending). Terms: https://sendpulse.com/legal/terms — Privacy: https://sendpulse.com/legal/pp
* **Pepipost / Netcore** — `https://api.pepipost.com`. Terms: https://netcorecloud.com/terms-of-service/ — Privacy: https://netcorecloud.com/privacy-policy/
* **Mandrill** — `https://mandrillapp.com`. Terms: https://mailchimp.com/legal/terms/ — Privacy: https://mailchimp.com/legal/privacy/
* **Yournotify** — `https://api.yournotify.com`. Terms: https://yournotify.com/terms — Privacy: https://yournotify.com/privacy-policy/
* **Amazon SES** — `https://email.{region}.amazonaws.com`. Terms: https://aws.amazon.com/service-terms/ — Privacy: https://aws.amazon.com/privacy/

OAuth mailers (authorization + send):

* **Gmail / Google Workspace** — `https://accounts.google.com`, `https://oauth2.googleapis.com`, `https://gmail.googleapis.com`. Terms: https://policies.google.com/terms — Privacy: https://policies.google.com/privacy
* **Outlook / Microsoft 365** — `https://login.microsoftonline.com`, `https://graph.microsoft.com`. Terms: https://www.microsoft.com/servicesagreement — Privacy: https://privacy.microsoft.com/privacystatement
* **Zoho Mail** — `https://accounts.zoho.{region}` and `https://mail.zoho.{region}` (region one of com/eu/in/com.au/jp). Terms: https://www.zoho.com/terms.html — Privacy: https://www.zoho.com/privacy.html

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
