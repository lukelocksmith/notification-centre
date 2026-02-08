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
