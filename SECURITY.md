# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 2.8.x (latest) | ✅ Active support |
| 2.7.x | ✅ Security fixes only |
| < 2.7.0 | ❌ No longer supported |

## Reporting a Vulnerability

**Please do not report security vulnerabilities via GitHub Issues** — issues are public and could expose users before a fix is available.

Instead, email: **mustafa@tahhan.cloud**

Include in your report:
- A clear description of the vulnerability
- Steps to reproduce
- Potential impact (which users / scenarios are affected)
- WordPress and PHP version you tested on
- Any proof-of-concept code (optional but helpful)

### What to expect

| Step | Timeframe |
|------|-----------|
| Acknowledgement of your report | Within 48 hours |
| Confirmation whether it is a valid vulnerability | Within 7 days |
| Fix released (if confirmed) | Within 14 days for critical, 30 days for others |
| Public disclosure | After the fix is released and users have had time to update |

You will be credited in the release notes (unless you prefer to remain anonymous).

## Security Design

### Safe Testing Mode
- Uses a 32-byte cryptographically random token (`bin2hex(random_bytes(32))`) stored in user meta.
- Cookie is `HttpOnly`, `SameSite=Strict`, and `Secure` on HTTPS sites.
- Token is verified server-side on every request — the cookie value alone cannot be forged.
- Visitors are never affected; only the admin session that started Safe Mode is impacted.

### Database queries
- All queries use `$wpdb->prepare()` combined with `$wpdb->esc_like()` where needed.
- The one direct `$wpdb` query in `Safe_Mode` bypasses `get_option()` intentionally to prevent infinite recursion inside `pre_option_active_plugins`; the result is immediately wrapped in `wp_cache_set()`.

### Output escaping
- All output uses `esc_html()`, `esc_attr()`, `esc_url()`, or `wp_kses_post()` as appropriate.

### AJAX / REST handlers
- Every AJAX action is protected by `check_ajax_referer('tahcd_nonce', 'nonce')`.
- All admin pages and AJAX handlers check `current_user_can('manage_options')`.
- Plugin slugs submitted via AJAX are validated against `get_plugins()` before use.
- Cron hook names submitted via AJAX are validated against the live WP-Cron schedule before execution.

### Agency Edition (2.7.0+)
- **API keys**: `tahcd_` + 64 hex chars from `random_bytes(32)`. Only `sha256(key)` is stored; verification uses `hash_equals`. Keys are accepted only in the `X-Tahcd-Key` header, never in a URL. Optional expiry; rotation and revocation take effect immediately.
- **Status endpoint**: read-only, returns counts and plugin names only. Unknown and invalid keys get the same 401. Failed attempts are throttled per IP (per /64 for IPv6) in a fixed 15-minute window; a valid key is never throttled. Sites behind a reverse proxy can map the real client IP with the `tahcd_rest_client_ip` filter.
- **Outbound requests (hub)**: all A/AAAA records are resolved; the host is rejected if any address is loopback, private, link-local, CGNAT, reserved, multicast or IPv4-mapped. cURL is pinned to the vetted address (`CURLOPT_RESOLVE`) and limited to HTTPS; requests that cannot be pinned fail closed. Port 443 only, no redirects, 64 KB response limit.
- **Stored remote keys**: encrypted with libsodium `secretbox`, key derived from `wp_salt('auth')`. Note: if your salts are stored in the database instead of `wp-config.php`, a database leak also exposes the derivation key.
- **Untrusted remote data**: reduced to a fixed, typed, length-limited structure before storage and escaped on output.
- **Email alerts**: recipients validated with `is_email` (max 10), control characters stripped, links in log excerpts defanged, plain-text only, test email throttled.
- **Multisite**: features that expose the shared error log or make outbound requests require `manage_network_options`.
- **Shared helpers (2.8.0+)**: all outbound requests to admin-configured URLs (hub polling and alert webhooks) go through `Safe_Http`; all secrets at rest (remote API keys, webhook URL) go through `Secret_Box` with a separate derived key per purpose.
- **Webhooks**: URL encrypted at rest and never sent back to the browser (only host + last 4 characters are shown). Slack `<!channel>`-style tokens are escaped and Discord mentions are disabled (`allowed_mentions`), so log text cannot ping a channel.
- **Unsubscribe endpoint**: the only unauthenticated action besides the status endpoint. Authorised by a 128-bit HMAC token derived from the AUTH salt (the URL never contains the address); GET only shows a confirmation, removal requires POST (form or RFC 8058 one-click); it can only remove the matching address.
- **Recipient privacy**: one email per recipient; addresses are never disclosed to each other.

### GitHub Actions
- The deploy workflow runs with `permissions: contents: read` — minimal necessary access.
- All third-party actions are pinned to immutable SHA digests (not mutable tags).
- SVN credentials are stored exclusively in GitHub Encrypted Secrets (`SVN_USERNAME`, `SVN_PASSWORD`) — never hardcoded.
