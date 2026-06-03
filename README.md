# WordPress Conflict Detective

> **"Which plugin broke my site?"** — answered automatically.

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-0073aa?logo=wordpress)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892be?logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)
[![Version](https://img.shields.io/badge/Version-2.1.1-green)](CHANGELOG.md)

---

## The Problem

Existing plugins *show* errors. This plugin *answers*:

> "WooCommerce was updated at 12:10 — errors started at 12:11. Confidence: 92%."

No more manually deactivating plugins one by one. No more guessing.

---

## Features

### Phase 1 — Dashboard & Monitoring

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

**Plugin Change History**  
Every plugin lifecycle event is logged with a timestamp:

| Date & Time         | Plugin      | Action   | Version       |
|---------------------|-------------|----------|---------------|
| 02-06-2026 12:10:05 | WooCommerce | Updated  | 8.3.0 → 8.4.0 |
| 02-06-2026 12:10:58 | Elementor   | Updated  | 3.20 → 3.21   |

**Health Scan**  
On-demand scan across three areas:

*Plugins* — Duplicate functionality (SEO, caching, security, backup), known incompatibilities, outdated plugins (> 2 years)  
*Theme* — Missing core files, missing parent theme, pending updates  
*Server* — PHP version, memory limit, `max_execution_time`, WordPress core updates

---

### Phase 2 — Smart Conflict Detection

**Conflict Scanner**  
Automatically correlates plugin update timestamps with error-log spikes and reports:

```
Suspect plugin:  WooCommerce  (updated 12:10)
First error:     woocommerce-gateway.php  (12:11)
Confidence:      92%
```

Detected conflicts are stored in the database and can be marked as resolved with one click.

**Safe Testing Mode**  
*Unique feature — visitors are never affected.*

Disable any plugin for your own admin session (cookie-isolated) while the live site stays completely intact. Test freely, then re-enable with one click.

```
WooCommerce   [OFF — admin only]
Elementor     [ON]
RankMath      [ON]
```

**Conflict Wizard**  
Step-by-step guided diagnosis for 7 symptom categories:

- White screen of death
- Login problem
- WooCommerce issue
- Slow site
- Broken admin panel
- Front-end error
- Other

Each symptom triggers an automatic analysis linking recent changes to matching errors, with a tailored advice list.

---

## Roadmap

| Phase | Status | Features |
|-------|--------|----------|
| 1 – MVP | ✅ Complete | Dashboard, Error Log, Change History, Health Scan |
| 2 – Smart Detection | ✅ Complete | Conflict Scanner (confidence %), Safe Testing Mode, Conflict Wizard |
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
git clone git@github.com:Tahhan-nl/WordPress-Conflict-Detective.git
cd WordPress-Conflict-Detective

# Copy the plugin folder into your local WordPress install
cp -r conflict-detective /path/to/wordpress/wp-content/plugins/
```
Then activate via **Plugins → Installed Plugins**. Navigate to **Conflict Detective** in the admin sidebar.

---

## Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| WordPress   | 5.8     | Latest      |
| PHP         | 7.4     | 8.2+        |
| MySQL       | 5.6     | 8.0+        |

---

## Database

The plugin creates four tables on activation (prefixed with your `$wpdb->prefix`):

| Table | Purpose |
|-------|---------|
| `{prefix}cd_plugin_changes` | Audit log of every plugin activation, deactivation, update, and deletion |
| `{prefix}cd_errors` | Parsed PHP / WordPress error entries |
| `{prefix}cd_scans` | Serialised health-scan results |
| `{prefix}cd_conflicts` | Detected conflict records with confidence scores |

Tables are **preserved on deactivation** so history is not lost.  
Tables are **removed on uninstall** (Plugin → Delete).

> **FTP / manual deployments:** The plugin detects missing tables on every request and recreates them automatically — no activation hook required.

---

## Security

- All database queries use `$wpdb->prepare()` combined with `$wpdb->esc_like()` where needed.
- All output is escaped with WordPress core helpers (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Every form and AJAX action uses a WordPress nonce.
- All admin pages are guarded by `current_user_can( 'manage_options' )`.
- Safe Testing Mode is cookie-isolated — only the admin session is affected, never visitors.

---

## WordPress.org Deployment

Releases are automatically deployed to the WordPress.org plugin directory via GitHub Actions when a new release is published.

**Setup (one time):**
1. Add your WordPress.org credentials as GitHub repository secrets:
   - `SVN_USERNAME` — your WordPress.org username
   - `SVN_PASSWORD` — your WordPress.org password
2. Add plugin assets (banner, icon, screenshots) to the `.wordpress-org/` directory.
3. Publish a GitHub release — the workflow pushes to SVN automatically.

See `.github/workflows/deploy-to-wordpress-org.yml`.

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
