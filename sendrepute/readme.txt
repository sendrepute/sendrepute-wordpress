=== SendRepute ===
Requires at least: 5.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later

Optional paid email analysis before WordPress mail transport, with manual AI tools.

== Release prerequisite ==
Development package, not a claim of production rollout. Before enabling paid
features, the SendRepute operator must apply migration 0081 and release the
expanded customer API. This plugin does not apply migrations or deploy anything.

== Installation ==
Upload sendrepute-0.1.0.zip in Plugins > Add New > Upload Plugin. Activate it,
then open Settings > SendRepute. Analysis is disabled by default. Configure a
scoped customer token and run the non-paid connection check. Review the current
classification tariff and explicitly consent before enabling analysis.
Minimum scopes: classify, account:read, catalog:read. Manual AI price display
also requires vip:read; rewrite requires rewrite; template generation requires
ai:generate, with an active VIP membership for VIP generation. Keep spend caps
and expiry on the token. Existing classification tokens gain no new permissions.

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

== Data retention ==
Deactivation keeps settings and opaque retry metadata. Uninstall defaults to
deleting plugin options. Opt-in retention keeps settings and retry metadata,
but always removes the stored token and disables analysis and paid consent.
A wp-config token is outside plugin storage and must be removed by the operator.
Uninstall covers all sites on multisite; configure/activate per site, not network
activation. No compatibility certification for particular SMTP plugins is claimed.

== Compatibility and validation ==
Targets WordPress 5.7+ / PHP 7.4+ with OpenSSL. Offline fixtures verified on PHP
8.4, not a full real-WordPress or cross-version certification. ZIP contains no
dependencies, customer credentials, messages, test stubs, or build tooling.