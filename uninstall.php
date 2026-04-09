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

// Only clean up cron hooks on uninstall.
// Options, notification posts, analytics tables, and user data are preserved
// so reinstalling or updating the plugin does not lose configuration.

// Clear cron hooks
wp_clear_scheduled_hook( 'nc_cleanup_old_events' );
wp_clear_scheduled_hook( 'nc_cleanup_expired_transients' );
wp_clear_scheduled_hook( 'nc_abandoned_cart_check' );
wp_clear_scheduled_hook( 'nc_user_notifications_cleanup' );

// Clean up expired transients only (not options)
global $wpdb;
$wpdb->query(
    "DELETE a, b FROM {$wpdb->options} a
     LEFT JOIN {$wpdb->options} b ON b.option_name = REPLACE(a.option_name, '_timeout_', '_')
     WHERE a.option_name LIKE '_transient_timeout_nc_%' AND a.option_value < UNIX_TIMESTAMP()"
);
