# Changelog

All notable changes to **Tahhan Conflict Detective** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [2.6.1] — 2026-06-16

### Changed
- `Tested up to` updated to WordPress 7.0.

---

## [2.6.0] — 2026-06-16

### Added
- **Performance Monitor** tab — hooks `plugin_loaded` to capture a before/after snapshot of PHP execution time, memory usage, and DB query count per plugin. Results color-coded Fast / Slow / Heavy. Stored in a 5-minute transient; refreshable on demand.
- **Cron Monitor** tab — lists all scheduled WP-Cron events (from `_get_cron_array()`) with next run time, interval, and overdue detection (red highlight + summary badge). Manual **Run Now** button per event; hook name validated against the live schedule before `do_action()` fires.
- **AJAX / REST Monitor** tab — automatically logs slow AJAX and REST API calls (> 500 ms) to a new dedicated database table. Filter bar: All / AJAX / REST / Slow. Auto-trims to the most recent 500 entries.
- **Plugin Interaction Map** tab — groups all installed plugins into known ecosystem clusters (WooCommerce, Elementor, Yoast SEO, Jetpack, ACF, Gravity Forms, Contact Form 7, WPML, LearnDash, Divi, WP Rocket, and more). Reads the `Requires Plugins` header (WordPress 6.5+) for explicit declared dependencies. Standalone plugins listed separately.
- New database table `{prefix}cd_ajax_log` (columns: id, type, action, duration_ms, status_code, user_id, created_at). Schema version bumped to **3**.
- Professional banner redesign: two-tone gradient background, mini UI card with live stat preview, feature pills, bullet-point summary, author credit.

### Changed
- `readme.txt` `Tested up to` corrected from the invalid value `7.0` to `6.7`.

### Security
- Cron **Run Now** AJAX handler validates the hook name against the live WP-Cron schedule before execution — prevents arbitrary `do_action()` dispatch via this interface.
- AJAX/REST monitor timing reads from `$_SERVER` directly (was mistakenly using the non-standard `$GLOBALS['_SERVER']`).
- `Ajax_Monitor::get_entries()` selects only required columns — no `SELECT *`.
- `Ajax_Monitor::insert_log()` includes a table-existence guard (`SHOW TABLES LIKE`) to fail safely on fresh installs before the first upgrade run.
- `Interaction_Map::build_map()` returns empty arrays early when `get_plugins()` returns nothing.
- `Error_Log::parse_file()` skips files larger than 50 MB to prevent out-of-memory errors on large logs.
- All four new `render()` methods (Performance, Cron, AJAX Monitor, Interaction Map) guarded by `current_user_can('manage_options')` with `wp_die()`.

---

## [2.5.2] — 2026-06-11

### Fixed
- `Safe_Mode::maybe_filter_active_plugins()` direct DB query now uses object cache (`wp_cache_get` / `wp_cache_set`) to satisfy the WordPress.org Plugin Check `DirectDatabaseQuery.NoCaching` warning. The direct query is unavoidable because calling `get_option()` inside `pre_option_active_plugins` would cause infinite recursion; an inline `phpcs:ignore` comment explains this.
- Raw SQL in the `active_plugins` lookup replaced with `$wpdb->prepare()`.

---

## [2.5.1] — 2026-06-11

### Fixed
- `Database::drop_tables()` now deletes option rows using the current `tahcd_prev_version_` prefix — was hardcoded to the stale `pcd_prev_version_` prefix since the v2.5.0 rename, leaving orphaned rows in `wp_options` on uninstall. Also switched to `$wpdb->prepare()` + `$wpdb->esc_like()`.
- `Dashboard::render_safe_mode()` self-exclusion filter now correctly matches `tahhan-conflict-detective/tahhan-conflict-detective.php` — was checking the old slug `conflict-detective/conflict-detective.php`, causing Conflict Detective itself to appear in the Safe Mode plugin toggle list.

### Changed
- `readme.txt` changelog updated with condensed entries for v2.2.0 through v2.5.0 to keep the WordPress.org plugin page in sync.

---

## [2.5.0] — 2026-06-04

