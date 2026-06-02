# WordPress Plugin Conflict Detector

> **"Which plugin broke my site?"** — answered automatically.

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-0073aa?logo=wordpress)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892be?logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.0.0-green)](CHANGELOG.md)

---

## The Problem

Existing plugins *show* errors. This plugin *answers*:

> "WooCommerce was updated at 12:10 — errors started at 12:11. Confidence: 92%."

No more manually deactivating plugins one by one. No more guessing.

---

## Features — Phase 1 (MVP)

### Dashboard
A single-screen overview under **Tools → Conflict Detector**:

- Active plugins with versions
- WordPress & PHP version
- Active theme
- Memory limit & debug mode status
- Recent plugin changes
- Latest error log entries

### Error Log Viewer
Automatically reads `debug.log` and the server PHP error log.  
Each entry is **attributed to the plugin** that owns the file where the error occurred.

```
Fatal Error  |  WooCommerce
Call to undefined method WC_Gateway::process_payment()
wp-content/plugins/woocommerce/includes/gateways/...  :247
```

### Plugin Change History
Every plugin lifecycle event is logged with a timestamp:

| Date & Time         | Plugin      | Action   | Version       |
|---------------------|-------------|----------|---------------|
| 02-06-2026 12:10:05 | WooCommerce | Updated  | 8.3.0 → 8.4.0 |
| 02-06-2026 12:10:58 | Elementor   | Updated  | 3.20 → 3.21   |

### Health Scan
On-demand scan across three areas:

**Plugins**
- Duplicate functionality (multiple SEO, caching, security, backup plugins)
- Known incompatibilities between specific plugin pairs
- Outdated plugins (not updated in > 2 years)

**Theme**
- Missing `functions.php`, `style.css`, `index.php`
- Missing parent theme (child themes)
- Pending theme update

**Server**
- PHP version (minimum 7.4, recommended 8.2)
- Memory limit (minimum 64 MB, recommended 256 MB)
- `max_execution_time` (minimum 30 s)
- WordPress core update available

---

## Roadmap

| Phase | Status | Features |
|-------|--------|----------|
| 1 – MVP | ✅ Done | Dashboard, Error Log, Change History, Health Scan |
| 2 – Smart Detection | 🔲 Planned | Conflict Scanner (confidence %), Safe Testing Mode, Conflict Wizard |
| 3 – Advanced Analysis | 🔲 Planned | Performance impact per plugin, Plugin Interaction Map, Ajax & Cron Monitor |
| 4 – Agency Edition | 🔲 Planned | Multi-site dashboard, Email alerts, PDF reporting |

See [ROADMAP.md](ROADMAP.md) for the full specification.

---

## Installation

### From ZIP
1. Download the latest release ZIP from the [Releases](../../releases) page.
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Activate**.

### From Source (development)
```bash
git clone git@github.com:Tahhan-nl/WordPress-Plugin-Conflict-Detector.git
cd WordPress-Plugin-Conflict-Detector

# Copy the plugin folder into your local WordPress install
cp -r plugin-conflict-detector /path/to/wordpress/wp-content/plugins/
```
Then activate via **Plugins → Installed Plugins**.

---

## Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| WordPress   | 5.8     | Latest      |
| PHP         | 7.4     | 8.2+        |
| MySQL       | 5.6     | 8.0+        |

---

## Database

The plugin creates three tables on activation (prefixed with your `$wpdb->prefix`):

| Table | Purpose |
|-------|---------|
| `{prefix}cd_plugin_changes` | Audit log of every plugin activation, deactivation, update, and deletion |
| `{prefix}cd_errors` | Parsed PHP / WordPress error entries |
| `{prefix}cd_scans` | Serialised health-scan results |

Tables are **preserved on deactivation** so history is not lost.  
Tables are **removed on uninstall** (Plugin → Delete).

---

## Security

- All database queries use `$wpdb->prepare()` or whitelisted table names.
- All output is escaped with WordPress core helpers (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Every form uses a WordPress nonce.
- All admin pages are guarded by `current_user_can( 'manage_options' )`.

---

## Contributing

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/your-feature`.
3. Commit your changes following the [Conventional Commits](https://www.conventionalcommits.org/) format.
4. Open a Pull Request against `main`.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## License

[GPL-2.0-or-later](LICENSE) © Tahhan
