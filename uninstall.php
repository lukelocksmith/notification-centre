<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package NotificationCentre
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options
delete_option( 'notification_centre_settings' );
delete_option( 'nc_topbar_enabled' ); // Old setting
delete_option( 'nc_disable_topbar' ); // New setting
delete_option( 'nc_topbar_dismissible' );
delete_option( 'nc_enable_sound' );
delete_option( 'nc_topbar_sticky' );
delete_option( 'nc_countdown_show_units' );
delete_option( 'nc_debug_mode' );

// Delete custom post type posts
$posts = get_posts( [
	'post_type'   => 'nc_notification',
	'numberposts' => -1,
	'post_status' => 'any',
] );

foreach ( $posts as $post ) {
	wp_delete_post( $post->ID, true );
}

// Drop tables
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nc_events" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nc_user_notifications" );

// Clear cron hooks
wp_clear_scheduled_hook( 'nc_cleanup_old_events' );
wp_clear_scheduled_hook( 'nc_abandoned_cart_check' );
wp_clear_scheduled_hook( 'nc_user_notifications_cleanup' );

// Clean up user meta from abandoned cart tracking
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'nc_cart_last_updated'" );