### Changed
- All plugin-specific prefixes standardised to `tahcd_` / `TAHCD_` to satisfy WordPress.org uniqueness requirements:
  - Constants: `CD_VERSION` → `TAHCD_VERSION`, `CD_PLUGIN_DIR` → `TAHCD_PLUGIN_DIR`, `CD_PLUGIN_URL` → `TAHCD_PLUGIN_URL`, `CD_PLUGIN_FILE` → `TAHCD_PLUGIN_FILE`, `CD_MIN_PHP` → `TAHCD_MIN_PHP`, `CD_MIN_WP` → `TAHCD_MIN_WP`
  - AJAX action hooks: `pcd_run_scan`, `pcd_clear_log`, `pcd_safe_mode_toggle`, `pcd_safe_mode_toggle_plugin` → `tahcd_*`
  - Nonce action: `pcd_nonce` → `tahcd_nonce`
  - User meta keys: `_pcd_safe_token`, `_pcd_disabled_plugins` → `_tahcd_safe_token`, `_tahcd_disabled_plugins`
  - Cookie name: `pcd_safe_mode` → `tahcd_safe_mode`
  - DB option keys: `pcd_db_version`, `pcd_prev_version_` → `tahcd_db_version`, `tahcd_prev_version_`
  - Script/style handle: `pcd-admin` → `tahcd-admin`
  - Localised JS data object: `pcdData` → `tahcdData`
  - All `phpcs:ignore NonPrefixedConstantFound` comments removed — now properly prefixed.

---

## [2.4.0] — 2026-06-04

### Changed
- Plugin renamed to **Tahhan Conflict Detective** (slug: `tahhan-conflict-detective`) for WordPress.org compliance.
- PHP namespace changed from `PluginConflictDetector` to `TahhanConflictDetective` across all files.
- Text domain changed from `conflict-detective` to `tahhan-conflict-detective` throughout.
- `Contributors` field updated to `mustafatahhan` in `readme.txt`.
- Debug log clear handler switched from `file_put_contents()` to `WP_Filesystem()->put_contents()`.

---

## [2.3.1] — 2026-06-03

### Fixed
- **Critical — infinite recursion crash:** `Safe_Mode::maybe_filter_active_plugins()` called `get_option('active_plugins')` which re-triggered the `pre_option_active_plugins` filter it was already inside, causing a stack overflow that crashed the entire site. Fixed by reading directly from `$wpdb` instead of `get_option()`.

---

## [2.3.0] — 2026-06-03

### Changed
- GitHub Actions workflow: all third-party action references pinned to immutable SHA digests (supply-chain security hardening).
- Code cleanup: removed orphan comments, fixed `usort` indentation in `class-dashboard.php` and `class-error-log.php`.

---

## [2.2.0] — 2026-06-03

### Added
- Safe Mode tab fully functional: **Start / Stop** button now triggers AJAX and reloads the page on success.
- Safe Mode inactive state: card with header, description, numbered how-to steps, and a **Start Safe Mode** button.
- Safe Mode active state: amber banner outside the card showing disabled-plugin count and a **Stop Safe Mode** button, followed by the plugin toggle list.
- `safeModeLoading` and `safeModeStop` localised JavaScript strings for button feedback during AJAX calls.
- CSS: `.pcd-safe-mode-banner`, `.pcd-safe-mode-banner__body`, `.pcd-btn-stop`, `.pcd-safe-mode-steps`, `.pcd-safe-mode-count`.

### Fixed
- **Architecture: Safe Mode AJAX handler never registered** — `Safe_Mode::init()` was registered via `plugins_loaded` at priority 1 inside `Plugin::init()`, which itself ran at priority 5. By the time `Plugin::init()` ran, priority 1 had already fired. Fixed by calling `Safe_Mode::init()` directly at file-load time outside the Plugin class.
- **Architecture: `Database::maybe_upgrade()` never ran** — same root cause. Now registered at priority 0 at file-load time.

---

## [2.1.4] — 2026-06-03

*Superseded by v2.2.0 the same day — all fixes are included in v2.2.0.*

---

## [2.1.3] — 2026-06-03

### Fixed
- Translators comment moved inside `sprintf()` call to sit directly above `_n()` in `class-conflict-scanner.php` (WordPress.WP.I18n.MissingTranslatorsComment).
- `phpcs:ignore` for `EscapeOutput.OutputNotEscaped` corrected to the full sniff name with double-dash separator in `class-dashboard.php`.
- All `phpcs:ignore` annotations for `$wpdb->query()` `DROP TABLE` consolidated onto the same line in `class-database.php`.

---

## [2.1.2] — 2026-06-03

