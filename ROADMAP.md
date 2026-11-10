# Roadmap — Tahhan Conflict Detective

> **Mission:** Answer "which plugin broke my site?" automatically — without the user ever having to disable plugins by hand.

**Target audience:** Website owners · Web designers · Freelancers · Hosting companies · WordPress agencies

**Current stable version:** 2.8.0 — released 2026-11-10  
**WordPress.org slug:** `tahhan-conflict-detective`  
**GitHub:** https://github.com/Tahhan-nl/Tahhan-Conflict-Detective

---

## Phase 1 — MVP ✅ Complete (v1.0.0)

### Dashboard
Top-level admin menu → **Conflict Detective**

- Active plugins with versions
- Recently activated/updated plugins
- PHP version · WordPress version · Active theme · Memory limit · Debug mode
- Latest error log entries
- Recent change summary

### Error Log Viewer
Automatically reads:
- `wp-content/debug.log`
- Server PHP `error_log` (via `ini_get('error_log')`)

Each entry is **attributed to the plugin** that owns the file where the error occurred.  
Filter bar: Fatal · Warning · Notice · Deprecated

Output example:
```
Fatal Error  |  WooCommerce
Call to undefined method WC_Gateway::process_payment()
wp-content/plugins/woocommerce/includes/gateways/...  :247
```

### Plugin Change History
Full audit trail with exact timestamps and version diffs:

| Date & Time         | Plugin      | Action   | Version       |
|---------------------|-------------|----------|---------------|
| 02-06-2026 12:10:05 | WooCommerce | Updated  | 8.3.0 → 8.4.0 |
| 02-06-2026 12:10:58 | Elementor   | Updated  | 3.20 → 3.21   |

Events logged: Activated · Deactivated · Updated · Installed · Deleted

### Health Scan
On-demand scan across three areas:

**Plugins**
- Duplicate functionality (SEO, caching, security, backup, contact forms, page builders, e-commerce)
- Known incompatibilities between specific plugin pairs
- Outdated plugins (not updated in > 2 years)

**Theme**
- Missing core files (`functions.php`, `style.css`, `index.php`)
- Missing parent theme

**Server**
- PHP version (min 7.4, recommended 8.2+)
- Memory limit (min 64 MB, recommended 256 MB)
- `max_execution_time` (min 30 s)
- WordPress core update pending

---

## Phase 2 — Smart Detection ✅ Complete (v2.0.0)

### Conflict Scanner
Automatically correlates plugin update timestamps with error-log spikes:

```
Suspect plugin:  WooCommerce  (updated 12:10)
First error:     woocommerce-gateway.php  (12:11)
Confidence:      92%
```

Detected conflicts are stored in the database and can be marked as resolved with one click.

### Safe Testing Mode
*Unique feature — visitors are never affected.*

Disable any plugin for your own admin session (cookie-isolated) while the live site stays completely intact. A 32-byte signed token is stored in user meta and verified on every request.

```
WooCommerce   [OFF — admin only]
Elementor     [ON]
RankMath      [ON]
```

Visitors always load the fully active site. The cookie is HttpOnly, SameSite=Strict, and Secure on HTTPS.

### Conflict Wizard
Step-by-step guided diagnosis for 7 symptom categories:

1. White screen of death
2. Login problem
3. WooCommerce issue
4. Slow site
5. Broken admin panel
6. Front-end error
7. Other

Each symptom triggers an automatic analysis linking recent changes to matching errors, producing a tailored action plan.

---

## Phase 3 — Advanced Analysis ✅ Complete (v2.6.0)

### Performance Monitor
Hooks `plugin_loaded` (fires once per plugin) to capture a before/after snapshot of:
- PHP execution time (ms)
- Memory usage delta (bytes)
- Database query count delta

Results are stored in a 5-minute transient, color-coded, and refreshable on demand:

| Plugin       | Load time | Memory | DB queries | Rating  |
|--------------|-----------|--------|------------|---------|
| Elementor    | 42 ms     | 1.1 MB | 8          | Slow    |
| WooCommerce  | 118 ms    | 3.4 MB | 24         | Heavy   |
| Akismet      | 4 ms      | 0.1 MB | 1          | Fast    |

Thresholds: Fast < 100 ms / < 2 MB / < 10 queries. Slow/Heavy above those values.

### Cron Monitor
Full visibility into the WordPress Cron schedule:

- Lists all events from `_get_cron_array()` with their next run time and interval
- Overdue events are highlighted in red; overdue count shown as a badge
- **Run Now** button per event — hook name is validated against the live schedule before `do_action()` fires, preventing arbitrary code execution via this interface

