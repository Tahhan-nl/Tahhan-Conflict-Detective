# WordPress.org Plugin Assets

This directory contains the static assets displayed on the WordPress.org plugin page.
They are **not** included in the plugin ZIP — they are deployed separately to the SVN `assets/` folder.

## Required files (add before submitting)

| File | Size | Purpose |
|------|------|---------|
| `banner-772x250.png` | 772 × 250 px | Plugin page header banner (low-DPI) |
| `banner-1544x500.png` | 1544 × 500 px | Plugin page header banner (high-DPI / Retina) |
| `icon-128x128.png` | 128 × 128 px | Plugin icon (low-DPI) |
| `icon-256x256.png` | 256 × 256 px | Plugin icon (high-DPI / Retina) |

## Screenshots (referenced in readme.txt)

Name screenshots exactly as `screenshot-1.png`, `screenshot-2.png`, etc.
The order must match the `== Screenshots ==` section in `readme.txt`.

| File | Matches |
|------|---------|
| `screenshot-1.png` | Dashboard overview |
| `screenshot-2.png` | Conflict Scanner |
| `screenshot-3.png` | Safe Testing Mode |
| `screenshot-4.png` | Conflict Wizard |
| `screenshot-5.png` | Error Log |
| `screenshot-6.png` | Change History |
| `screenshot-7.png` | Health Scan |

## Notes

- PNG or JPG accepted; PNG preferred for UI screenshots
- Banners and icons are served by WordPress.org CDN — keep file sizes reasonable
- Use the WordPress admin colour scheme (`#1d2327` dark, `#2271b1` blue) for brand consistency
