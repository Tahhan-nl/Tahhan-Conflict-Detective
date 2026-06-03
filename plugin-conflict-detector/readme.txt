=== Plugin Conflict Detector ===
Contributors: tahhan
Tags: conflict, debug, plugins, errors, health, safe mode, conflict scanner
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically detects which plugin, theme, or update broke your WordPress site — without manual trial and error.

== Description ==

**"Which plugin just broke my site?"** — answered automatically.

Existing tools show you *that* an error occurred. Plugin Conflict Detector tells you *which plugin caused it* by correlating the plugin change timeline with the error log — and gives you a confidence score.

= Features =

**Dashboard**
Live overview of your WordPress/PHP version, active plugins, active theme, memory limit, debug mode, recent changes, and latest errors — all on one screen.

**Error Log Viewer**
Reads `debug.log` and the server PHP error log automatically. Each entry is attributed to the plugin that owns the file where the error occurred. Filter by Fatal / Warning / Notice / Deprecated.

**Change History**
Full audit trail of every plugin activation, deactivation, update, and deletion — with exact timestamps and version diffs (e.g. `8.3.0 → 8.4.0`).

**Health Scan**
On-demand scan that detects:

* Duplicate plugin functionality (multiple SEO, caching, security, or backup plugins)
* Known incompatibilities between specific plugin pairs
* Outdated plugins (not updated in more than 2 years)
* Missing theme files or a missing parent theme
* PHP version, memory limit, and `max_execution_time` misconfigurations
* Pending WordPress core updates

**Conflict Scanner** *(Phase 2)*
Automatically correlates plugin update timestamps with error spikes and reports a suspect plugin with a confidence percentage. Detected conflicts are stored in the database and can be marked as resolved with one click.

**Safe Testing Mode** *(Phase 2)*
Disable any plugin for your own admin session while visitors remain completely unaffected. Cookie-isolated — the live site is never touched.

**Conflict Wizard** *(Phase 2)*
Step-by-step guided diagnosis. Choose your symptom (white screen, login problem, WooCommerce issue, slow site, broken admin, front-end error, or other) and the wizard automatically analyses recent changes and errors to produce a tailored action plan.

== Installation ==

1. Upload the `plugin-conflict-detector` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Conflict Detector** in the WordPress admin sidebar.

No configuration is required. All scanning is done on demand inside the admin area — zero front-end overhead.

== Frequently Asked Questions ==

= Does this plugin slow down my site? =
No. All analysis runs on demand inside the WordPress admin. There is zero front-end overhead.

= What log files does it read? =
`wp-content/debug.log` and the server PHP `error_log` path returned by `ini_get('error_log')`.

= Do I need to configure anything? =
No. Optionally, enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php` to populate the error log.

= Does Safe Testing Mode affect my visitors? =
Never. Safe Testing Mode uses a session cookie that is only present for your admin session. Visitors always see the live site.

= What happens if I install the plugin via FTP? =
The plugin detects missing database tables on every request and recreates them automatically — no activation hook required.

== Changelog ==

= 2.1.0 =
* Redesigned admin UI using native WordPress Dashicons — no emoji, no custom icon fonts
* Full-width layout: plugin now fills the entire admin content area like any native WordPress page
* Fixed "half-empty page" dashboard grid layout bug
* Fixed `SHOW TABLES LIKE` SQL injection risk — now uses `$wpdb->prepare()` with `$wpdb->esc_like()`
* Added automatic table self-repair for FTP/manual deployments where the activation hook never fires
* Automatic CSS/JS cache-busting via `filemtime()` — no more stale stylesheets after updates

= 2.0.0 =
* Added Conflict Scanner with confidence percentage
* Added Safe Testing Mode (admin-only plugin toggle, visitors unaffected)
* Added Conflict Wizard with 7 symptom categories and automatic analysis
* New database table `{prefix}cd_conflicts` for storing scanner results

= 1.0.0 =
* Initial release — Dashboard, Error Log Viewer, Plugin Change History, Health Scan

== Upgrade Notice ==

= 2.1.0 =
UI redesign and important bug fixes. Upgrade recommended for all users.

= 2.0.0 =
Major feature release: Conflict Scanner, Safe Testing Mode, and Conflict Wizard.
