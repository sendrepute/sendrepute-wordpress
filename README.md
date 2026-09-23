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
`dist/sendrepute-0.1.0.zip`, then activate. Alternatively copy `sendrepute/`
into `wp-content/plugins/`. This is a source distribution, not a claim of
WordPress.org directory publication.

See [the plugin manual](sendrepute/readme.txt) for complete configuration,
scopes, billing, privacy, transport and uninstall instructions.

## Configure and use

In **Settings → SendRepute**, enter a server-side customer API key with the
appropriate scopes. Credentials are encrypted at rest using WordPress salts;
changing salts requires entering the credential again. Keep backups private.
You may instead set `SENDREPUTE_API_TOKEN` in private server configuration.
Do not commit keys, expose them to browsers, or paste them in support issues.

Enable analysis and separately confirm paid consent before enabling the mail
hook. Start with advisory mode. Blocking mode compares the returned score with
your threshold; choose fail-open or fail-closed deliberately. Test critical
transactional mail before enabling blocking. A blocked `wp_mail` returns false;
the plugin does not queue or retry the send.

Only sender display name, subject and the selected body are submitted to
`https://www.sendrepute.com/api/v1/classify`. Recipients and attachments are not
submitted. Credentials never follow redirects. Paid input changes may incur a
new charge; local opaque locks and API receipt replay reduce duplicate analysis,
not duplicate email sending. HTTP is never automatically retried.

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
authorization, nonce checks and cleanup. They do not certify every WordPress,
PHP or SMTP-plugin version. Server-internal contract tests are not part of this
standalone distribution. No GitHub compatibility-matrix result is claimed.

Deactivation retains settings. Uninstall deletes plugin data unless retention
was selected; even with retention the stored credential is removed and paid
consent disabled. Remove any configuration-defined credential yourself.

## Support and license

Report reproducible, non-sensitive bugs in this repository's GitHub issues.
For account support or private vulnerability reports: support@sendrepute.com.
Never attach customer email content, credentials or unredacted logs.
See [SECURITY.md](SECURITY.md). The plugin retains its declared
GPL-2.0-or-later license.