<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-user WooCommerce notifications stored in a lightweight custom table.
 * Handles order status changes, new orders, abandoned carts, and optional OneSignal push.
 */
class NC_Woo_Notifications {

	const TABLE_NAME = 'nc_user_notifications';

	public function __construct() {
		// Only register hooks if WooCommerce is active (checked before instantiation)
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_checkout_processed' ], 10, 3 );

		// Abandoned cart tracking
		add_action( 'woocommerce_cart_updated', [ $this, 'track_cart_activity' ] );
		add_action( 'nc_abandoned_cart_check', [ $this, 'check_abandoned_carts' ] );

		// Schedule abandoned cart cron if not already scheduled
		if ( ! wp_next_scheduled( 'nc_abandoned_cart_check' ) ) {
			wp_schedule_event( time(), 'hourly', 'nc_abandoned_cart_check' );
		}

		// Daily cleanup of old notifications
		add_action( 'nc_user_notifications_cleanup', [ $this, 'run_cleanup' ] );
		if ( ! wp_next_scheduled( 'nc_user_notifications_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'nc_user_notifications_cleanup' );
		}
	}

	/* =========================================
	 * TABLE CREATION
	 * ========================================= */

	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT NOT NULL,
			event_type VARCHAR(50) NOT NULL,
			title VARCHAR(255) NOT NULL,
			body TEXT,
			cta_url VARCHAR(500),
			cta_label VARCHAR(100),
			icon VARCHAR(50) DEFAULT '📦',
			reference_id BIGINT DEFAULT 0,
			is_read TINYINT(1) DEFAULT 0,
			is_dismissed TINYINT(1) DEFAULT 0,
			push_sent TINYINT(1) DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_user_active (user_id, is_dismissed, created_at),
			INDEX idx_event_type (event_type),
			INDEX idx_cleanup (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* =========================================
	 * CRUD
	 * ========================================= */

	/**
	 * Insert a new user notification and optionally send a push via OneSignal.
	 */
	public static function insert( $user_id, $event_type, $title, $body = '', $cta_url = '', $cta_label = '', $icon = '📦', $ref_id = 0 ) {
		if ( ! $user_id ) {
			return false; // Ignore guests
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->insert( $table, [
			'user_id'      => $user_id,
			'event_type'   => $event_type,
			'title'        => $title,
			'body'         => $body,
			'cta_url'      => $cta_url,
			'cta_label'    => $cta_label,
			'icon'         => $icon,
			'reference_id' => $ref_id,
		], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ] );

		$insert_id = $wpdb->insert_id;

		// Optional OneSignal push
		if ( $insert_id ) {
			self::maybe_send_push( $user_id, $title, $body, $cta_url );

			// Mark push_sent
			$wpdb->update( $table, [ 'push_sent' => 1 ], [ 'id' => $insert_id ], [ '%d' ], [ '%d' ] );
		}

		return $insert_id;
	}

	/**
	 * Get active (non-dismissed) notifications for a user, newest first.
	 */
	public static function get_for_user( $user_id, $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d AND is_dismissed = 0 ORDER BY created_at DESC LIMIT %d",
			$user_id,
			$limit
		) );
	}

	/**
	 * Mark a notification as read (with user_id security check).
	 */
	public static function mark_read( $id, $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->update(
			$table,
			[ 'is_read' => 1 ],
			[ 'id' => $id, 'user_id' => $user_id ],
			[ '%d' ],
			[ '%d', '%d' ]
		);
	}

	/**
	 * Mark a notification as dismissed (with user_id security check).
	 */
	public static function mark_dismissed( $id, $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->update(
			$table,
			[ 'is_dismissed' => 1 ],
			[ 'id' => $id, 'user_id' => $user_id ],
			[ '%d' ],
			[ '%d', '%d' ]
		);
	}

