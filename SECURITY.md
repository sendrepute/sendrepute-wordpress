# Security

Report vulnerabilities privately to support@sendrepute.com with the affected
version, impact and a redacted reproduction. Never include API keys, customer
messages, database dumps or personal information. Do not disclose exploit
details in public issues before a fix is coordinated. No response-time SLA is
promised. Version 0.2.x is the supported source line.

Keep WordPress, PHP and SMTP plugins supported and updated. Store API keys only
in private server configuration or the encrypted settings field. Protect salts
and backups. Enable paid analysis explicitly and test fail-open/fail-closed
behavior on critical mail. Classification is not a delivery guarantee.

WooCommerce analysis is independently disabled by default. Selected protected
customer mail can be analyzed but cannot be blocked by SendRepute. Multipart
WooCommerce mail is never classified from only one displayed alternative:
unsupported selected mail follows fail-open/fail-closed policy without a paid
request, while protected customer mail continues. The callback wrapper retains
the transport chosen by WooCommerce/SMTP plugins and removes request context in
a `finally` block after success, failure, or nested sends. Do not include mail
content, recipients, credentials, attachments, or HTTP bodies in diagnostics.
Active Woo callback context is fail-safe: if another Woo filter mutates callback
arguments, or unrelated mail is nested inside that callback, the mismatch
bypasses analysis instead of falling back to general paid classification.
API responses are buffered only to a 1 MiB ceiling plus one overflow-detection
byte; declared or observed overflow and malformed typed result/billing data are
rejected under the configured failure policy.