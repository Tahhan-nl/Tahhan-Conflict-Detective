# Changelog — Tahhan Conflict Detective

All notable changes are documented here.  
Full developer changelog: [CHANGELOG.md](../CHANGELOG.md) at the repository root.

---

## [2.6.0] — 2026-06-16

### Added
- Performance Monitor tab — per-plugin load time, memory delta, DB query count. Fast / Slow / Heavy badges. 5-minute transient cache, refreshable on demand.
- Cron Monitor tab — full WP-Cron event list with next run time, overdue detection (red), and a validated **Run Now** button per event.
- AJAX / REST Monitor tab — logs slow calls (> 500 ms) to `{prefix}cd_ajax_log`. Filter: All / AJAX / REST / Slow. Auto-trims to 500 rows.
- Plugin Interaction Map tab — ecosystem clusters (WooCommerce, Elementor, Yoast SEO, Jetpack, ACF, Gravity Forms, and more) plus `Requires Plugins` dependency reading.
- New database table `{prefix}cd_ajax_log`. Schema version → 3.
- Professional banner redesign.

### Security
- Cron Run Now validates hook against live schedule before execution.
- AJAX monitor reads `$_SERVER` directly (not `$GLOBALS['_SERVER']`).
- `get_entries()` selects only required columns (no `SELECT *`).
- All new `render()` methods guarded by `current_user_can('manage_options')`.

### Fixed
- `Tested up to` corrected to 6.7 (was incorrectly set to 7.0).

---

## [2.5.2] — 2026-06-11

### Fixed
- `Safe_Mode::maybe_filter_active_plugins()` direct DB query now wrapped in `wp_cache_get / wp_cache_set` — resolves Plugin Check `DirectDatabaseQuery.NoCaching` warning.
- Raw SQL replaced with `$wpdb->prepare()` for the `active_plugins` lookup.

---

## [2.5.1] — 2026-06-11

### Fixed
- Uninstall routine now correctly deletes `tahcd_prev_version_*` options (was using the stale `pcd_prev_version_` prefix, leaving orphaned rows in `wp_options`).
- Safe Mode plugin list no longer shows Conflict Detective itself (self-exclusion filter was checking the old slug `conflict-detective/conflict-detective.php`).

---

## [2.5.0] — 2026-06-04

### Changed
- All plugin-specific prefixes standardised to `tahcd_` / `TAHCD_` (constants, AJAX actions, nonce, user meta, cookie, option keys, script handle, JS data object).

---

## [2.4.0] — 2026-06-04

### Changed
- Plugin renamed to **Tahhan Conflict Detective** (slug: `tahhan-conflict-detective`).
- Namespace → `TahhanConflictDetective`. Text domain → `tahhan-conflict-detective`.
- Debug log clear handler uses `WP_Filesystem()->put_contents()` instead of `file_put_contents()`.

---

## [2.3.1] — 2026-06-03

### Fixed
- Critical infinite recursion crash in `maybe_filter_active_plugins()` — calling `get_option()` inside `pre_option_active_plugins` caused a stack overflow. Fixed by reading directly from `$wpdb`.

---

## [2.3.0] — 2026-06-03

### Changed
- GitHub Actions: all action references pinned to immutable SHA digests.
- Code cleanup in `class-dashboard.php` and `class-error-log.php`.

---

## [2.2.0] — 2026-06-03

### Added
- Safe Mode Start / Stop fully functional via AJAX.
- Redesigned Safe Mode UI: inactive card + active amber banner.

### Fixed
- Safe Mode AJAX handler and `Database::maybe_upgrade()` were never registered (wrong `plugins_loaded` priority). Both now called at file-load time.

---

## [2.1.1] — 2026-06-03

### Fixed
- Plugin slug validated against `get_plugins()` before storing in user meta.
- All JS UI strings moved to `wp_localize_script`.
- Menu position `65` → `65.1` (avoids collision with WordPress core Plugins menu).

---

## [2.0.0] — 2026-06-02

### Added
- Conflict Scanner with confidence percentage (0–100 %) and one-click Mark Resolved.
- Safe Testing Mode — cookie-isolated admin-only plugin toggle; visitors unaffected.
- Conflict Wizard — 7 symptom categories, automatic analysis, tailored action plan.
- Database table `{prefix}cd_conflicts`. Schema version → 2.

---

## [1.0.0] — 2026-06-02

### Added
- Dashboard — system overview, active plugins, recent changes, recent errors.
- Error Log Viewer — reads `debug.log` + server `error_log`; plugin attribution; filter bar.
- Plugin Change History — full audit trail with version diffs.
- Health Scan — duplicate plugins, incompatibilities, outdated plugins, theme + server checks.
- Database tables: `{prefix}cd_plugin_changes`, `{prefix}cd_errors`, `{prefix}cd_scans`. Schema version 1.
