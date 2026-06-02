# Changelog

All notable changes to **WordPress Plugin Conflict Detector** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Planned (Phase 2)
- Conflict Scanner with confidence percentage
- Safe Testing Mode (disable plugins for admin only, visitors unaffected)
- Conflict Wizard — step-by-step guided diagnosis

---

## [1.0.0] — 2026-06-02

### Added
- **Dashboard** tab under Tools → Conflict Detector
  - System overview: WordPress version, PHP version, active theme, memory limit, debug mode
  - Active plugins list with version numbers
  - Recent plugin changes widget (last 5 entries)
  - Recent errors widget (last 5 entries) with plugin attribution
- **Error Log** tab
  - Automatic reading of `wp-content/debug.log` and server `error_log`
  - Memory-efficient tail reader — handles files of any size without memory exhaustion
  - Each error attributed to the owning plugin via file path analysis
  - Filter bar: All / Fatal / Warning / Notice / Deprecated
- **Change History** tab
  - Logs every plugin activation, deactivation, update, and deletion with timestamp
  - Version transition display for updates (e.g. `8.3.0 → 8.4.0`)
  - Hooks: `activated_plugin`, `deactivated_plugin`, `upgrader_process_complete`, `delete_plugin`
- **Health Scan** tab
  - Plugin section: duplicate functional categories (SEO, Caching, Security, Backup, etc.), known plugin incompatibilities, outdated plugins (> 2 years without update)
  - Theme section: missing core files, missing parent theme, pending updates
  - Server section: PHP version check, memory limit check, `max_execution_time` check, WordPress core update check
  - Scan results persisted to database; last result shown without re-running
- Database schema: `cd_plugin_changes`, `cd_errors`, `cd_scans`
- Clean uninstall via `uninstall.php` (tables dropped, options removed)
- Environment guard: friendly admin notice when PHP < 7.4 instead of fatal error
- Full PHPDoc on all classes and public methods
- `declare(strict_types=1)` throughout

[Unreleased]: https://github.com/Tahhan-nl/WordPress-Plugin-Conflict-Detector/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Tahhan-nl/WordPress-Plugin-Conflict-Detector/releases/tag/v1.0.0
