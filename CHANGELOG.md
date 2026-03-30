# Changelog

## [1.4.3] - 2026-03-30
### Fixed
- Topbar **permanent** option now correctly hides the X button (was a boolean/string type mismatch in JS — API returned `true`, JS compared `=== '1'`)
- Topbar **sticky** now uses `position: sticky` instead of `position: fixed` — the bar stays in the document flow, page content no longer scrolls underneath it

## [1.4.2] - 2026-03-30
### Fixed
- PHP 8 `Undefined array key` warnings for `nc_radius_custom`, `nc_toast_position` and all other NC options not yet saved in the database (fresh installs, sites that never touched a given setting)
- All option defaults are now centralized in `get_cached_options()` — single place, zero notices

## [1.4.1] - 2026-03-26
### Added
- **Admin preview mode** — append `?nc_preview=1` to any page URL (admins only) to see how a notification looks regardless of time/day/countdown restrictions; page rules still apply

## [1.4.0] - 2026-03-26
### Added
- **Inline notifications for anonymous users** — notifications are now baked into the HTML via `wp_localize_script` for logged-out visitors; zero AJAX requests on cached pages

### Changed
- LiteSpeed Cache (`litespeed_purge_all`) is now triggered whenever notifications are saved, trashed, deleted or restored
- Notification transient TTL increased from 60 s to 300 s

## [1.3.5] - 2026-03-26
### Fixed
- `uninstall.php` no longer deletes notification posts or analytics tables when the plugin is removed — prevents data loss when manually re-uploading a ZIP leaves a duplicate folder that gets deleted

### Performance
- UTM and tracking parameters (`utm_*`, `fbclid`, `gclid`, etc.) are now stripped from the cache key — prevents cache fragmentation on traffic from paid campaigns
- Added `X-LiteSpeed-Cache-Control: public` header for anonymous users, `no-cache` for logged-in users

## [1.3.2] - 2026-02-xx
### Added
- Audience option: **Tylko administrator** (show only to logged-in administrators)

### Fixed
- Audience filtering moved back to PHP REST API (previously was JS-side, causing incorrect results)
- Nonce handling in CPT fetch
- Cookie fallback auth for `audience: administrator` check

## [1.3.1] - 2026-02-xx
### Fixed
- Countdown timer calculation

## [1.3.0] - 2026-02-xx
### Added
- Behavioral triggers: exit intent, scroll depth, time on page, inactivity, click selector
- Floating notifications with configurable position, delay, duration
- Sidebar panel
- Topbar notifications with rotation, compact style, above/below header positioning
- Countdown widget (date-based and daily)
- Day exclusion rules
- Per-notification color overrides
- Global style settings (radius, colors, bell button)

## [1.2.0] - 2026-02-xx
### Added
- WooCommerce per-user notifications

## [1.0.5] - 2026-02-12
### Added
- GitHub auto-updater (`NC_GitHub_Updater`) — WordPress update checks pull releases from GitHub

## [1.0.3] - Initial release
- Custom post type `nc_notification`
- REST API endpoint `/nc/v1/notifications`
- Bell icon widget with drawer
- Page rules (show/hide by URL, post ID, front page)
- Date range scheduling
- Pinned / dismissible notifications