### AJAX / REST Monitor
Automatically logs slow AJAX and REST API calls (> 500 ms):

- Stores to dedicated `{prefix}cd_ajax_log` table (schema version 3)
- Filter bar: All · AJAX · REST · Slow
- Table auto-trims to the most recent 500 entries
- Columns: type, action/endpoint, duration (ms), HTTP status, user, timestamp

### Plugin Interaction Map
Groups all installed plugins into known ecosystem clusters:

| Ecosystem | Example plugins |
|-----------|----------------|
| WooCommerce | WooCommerce, WC Subscriptions, WC Memberships, Stripe for WC |
| Elementor | Elementor, Elementor Pro, Essential Addons |
| Yoast SEO | Yoast SEO, Yoast SEO Premium, Yoast WooCommerce SEO |
| Jetpack | Jetpack, Jetpack Protect |
| ACF | Advanced Custom Fields, ACF Pro |
| Gravity Forms | Gravity Forms, GF add-ons |
| Contact Form 7 | CF7, CF7 add-ons |
| WPML | WPML, WPML add-ons |
| LearnDash | LearnDash, LearnDash add-ons |
| Divi | Divi, Extra Theme, Bloom, Monarch |
| WP Rocket | WP Rocket, Imagify |
| + more | … |

Also reads the `Requires Plugins` header (WordPress 6.5+) for explicit declared dependencies. Standalone plugins are listed separately.

---

## Phase 4 — Agency Edition 🚧 In progress (v2.7.0 → v2.9.0) — 3 of 4 parts done

> **Goal:** Let web agencies and freelancers monitor many client sites from a single dashboard — without logging into each WP admin separately.

**Design decision (v2.7.0):** the hub is built into the plugin itself (Agency tab) instead of a separate standalone app. Every install can be a monitored site, a hub, or both. No external service, no account, nothing leaves the network except requests the admin configures.

### 4.1 — Multi-site Dashboard ✅ v2.7.0

- Read-only endpoint `GET /wp-json/tahhan-cd/v1/status` — status (ok/warning/critical), conflict count + top suspect, fatal errors last 24 h, health issues (total + critical), last plugin change, WP/PHP/plugin versions. No paths or log text.
- Hub: up to 50 sites, URL + API key, polled every 5 / 15 / 60 min via WP-Cron or with **Check now**; results cached in `tahcd_remote_status`.
- SSRF-hardened outbound requests (all A/AAAA public, IP pinned, HTTPS/443, no redirects), remote keys encrypted with libsodium.
- Per-site result saved immediately; run time bounded below `max_execution_time`.

**Added in v2.8.0:** overview stat bar (critical / unreachable / warning / all clear), table sorted by severity, critical-health column (critical / total), API-key expiry warning per site.

**Still open (v2.9.0):**
- Search/filter in the site table; "last seen OK" timestamp per site.
- Bulk import/export of the site list (keys excluded from export).

### 4.2 — Alerts ✅ v2.7.0 + v2.8.0

v2.7.0:
- Hourly check for new fatal errors, conflicts ≥ 50 %, critical health issues (severity: critical / high / all).
- Up to 10 recipients, deduplicated per conflict and per health issue, no backlog mail on enable, throttled test.

v2.8.0:
- **Hub alerts** — a monitored site that is *critical* or *unreachable* for two consecutive polls triggers one alert (max one per site per 6 h, max 6 hub messages per hour overall); recovery after two good polls triggers one more; a warning two weeks before a site's API key expires. Always immediate. If every site is unreachable in the same run, the alert notes that the hub's own connection may be down.
- **Delivery** — immediate · daily digest · weekly digest (queued items capped at 50 per section).
- **Privacy** — one email per recipient (no shared To: line), personal HMAC unsubscribe link, RFC 8058 one-click headers; GET shows a confirmation so link scanners cannot unsubscribe anyone.
- **Webhook** — Slack/Mattermost, Discord, Teams Workflows (Adaptive Card) or generic JSON; URL encrypted at rest, same SSRF guard as the hub, mentions neutralised, last delivery status shown.

**Still open:** per-recipient severity (e.g. client gets critical only, agency gets everything); quiet hours.

### 4.3 — PDF / Printable Reporting 🔲 v2.9.0

| Section | Content |
|---------|---------|
| Executive summary | Status, open issues, trend vs. previous period |
| Detected conflicts | List with confidence scores and timestamps |
| Performance overview | Slow / Heavy plugins with load times |
| Change history | All plugin changes in the report period |
| Recommendations | Prioritised action list |