	/**
	 * Get the count of unread, non-dismissed notifications for a user.
	 */
	public static function get_unread_count( $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0 AND is_dismissed = 0",
			$user_id
		) );
	}

	/**
	 * Delete notifications older than 90 days.
	 */
	public static function cleanup_old() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->query( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
	}

	public function run_cleanup() {
		self::cleanup_old();
	}

	/* =========================================
	 * WOOCOMMERCE HOOKS
	 * ========================================= */

	/**
	 * Check if WooCommerce notifications are globally enabled.
	 */
	private static function is_enabled() {
		return get_option( 'nc_woo_enabled', '1' ) === '1';
	}

	/**
	 * Check if a specific notification event is enabled.
	 */
	private static function is_event_enabled( $event_key ) {
		return self::is_enabled() && get_option( 'nc_woo_' . $event_key, '1' ) === '1';
	}

	/**
	 * Fires when an order status changes (e.g. processing → completed).
	 */
	public function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return; // Guest order — no account to notify
		}

		$order_number = $order->get_order_number();
		$view_url     = $order->get_view_order_url();

		$messages = [
			'processing' => [
				'title' => "Zamówienie #{$order_number} jest w realizacji",
				'body'  => "Twoje zamówienie #{$order_number} jest obecnie przetwarzane. Poinformujemy Cię, gdy zostanie wysłane.",
				'icon'  => '⚙️',
			],
			'completed' => [
				'title' => "Zamówienie #{$order_number} zostało zrealizowane!",
				'body'  => "Twoje zamówienie #{$order_number} zostało zrealizowane. Dziękujemy za zakupy!",
				'icon'  => '✅',
			],
			'on-hold' => [
				'title' => "Zamówienie #{$order_number} oczekuje na płatność",
				'body'  => "Zamówienie #{$order_number} oczekuje na potwierdzenie płatności.",
				'icon'  => '⏳',
			],
			'refunded' => [
				'title' => "Zwrot za zamówienie #{$order_number}",
				'body'  => "Zwrot za zamówienie #{$order_number} został przetworzony.",
				'icon'  => '💰',
			],
			'cancelled' => [
				'title' => "Zamówienie #{$order_number} zostało anulowane",
				'body'  => "Zamówienie #{$order_number} zostało anulowane.",
				'icon'  => '❌',
			],
		];

		if ( ! isset( $messages[ $new_status ] ) ) {
			return;
		}

		// Check if this specific event is enabled in settings
		if ( ! self::is_event_enabled( 'order_' . $new_status ) ) {
			return;
		}

		$msg = $messages[ $new_status ];

		self::insert(
			$user_id,
			'order_' . $new_status,
			$msg['title'],
			$msg['body'],
			$view_url,
			'Zobacz zamówienie',
			$msg['icon'],
			$order_id
		);
	}

	/**
	 * Fires after checkout is processed — order confirmation.
	 */
	public function on_checkout_processed( $order_id, $posted_data, $order ) {
		if ( ! self::is_event_enabled( 'order_new' ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return;
		}

		$order_number = $order->get_order_number();

		self::insert(
			$user_id,
			'order_new',
			"Dziękujemy! Zamówienie #{$order_number} zostało przyjęte",
			"Twoje zamówienie #{$order_number} zostało przyjęte i wkrótce zostanie przetworzone.",
			$order->get_view_order_url(),
			'Zobacz zamówienie',
			'🛒',
			$order_id
		);
	}

	/* =========================================
	 * ABANDONED CART
	 * ========================================= */

	/**
	 * Track when a logged-in user updates their cart.
	 */
	public function track_cart_activity() {
		if ( ! self::is_event_enabled( 'abandoned_cart' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		update_user_meta( $user_id, 'nc_cart_last_updated', time() );
	}

	/**
	 * Cron: Check for abandoned carts (configurable delay, still has items).
	 */
	public function check_abandoned_carts() {
		if ( ! self::is_event_enabled( 'abandoned_cart' ) ) {
			return;
		}

		global $wpdb;

		$delay_minutes = (int) get_option( 'nc_woo_abandoned_cart_delay', 60 );
		$cutoff_time   = time() - ( $delay_minutes * 60 );
		$table        = $wpdb->prefix . self::TABLE_NAME;

		// Find users who updated cart more than $delay_minutes ago
		$users = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, meta_value AS last_updated
			 FROM {$wpdb->usermeta}
			 WHERE meta_key = 'nc_cart_last_updated'
			   AND meta_value > 0
			   AND meta_value < %d",
			$cutoff_time
		) );

		foreach ( $users as $row ) {
			$user_id = (int) $row->user_id;

			// Check if user still has a persistent cart
			$cart = get_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
			if ( empty( $cart['cart'] ) ) {
				// Cart empty — clear the meta and skip
				delete_user_meta( $user_id, 'nc_cart_last_updated' );
				continue;
			}

			// Rate limit: max 1 abandoned cart notification per 24h
			$recent = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE user_id = %d AND event_type = 'abandoned_cart'
				   AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
				$user_id
			) );

			if ( $recent > 0 ) {
				continue;
			}

			self::insert(
				$user_id,
				'abandoned_cart',
				'Masz produkty w koszyku!',
				'Wygląda na to, że nie dokończyłeś zakupów. Twoje produkty czekają.',
				wc_get_cart_url(),
				'Dokończ zakupy',
				'🛒',
				0
			);

			// Clear the tracking meta so we don't re-trigger until next cart update
			delete_user_meta( $user_id, 'nc_cart_last_updated' );
		}
	}

	/* =========================================
	 * ONESIGNAL PUSH (optional)
	 * ========================================= */

	/**
	 * Send push notification via OneSignal if configured.
	 */
	private static function maybe_send_push( $user_id, $title, $body, $url = '' ) {
		if ( get_option( 'nc_woo_push_enabled', '' ) !== '1' ) {
			return;
		}

		$settings = get_option( 'OneSignalWPSetting' );
		if ( ! $settings || empty( $settings['app_id'] ) || empty( $settings['app_rest_api_key'] ) ) {
			return;
		}

		wp_remote_post( 'https://onesignal.com/api/v1/notifications', [
			'headers' => [
				'Authorization' => 'Basic ' . $settings['app_rest_api_key'],
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'app_id'          => $settings['app_id'],
				'include_aliases' => [ 'external_id' => [ (string) $user_id ] ],
				'target_channel'  => 'push',
				'headings'        => [ 'en' => $title ],
				'contents'        => [ 'en' => wp_strip_all_tags( $body ) ],
				'url'             => $url,
			] ),
			'timeout'   => 10,
			'blocking'  => false, // Fire-and-forget
		] );
	}
}
