<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NC_Rest_Api {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( 'nc/v1', '/notifications', [
			'methods'  => 'GET',
			'callback' => [ $this, 'get_items' ],
			'permission_callback' => '__return_true', // Public endpoint, filters applied inside
            'args' => [
                'url' => [ 'required' => false ],
                'pid' => [ 'required' => false ],
            ]
		] );
	}

	public function get_items( $request ) {
        $context = [
            'url' => esc_url_raw( $request->get_param('url') ?: '' ),
            'post_id' => absint( $request->get_param('pid') ?: 0 ),
            'user_id' => get_current_user_id()
        ];
        
        $notifications = NC_Logic::get_valid_notifications( $context );
        
		return rest_ensure_response( $notifications );
	}
}
