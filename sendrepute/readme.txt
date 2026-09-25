=== SendRepute ===
Requires at least: 5.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later

Optional paid email analysis before WordPress mail transport, with manual AI tools.

== Release prerequisite ==
Development package, not a claim of production rollout. Before enabling paid
features, the SendRepute operator must apply migration 0081 and release the
expanded customer API. This plugin does not apply migrations or deploy anything.

== Installation ==
Upload sendrepute-0.2.0.zip in Plugins > Add New > Upload Plugin. Activate it,
then open Settings > SendRepute. Analysis is disabled by default. Configure a
scoped customer token and run the non-paid connection check. Review the current
classification tariff and explicitly consent before enabling analysis.
Consent records all four effective rate fields and an explicit maximum actual
charge for each new classification; this variable tariff is not a fixed quote.
The API checks both at settlement. PRICE_CHANGED is never retried or accepted
automatically and blocks that delivery even with fail-open selected. Review the
new tariff and save fresh consent. Per-request authorization remains separate
from cumulative API-key spending caps. Exact completed receipt replays stay free.
Minimum scopes: classify, account:read, catalog:read. Manual AI price display
also requires vip:read; rewrite requires rewrite; template generation requires
ai:generate, with an active VIP membership for VIP generation. Keep spend caps
and expiry on the token. Existing classification tokens gain no new permissions.

WooCommerce support is included in this same ZIP and has a separate opt-in on
Settings > SendRepute. It is off by default. Administrators select individual
built-in email types; unselected and extension-defined types bypass analysis.
The global analysis switch and paid consent must also be enabled.
Per-site settings are stored in the non-autoloaded sendrepute_settings option;
Woo keys are woocommerce_enabled (false by default) and woocommerce_types
(empty by default). The encrypted credential uses the separate non-autoloaded
sendrepute_token option unless SENDREPUTE_API_TOKEN is defined. Internal PHP
class methods are not a promised extension API.

== Security and privacy ==
Token storage is authenticated AES-256-GCM encrypted using WordPress salts,
non-autoloaded, and never populated into the settings form. OpenSSL is required.
Alternatively define SENDREPUTE_API_TOKEN in wp-config.php from your server's
secret store. Rotate/re-enter the token after rotating WordPress salts.
Database and server administrators can still access credentials; encryption
does not protect a fully compromised WordPress installation.

Analysis transmits sender display name, subject, and body to
https://www.sendrepute.com/api over verified HTTPS. Recipients and attachments
are not transmitted separately, but anything inside the body is transmitted.
No full messages or AI results are stored by this plugin. Third-party HTTP
debuggers may capture requests: disable credential/body logging on your server.
Consult SendRepute's privacy terms before processing personal or sensitive data.

== Behavior ==
Uses the official pre_wp_mail filter (WordPress 5.7+), after wp_mail filtering.
Null means continue the existing WordPress/SMTP transport; false means blocked.
Earlier non-null hook decisions are respected. Recipients, headers, attachments,
and content are never rewritten, and no SMTP configuration is replaced.
Analysis reflects the message at this hook, not later SMTP-plugin alterations.
WordPress password retrieval, password/email change, new-user access and
fatal-error recovery messages are matched from their final core email filter
payloads. They may be analyzed when enabled, but an exact match is never
blocked by this plugin.
Plugins that bypass wp_mail entirely are outside this integration.
Advisory mode continues sending regardless of classification; block mode
compares the returned probability to the configured threshold. Error policy is
independent: fail-open continues, fail-closed returns false, including timeouts.
Blocking does not queue a message. The caller must handle false and any retry.
This tool does not guarantee delivery or Inbox placement.

Opaque HMAC locks and safe result metadata suppress duplicate analysis for
24 hours; the API also provides account/content/model replay protection.
Unknown network outcomes are suppressed rather than automatically retried.
The plugin never retries HTTP automatically and never sends a manual idempotency
header. Repeated sends themselves are not deduplicated. Changed content/model
can incur a new charge. Removing plugin data discards local replay protection.
Manual AI actions require separate confirmation and display effective pricing.
Results are escaped copy-only text; never automatically sent or applied.

== WooCommerce behavior ==
The adapter uses WooCommerce's woocommerce_mail_callback filter and calls the
existing callback with the original arguments. It does not replace SMTP
transport or change recipients, headers, body, or attachments. Context is scoped
to the matching callback arguments and removed after success or failure, so
nested mail cannot inherit stale WooCommerce state. If a custom callback never
calls wp_mail, SendRepute does not analyze that message.
Official low-stock, no-stock and backorder paths call wp_mail directly; those
types are recognized only while their corresponding WooCommerce notification
action is running.
If callback arguments are changed after context capture, or unrelated mail is
nested inside a Woo callback, the mismatch bypasses analysis and never falls
back to ordinary paid classification.

Customer authentication, payment, invoice, note, and order-status types marked
protected in the UI are not blocked by risk, ordinary API failure, or
unsupported content, including password reset and new-account access mail.
The billing-consent exception is PRICE_CHANGED, which blocks any selected
delivery rather than authorizing a new tariff. Selection otherwise permits
advisory analysis; it does not permit blocking those messages. WooCommerce builds multipart AltBody later at
phpmailer_init, after pre_wp_mail. SendRepute therefore never approves or pays
for multipart mail based on HTML alone: selected non-protected multipart mail
continues under fail-open/advisory and returns false under fail-closed; protected
customer mail always continues.

== Data retention ==
Deactivation keeps settings and opaque retry metadata. Uninstall defaults to
deleting plugin options. Opt-in retention keeps settings and retry metadata,
but always removes the stored token and disables analysis and paid consent.
A wp-config token is outside plugin storage and must be removed by the operator.
Uninstall covers all sites on multisite; configure/activate per site, not network
activation. No compatibility certification for particular SMTP plugins is claimed.

== Compatibility and validation ==
Targets WordPress 5.7+ / PHP 7.4+ with OpenSSL. The Woo adapter was reviewed
against the official WooCommerce 10.4.3 WC_Email::send source path; no broad
WooCommerce version certification is claimed. Offline fixtures verified on PHP
8.4, not a full real-WordPress or cross-version certification. ZIP contains no
dependencies, customer credentials, messages, test stubs, or build tooling.