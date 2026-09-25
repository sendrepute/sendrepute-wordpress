> **Standalone source distribution:** this repository contains the integration runtime, documentation, and source packager. Upstream workspace/CMS/production-normalizer regression suites are deliberately not distributed here because they depend on private server code or isolated platform fixtures. Testing commands and historical verification evidence below describe upstream maintainer validation, not a self-contained test suite in this source-only checkout. No third-party registry publication is implied.

# SendRepute for WordPress

Optional paid pre-send email analysis and administrator-initiated AI tools.
Classification is a content safety signal, not a guarantee of delivery or inbox
placement. The plugin uses your existing mail transport; it does not send email
itself.

## Install

Requirements: WordPress 5.7+, PHP 7.4+ with OpenSSL. Prefer actively supported
WordPress and PHP versions. Python 3 is needed only to build the ZIP.

```sh
git clone https://github.com/sendrepute/sendrepute-wordpress.git
cd sendrepute-wordpress
python3 package.py
```

In WordPress, select **Plugins → Add New → Upload Plugin** and upload
`dist/sendrepute-0.2.0.zip`, then activate. This same ZIP includes optional
WooCommerce support; there is no separate WooCommerce ZIP. Alternatively copy `sendrepute/`
into `wp-content/plugins/`. This is a source distribution, not a claim of
WordPress.org directory publication.

See [the plugin manual](sendrepute/readme.txt) for complete configuration,
scopes, billing, privacy, transport and uninstall instructions.

## WordPress and WooCommerce integration surface

The plugin registers WordPress `pre_wp_mail` and WooCommerce
`woocommerce_mail_callback` filters. Current WooCommerce stock notifications,
which call `wp_mail()` directly, are identified only while the corresponding
official `woocommerce_low_stock_notification`,
`woocommerce_no_stock_notification`, or
`woocommerce_product_on_backorder_notification` action is running. The
original callback and all mail arguments remain unchanged.
If callback arguments no longer match because another filter mutated them, or
ordinary mail is nested during the Woo callback, analysis is conservatively
bypassed rather than falling back to the general paid mail path.

Configuration is stored per site in the non-autoloaded
`sendrepute_settings` option. Its WooCommerce keys are
`woocommerce_enabled` (boolean, default `false`) and `woocommerce_types`
(allowlisted email-ID array, default empty). `enabled` and `paid_consent` are
also both false by default and remain required. Paid consent stores the exact
four-field effective classification schedule plus an explicit per-request
maximum; it is not a fixed final quote. The encrypted credential is
stored separately in the non-autoloaded `sendrepute_token` option, unless the
private `SENDREPUTE_API_TOKEN` constant is defined. These option names and the
constant are the supported operator-facing integration points; internal class
methods are not a promised third-party PHP API.

## Configure and use

In **Settings → SendRepute**, enter a server-side customer API key with the
appropriate scopes. Credentials are encrypted at rest using WordPress salts;
changing salts requires entering the credential again. Keep backups private.
You may instead set `SENDREPUTE_API_TOKEN` in private server configuration.
Do not commit keys, expose them to browsers, or paste them in support issues.

Enable analysis and separately confirm paid consent before enabling the mail
hook. Review all four authenticated tariff fields and choose the maximum actual
charge permitted for each new request. They are sent as `priceAuthorization`;
the API checks current effective rates and the computed charge atomically at
settlement. `PRICE_CHANGED` is never retried or accepted automatically and
blocks that delivery even under fail-open. Refresh the settings page and
deliberately save new consent. This per-request authorization does not replace
the API key's cumulative spending cap. Exact completed receipt replays remain
free, and authorization is excluded from content/model replay identity.
Consent is bound to the active credential identity; rotating the API key
requires reviewing and saving consent again. Local replay locks are likewise
credential-scoped.
Start with advisory mode. Blocking mode compares the returned score with
your threshold; choose fail-open or fail-closed deliberately. Test critical
transactional mail before enabling blocking. A blocked `wp_mail` returns false;
the plugin does not queue or retry the send. WordPress-generated password
retrieval, password/email change, new-user access, and fatal-error recovery
messages are recognized from their final core filter payloads. They may be
analyzed when global analysis is enabled, but this adapter never blocks an
exact matching core-generated message.

WooCommerce support is a separate administrator opt-in on the same settings
page and is off by default. Select individual built-in WooCommerce email types;
unselected and extension-defined types bypass analysis. Customer authentication,
payment, invoice, note and order-status types marked **protected** may be
analyzed when selected but are not blocked by risk, ordinary API failure, or
unsupported content. The billing-consent exception is `PRICE_CHANGED`, which
blocks any selected delivery rather than accepting a new tariff. WooCommerce
multipart messages are not approved from only one alternative: no paid request
is made, fail-open/advisory continues, and fail-closed blocks only selected,
non-protected types.

Only sender display name, subject and the selected body are submitted to
`https://www.sendrepute.com/api/v1/classify`. Recipients and attachments are not
submitted. Credentials never follow redirects. Paid input changes may incur a
new charge; local opaque locks and API receipt replay reduce duplicate analysis,
not duplicate email sending. HTTP is never automatically retried.
Responses are capped at 1 MiB and must match the typed public classification
result and billing contract before any local policy decision is used.

Use the administrator manual actions for rewrite, standard AI templates and VIP
AI templates. These require explicit confirmation, sufficient scopes/balance,
and current pricing. Results are escaped, copy-only output, never automatically
applied or sent. Standard template generation requires the effective expected
price; rewrite is variable-priced, not a fabricated advance quote.

## Offline development tests

From the checkout root:

```sh
php tests/fixtures.php
php tests/admin-fixtures.php
php tests/lifecycle-fixtures.php
find sendrepute tests -name '*.php' -print0 | xargs -0 -n1 php -l
python3 package.py
```

These tests use synthetic WordPress doubles, make no paid calls and send no
email. They cover consent, duplicate protection, response validation, admin
authorization, nonce checks, Woo selection/protection, callback cleanup and
cleanup. The real WordPress harness installs the same ZIP and blocks HTTP and
mail. The adapter was checked against the official WooCommerce 10.4.3
`WC_Email::send()` source path (`woocommerce_mail_callback` then
`woocommerce_mail_callback_params` then the selected callback); this is source
validation, not a WooCommerce compatibility certification. It does not certify
every WordPress, PHP, WooCommerce or SMTP-plugin version. Server-internal contract tests are not part of this
standalone distribution. No GitHub compatibility-matrix result is claimed.

An isolated, installed-framework check is documented in
[INSTALLED_TESTING.md](INSTALLED_TESTING.md). Its narrow covered tuple must not
be confused with the broader core-only matrix.

Deactivation retains settings. Uninstall deletes plugin data unless retention
was selected; even with retention the stored credential is removed and paid
consent disabled. Remove any configuration-defined credential yourself.

## Support and license

Report reproducible, non-sensitive bugs in this repository's GitHub issues.
For account support or private vulnerability reports: support@sendrepute.com.
Never attach customer email content, credentials or unredacted logs.
See [SECURITY.md](SECURITY.md). The plugin retains its declared
GPL-2.0-or-later license.
