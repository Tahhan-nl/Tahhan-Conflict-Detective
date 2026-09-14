# Changelog — Tahhan Conflict Detective

All notable changes are documented here.  
Full developer changelog: [CHANGELOG.md](../CHANGELOG.md) at the repository root.

---

## [2.6.5] — 2026-09-14

### Fixed
- `Safe_Mode::ajax_toggle_plugin()` — hardcoded `'Forbidden'` string now wrapped in `__()` for translatability; was missed in the v2.6.3 audit.
- `Dashboard::ajax_clear_log()` — added missing `return` after `wp_send_json_error()` when the log file does not exist; execution would otherwise fall through to `wp_send_json_success()`.
- `Dashboard::ajax_clear_log()` — added missing `return` after `wp_send_json_error()` when `put_contents()` fails.
- `Cron_Monitor::ajax_run_cron_event()` — added missing `return` after `wp_send_json_error()` for the unknown-hook validation path; without it `run_event()` would execute despite the error response already being sent.

---

## [2.6.4] — 2026-08-26

### Fixed
- `Database::install()` — `cd_ajax_log.created_at` schema now includes `DEFAULT CURRENT_TIMESTAMP`; a raw INSERT without an explicit timestamp value no longer fails under MySQL strict mode.
- `Database::install()` — `cd_conflicts.changed_at` changed from `NOT NULL` to `DEFAULT NULL`; prevents strict-mode INSERT failure when the column is omitted.
- `Database::drop_tables()` — now also deletes the `tahcd_main_table_exists` transient on uninstall, removing the orphaned option row that would otherwise linger in `wp_options`.
- `Database::tables_exist()` — now checks both `cd_plugin_changes` (schema v1) and `cd_ajax_log` (schema v3); previously only `cd_plugin_changes` was checked so a partial install where Phase 3 tables were missing went undetected.
- `Health_Scan::run()` — `$wpdb->insert()` return value is now checked; a `db_error` flag is set in the returned result array on failure instead of silently discarding the error.
- `Health_Scan::run()` — `scanned_at` in the returned result array now uses the same UTC timestamp written to the database; previously it used `current_time('mysql')` (local time) while the DB row held UTC, causing a timezone mismatch when callers compared the returned timestamp to stored records.
- `Health_Scan::scan_theme()` — `get_site_transient('update_themes')` return value now guarded with `is_object()` before property access, preventing a PHP warning when the transient is corrupt or not yet populated.
- `Health_Scan::scan_server()` — `get_site_transient('update_core')` return value now guarded with `is_object()` before property access.
- `Change_History::get_recent()` — returns an empty array instead of `null` when `$wpdb->get_results()` fails; prevents type errors in callers that iterate the result.
- `Change_History::on_update()` — option key for the previous-version baseline now uses `md5($plugin)` instead of `sanitize_key($plugin)`; `sanitize_key()` strips `/` and `.` from the plugin path, causing two different plugins to map to the same option key and corrupt each other's baseline.
- `Change_History::write()` — now returns `bool` (was `void`) so callers can check whether the DB insert actually succeeded.
- `Change_History::on_update()` — `update_option()` for the version baseline is now called only when `write()` returns `true`; previously the baseline was advanced even when the audit-log insert failed, leading to a missing record with an incorrect "previous version" on the next update.

---

## [2.6.3] — 2026-08-03

