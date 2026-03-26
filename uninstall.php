<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Intentionally does NOT delete notification data (CPT posts, tables).
 * Users may reinstall the plugin and expect their data to persist.
 *
 * @package NotificationCentre
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only clean up lightweight options and cron hooks.
// Notification posts, analytics tables, and user data are preserved.

// Delete options
delete_option( 'nc_topbar_enabled' );
delete_option( 'nc_disable_topbar' );
delete_option( 'nc_topbar_dismissible' );
delete_option( 'nc_enable_sound' );
delete_option( 'nc_topbar_sticky' );
delete_option( 'nc_countdown_show_units' );
delete_option( 'nc_debug_mode' );
delete_option( 'nc_cache_version' );

// Clear cron hooks
wp_clear_scheduled_hook( 'nc_cleanup_old_events' );
wp_clear_scheduled_hook( 'nc_abandoned_cart_check' );
wp_clear_scheduled_hook( 'nc_user_notifications_cleanup' );
