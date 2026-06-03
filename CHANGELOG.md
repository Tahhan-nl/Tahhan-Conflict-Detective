# Changelog

All notable changes to **Conflict Detective** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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

[Unreleased]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v2.1.3...HEAD
[2.1.3]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v2.1.2...v2.1.3
[2.1.2]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/releases/tag/v1.0.0
