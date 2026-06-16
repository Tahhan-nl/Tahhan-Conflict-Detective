# Changelog

All notable changes to **Conflict Detective** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [2.6.0] — 2026-06-16

### Added
- **Performance Monitor** — tracks per-plugin estimated load time, memory delta, and DB query count during the current page load; results color-coded Fast / Slow / Heavy; Refresh Data button; 5-minute transient cache.
- **Cron Monitor** — lists all scheduled WP-Cron events with next run time and overdue detection (red highlight); manual "Run Now" button validated against live `_get_cron_array()` schedule before execution; overdue summary badge at top.
- **AJAX / REST Monitor** — logs slow AJAX and REST API calls (>500 ms) to new `{prefix}cd_ajax_log` table; filter bar for All / AJAX / REST / Slow views; auto-trims to 500 entries.
- **Plugin Interaction Map** — groups installed plugins into known ecosystem clusters (WooCommerce, Elementor, Yoast SEO, Jetpack, ACF, Gravity Forms, Contact Form 7, WPML, LearnDash, Divi, WP Rocket, and more); reads `Requires Plugins` header (WP 6.5+) for explicit dependencies; standalone plugins listed separately.
- New database table `{prefix}cd_ajax_log`; schema version bumped to 3.
- Professional banner redesign: two-tone gradient, mini UI card preview, feature pills, bullet points.

### Changed
- `readme.txt`: `Tested up to` corrected to 6.7 (was incorrectly set to 7.0 in earlier beta).

### Security
- Cron "Run Now" AJAX handler validates hook against live WP-Cron schedule before execution — prevents arbitrary action dispatch.
- AJAX monitor REST timing reads from `$_SERVER` directly (was using non-standard `$GLOBALS['_SERVER']`).
- AJAX log `get_entries()` selects only required columns (no `SELECT *`).
- All four new `render()` methods guarded by `current_user_can('manage_options')`.

---

## [2.5.2] — 2026-06-11

### Fixed
- `Safe_Mode::maybe_filter_active_plugins()` direct DB query now uses object cache (`wp_cache_get/set`) to satisfy WordPress.org Plugin Check — resolves `DirectDatabaseQuery.NoCaching` warning. The `DirectQuery` is unavoidable (calling `get_option()` here would cause infinite recursion) and is suppressed with an inline `phpcs:ignore` comment explaining why.
- Raw SQL replaced with `$wpdb->prepare()` for the `active_plugins` lookup.

---

## [2.5.1] — 2026-06-11

### Fixed
- `class-database.php::drop_tables()` now deletes option rows using the current `tahcd_prev_version_` prefix (was hardcoded to the stale `pcd_prev_version_` prefix since v2.5.0 rename, leaving orphaned rows in `wp_options` on uninstall). Also switched to `$wpdb->prepare()` + `$wpdb->esc_like()` for correctness.
- `class-dashboard.php::render_safe_mode()` self-exclusion filter now correctly matches `tahhan-conflict-detective/tahhan-conflict-detective.php` (was checking old slug `conflict-detective/conflict-detective.php`, causing the plugin itself to appear in the Safe Mode toggle list).

### Changed
- `tahhan-conflict-detective/readme.txt` changelog updated with condensed entries for v2.2.0 through v2.5.0 to keep the WordPress.org plugin page in sync.

---

## [2.5.0] — 2026-06-04

### Changed
- All plugin-specific prefixes standardised to `tahcd_` / `TAHCD_` to satisfy WordPress.org uniqueness requirements:
  - Constants renamed: `CD_VERSION` → `TAHCD_VERSION`, `CD_PLUGIN_DIR` → `TAHCD_PLUGIN_DIR`, etc. (all six constants). `phpcs:ignore` suppression comments removed — now properly prefixed.
  - AJAX action hooks: `pcd_run_scan`, `pcd_clear_log`, `pcd_safe_mode_toggle`, `pcd_safe_mode_toggle_plugin` → `tahcd_*`
  - Nonce action: `pcd_nonce` → `tahcd_nonce`
  - User meta keys: `_pcd_safe_token`, `_pcd_disabled_plugins` → `_tahcd_*`
  - Cookie name: `pcd_safe_mode` → `tahcd_safe_mode`
  - Database option keys: `pcd_db_version`, `pcd_prev_version_` → `tahcd_*`
  - Script/style handle: `pcd-admin` → `tahcd-admin`
  - Localised JS data object: `pcdData` → `tahcdData`

---

## [2.4.0] — 2026-06-04

### Changed
- Plugin renamed to **Tahhan Conflict Detective** (slug: `tahhan-conflict-detective`) for WordPress.org compliance.
- Namespace changed from `PluginConflictDetector` to `TahhanConflictDetective` across all PHP files.
- Text domain changed from `conflict-detective` to `tahhan-conflict-detective` throughout.
- `Contributors` updated to `mustafatahhan` in `readme.txt`.
- Debug log clear handler now uses `WP_Filesystem::put_contents()` instead of `file_put_contents()`.

---

## [2.3.1] — 2026-06-03

### Fixed
- Critical infinite recursion crash: `maybe_filter_active_plugins()` called `get_option('active_plugins')` which re-triggered the same `pre_option_active_plugins` filter, causing a stack overflow that took the entire site down. Fixed by reading directly from `$wpdb` instead of `get_option()`.

---

## [2.3.0] — 2026-06-03

### Changed
- CI action SHA pinning (security hardening in GitHub Actions workflow)
- Code cleanup: removed orphan comment and fixed usort indentation in class-dashboard.php and class-error-log.php

---

## [2.2.0] — 2026-06-03

