# Changelog

All notable changes to Conflict Detective are documented here.

## [2.1.4] — 2026-06-03

### Fixed
- **Architecture: Safe Mode AJAX handler never registered** — `Safe_Mode::init()` was called via `add_action('plugins_loaded', ..., 1)` inside `Plugin::init()` which itself ran at priority 5. By then priority 1 had already fired, so `pcd_safe_mode_toggle` never existed and WordPress returned `-1`. Fixed by calling `Safe_Mode::init()` directly at file-load time (outside the Plugin class).
- **Architecture: Database::maybe_upgrade() never ran** — same root cause. Now registered at priority 0 at file-load time.

### Changed
- **Safe Mode tab UI fully redesigned**
  - Inactive state: a card with header, "Start Safe Mode" button, description text, and numbered how-to steps.
  - Active state: an amber banner (outside the card) showing the disabled-plugin count and a "Stop Safe Mode" button, followed by a card with the plugin toggle list and a disabled-count label.
- **JavaScript toggle handler** now uses delegated event binding (`$(document).on('click', '#pcd-toggle-safe-mode', ...)`) and reloads the page on success to reflect the server-side state change.
- Added `safeModeLoading` and `safeModeStop` localised strings for button feedback during AJAX calls.

### Added
- CSS for `.pcd-safe-mode-banner`, `.pcd-safe-mode-banner__body`, `.pcd-btn-stop`, `.pcd-safe-mode-steps`, and `.pcd-safe-mode-count`.

---

## [2.1.3] — 2026-05-XX

- Added Safe Mode tab placeholder UI.

## [2.1.2] — 2026-05-XX

- Version bump after Plugin Check fixes.

## [2.1.1] — 2026-05-XX

- Security: plugin slug from AJAX request now validated against the installed plugins list before being stored in user meta.
- All JavaScript UI strings are now translatable via `wp_localize_script`.
- Replaced last remaining emoji with a Dashicon in the Conflict Wizard.
- Fixed menu position conflict with WordPress core Plugins menu (changed from 65 to 65.1).

## [2.0.0]

- Added Conflict Scanner with confidence percentage.
- Added Safe Testing Mode (admin-only plugin toggle, visitors unaffected).
- Added Conflict Wizard with 7 symptom categories and automatic analysis.
- New database table for storing scanner results.

## [1.0.0]

- Initial release — Dashboard, Error Log Viewer, Plugin Change History, Health Scan.

[2.1.4]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v2.1.3...v2.1.4
[2.1.3]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v2.1.2...v2.1.3
[2.1.2]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v2.0.0...v2.1.1
[2.0.0]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/Tahhan-nl/WordPress-Conflict-Detective/releases/tag/v1.0.0