**Approach (revised):** step 1 is a print-optimised HTML report (`@media print`, browser "Save as PDF") — zero dependencies, fully WP.org-compliant. A bundled PHP PDF library is only considered afterwards, because it adds ~2 MB and a third-party licence to review. White-label: agency name, logo (media library) and accent colour.

### 4.4 — API Key Management ✅ v2.7.0

- 256-bit key, shown once, SHA-256 hash only, `hash_equals` verification.
- Expiry 30 / 90 / 365 days or never; rotation and instant revocation; "last used" timestamp.
- Read-only scope by design (the endpoint has no write actions).
- Failed attempts throttled per IP (/64 for IPv6); a valid key is never throttled.

**Added in v2.8.0:** expiry warning on the hub (table + one alert, 14 days ahead) and a notice on the monitored site's own admin pages; the status cache is flushed on rotation/revocation.

**Still open:** an optional reminder email on the monitored site itself.

### Release plan

| Version | Scope |
|---------|-------|
| 2.7.0 ✅ | 4.1 hub + endpoint, 4.2 local alerts, 4.4 API keys, SSRF/crypto hardening, multisite restrictions |
| 2.8.0 ✅ | Hub alerts (critical/unreachable/recovery/key expiry), digest modes, unsubscribe link, webhooks, overview bar, per-recipient privacy fix; shared Safe_Http/Secret_Box helpers |
| 2.9.0 | 4.3 printable HTML report + white-label + scheduled report email; site search/filter, "last seen OK", import/export; test suite moved into the repo with CI |
| 2.10.0 | Per-recipient severity, quiet hours, monitored-site key reminder email |
| 3.0.0 | Phase 5 (below) |

**Quality gate for every release:** unit tests green, three security + two development review passes on new code, docs (readme, changelogs, roadmap, SECURITY.md) updated in the same commit.

---

## Phase 5 — Deeper Diagnosis 🔲 Idea backlog

- **Automatic bisection in Safe Mode** — disable half of the suspect plugins, ask "is the problem gone?", repeat: finds the culprit in log₂(n) steps.
- **Front-end JavaScript error capture** (opt-in, admin sessions only) to attribute JS errors to plugin assets.
- **Update guard** — snapshot error rate before/after each plugin update and flag regressions within the first hour.
- **WP-CLI commands** — `wp tahcd scan`, `wp tahcd status --format=json` for agencies using CLI tooling.
- **Automated test suite** — PHPUnit with the WordPress test library in CI. Scheduled for 2.9.0: the out-of-tree harness (87 checks covering API keys, rate limiting, SSRF filter, encryption, hub alerts and anti-flood, digests, webhooks and unsubscribe in 2.8.0) moves into `tests/` and runs on every push.

---

## Technical Architecture

### Database tables

| Table | Purpose | Since |
|-------|---------|-------|
| `{prefix}cd_plugin_changes` | Plugin lifecycle audit log (activate/deactivate/update/delete) | v1.0.0 |
| `{prefix}cd_errors` | Parsed PHP/WordPress error log entries | v1.0.0 |
| `{prefix}cd_scans` | Serialised health-scan results | v1.0.0 |
| `{prefix}cd_conflicts` | Detected conflict records with confidence scores | v2.0.0 |
| `{prefix}cd_ajax_log` | Slow AJAX/REST call log (> 500 ms) | v2.6.0 |

### Options (Agency Edition, v2.7.0)

| Option | Purpose | Autoload |
|--------|---------|----------|
| `tahcd_api_key` | Hash, hint, created, expiry of this site's API key | no |
| `tahcd_api_key_last_used` | Last successful key use (separate to avoid write races) | no |
| `tahcd_remote_sites` | Hub: remote sites (label, URL, encrypted key) | no |
| `tahcd_remote_status` | Hub: last poll result per site | no |
| `tahcd_agency_settings` | Hub: polling interval | no |
| `tahcd_alert_settings` | Alerts: enabled, recipients, severity, frequency, hub toggle, encrypted webhook URL + format | yes |
| `tahcd_alert_state` | Alerts: last check, reported signatures, digest queue, next digest, last webhook result | no |

Cron events: `tahcd_agency_poll` (5/15/60 min), `tahcd_alerts_check` (hourly). Both are removed on deactivation and restored on the next admin request after reactivation.

Schema is managed by `Database::maybe_upgrade()` at priority 0 on `plugins_loaded`.  
Current `SCHEMA_VERSION = 3`.  
Tables are preserved on deactivation and removed cleanly on uninstall.

### WordPress hooks used