### Added
- Safe Mode tab fully functional: Start/Stop button now triggers AJAX and reloads page on success
- Redesigned Safe Mode UI: inactive state shows instructions + Start button; active state shows amber banner with plugin count + Stop button above toggle list
- Architecture fix: `Safe_Mode::init()` and `Database::maybe_upgrade()` moved to file-load time so AJAX handlers are always registered

---

## [2.1.3] — 2026-06-03

### Fixed
- Translators comment moved inside `sprintf()` call to sit directly above `_n()` in class-conflict-scanner.php (WordPress.WP.I18n.MissingTranslatorsComment)
- phpcs:ignore for `EscapeOutput.OutputNotEscaped` corrected to full sniff name with double-dash separator in class-dashboard.php
- All phpcs:ignore annotations for `$wpdb->query()` DROP TABLE consolidated onto the same line in class-database.php
- phpcs:ignore for `NonPrefixedConstantFound` added inline to all six `CD_` constant definitions in conflict-detective.php

---

## [2.1.2] — 2026-06-03

### Fixed
- Translators comments added to all `__()` and `_n()` calls with placeholders (WordPress.WP.I18n)
- Ordered placeholders `%s` → `%1$s, %2$s, %3$s` in class-health-scan.php
- Unescaped integers wrapped with `absint()` in class-dashboard.php and class-wizard.php
- Constants renamed `PCD_` → `CD_` to match plugin slug prefix (WordPress.NamingConventions)
- phpcs:ignore sniff names corrected for WP_Filesystem bypass in class-error-log.php
- PluginCheck.CodeAnalysis.WriteFile phpcs:ignore added for debug.log clear handler
- Direct `$wpdb` query phpcs:ignore comments added with justification throughout

---

## [2.1.1] — 2026-06-03

### Added
- `languages/index.php` — standard WordPress silent-guard file; replaces hidden `.gitkeep` (not permitted by WordPress.org)
- GitHub Actions workflow for automatic WordPress.org SVN deployment on release
- `.wordpress-org/` directory for plugin page assets (banner, icon, screenshots)

### Changed
- Plugin renamed **Plugin Conflict Detector → Conflict Detector → Conflict Detective** for WordPress.org compliance
- Plugin folder: `plugin-conflict-detector/` → `conflict-detective/`
- Main file: `plugin-conflict-detector.php` → `conflict-detective.php`
- Text domain: `plugin-conflict-detector` → `conflict-detective`
- GitHub repository renamed: `WordPress-Plugin-Conflict-Detector` → `WordPress-Conflict-Detective`
- `Plugin URI` updated to new GitHub repo URL
- `Tested up to` bumped from `6.7` → `7.0`
- Menu position changed from `65` → `65.1` (avoids collision with WordPress core Plugins menu)

### Fixed
- Safe Mode AJAX: `plugin_file` validated against `get_plugins()` before storing in user meta
- JavaScript: all 7 UI strings moved to `pcdData` via `wp_localize_script` — fully translatable
- Wizard: last `⚠` emoji replaced with `dashicons-warning`
- LICENSE: full GPL-2.0-or-later text with "or any later version" clause
- Navigation docs: corrected to reflect top-level admin menu (not under Tools)
- WP_Filesystem bypass: inline justification comments added in tail reader and log-clear handler

---

## [2.1.0] — 2026-06-03

### Added
- WordPress Dashicons throughout entire admin UI — no emoji, no custom icon fonts
- `Database::tables_exist()` helper for lightweight DB guard
- Self-repair guard in `render_dashboard()` — missing tables recreated automatically
- Automatic CSS & JS cache-busting via `filemtime()`

### Changed
- Full-width layout — `.pcd-wrap` fills the entire `#wpcontent` area (no max-width)
- Dashboard grid — single `.pcd-dash-grid` container fixes the half-empty page bug
- Stat card icons — per-state dashicon with correct brand colour; no background box
- Page title — dashicon inline in `<h1>`; purple icon-box wrapper removed
- Tab navigation — underline style with per-tab dashicon
- Conflict Wizard — dashicons replace emoji; CSS `::before` dots replace inline emoji
- `plugins_loaded` priority for `Database::maybe_upgrade()` lowered to `0`

### Fixed
- `SHOW TABLES LIKE` uses `$wpdb->prepare()` + `$wpdb->esc_like()` — prevents SQL wildcard issues
- Database tables auto-created on FTP/manual deployments

---

## [2.0.0] — 2026-06-02

### Added
- **Conflict Scanner** — confidence score 0–100 %; one-click "Mark resolved"; stored in `{prefix}cd_conflicts`
- **Safe Testing Mode** — cookie-isolated admin-only plugin toggle; visitors unaffected
- **Conflict Wizard** — 7 symptoms; automatic analysis; timeline; tailored advice
- `{prefix}cd_conflicts` database table; `SCHEMA_VERSION` bumped to `2`

---

## [1.0.0] — 2026-06-02

### Added
- **Dashboard** — system overview, active plugins, recent changes, recent errors (top-level admin menu)
- **Error Log** — reads `debug.log` + server `error_log`; plugin attribution; filter bar
- **Change History** — audit trail with version diffs
- **Health Scan** — duplicate functionality, incompatibilities, outdated plugins, theme + server checks
- Database schema: `{prefix}cd_plugin_changes`, `{prefix}cd_errors`, `{prefix}cd_scans`
- Clean uninstall via `uninstall.php`; PHP version guard; `declare(strict_types=1)` throughout

---

[Unreleased]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.6.0...HEAD
[2.6.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.5.2...v2.6.0
[2.5.2]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.5.1...v2.5.2
[2.5.1]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.5.0...v2.5.1
[2.5.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.3.1...v2.4.0
[2.3.1]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.3.0...v2.3.1
[2.3.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.1.3...v2.2.0
[2.1.3]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.1.2...v2.1.3
[2.1.2]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/releases/tag/v1.0.0
