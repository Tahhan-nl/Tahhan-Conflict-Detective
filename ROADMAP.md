# Roadmap — WordPress Conflict Detector

> **Mission:** Answer "which plugin broke my site?" automatically — without the user ever having to disable plugins by hand.

**Target audience:** Website owners · Web designers · Freelancers · Hosting companies · WordPress agencies

---

## Phase 1 — MVP ✅ Complete

### Dashboard (`Tools → Conflict Detector`)
- Active plugins with versions
- Recently activated plugins
- Latest updates
- PHP version · WordPress version · Active theme
- Last errors

### Error Log Viewer
Automatically reads:
- `debug.log`
- PHP `error_log`
- Fatal errors · PHP warnings · PHP notices

Output example:
```
Plugin:  WooCommerce
Error:   Call to undefined method ...
File:    wp-content/plugins/woocommerce/...
```

### Plugin Change History
Automatically logs:
- Plugin activated
- Plugin deactivated
- Plugin updated
- Plugin deleted

Example:
```
02-06-2026 12:10  WooCommerce updated (8.3.0 → 8.4.0)
02-06-2026 12:11  Errors started
```

### Health Scan
**Plugins**
- Duplicate functionality (SEO, caching, security, backup, contact forms, page builder, e-commerce)
- Known incompatibilities between specific plugin pairs
- Outdated plugins (not updated in > 2 years)

**Theme**
- Errors in theme files
- Missing files (`functions.php`, `style.css`, `index.php`)
- Missing parent theme

**Server**
- PHP version (min 7.4, recommended 8.2)
- Memory limit (min 64 MB, recommended 256 MB)
- `max_execution_time` (min 30 s)
- WordPress core update available

---

## Phase 2 — Smart Detection ✅ Complete

### Conflict Scanner
User reports: *"My site stopped working."*

Plugin responds:
```
Latest update:  WooCommerce
Latest error:   woocommerce-gateway.php
Confidence:     92%
```

### Safe Testing Mode
*Unique feature — visitors see nothing.*

The administrator sees:
```
[ Start Test Mode ]
```

The plugin:
- Disables plugins **for the admin only** (via session cookie)
- Visitors are completely unaffected
- Admin tests the site normally

Example state:
```
WooCommerce   OFF
Elementor     ON
RankMath      ON
```

### Conflict Wizard
Step-by-step guided diagnosis:

**Step 1 — What isn't working?**
- White screen of death
- Login problem
- WooCommerce issue
- Slow site

Followed by automatic analysis based on the chosen symptom.

---

## Phase 3 — Advanced Analysis 🔲 Planned

### Performance Impact
Per plugin:
- Load time added (ms)
- Database queries
- Memory usage

Example:
```
Elementor    +250 ms
Plugin X     +800 ms  ⚠️
```

### Plugin Interaction Map
Visual dependency tree:
```
WooCommerce
   ├── Stripe Gateway
   ├── WC Subscriptions
   └── WC Memberships
```

### Ajax Error Monitor
Detects:
- `admin-ajax.php` errors
- REST API errors
- JavaScript console errors

### Cron Monitor
Tracks:
- Stuck cron jobs
- Failed cron jobs

---

## Phase 4 — Agency Edition 🔲 Planned

### Multi-site Dashboard
Single overview for:
```
site1.nl  — 2 issues
site2.nl  — OK
site3.nl  — 1 issue
site4.nl  — OK
```

### Email Alerts
Triggered on:
- Fatal error detected
- Plugin update caused new errors
- Site unreachable (down)

### PDF Reporting
Auto-generated report containing:
- Site health summary
- Detected conflicts
- Performance issues
- Recommended actions

---

## Technical Architecture

### Database tables

| Table | Purpose |
|-------|---------|
| `{prefix}cd_plugin_changes` | Plugin lifecycle audit log |
| `{prefix}cd_errors` | Parsed error log entries |
| `{prefix}cd_scans` | Health scan results |
| `{prefix}cd_conflicts` | Detected conflict records with confidence scores |

### WordPress hooks used

| Hook | Purpose |
|------|---------|
| `activated_plugin` | Log activation |
| `deactivated_plugin` | Log deactivation |
| `upgrader_process_complete` | Log updates |
| `delete_plugin` | Log deletions |
| `shutdown` | Capture fatal errors |
| `wp_die_handler` | Capture white-screen events |

### File scanning targets
- `wp-content/debug.log`
- Server `error_log`
- `wp-content/plugins/`
- `wp-content/themes/`

---

## Premium Features (Future)

No AI required. A premium tier could include:

- Multi-site dashboard
- White-label branding
- PDF export
- Email alerts
- Advanced performance scans
- Conflict history export (CSV / JSON)

---

## Unique Selling Point

> Existing plugins **show** errors.  
> This plugin **answers**: *"Which plugin broke your site?"*
