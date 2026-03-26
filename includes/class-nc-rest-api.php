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

		// Per-user notification endpoints (require logged-in user)
		register_rest_route( 'nc/v1', '/user-notifications', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_user_notifications' ],
			'permission_callback' => [ $this, 'require_logged_in' ],
		] );

		register_rest_route( 'nc/v1', '/user-notifications/read', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'mark_user_notification_read' ],
			'permission_callback' => [ $this, 'require_logged_in' ],
			'args'                => [
				'id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( 'nc/v1', '/user-notifications/dismiss', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'dismiss_user_notification' ],
			'permission_callback' => [ $this, 'require_logged_in' ],
			'args'                => [
				'id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );
	}

	/**
	 * Permission callback: requires a logged-in user.
	 */
	public function require_logged_in() {
		return get_current_user_id() > 0;
	}

	/**
	 * GET /nc/v1/user-notifications — returns per-user notifications in the same shape as CPT.
	 */
	public function get_user_notifications( $request ) {
		$user_id = get_current_user_id();
		$rows    = NC_Woo_Notifications::get_for_user( $user_id );

		$items = array_map( function( $row ) {
			return [
				'id'        => 'user_' . $row->id,
				'title'     => $row->title,
				'body'      => $row->body,
				'cta_label' => $row->cta_label,
				'cta_url'   => $row->cta_url,
				'icon'      => $row->icon,
				'type'      => 'user',
				'date'      => $row->created_at,
				'is_read'   => (bool) $row->is_read,
				'settings'  => [
					'dismissible'      => true,
					'pinned'           => false,
					'show_as_floating' => '1',
					'floating_position'=> 'bottom_right',
					'floating_delay'   => 0,
					'floating_duration'=> 0,
					'show_in_sidebar'  => '1',
					'colors'           => [
						'bg'       => '#f0f7ff',
						'text'     => '#333',
						'btn_bg'   => '#007AFF',
						'btn_text' => '#fff',
					],
				],
			];
		}, $rows );

		return rest_ensure_response( $items );
	}

	/**
	 * POST /nc/v1/user-notifications/read
	 */
	public function mark_user_notification_read( $request ) {
		$id      = $request->get_param( 'id' );
		$user_id = get_current_user_id();

		$result = NC_Woo_Notifications::mark_read( $id, $user_id );

		return rest_ensure_response( [ 'success' => (bool) $result ] );
	}

	/**
	 * POST /nc/v1/user-notifications/dismiss
	 */
	public function dismiss_user_notification( $request ) {
		$id      = $request->get_param( 'id' );
		$user_id = get_current_user_id();

		$result = NC_Woo_Notifications::mark_dismissed( $id, $user_id );

		return rest_ensure_response( [ 'success' => (bool) $result ] );
	}

	/**
	 * Strip UTM and tracking parameters from URL for cache key purposes.
	 */
	private function strip_tracking_params( $url ) {
		if ( empty( $url ) ) {
			return $url;
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['query'] ) ) {
			return $url;
		}

		parse_str( $parsed['query'], $query_params );

		$tracking_params = [
			'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
			'fbclid', 'gclid', 'ttclid', 'gad_source', 'gad_campaignid',
			'_ga', '_gl', 'mc_cid', 'mc_eid',
		];
		foreach ( $tracking_params as $param ) {
			unset( $query_params[ $param ] );
		}

		// Rebuild URL without tracking params
		$clean_url = '';
		if ( ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] ) ) {
			$clean_url .= $parsed['scheme'] . '://' . $parsed['host'];
		}
		if ( ! empty( $parsed['path'] ) ) {
			$clean_url .= $parsed['path'];
		}

		// If we couldn't parse scheme/host/path, return original URL minus query
		if ( empty( $clean_url ) ) {
			return strtok( $url, '?' );
		}

		if ( $query_params ) {
			$clean_url .= '?' . http_build_query( $query_params );
		}

		return $clean_url;
	}

	public function get_items( $request ) {
        // Nonce-based auth may fail when HTML is cached (stale nonce).
        // Fall back to direct cookie validation for GET requests — safe for read-only data.
        if ( get_current_user_id() === 0 && defined( 'LOGGED_IN_COOKIE' ) && isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
            $cookie_user_id = wp_validate_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ], 'logged_in' );
            if ( $cookie_user_id ) {
                wp_set_current_user( $cookie_user_id );
            }
        }

        $raw_url = esc_url_raw( $request->get_param('url') ?: '' );
        $user_id = get_current_user_id();

        $context = [
            'url' => $this->strip_tracking_params( $raw_url ),
            'post_id' => absint( $request->get_param('pid') ?: 0 ),
            'user_id' => $user_id
        ];

        // Transient cache (5 min) keyed by context hash
        $version = get_option( 'nc_cache_version', 0 );
        $cache_key = 'nc_api_' . md5( $version . wp_json_encode( $context ) );
        $notifications = get_transient( $cache_key );

        if ( $notifications === false ) {
            $notifications = NC_Logic::get_valid_notifications( $context );
            set_transient( $cache_key, $notifications, 300 );
        }

        // Allow LSCache to cache responses for anonymous users only
        if ( $user_id === 0 ) {
            header( 'X-LiteSpeed-Cache-Control: public, max-age=300' );
        } else {
            header( 'X-LiteSpeed-Cache-Control: no-cache' );
        }

		return rest_ensure_response( $notifications );
	}
}
