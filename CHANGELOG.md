# Changelog

## [1.6.0] - 2026-07-21
### Added
- **Device targeting** — new "Urządzenie" select in section 4 (Targetowanie) lets a notification be restricted to `Tylko mobile (telefony + tablety)` or `Tylko desktop`, on top of the existing audience rule. Uses `wp_is_mobile()` server-side against the requesting visitor, evaluated live per-request through the existing AJAX notification endpoint (not baked into cached page HTML).
- **Hard impression cap** — new "Twardy limit wyświetleń (Capping)" fields alongside the existing dismiss-based "Częstotliwość" repeat: minimum hours between shows, max total shows, and the rolling window (in days) those shows are counted over. Unlike the existing repeat field, this tracks every real "shown" event independently of whether the user dismisses the notification, so a popup can be capped to e.g. 1 show/day and 10 shows/30 days even if the visitor never clicks the close button.

## [1.5.5] - 2026-07-08
### Fixed
- **Blank space around image-only center popups** — `.nc-floating.nc-pos-center` sets `padding: 30px` with the exact same CSS specificity (two classes) as `.nc-has-image-only`'s `padding: 0` reset, and appears later in the stylesheet, so it silently won and left a padded white border around image-only notifications using the "Środek Ekranu (Popup)" position. Added a 3-class `.nc-floating.nc-pos-center.nc-has-image-only` rule to force padding to 0 regardless of source order.

## [1.5.4] - 2026-07-08
### Fixed
- **Letterboxed/blank background around image-only notifications** — the 1.5.3 `object-fit: contain` fix used a fixed-height image box, which left large empty side bars (filled with the letterbox background) for non-16:9 images, e.g. a square 1:1 promo image in a wide popup. Image-only notifications (no title/body/CTA) now size to the image's natural aspect ratio (`height: auto`, capped at 70vh) instead of a fixed box, so square/portrait images display edge-to-edge with no blank space. Notifications that DO have a title/body still use the fixed-height contain box, since that layout needs a consistent card height.

## [1.5.3] - 2026-07-08
### Added
- **"Ukryj tytuł na froncie"** checkbox (section 2) — keeps the post title for internal identification in wp-admin only, without rendering it in the sidebar/drawer or floating popup/toast. Combined with image-only mode, this lets a notification's title stay filled in (required by WordPress) while the frontend shows a clean, text-free image.
- **Max width (Desktop / Mobile)** fields for floating popups/toasts (section "Pływające powiadomienie") — caps the card width independently above/below the 768px breakpoint, on top of the existing fixed-width option.

### Fixed
- **Notification images no longer get cropped** — floating popup/toast and sidebar/drawer images switched from `object-fit: cover` to `object-fit: contain` inside a fixed-height box, so tall/wide images (including ones with baked-in text) display in full instead of being cut off; letterboxed edges are filled with a subtle neutral background.

## [1.5.2] - 2026-07-08
### Added
- **Image-only notifications with whole-image link** — leaving Title, Description and CTA label empty (while setting an image and a CTA URL) now renders just the image, with the entire image acting as a clickable link to the CTA URL/target. Works in both the sidebar/drawer list and floating popups/toasts. Clicks on the linked image are tracked via the existing CTA click analytics event.

## [1.4.9] - 2026-06-18
### Added
- **CTA link target** — new "Otwieranie linku" option in the CTA section (section 3) lets you choose whether the button opens in the same window (`_self`) or a new tab (`_blank`). The chosen target is rendered as a `target` attribute on the frontend button across all display modes (sidebar/drawer, floating, top bar). New-tab links also get `rel="noopener noreferrer"` for security. Defaults to same window for existing notifications.

## [1.4.8] - 2026-04-09
### Fixed
- **uninstall.php no longer deletes plugin options** — prevents data loss when user installs new version via ZIP upload alongside old version and then deletes old copy
- **Expired transient bloat** — daily cron job now cleans up old `nc_api_*` transients from `wp_options` (80 stale rows cleared on first run)

### Changed
- **CSS loading strategy** — `style.css` now loads as non-render-blocking (`media="print" onload="this.media='all'"`) for better First Paint
- **Admin assets scope** — `nc-admin.js` and `nc-admin.css` now only load on NC notification screens (post edit, list, settings, analytics) instead of all wp-admin pages

## [1.4.7] - 2026-04-09
### Fixed
- **Plugin loading in page builder editors** (Bricks, Elementor, Brizy, Oxygen) — NC scripts, drawer and topbar no longer render in editor iframe contexts; prevents conflict with WP Grid Builder patching `fetch()`
- Added `is_admin()` guard as defensive check — `wp_enqueue_scripts` shouldn't fire in admin, but guard prevents edge cases

## [1.4.6] - 2026-04-01
### Fixed
- **Topbar close button (X) not appearing** when notification is not marked as permanent — caused by a redundant global `nc_topbar_dismissible` setting that defaulted to off; removed entirely in favour of per-notification `nc_topbar_permanent` flag
- Topbar with mixed permanent/non-permanent items now shows X and dismisses only non-permanent items (previously `some()` logic hid X if any item was permanent)

### Removed
- Global plugin setting **"Możliwość zamknięcia"** (`nc_topbar_dismissible`) from Top Bar settings page — the per-notification "Bez możliwości zamknięcia (Permanentne)" checkbox is the single control

## [1.4.5] - 2026-03-30
### Added
- **PHP logic test suite** (`tests/php/run-tests.php`) — 42 assertions via `ddev wp eval-file` covering time restrictions, day exclusions, audience, page rules, countdown visibility, API response types and pinned sort order
- **Playwright E2E test suite** (`tests/e2e/nc.spec.js`) — 35 browser-level tests in 10 groups; setup uses single `wp eval` per test for fast execution (~1s setup vs 24s with individual WP-CLI calls)

## [1.4.4] - 2026-03-30
### Fixed
- GitHub Actions release workflow now correctly attaches `notification-centre.zip` to manually triggered (`workflow_dispatch`) releases

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
