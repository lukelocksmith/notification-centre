<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NC_Post_Type {

	public function __construct() {
		add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'init', [ $this, 'register_taxonomy' ] );
        add_action( 'admin_menu', [ $this, 'fix_admin_menu_title' ], 999 );

        // Duplicate notification action
        add_filter( 'post_row_actions', [ $this, 'add_duplicate_action' ], 10, 2 );
        add_action( 'admin_action_nc_duplicate', [ $this, 'handle_duplicate' ] );

        // Custom columns
        add_filter( 'manage_nc_notification_posts_columns', [ $this, 'add_custom_columns' ] );
        add_action( 'manage_nc_notification_posts_custom_column', [ $this, 'render_custom_column' ], 10, 2 );
	}

    public function change_title_placeholder( $placeholder, $post ) {
        if ( $post->post_type === 'nc_notification' ) {
            return 'Nazwa wewnętrzna (np. "Promocja majowa")';
        }
        return $placeholder;
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
			'supports'           => [ 'title', 'custom-fields' ],
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
            
            wp_safe_redirect( esc_url_raw( $url ), 302 );
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

    // ── Duplicate Action ───────────────────────────────

    public function add_duplicate_action( $actions, $post ) {
        if ( $post->post_type !== 'nc_notification' || ! current_user_can( 'edit_posts' ) ) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url( 'admin.php?action=nc_duplicate&post=' . $post->ID ),
            'nc_duplicate_' . $post->ID
        );

        $actions['nc_duplicate'] = '<a href="' . esc_url( $url ) . '">Duplikuj</a>';
        return $actions;
    }

    public function handle_duplicate() {
        if ( ! isset( $_GET['post'] ) ) {
            wp_die( 'Brak ID posta.' );
        }

        $post_id = absint( $_GET['post'] );

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'nc_duplicate_' . $post_id ) ) {
            wp_die( 'Nieprawidłowy nonce.' );
        }

        $original = get_post( $post_id );
        if ( ! $original || $original->post_type !== 'nc_notification' ) {
            wp_die( 'Nie znaleziono notyfikacji.' );
        }

        $new_id = wp_insert_post( [
            'post_title'   => 'Kopia - ' . $original->post_title,
            'post_content' => $original->post_content,
            'post_type'    => 'nc_notification',
            'post_status'  => 'draft',
        ] );

        if ( is_wp_error( $new_id ) ) {
            wp_die( 'Błąd podczas duplikowania.' );
        }

        // Copy all nc_ meta fields
        $all_meta = get_post_meta( $post_id );
        foreach ( $all_meta as $key => $values ) {
            if ( strpos( $key, 'nc_' ) === 0 ) {
                foreach ( $values as $value ) {
                    add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
                }
            }
        }

        // Copy taxonomy terms
        $terms = wp_get_object_terms( $post_id, 'nc_category', [ 'fields' => 'ids' ] );
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            wp_set_object_terms( $new_id, $terms, 'nc_category' );
        }

        wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
        exit;
    }

    // ── Custom Columns ─────────────────────────────────

    public function add_custom_columns( $columns ) {
        $new_columns = [];

        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;

            // Insert custom columns after the title
            if ( $key === 'title' ) {
                $new_columns['nc_type']     = 'Typ';
                $new_columns['nc_status']   = 'Status';
                $new_columns['nc_audience'] = 'Odbiorcy';
                $new_columns['nc_views']    = 'Wyświetlenia';
                $new_columns['nc_ctr']      = 'CTR';
            }
        }

        return $new_columns;
    }

    public function render_custom_column( $column, $post_id ) {
        switch ( $column ) {
            case 'nc_type':
                $types = [];
                if ( get_post_meta( $post_id, 'nc_show_in_sidebar', true ) === '1' ) {
                    $types[] = '<span class="nc-col-badge nc-badge-sidebar">Panel</span>';
                }
                if ( get_post_meta( $post_id, 'nc_show_as_floating', true ) === '1' ) {
                    $types[] = '<span class="nc-col-badge nc-badge-floating">Toast</span>';
                }
                if ( get_post_meta( $post_id, 'nc_show_as_topbar', true ) === '1' ) {
                    $types[] = '<span class="nc-col-badge nc-badge-topbar">Top Bar</span>';
                }
                echo ! empty( $types ) ? implode( ' ', $types ) : '<span style="color:#999;">—</span>';
                break;

            case 'nc_status':
                $now  = current_time( 'Y-m-d\TH:i' );
                $from = get_post_meta( $post_id, 'nc_active_from', true );
                $to   = get_post_meta( $post_id, 'nc_active_to', true );

                if ( ! empty( $to ) && $to < $now ) {
                    echo '<span class="nc-col-status nc-status-expired">Wygasłe</span>';
                } elseif ( ! empty( $from ) && $from > $now ) {
                    echo '<span class="nc-col-status nc-status-scheduled">Zaplanowane</span>';
                } else {
                    echo '<span class="nc-col-status nc-status-active">Aktywne</span>';
                }
                break;

            case 'nc_audience':
                $audience = get_post_meta( $post_id, 'nc_audience', true ) ?: 'all';
                $labels = [
                    'all'        => 'Wszyscy',
                    'logged_in'  => 'Zalogowani',
                    'logged_out' => 'Goście',
                ];
                echo esc_html( $labels[ $audience ] ?? $audience );
                break;

            case 'nc_views':
            case 'nc_ctr':
                $this->render_stats_column( $column, $post_id );
                break;
        }
    }

    private function render_stats_column( $column, $post_id ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'nc_events';

        // Check if analytics table exists (cached per request)
        static $table_exists = null;
        if ( $table_exists === null ) {
            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
        }

        if ( ! $table_exists ) {
            echo '<span style="color:#999;">—</span>';
            return;
        }

        // Cache stats per request to avoid repeated queries
        static $stats_cache = [];
        if ( ! isset( $stats_cache[ $post_id ] ) ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    SUM(event_type = 'view') AS views,
                    SUM(event_type = 'cta_click') AS cta_clicks
                FROM {$table_name}
                WHERE notification_id = %d",
                $post_id
            ) );
            $stats_cache[ $post_id ] = $row;
        }

        $row = $stats_cache[ $post_id ];
        $views = (int) ( $row->views ?? 0 );
        $cta   = (int) ( $row->cta_clicks ?? 0 );

        if ( $column === 'nc_views' ) {
            echo $views > 0 ? number_format_i18n( $views ) : '<span style="color:#999;">0</span>';
        } elseif ( $column === 'nc_ctr' ) {
            if ( $views > 0 ) {
                $ctr = round( ( $cta / $views ) * 100, 1 );
                echo $ctr . '%';
            } else {
                echo '<span style="color:#999;">—</span>';
            }
        }
    }
}