### Fixed
- `Health_Scan::scan_plugins()` — `get_site_transient('update_plugins')` return value now guarded with `is_object()` before property access, preventing a PHP 8.x warning when the transient is not yet populated.
- `Dashboard::ajax_clear_log()` — `WP_Filesystem()` return value checked before calling `put_contents()`; returns a JSON error on FTP/SSH filesystem environments instead of a fatal error.
- `Ajax_Monitor::insert_log()` — `created_at` now stored as UTC via `current_time('mysql', true)`; was using local WordPress time, causing time-correlation errors on non-UTC sites.
- `Safe_Mode::verify_token()` — result cached with a static variable to prevent a duplicate DB query when both `maybe_filter_active_plugins()` and `is_active()` run in the same request.
- `Change_History::on_delete()` — now reads plugin name and version before deletion (`delete_plugin` fires before files are removed); was incorrectly falling back to `basename()` with a wrong comment.
- `Change_History::read_plugin_data()` — path is validated via `realpath()` to ensure it remains within `WP_PLUGIN_DIR`; prevents path-traversal on plugins with unusual relative paths.
- `Error_Log::attribute_to_plugin()` — `get_plugins()` call cached with a static variable; eliminates N+1 calls when the method is invoked once per log entry.
- `Conflict_Scanner::score_plugin()` — `strtotime()` return value validated; an invalid timestamp now returns zero confidence instead of polluting the score with epoch-based arithmetic.
- `Conflict_Scanner::build_reason()` — `strtotime()` call guarded with `?: 0` fallback.
- `Ajax_Monitor::on_ajax_shutdown()` — `$ajax_start` reset to `null` after logging to prevent a duplicate entry if the shutdown function fires more than once.
- `Database::maybe_upgrade()` — `SHOW TABLES` existence check cached with a 1-hour transient, eliminating a raw DB query on every page load during normal operation.
- `uninstall.php` — multisite installations now iterate all sub-sites via `switch_to_blog()` / `restore_current_blog()` so tables on sub-sites are also removed on plugin delete.
- `Cron_Monitor::get_events()` — `$schedules[$key]['display']` access guarded with `isset()` to handle custom schedules that omit the `display` key.
- `Interaction_Map::slug_from_basename()` — `str_replace('.php', …)` replaced with a trailing-extension-only removal to handle plugin filenames that contain `.php` mid-name.
- `Wizard::render_step2()` — `usort` comparator now uses `<=>` spaceship operator instead of integer subtraction, preventing overflow on 32-bit PHP.

### Security
- `Wizard::get_advice()` — plugin name interpolated into HTML advice string now escaped with `esc_html()` before `sprintf()`.
- All AJAX handlers — hard-coded `'Forbidden'`, `'Invalid plugin.'`, `'Unknown plugin.'` error strings now passed through `__()` for consistency and translatability.
- All AJAX capability-check guards — explicit `return` added after `wp_send_json_error()` for code-flow clarity.

---

## [2.6.2] — 2026-06-16

### Security
- Safe Mode plugin path now validated with `plugin_basename()` (was `sanitize_text_field()`) — prevents path traversal before the `get_plugins()` whitelist check.
- AJAX monitor reads action from `$_POST` instead of `$_REQUEST` — prevents a crafted cookie from affecting log entries.
- All early-exit AJAX error responses now include explicit `return` statements.

### Fixed
- `filemtime()` for CSS/JS assets wrapped in `file_exists()` guard.
- Change History DB row properties use null-coalescing to prevent PHP notices.
- `strtok()` replaced with stateless `explode()` in Error Log file attribution.
- Health Scan issue count guards against non-array scan section returns.

### Changed
- Wizard symptom labels are now translatable (moved to `symptoms()` method).
- "Plugin:" label in Error Log Viewer is now translatable.

---

## [2.6.1] — 2026-06-16

### Changed
- `Tested up to` bumped to WordPress 7.0.

---

## [2.6.0] — 2026-06-16

### Added
- **Performance Monitor** tab — per-plugin load time, memory delta, DB query count. Fast / Slow / Heavy badges. 5-minute transient cache, refreshable on demand.
- **Cron Monitor** tab — full WP-Cron event list with next run time, overdue detection (red), and a validated **Run Now** button per event.
- **AJAX / REST Monitor** tab — logs slow calls (> 500 ms) to `{prefix}cd_ajax_log`. Filter: All / AJAX / REST / Slow. Auto-trims to 500 rows.
- **Plugin Interaction Map** tab — ecosystem clusters (WooCommerce, Elementor, Yoast SEO, Jetpack, ACF, Gravity Forms, and more) plus `Requires Plugins` dependency reading.
- New database table `{prefix}cd_ajax_log`. Schema version → 3.
- Professional banner redesign.

### Security
- Cron Run Now validates hook against live schedule before execution.
- AJAX monitor reads `$_SERVER` directly (not `$GLOBALS['_SERVER']`).
- `get_entries()` selects only required columns (no `SELECT *`).
- All new `render()` methods guarded by `current_user_can('manage_options')`.

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
