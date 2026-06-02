=== Plugin Conflict Detector ===
Contributors: tahhan
Tags: conflict, debug, plugins, errors, health
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically detects which plugin, theme, or update broke your WordPress site — without manual trial and error.

== Description ==

**"Which plugin just broke my site?"** — answered automatically.

Existing tools show you *that* an error occurred. Plugin Conflict Detector tells you *which plugin caused it* by correlating the plugin change timeline with the error log.

= Features (Phase 1 — MVP) =

* **Dashboard** — live overview of your WordPress/PHP version, active plugins, theme, memory limit, and debug mode
* **Error Log Viewer** — reads debug.log and the PHP error log, attributes each error to the owning plugin
* **Change History** — audit trail of every activation, deactivation, update, and deletion with exact timestamps and version diffs
* **Health Scan** — detects duplicate functionality, known incompatibilities, outdated plugins, theme issues, and server misconfigurations

== Installation ==

1. Upload the `plugin-conflict-detector` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Tools → Conflict Detector**.

== Frequently Asked Questions ==

= Does this plugin slow down my site? =
No. All scanning is done on demand inside the admin area. There is zero frontend overhead.

= What log files does it read? =
It reads `wp-content/debug.log` and the server PHP `error_log` path returned by `ini_get('error_log')`.

= Do I need to configure anything? =
No configuration is required. Optionally, enable `WP_DEBUG` and `WP_DEBUG_LOG` in wp-config.php to populate the error log.

== Changelog ==

= 1.0.0 =
* Initial release — Dashboard, Error Log Viewer, Plugin Change History, Health Scan.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
