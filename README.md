# Tahhan Conflict Detective

> **"Which plugin broke my site?"** — answered automatically.

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-0073aa?logo=wordpress)](https://wordpress.org/plugins/tahhan-conflict-detective/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892be?logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)
[![Version](https://img.shields.io/badge/Version-2.6.0-green)](CHANGELOG.md)
[![Tested up to](https://img.shields.io/badge/Tested%20up%20to-WP%206.7-0073aa)](https://wordpress.org/plugins/tahhan-conflict-detective/)

---

## The Problem

Existing plugins *show* errors. This plugin *answers*:

> "WooCommerce was updated at 12:10 — errors started at 12:11. Confidence: 92%."

No more manually deactivating plugins one by one. No more guessing.

---

## Features

### Phase 1 — Dashboard & Monitoring ✅

**Dashboard**  
A single-screen overview under **Conflict Detective** in the WordPress admin menu:

- Active plugins with versions
- WordPress & PHP version, active theme, memory limit, debug mode
- Recent plugin changes
- Latest error log entries

**Error Log Viewer**  
Automatically reads `debug.log` and the server PHP error log.  
Each entry is **attributed to the plugin** that owns the file where the error occurred.

```
Fatal Error  |  WooCommerce
Call to undefined method WC_Gateway::process_payment()
wp-content/plugins/woocommerce/includes/gateways/...  :247
```

Filter bar: Fatal · Warning · Notice · Deprecated

**Plugin Change History**  
Every plugin lifecycle event is logged with a timestamp and version diff:

| Date & Time         | Plugin      | Action   | Version       |
|---------------------|-------------|----------|---------------|
| 02-06-2026 12:10:05 | WooCommerce | Updated  | 8.3.0 → 8.4.0 |
| 02-06-2026 12:10:58 | Elementor   | Updated  | 3.20 → 3.21   |

**Health Scan**  
On-demand scan across three areas:

- *Plugins* — Duplicate functionality (SEO, caching, security, backup), known incompatibilities, outdated plugins (> 2 years)
- *Theme* — Missing core files, missing parent theme
- *Server* — PHP version, memory limit, `max_execution_time`, WordPress core updates

---

### Phase 2 — Smart Conflict Detection ✅

**Conflict Scanner**  
Automatically correlates plugin update timestamps with error-log spikes:

```
Suspect plugin:  WooCommerce  (updated 12:10)
First error:     woocommerce-gateway.php  (12:11)
Confidence:      92%
```

Detected conflicts are stored in the database and can be marked as resolved with one click.

**Safe Testing Mode**  
*Unique feature — visitors are never affected.*

Disable any plugin for your own admin session (cookie-isolated) while the live site stays completely intact. A 32-byte signed token is stored in user meta and verified on every request. The cookie is HttpOnly, SameSite=Strict, and Secure on HTTPS.

```
WooCommerce   [OFF — admin only]
Elementor     [ON]
RankMath      [ON]
```

**Conflict Wizard**  
Step-by-step guided diagnosis for 7 symptom categories:

- White screen of death · Login problem · WooCommerce issue · Slow site
- Broken admin panel · Front-end error · Other

Each symptom triggers an automatic analysis linking recent changes to matching errors, with a tailored action plan.

---

### Phase 3 — Advanced Analysis ✅ (new in v2.6.0)

**Performance Monitor**  
Hooks `plugin_loaded` to capture a before/after snapshot of PHP execution time, memory usage, and DB query count per plugin. Results color-coded Fast / Slow / Heavy. Stored in a 5-minute transient; refreshable on demand.

| Plugin       | Load time | Memory | DB queries | Rating  |
|--------------|-----------|--------|------------|---------|
| Elementor    | 42 ms     | 1.1 MB | 8          | Slow    |
| WooCommerce  | 118 ms    | 3.4 MB | 24         | Heavy   |
| Akismet      | 4 ms      | 0.1 MB | 1          | Fast    |

**Cron Monitor**  
Full visibility into the WordPress Cron schedule. Overdue events highlighted in red. Manual **Run Now** button per event — hook name validated against the live schedule before execution (prevents arbitrary code dispatch).

**AJAX / REST Monitor**  
Automatically logs slow AJAX and REST API calls (> 500 ms) to a dedicated database table. Filter bar: All / AJAX / REST / Slow. Auto-trims to the most recent 500 entries.

**Plugin Interaction Map**  
Groups all installed plugins into known ecosystem clusters — WooCommerce, Elementor, Yoast SEO, Jetpack, ACF, Gravity Forms, Contact Form 7, WPML, LearnDash, Divi, WP Rocket, and more. Also reads the `Requires Plugins` header (WordPress 6.5+) for explicit declared dependencies.

---

## Roadmap

| Phase | Status | Version | Features |
|-------|--------|---------|----------|
| 1 — MVP | ✅ Complete | v1.0.0 | Dashboard, Error Log, Change History, Health Scan |
| 2 — Smart Detection | ✅ Complete | v2.0.0 | Conflict Scanner, Safe Testing Mode, Conflict Wizard |
| 3 — Advanced Analysis | ✅ Complete | v2.6.0 | Performance Monitor, Cron Monitor, AJAX/REST Monitor, Interaction Map |
| 4 — Agency Edition | 🔲 Planned | — | Multi-site dashboard, Email alerts, PDF reporting |

See [ROADMAP.md](ROADMAP.md) for the full specification.

---

## Installation

### From WordPress.org
Search for **Tahhan Conflict Detective** in **Plugins → Add New** and click Install.

### From ZIP
1. Download the latest release ZIP from the [Releases](../../releases) page.
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Activate**.

### From Source (development)
```bash
git clone https://github.com/Tahhan-nl/Tahhan-Conflict-Detective.git
cd Tahhan-Conflict-Detective

# Copy the plugin folder into your local WordPress install
cp -r tahhan-conflict-detective /path/to/wordpress/wp-content/plugins/
```
Then activate via **Plugins → Installed Plugins** and navigate to **Conflict Detective** in the admin sidebar.

---

## Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| WordPress   | 5.8     | Latest      |
| PHP         | 7.4     | 8.2+        |
| MySQL       | 5.6     | 8.0+        |

---

## Database

The plugin creates five tables on activation (prefixed with your `$wpdb->prefix`):

| Table | Purpose | Since |
|-------|---------|-------|
| `{prefix}cd_plugin_changes` | Audit log of every plugin activation, deactivation, update, and deletion | v1.0.0 |
| `{prefix}cd_errors` | Parsed PHP / WordPress error log entries | v1.0.0 |
| `{prefix}cd_scans` | Serialised health-scan results | v1.0.0 |
| `{prefix}cd_conflicts` | Detected conflict records with confidence scores | v2.0.0 |
| `{prefix}cd_ajax_log` | Slow AJAX / REST call log (> 500 ms) | v2.6.0 |

Current schema version: **3**

Tables are **preserved on deactivation** so history is not lost.  
Tables are **removed on uninstall** (Plugin → Delete).

> **FTP / manual deployments:** The plugin detects missing tables on every request and recreates them automatically — no activation hook required.

---

## Security

- All database queries use `$wpdb->prepare()` combined with `$wpdb->esc_like()` where needed.
- All output is escaped with WordPress core helpers (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Every AJAX action is protected by a WordPress nonce (`tahcd_nonce`).
- All admin pages are guarded by `current_user_can('manage_options')`.
- Safe Testing Mode uses a 32-byte random token stored in user meta — cookie-isolated, HttpOnly, SameSite=Strict.
- Cron Run Now validates hook names against the live WP-Cron schedule before execution.
- Safe Mode direct DB query uses `wp_cache_get/set` to satisfy WordPress.org Plugin Check caching requirements.

---

## WordPress.org Deployment

Releases are automatically deployed to the WordPress.org plugin directory via GitHub Actions when a new release is published on GitHub.

**Setup (one time):**
1. Add WordPress.org credentials as GitHub repository secrets:
   - `SVN_USERNAME` — your WordPress.org username
   - `SVN_PASSWORD` — your WordPress.org application password
2. Add plugin assets (banner, icon, screenshots) to the `.wordpress-org/` directory.
3. Publish a GitHub release tagged `v2.x.x` — the workflow strips the `v` prefix and pushes to SVN automatically.

See `.github/workflows/deploy-to-wordpress-org.yml`.

---

## Contributing

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/your-feature`.
3. Commit your changes following [Conventional Commits](https://www.conventionalcommits.org/).
4. Open a Pull Request against `main`.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## License

[GPL-2.0-or-later](LICENSE) © Tahhan
