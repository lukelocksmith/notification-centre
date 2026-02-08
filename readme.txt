=== Notification Centre ===
Contributors: agencyjnie
Donate link: https://agencyjnie.pl
Tags: notifications, popup, toast, announcement bar, exit intent
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced on-site notification center with floating popups, toasts, top bar announcements, and behavioral triggers like exit intent.

== Description ==

**Notification Centre** is a powerful WordPress plugin for creating and managing on-site notifications. Display important messages, announcements, and promotions through various notification types.

= Features =

* **Multiple Display Types:**
  * Notification Center (sidebar drawer)
  * Floating Notifications (toasts/popups)
  * Top Bar Announcements
  
* **Behavioral Triggers:**
  * Delay-based display
  * Exit Intent detection
  * Scroll depth percentage
  * Time on page
  * User inactivity
  * Click-based triggers
  
* **Smart Queue System:**
  * Shows one notification at a time
  * Priority-based ordering
  * Automatic queue advancement
  
* **Customization:**
  * Custom colors and styling
  * Multiple positions (corners, center)
  * Countdown timers
  * Call-to-action buttons
  
* **Targeting:**
  * Page-specific rules
  * User role targeting
  * Schedule by date/time

= Pro Features (Coming Soon) =

* OneSignal push notification integration
* Advanced analytics
* A/B testing

== Installation ==

1. Upload the `notification-centre` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'Notifications' in your admin menu to create your first notification
4. Add the `[nc_bell]` shortcode to your theme to display the notification bell icon

== Frequently Asked Questions ==

= How do I display the notification bell? =

Add the shortcode `[nc_bell]` to your theme's header, footer, or any widget area.

= Can I show different notifications on different pages? =

Yes! Each notification has targeting rules where you can specify which pages to show or hide the notification.

= How does the queue system work? =

Only one floating notification is shown at a time. Notifications are queued by priority (center popups first, then top positions, then bottom). When one is dismissed, the next appears automatically.

= What is Exit Intent? =

Exit Intent detects when a user moves their mouse toward the browser's close/tab area, triggering a notification before they leave your site.

== Screenshots ==

1. Notification editor with all settings
2. Floating toast notification
3. Center popup notification
4. Top bar announcement
5. Notification center drawer

== Changelog ==

= 1.0.3 =
* Added behavioral triggers (Exit Intent, Scroll Depth, Time on Page, Inactivity, Click)
* Implemented global notification queue (one at a time)
* Mobile responsive floating notifications
* Changed global settings to "disable" pattern for better UX
* Added Elementor header support for Top Bar positioning

= 1.0.2 =
* Performance optimizations (cached options)
* Fixed checkbox save bug
* Improved sidebar filter logic
* Added Top Bar compact style

= 1.0.1 =
* Initial public release
* Toast/Popup notifications
* Top Bar announcements
* Notification Center drawer
* Basic targeting rules

== Upgrade Notice ==

= 1.0.3 =
Major update with behavioral triggers and improved queue system. Recommended for all users.

== Privacy Policy ==

This plugin stores notification dismissal data in the browser's localStorage. No personal data is sent to external servers.

The plugin does not collect any user data or use cookies for tracking purposes.
