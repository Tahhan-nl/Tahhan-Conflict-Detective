=== Tahhan Conflict Detective ===
Contributors: mustafatahhan
Tags: conflict, debug, plugins, errors, health
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.5.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically detects which plugin, theme, or update broke your WordPress site — without manual trial and error.

== Description ==

**"Which plugin just broke my site?"** — answered automatically.

Existing tools show you *that* an error occurred. Conflict Detective tells you *which plugin caused it* by correlating the plugin change timeline with the error log — and gives you a confidence score.

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

**Conflict Scanner**
Automatically correlates plugin update timestamps with error spikes and reports a suspect plugin with a confidence percentage. Detected conflicts are stored in the database and can be marked as resolved with one click.

**Safe Testing Mode**
Disable any plugin for your own admin session while visitors remain completely unaffected. Cookie-isolated — the live site is never touched.

**Conflict Wizard**
Step-by-step guided diagnosis. Choose your symptom (white screen, login problem, WooCommerce issue, slow site, broken admin, front-end error, or other) and the wizard automatically analyses recent changes and errors to produce a tailored action plan.

== Installation ==

1. Upload the `conflict-detective` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Conflict Detective** in the WordPress admin sidebar.

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

= Is this plugin compatible with Multisite? =
The plugin monitors the current site only. It is not a network-wide tool in this version.

== Screenshots ==

1. Dashboard — system overview, active plugins, recent changes and errors at a glance.
2. Conflict Scanner — automatically detected suspect plugin with confidence percentage.
3. Safe Testing Mode — disable plugins for your session without affecting visitors.
4. Conflict Wizard — step-by-step guided diagnosis with tailored advice.
5. Error Log — parsed PHP errors attributed to the owning plugin, with type filters.
6. Change History — full audit trail of every plugin activation, update, and deletion.
7. Health Scan — duplicate functionality, incompatibilities, and server configuration checks.

== Changelog ==

= 2.5.1 =
* Fixed: uninstall routine now correctly deletes `tahcd_prev_version_*` options (was using the stale `pcd_prev_version_` prefix, leaving orphaned rows in wp_options).
* Fixed: Safe Mode plugin list no longer shows Conflict Detective itself (self-exclusion filter used the old slug `conflict-detective/conflict-detective.php`).
* Docs: readme.txt changelog updated with entries for v2.2.0 through v2.5.0.

= 2.5.0 =
* All plugin-specific prefixes standardised to `tahcd_` / `TAHCD_` to satisfy WordPress.org uniqueness requirements (constants, AJAX actions, nonce, user meta, cookie, option keys, script handle, JS data object).

= 2.4.0 =
* Plugin renamed to Tahhan Conflict Detective (slug: `tahhan-conflict-detective`) for WordPress.org compliance. Namespace changed to `TahhanConflictDetective`. Text domain changed to `tahhan-conflict-detective`.
* Debug log clear handler now uses `WP_Filesystem::put_contents()` instead of `file_put_contents()`.

= 2.3.1 =
* Fixed critical infinite recursion crash: `maybe_filter_active_plugins()` called `get_option('active_plugins')` which re-triggered the same filter, causing a stack overflow. Fixed by reading directly from `$wpdb`.

= 2.3.0 =
* CI action SHA pinning for security hardening. Code cleanup in class-dashboard.php and class-error-log.php.

= 2.2.0 =
* Safe Mode tab fully functional: Start/Stop button now triggers AJAX and reloads on success.
* Architecture fix: `Safe_Mode::init()` and `Database::maybe_upgrade()` moved to file-load time.

= 2.1.4 =
* Fixed critical bug: Safe_Mode::init() and Database::maybe_upgrade() were registered inside Plugin::init() on plugins_loaded priority 5, meaning they were never called (priority 1 and 0 already fired). Both are now wired up directly at file load time, outside the Plugin class.
* Safe Mode tab fully redesigned: inactive state shows a card with instructions and a Start button; active state shows an amber banner with plugin count and a Stop button above the plugin toggle list.
* JavaScript Safe Mode toggle now uses delegated event binding ($(document).on) so it works regardless of DOM load order, and reloads the page on success to reflect the new state.
* Added safeModeLoading and safeModeStop localised strings for button feedback during AJAX calls.
* Added CSS for .pcd-safe-mode-banner, .pcd-btn-stop, .pcd-safe-mode-steps, and .pcd-safe-mode-count.

= 2.1.3 =
* Added Safe Mode tab UI (render_safe_mode placeholder).

= 2.1.1 =
* Security: plugin slug from AJAX request now validated against the installed plugins list before being stored in user meta
* All JavaScript UI strings are now translatable via wp_localize_script — no hardcoded English in JS
* Replaced last remaining emoji with a Dashicon in the Conflict Wizard
* Fixed menu position conflict with WordPress core Plugins menu (changed from 65 to 65.1)
* Added languages/ directory to match the Domain Path declaration in the plugin header
* Fixed navigation instructions in documentation (plugin is a top-level menu, not under Tools)
* LICENSE updated to full GPL-2.0-or-later text including the "or any later version" clause

= 2.0.0 =
* Added Conflict Scanner with confidence percentage
* Added Safe Testing Mode (admin-only plugin toggle, visitors unaffected)
* Added Conflict Wizard with 7 symptom categories and automatic analysis
* New database table for storing scanner results

= 1.0.0 =
* Initial release — Dashboard, Error Log Viewer, Plugin Change History, Health Scan

== Upgrade Notice ==

= 2.1.1 =
Security and quality patch. Upgrade recommended for all users on 2.1.0.

= 2.0.0 =
Major feature release: Conflict Scanner, Safe Testing Mode, and Conflict Wizard.
