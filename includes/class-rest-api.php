<?php
namespace WNH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_Api {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( 'wnh/v1', '/subscribe', [
			'methods'  => 'POST',
			'callback' => [ $this, 'handle_subscribe' ],
			'permission_callback' => '__return_true', // Validation inside
		] );

		register_rest_route( 'wnh/v1', '/list', [
			'methods'  => 'GET',
			'callback' => [ $this, 'get_notifications' ],
			'permission_callback' => '__return_true', 
		] );
	}

	public function handle_subscribe( $request ) {
		$params = $request->get_json_params();

		if ( empty( $params['endpoint'] ) || empty( $params['keys']['p256dh'] ) || empty( $params['keys']['auth'] ) ) {
			return new \WP_Error( 'invalid_data', 'Missing subscription keys', [ 'status' => 400 ] );
		}

		$user_id = get_current_user_id(); // 0 if guest

		$id = DB_Manager::save_subscription(
			sanitize_text_field( $params['endpoint'] ),
			sanitize_text_field( $params['keys']['p256dh'] ),
			sanitize_text_field( $params['keys']['auth'] ),
			$user_id
		);

		return rest_ensure_response( [ 'success' => true, 'id' => $id ] );
	}

	public function get_notifications( $request ) {
        // Fetch last 10 notifications from CPT
        // Ideally we filter by user if we had segmentation, but for now getting global broadcasts
        $args = [
            'post_type' => 'wnh_notification',
            'posts_per_page' => 10,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $query = new \WP_Query( $args );
        $posts = [];

        foreach ( $query->posts as $post ) {
            $posts[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'body' => wp_strip_all_tags( $post->post_content ), // Plain text for notification card
                'date' => get_the_date( 'Y-m-d H:i', $post ),
                'url' => get_post_meta( $post->ID, 'wnh_url', true ) ?: home_url(),
                // 'icon' => ...
            ];
        }

		return rest_ensure_response( $posts );
	}
}
