<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NC_Analytics {

	const TABLE_NAME = 'nc_events';
	const ALLOWED_EVENTS = [ 'view', 'click', 'dismiss', 'cta_click' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_menu', [ $this, 'add_analytics_page' ] );
		add_action( 'nc_cleanup_old_events', [ $this, 'cleanup_old_events' ] );

		// Schedule cleanup cron if not already scheduled
		if ( ! wp_next_scheduled( 'nc_cleanup_old_events' ) ) {
			wp_schedule_event( time(), 'daily', 'nc_cleanup_old_events' );
		}
	}

	/**
	 * Create the analytics table via dbDelta.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT AUTO_INCREMENT PRIMARY KEY,
			notification_id BIGINT NOT NULL,
			event_type VARCHAR(20) NOT NULL,
			user_id BIGINT DEFAULT 0,
			session_id VARCHAR(64),
			page_url VARCHAR(500),
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_notification_id (notification_id),
			INDEX idx_event_type (event_type),
			INDEX idx_created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Register REST API route for event tracking.
	 */
	public function register_routes() {
		register_rest_route( 'nc/v1', '/events', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_event' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'notification_id' => [
					'required'          => true,
					'validate_callback' => function( $value ) {
						return is_numeric( $value ) && $value > 0;
					},
					'sanitize_callback' => 'absint',
				],
				'event_type' => [
					'required'          => true,
					'validate_callback' => function( $value ) {
						return in_array( $value, self::ALLOWED_EVENTS, true );
					},
					'sanitize_callback' => 'sanitize_text_field',
				],
				'page_url' => [
					'required'          => false,
					'sanitize_callback' => 'esc_url_raw',
				],
			],
		] );
	}

	/**
	 * Handle incoming event from frontend.
	 */
	public function handle_event( $request ) {
		// Rate limit: max 100 events per minute per IP
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		$rate_key = 'nc_rate_' . md5( $ip );
		$count = (int) get_transient( $rate_key );

		if ( $count >= 100 ) {
			return new WP_Error( 'rate_limited', 'Too many requests', [ 'status' => 429 ] );
		}

		set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

		$notification_id = $request->get_param( 'notification_id' );
		$event_type      = $request->get_param( 'event_type' );
		$page_url        = $request->get_param( 'page_url' ) ?: '';
		$user_id         = get_current_user_id();

		// Session ID from cookie or generate one
		$session_id = $_COOKIE['nc_session'] ?? wp_generate_uuid4();

		$this->track_event( $notification_id, $event_type, $user_id, $session_id, $page_url );

		return rest_ensure_response( [ 'success' => true, 'session_id' => $session_id ] );
	}

	/**
	 * Store an event in the database.
	 */
	public function track_event( $notification_id, $event_type, $user_id = 0, $session_id = '', $page_url = '' ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->insert(
			$table_name,
			[
				'notification_id' => absint( $notification_id ),
				'event_type'      => sanitize_text_field( $event_type ),
				'user_id'         => absint( $user_id ),
				'session_id'      => sanitize_text_field( $session_id ),
				'page_url'        => esc_url_raw( $page_url ),
				'created_at'      => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Get aggregated stats per notification.
	 */
	public function get_stats( $notification_id = null, $days = 30 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$where = '';
		$params = [];

		if ( $days > 0 ) {
			$where .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
			$params[] = $days;
		}

		if ( $notification_id ) {
			$where .= ' AND notification_id = %d';
			$params[] = $notification_id;
		}

		$sql = "SELECT
			notification_id,
			SUM(event_type = 'view') AS views,
			SUM(event_type = 'click') AS clicks,
			SUM(event_type = 'cta_click') AS cta_clicks,
			SUM(event_type = 'dismiss') AS dismissals
		FROM {$table_name}
		WHERE 1=1 {$where}
		GROUP BY notification_id
		ORDER BY views DESC";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql );
	}

	/**
	 * Get summary totals across all notifications.
	 */
	public function get_summary( $days = 30 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$where = '';
		$params = [];

		if ( $days > 0 ) {
			$where = 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
			$params[] = $days;
		}

		$sql = "SELECT
			SUM(event_type = 'view') AS total_views,
			SUM(event_type = 'cta_click') AS total_cta_clicks,
			SUM(event_type = 'click') AS total_clicks,
			SUM(event_type = 'dismiss') AS total_dismissals
		FROM {$table_name}
		{$where}";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_row( $sql );
	}

	/**
	 * Cleanup events older than X days (default 90).
	 */
	public function cleanup_old_events( $days = 90 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	/**
	 * Add Analityka submenu page.
	 */
	public function add_analytics_page() {
		add_submenu_page(
			'edit.php?post_type=nc_notification',
			'Analityka Notification Centre',
			'Analityka',
			'manage_options',
			'nc-analytics',
			[ $this, 'render_analytics_page' ]
		);
	}

	/**
	 * Render the analytics admin page.
	 */
	public function render_analytics_page() {
		// Period selector
		$period = isset( $_GET['period'] ) ? sanitize_text_field( $_GET['period'] ) : '30';
		$days = $period === 'all' ? 0 : (int) $period;
		$period_label = $this->get_period_label( $period );

		// Get data
		$summary = $this->get_summary( $days );
		$stats   = $this->get_stats( null, $days );

		$total_views     = (int) ( $summary->total_views ?? 0 );
		$total_cta       = (int) ( $summary->total_cta_clicks ?? 0 );
		$total_dismissals = (int) ( $summary->total_dismissals ?? 0 );
		$avg_ctr         = $total_views > 0 ? round( ( $total_cta / $total_views ) * 100, 1 ) : 0;

		?>
		<div class="wrap">
			<h1>Analityka Powiadomień</h1>

			<div class="nc-analytics-period" style="margin: 15px 0;">
				<?php
				$base_url = admin_url( 'edit.php?post_type=nc_notification&page=nc-analytics' );
				$periods = [
					'7'   => 'Ostatnie 7 dni',
					'30'  => 'Ostatnie 30 dni',
					'all' => 'Wszystko',
				];
				foreach ( $periods as $val => $label ) :
					$active = ( $period === $val ) ? ' button-primary' : '';
					?>
					<a href="<?php echo esc_url( add_query_arg( 'period', $val, $base_url ) ); ?>" class="button<?php echo $active; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>

			<div class="nc-analytics-cards" style="display: flex; gap: 15px; margin-bottom: 25px;">
				<div class="nc-analytics-card" style="flex: 1; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; text-align: center;">
					<div style="font-size: 28px; font-weight: 700; color: #2271b1;"><?php echo number_format_i18n( $total_views ); ?></div>
					<div style="color: #50575e; margin-top: 5px;">Łącznie wyświetleń</div>
				</div>
				<div class="nc-analytics-card" style="flex: 1; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; text-align: center;">
					<div style="font-size: 28px; font-weight: 700; color: #00a32a;"><?php echo number_format_i18n( $total_cta ); ?></div>
					<div style="color: #50575e; margin-top: 5px;">Łącznie kliknięć CTA</div>
				</div>
				<div class="nc-analytics-card" style="flex: 1; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; text-align: center;">
					<div style="font-size: 28px; font-weight: 700; color: #dba617;"><?php echo $avg_ctr; ?>%</div>
					<div style="color: #50575e; margin-top: 5px;">Średni CTR</div>
				</div>
			</div>

			<?php if ( ! empty( $stats ) ) : ?>
			<table class="widefat striped" style="margin-top: 10px;">
				<thead>
					<tr>
						<th>Tytuł</th>
						<th>Typ</th>
						<th style="text-align:center;">Wyświetlenia</th>
						<th style="text-align:center;">Kliknięcia CTA</th>
						<th style="text-align:center;">CTR</th>
						<th style="text-align:center;">Zamknięcia</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stats as $row ) :
						$post = get_post( $row->notification_id );
						if ( ! $post ) continue;

						$views   = (int) $row->views;
						$cta     = (int) $row->cta_clicks;
						$dismiss = (int) $row->dismissals;
						$ctr     = $views > 0 ? round( ( $cta / $views ) * 100, 1 ) : 0;

						// Determine notification type
						$types = [];
						if ( get_post_meta( $post->ID, 'nc_show_in_sidebar', true ) === '1' ) $types[] = 'Panel';
						if ( get_post_meta( $post->ID, 'nc_show_as_floating', true ) === '1' ) $types[] = 'Toast';
						if ( get_post_meta( $post->ID, 'nc_show_as_topbar', true ) === '1' ) $types[] = 'Top Bar';
						$type_label = ! empty( $types ) ? implode( ', ', $types ) : '—';
					?>
					<tr>
						<td>
							<a href="<?php echo get_edit_post_link( $post->ID ); ?>">
								<?php echo esc_html( $post->post_title ); ?>
							</a>
						</td>
						<td><?php echo esc_html( $type_label ); ?></td>
						<td style="text-align:center;"><?php echo number_format_i18n( $views ); ?></td>
						<td style="text-align:center;"><?php echo number_format_i18n( $cta ); ?></td>
						<td style="text-align:center;"><?php echo $ctr; ?>%</td>
						<td style="text-align:center;"><?php echo number_format_i18n( $dismiss ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
			<div class="notice notice-info" style="margin-top: 10px;">
				<p>Brak danych dla wybranego okresu (<?php echo esc_html( $period_label ); ?>).</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get human-readable period label.
	 */
	private function get_period_label( $period ) {
		switch ( $period ) {
			case '7':   return 'Ostatnie 7 dni';
			case 'all': return 'Wszystko';
			default:    return 'Ostatnie 30 dni';
		}
	}
}