| Hook | Class | Purpose |
|------|-------|---------|
| `activated_plugin` | Change_History | Log plugin activation |
| `deactivated_plugin` | Change_History | Log plugin deactivation |
| `upgrader_process_complete` | Change_History | Log plugin updates |
| `delete_plugin` | Change_History | Log plugin deletion |
| `pre_option_active_plugins` | Safe_Mode | Strip disabled plugins from active list (admin session only) |
| `plugin_loaded` | Performance | Snapshot execution time/memory/queries per plugin |
| `all` | Ajax_Monitor | Hook AJAX/REST request timing |
| `plugins_loaded` | Multiple | Init AJAX handlers, DB migration, main bootstrap |
| `admin_menu` | Dashboard | Register top-level admin menu |
| `admin_enqueue_scripts` | Dashboard | Enqueue CSS/JS assets |
| `wp_ajax_tahcd_*` | Multiple | All AJAX handlers (nonce-protected) |
| `rest_api_init` | Rest_Api | Register `tahhan-cd/v1/status` |
| `cron_schedules` | Agency | `tahcd_5min`, `tahcd_15min` intervals |
| `http_api_curl` | Safe_Http | Pin outbound hub/webhook requests to the vetted IP (only during a request) |
| `admin_init` | Alerts, Agency | Keep cron events in sync with settings |
| `admin_post(_nopriv)_tahcd_unsubscribe` | Alerts | Unsubscribe confirmation (GET) and removal (POST / one-click) |

### File scanning targets
- `wp-content/debug.log` — read backwards from the end, last 500 lines, at most 2 MB (any file size)
- Server `error_log` path from `ini_get('error_log')` (same limits)
- `wp-content/plugins/` (plugin discovery)
- `wp-content/themes/` (theme health checks)

### Prefix / namespace conventions

| Type | Value |
|------|-------|
| PHP namespace | `TahhanConflictDetective` |
| PHP constants | `TAHCD_` |
| AJAX actions | `tahcd_` |
| Nonce action | `tahcd_nonce` |
| Cookie name | `tahcd_safe_mode` |
| User meta keys | `_tahcd_*` |
| DB option keys | `tahcd_*` |
| Script/style handle | `tahcd-admin` |
| JS data object | `tahcdData` |

---

## Release History

| Version | Date | Phase | Highlights |
|---------|------|-------|------------|
| 1.0.0 | 2026-06-02 | Phase 1 | Dashboard, Error Log, Change History, Health Scan |
| 2.0.0 | 2026-06-02 | Phase 2 | Conflict Scanner, Safe Testing Mode, Conflict Wizard |
| 2.1.x | 2026-06-03 | Fixes | Dashicons UI, Safe Mode architecture, Plugin Check fixes |
| 2.2.0 | 2026-06-03 | Fix | Safe Mode AJAX fully functional |
| 2.3.x | 2026-06-03 | Fix | Infinite recursion fix, CI hardening |
| 2.4.0 | 2026-06-04 | Rename | → Tahhan Conflict Detective, new namespace/slug |
| 2.5.x | 2026-06-04–11 | Polish | `tahcd_` prefix, Plugin Check compliance, uninstall fixes |
| 2.6.0 | 2026-06-16 | Phase 3 | Performance Monitor, Cron Monitor, AJAX Monitor, Interaction Map |
| 2.6.1 | 2026-06-16 | Fix | Tested up to WordPress 7.0 |
| 2.6.2 | 2026-06-16 | Security | `plugin_basename()` path validation, `$_POST` for AJAX monitor, `WP_Filesystem` guard |
| 2.6.3 | 2026-08-03 | Audit | Full audit: 16 correctness, security, performance, and quality fixes |
| 2.6.4 | 2026-08-26 | Fix | Schema defaults, `tables_exist()` partial-install detection, DB error propagation, `md5()` option keys |
| 2.6.5 | 2026-09-14 | Fix | Missing `return` after error responses in 3 AJAX handlers, untranslated 'Forbidden' string |
| 2.6.6 | 2026-10-14 | Fix | `fread()` false guard in tail(), null guard in get_recent_changes(), strtotime false guards, dashboard double-call eliminated |
| 2.7.0 | 2026-10-20 | Phase 4 | Agency hub, REST status endpoint, API keys, email alerts; SSRF/crypto hardening; activation-hook fix |
| 2.8.0 | 2026-11-10 | Phase 4 | Hub alerts, daily/weekly digests, webhooks, one-click unsubscribe, overview bar, key-expiry warnings; recipients no longer see each other's addresses |

---

## Unique Selling Point

> Existing plugins **show** errors.  
> This plugin **answers**: *"Which plugin broke your site?"*
