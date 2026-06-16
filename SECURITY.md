# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 2.6.x (latest) | ✅ Active support |
| 2.5.x | ✅ Security fixes only |
| < 2.5.0 | ❌ No longer supported |

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

### GitHub Actions
- The deploy workflow runs with `permissions: contents: read` — minimal necessary access.
- All third-party actions are pinned to immutable SHA digests (not mutable tags).
- SVN credentials are stored exclusively in GitHub Encrypted Secrets (`SVN_USERNAME`, `SVN_PASSWORD`) — never hardcoded.