### Fixed
- Translators comments added to all `__()` and `_n()` calls with placeholders (WordPress.WP.I18n).
- Ordered placeholders `%s` → `%1$s`, `%2$s`, `%3$s` in `class-health-scan.php`.
- Unescaped integers wrapped with `absint()` in `class-dashboard.php` and `class-wizard.php`.
- `phpcs:ignore` sniff names corrected for WP_Filesystem bypass in `class-error-log.php`.
- Direct `$wpdb` query `phpcs:ignore` comments added with justification throughout.

---

## [2.1.1] — 2026-06-03

### Added
- `languages/index.php` — standard WordPress silent-guard file; replaces hidden `.gitkeep` (not permitted by WordPress.org).
- GitHub Actions workflow for automatic WordPress.org SVN deployment on release (strips `v` prefix from tag via `${GITHUB_REF_NAME#v}`).
- `.wordpress-org/` directory for plugin page assets (banner, icon, screenshots).

### Changed
- Plugin renamed **Conflict Detective → Tahhan Conflict Detective** for WordPress.org uniqueness compliance.
- Menu position changed from `65` to `65.1` to avoid collision with the WordPress core Plugins menu.
- `Tested up to` bumped to `6.7`.

### Fixed
- Safe Mode AJAX: `plugin_file` validated against `get_plugins()` before storing in user meta.
- JavaScript: all UI strings moved to `tahcdData` via `wp_localize_script` — no hardcoded English in JS.
- Conflict Wizard: last emoji replaced with `dashicons-warning`.
- LICENSE: full GPL-2.0-or-later text with "or any later version" clause.
- Navigation docs: corrected to reflect top-level admin menu (not under Tools).

---

## [2.1.0] — 2026-06-03

### Added
- WordPress Dashicons throughout the entire admin UI — no emoji, no custom icon fonts.
- `Database::tables_exist()` helper for lightweight DB guard.
- Self-repair guard in `render_dashboard()` — missing tables are recreated automatically on every request.
- Automatic CSS & JS cache-busting via `filemtime()`.

### Changed
- Full-width layout — `.pcd-wrap` fills the entire `#wpcontent` area (no max-width constraint).
- Dashboard grid — single `.pcd-dash-grid` container; fixes the half-empty page layout bug.
- Stat card icons — per-state dashicon with correct brand colour; background box removed.
- Tab navigation — underline style with per-tab dashicon.
- Conflict Wizard — dashicons replace emoji; CSS `::before` dots replace inline emoji.
- `plugins_loaded` priority for `Database::maybe_upgrade()` lowered to `0`.

### Fixed
- `SHOW TABLES LIKE` uses `$wpdb->prepare()` + `$wpdb->esc_like()` — prevents SQL wildcard issues.
- Database tables auto-created on FTP / manual deployments (no activation hook required).

---

## [2.0.0] — 2026-06-02

### Added
- **Conflict Scanner** — confidence score 0–100 %; detected conflicts stored in `{prefix}cd_conflicts`; one-click **Mark Resolved**.
- **Safe Testing Mode** — cookie-isolated admin-only plugin toggle; visitors are completely unaffected; 32-byte signed token stored in user meta.
- **Conflict Wizard** — 7 symptom categories; automatic analysis of recent changes and error log; tailored action plan.
- New database table `{prefix}cd_conflicts`; `SCHEMA_VERSION` bumped to `2`.

---

## [1.0.0] — 2026-06-02

### Added
- **Dashboard** — system overview, active plugins, recent changes, recent errors (top-level admin menu `Conflict Detective`).
- **Error Log Viewer** — reads `debug.log` + server `error_log`; plugin attribution per entry; filter bar (Fatal / Warning / Notice / Deprecated).
- **Change History** — audit trail for every plugin activation, deactivation, update, and deletion; version diffs.
- **Health Scan** — duplicate functionality detection, known incompatibilities, outdated plugins, theme file checks, server configuration checks.
- Database tables: `{prefix}cd_plugin_changes`, `{prefix}cd_errors`, `{prefix}cd_scans`. Schema version `1`.
- Clean uninstall via `uninstall.php`; PHP version guard; `declare(strict_types=1)` throughout.

---

[Unreleased]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.6.1...HEAD
[2.6.1]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.5.2...v2.6.0
[2.5.2]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.5.1...v2.5.2
[2.5.1]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.5.0...v2.5.1
[2.5.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.3.1...v2.4.0
[2.3.1]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.3.0...v2.3.1
[2.3.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.1.4...v2.2.0
[2.1.4]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.1.3...v2.1.4
[2.1.3]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.1.2...v2.1.3
[2.1.2]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/Tahhan-nl/Tahhan-Conflict-Detective/releases/tag/v1.0.0
