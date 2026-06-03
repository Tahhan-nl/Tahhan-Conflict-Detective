# Changelog

All notable changes to **WordPress Plugin Conflict Detector** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [2.1.1] — 2026-06-03

### Fixed
- **Safe Mode AJAX**: `plugin_file` from `$_POST` now validated against `get_plugins()` before being stored in user meta — prevents arbitrary strings from being persisted by an authenticated admin
- **JavaScript strings**: all 7 UI strings in `admin.js` now come from `pcdData` (via `wp_localize_script`) — fully translatable, no hardcoded English
- **Wizard emoji**: last remaining `⚠` emoji replaced with `dashicons-warning` — consistent with the v2.1.0 "no emoji" policy
- **Menu position**: changed from `65` to `65.1` to avoid silent collision with WordPress core Plugins menu
- **`languages/` directory**: created to match the `Domain Path: /languages` declaration in the plugin header
- **Inline justification**: added comments for the intentional WP_Filesystem bypass in the tail reader and log-clear handler
- **LICENSE**: replaced truncated GPL-2.0-only text with full GPL-2.0-or-later text including "either version 2 … or any later version" clause
- **Docs**: corrected navigation instructions — plugin registers as a top-level admin menu item, not under Tools
- **Plugin version**: bumped to `2.1.1` to match the git tag

---

## [2.1.0] — 2026-06-03

### Added
- **WordPress Dashicons** throughout the entire admin UI — no emoji, no custom icon fonts, zero extra HTTP requests
- `Database::tables_exist()` helper method for lightweight DB guard before any dashboard query
- Self-repair guard in `render_dashboard()`: if tables are missing (e.g. after a database restore), they are recreated automatically and an inline admin notice prompts the user to reload
- Automatic CSS & JS cache-busting via `filemtime()` — every file change produces a new query-string version without manually bumping `PCD_VERSION`

### Changed
- **Full-width layout**: `.pcd-wrap` no longer imposes a `max-width`; the plugin fills the entire `#wpcontent` area exactly like native WordPress admin pages
- **Dashboard grid**: replaced separate `.pcd-grid--top` / `.pcd-grid--bottom` row wrappers with a single `.pcd-dash-grid` container (`grid-template-columns: 1fr 1fr`) — eliminates the "half-empty page" visual bug
- **Stat card icons**: each severity state (OK / Paused / Warning / Error) now has its own dashicon with the correct brand colour (`--pcd-green`, `--pcd-amber`, `--pcd-red`, `--pcd-purple`); no coloured background box
- **Page title**: dashicon rendered inline in the `<h1>` — removed the separate icon-box wrapper that appeared as a purple rectangle
- **Tab navigation**: underline style (`border-bottom: 2px solid`) instead of pill/button container; each tab carries a contextual dashicon
- **Conflict Wizard symptom cards**: dashicons replace the previous emoji symptom indicators
- **Timeline dots**: replaced inline emoji (🔴/🔵) with pure CSS `::before` dots
- **Advice list**: `<ol>` replaced with `<ul>` + `dashicons-arrow-right-alt2` per item
- **Submenu labels**: all emoji stripped from the admin menu registration
- `plugins_loaded` priority for `Database::maybe_upgrade()` lowered from `1` → `0` so tables always exist before any hook at the default priority runs

### Fixed
- `SHOW TABLES LIKE` query now uses `$wpdb->prepare()` combined with `$wpdb->esc_like()` — prevents the SQL `_` wildcard from matching unintended table names
- Database tables no longer silently fail on FTP/manual deployments where the activation hook never fires; `maybe_upgrade()` detects the missing tables and runs `install()` automatically

---

## [2.0.0] — 2026-06-02

### Added
- **Conflict Scanner** tab with confidence percentage
  - Correlates plugin change timestamps against error log spikes
  - Confidence score (0–100 %) based on temporal proximity and error-count delta
  - One-click "Mark resolved" action per detected conflict
  - Results stored in `{prefix}cd_conflicts` for persistence across page loads
- **Safe Testing Mode**
  - Admin-only plugin toggle: disable plugins for the current session without affecting visitors
  - Cookie-based isolation — visitors see the live site; admin sees the test environment
  - Persistent toggle list with one-click enable/disable per plugin
- **Conflict Wizard**
  - 7 symptom categories: white screen, login issue, WooCommerce, slow site, broken admin, front-end error, other
  - Automatic analysis step: correlates the chosen symptom with recent changes and errors
  - Timeline view of relevant events
  - Actionable advice list tailored to the symptom
- New database table `{prefix}cd_conflicts` for storing scanner results (Phase 2 schema, `SCHEMA_VERSION = 2`)
- `Database::SCHEMA_VERSION` bumped to `2`; `maybe_upgrade()` runs schema migrations automatically

### Changed
- Plugin version header updated to `2.0.0`
- Namespace-safe singleton bootstrap (`add_action( 'plugins_loaded', ... , 5 )`)

---

## [1.0.0] — 2026-06-02

### Added
- **Dashboard** tab under **Tools → Conflict Detector**
  - System overview: WordPress version, PHP version, active theme, memory limit, debug mode
  - Active plugins list with version numbers
  - Recent plugin changes widget (last 5 entries)
  - Recent errors widget (last 5 entries) with plugin attribution
- **Error Log** tab
  - Automatic reading of `wp-content/debug.log` and server `error_log`
  - Memory-efficient tail reader — handles files of any size without memory exhaustion
  - Each error attributed to the owning plugin via file-path analysis
  - Filter bar: All / Fatal / Warning / Notice / Deprecated
- **Change History** tab
  - Logs every plugin activation, deactivation, update, and deletion with timestamp
  - Version transition display for updates (e.g. `8.3.0 → 8.4.0`)
  - Hooks: `activated_plugin`, `deactivated_plugin`, `upgrader_process_complete`, `delete_plugin`
- **Health Scan** tab
  - Plugin section: duplicate functional categories (SEO, Caching, Security, Backup, etc.), known plugin incompatibilities, outdated plugins (> 2 years without update)
  - Theme section: missing core files, missing parent theme, pending updates
  - Server section: PHP version, memory limit, `max_execution_time`, WordPress core update check
  - Scan results persisted to database; last result shown without re-running
- Database schema: `{prefix}cd_plugin_changes`, `{prefix}cd_errors`, `{prefix}cd_scans`
- Clean uninstall via `uninstall.php` (tables dropped, options removed)
- Environment guard: friendly admin notice when PHP < 7.4 instead of a fatal error
- `declare(strict_types=1)` throughout; full PHPDoc on all classes and public methods

---

[Unreleased]: https://github.com/Tahhan-nl/WordPress-Plugin-Conflict-Detector/compare/v2.1.1...HEAD
[2.1.1]: https://github.com/Tahhan-nl/WordPress-Plugin-Conflict-Detector/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/Tahhan-nl/WordPress-Plugin-Conflict-Detector/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/Tahhan-nl/WordPress-Plugin-Conflict-Detector/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/Tahhan-nl/WordPress-Plugin-Conflict-Detector/releases/tag/v1.0.0
