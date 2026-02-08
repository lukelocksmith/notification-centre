<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NC_Post_Type {

	public function __construct() {
		add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'init', [ $this, 'register_taxonomy' ] );
        add_action( 'admin_menu', [ $this, 'fix_admin_menu_title' ], 999 );
	}

    public function fix_admin_menu_title() {
        global $menu;
        foreach ( $menu as $key => $item ) {
            if ( isset($item[2]) && $item[2] === 'edit.php?post_type=nc_notification' ) {
                $menu[$key][0] = 'Notification<br>Centre';
                break;
            }
        }
    }

	public function register_cpt() {
		$labels = [
			'name'                  => 'Notyfikacje',
			'singular_name'         => 'Notyfikacja',
			'menu_name'             => 'Notification Centre',
			'add_new'               => 'Dodaj nową',
			'add_new_item'          => 'Dodaj nową notyfikację',
			'edit_item'             => 'Edytuj notyfikację',
            'all_items'             => 'Wszystkie notyfikacje',
		];

		$args = [
			'labels'             => $labels,
			'public'             => false, // Nie chcemy single view na froncie dla notyfikacji
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 30,
            'menu_icon'          => 'dashicons-bell',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'custom-fields' ], 
		];

		register_post_type( 'nc_notification', $args );
        
        // Redirect logic for single notification view
        add_action( 'template_redirect', [ $this, 'handle_single_redirect' ] );
	}
    
    public function handle_single_redirect() {
        $post_id = 0;
        
        // Method 1: Standard singular check
        if ( is_singular( 'nc_notification' ) ) {
            $post_id = get_the_ID();
        }
        // Method 2: Fallback for ?post_type=nc_notification&p=XX URLs
        elseif ( isset( $_GET['post_type'] ) && $_GET['post_type'] === 'nc_notification' && isset( $_GET['p'] ) ) {
            $post_id = absint( $_GET['p'] );
            // Verify post exists and is correct type
            if ( get_post_type( $post_id ) !== 'nc_notification' ) {
                $post_id = 0;
            }
        }
        
        if ( $post_id > 0 ) {
            $url = get_post_meta( $post_id, 'nc_cta_url', true );
            
            // Handle relative URLs (e.g., /test)
            if ( ! empty( $url ) && strpos( $url, 'http' ) !== 0 && strpos( $url, '/' ) === 0 ) {
                $url = home_url( $url );
            }
            
            if ( empty( $url ) ) {
                $url = home_url( '/' ); // Redirect to home if no URL
            }
            
            // Add UTM source = push
            $url = add_query_arg( [
                'utm_source' => 'push',
                'utm_medium' => 'notification-centre'
            ], $url );
            
            wp_redirect( $url, 301 );
            exit;
        }
    }
    
    public function register_taxonomy() {
        register_taxonomy( 'nc_category', 'nc_notification', [
            'label' => 'Kategorie',
            'public' => false,
            'show_ui' => true,
            'hierarchical' => true,
        ]);
    }
}
