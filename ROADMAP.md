# Roadmap — Tahhan Conflict Detective

> **Mission:** Answer "which plugin broke my site?" automatically — without the user ever having to disable plugins by hand.

**Target audience:** Website owners · Web designers · Freelancers · Hosting companies · WordPress agencies

**Current stable version:** 2.6.0 — released 2026-06-16  
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

## Phase 4 — Agency Edition 🔲 Planned

### Multi-site Dashboard
Single overview for all managed sites:

```
site1.nl  — 2 issues detected
site2.nl  — All clear
site3.nl  — 1 conflict pending
site4.nl  — All clear
```

Remote health data fetched via a lightweight REST endpoint on each site.

### Email Alerts
Triggered automatically on:
- Fatal error detected
- Plugin update caused new errors (confidence > 50 %)
- Site unreachable (down)

Configurable recipients and thresholds. Digest mode (daily/weekly) available.

### PDF Reporting
Auto-generated report per site containing:
- Site health summary
- Detected conflicts with confidence scores
- Performance issues per plugin
- Recommended actions
- Change history over the report period

White-label branding option for agencies.

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

### File scanning targets
- `wp-content/debug.log` (max 50 MB — larger files are skipped to prevent OOM)
- Server `error_log` path from `ini_get('error_log')` (same 50 MB guard)
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

---

## Unique Selling Point

> Existing plugins **show** errors.  
> This plugin **answers**: *"Which plugin broke your site?"*
