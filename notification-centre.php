<?php
/**
 * Plugin Name: Notification Centre
 * Plugin URI:  https://agencyjnie.pl
 * Description: Advanced on-site notification center with OneSignal integration.
 * Version:     1.0.5
 * Author:      Agencyjnie
 * Text Domain: notification-centre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'NC_VERSION', '1.0.5' );
define( 'NC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Plugin Class
 */
class Notification_Centre {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->hooks();
	}

	private function includes() {
		require_once NC_PLUGIN_DIR . 'includes/class-nc-post-type.php';
		require_once NC_PLUGIN_DIR . 'includes/class-nc-metaboxes.php';
		require_once NC_PLUGIN_DIR . 'includes/class-nc-rest-api.php';
        // Logic classes
        require_once NC_PLUGIN_DIR . 'includes/class-nc-logic.php';
        require_once NC_PLUGIN_DIR . 'includes/class-nc-onesignal.php';
        require_once NC_PLUGIN_DIR . 'includes/class-nc-settings.php';
        require_once NC_PLUGIN_DIR . 'includes/class-nc-github-updater.php';
	}

	private function hooks() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_shortcode( 'notification_center', [ $this, 'render_shortcode' ] );
        add_filter( 'wp_nav_menu_items', 'do_shortcode' );
        
        // Render drawer in footer to avoid wpautop adding <p> tags
        add_action( 'wp_footer', [ $this, 'render_drawer_in_footer' ] );
        
        // Render Top Bar at the beginning of body for proper positioning
        add_action( 'wp_body_open', [ $this, 'render_topbar' ] );
	}

    public function enqueue_assets() {
        // Front-end assets
		wp_enqueue_style( 'nc-style', NC_PLUGIN_URL . 'assets/css/style.css', [], NC_VERSION );

        // Check for Fluent Forms in active notifications and enqueue necessary assets
        if ( function_exists( 'fluentFormMix' ) ) {
            $active_notifications = new WP_Query( [
                'post_type' => 'nc_notification',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                's' => '[fluentform' // Efficiently filter posts containing the shortcode
            ] );

            if ( $active_notifications->have_posts() ) {
                // Enqueue Fluent Forms main scripts  
                wp_enqueue_style( 'fluent-form-styles', fluentFormMix( 'css/fluent-forms-public.css' ), [], FLUENTFORM_VERSION );
                wp_enqueue_style( 'fluentform-public-default', fluentFormMix( 'css/fluentform-public-default.css' ), [], FLUENTFORM_VERSION );
                wp_enqueue_script( 'fluent-form-submission', fluentFormMix( 'js/form-submission.js' ), [ 'jquery' ], FLUENTFORM_VERSION, true );

                // Global fluentFormVars
                if ( ! wp_script_is( 'fluent-form-submission', 'done' ) ) {
                    $fluent_vars = [
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'forms' => [],
                        'step_text' => __( 'Step %activeStep% of %totalStep% - %stepTitle%', 'fluentform' ),
                        'is_rtl' => is_rtl(),
                        'date_i18n' => [],
                        'pro_version' => defined( 'FLUENTFORMPRO_VERSION' ) ? FLUENTFORMPRO_VERSION : false,
                        'fluentform_version' => FLUENTFORM_VERSION,
                        'force_init' => false,
                        'nonce' => wp_create_nonce(),
                    ];
                    wp_localize_script( 'fluent-form-submission', 'fluentFormVars', $fluent_vars );
                }

                // Now extract form IDs and add inline form-specific config
                while ( $active_notifications->have_posts() ) {
                    $active_notifications->the_post();
                    $content = get_the_content();
                    
                    // Find all fluentform shortcodes and extract form IDs
                    if ( preg_match_all( '/\[fluentform\s+[^\]]*id=["\']?(\d+)["\']?[^\]]*\]/i', $content, $matches ) ) {
                        foreach ( $matches[1] as $form_id ) {
                            // Get form data
                            $form = wpFluent()->table( 'fluentform_forms' )->where( 'id', $form_id )->first();
                            if ( ! $form ) continue;

                            // Add inline script with generic form variables (to be mapped in JS)
                            // We use a generic name because the instance ID (ff_form_instance_X_Y) is generated randomly/dynamically
                            // and we cannot predict it perfectly here. JS will map it.
                            $generic_var_name = 'fluent_form_model_' . $form_id;
                            
                            $form_vars = [
                                'id' => $form_id,
                                'settings' => [ 'layout' => [] ],
                                'form_instance' => '', // Will be filled by JS
                                'form_id_selector' => 'fluentform_' . $form_id,
                                'rules' => [],
                            ];

                            $inline_script = 'window.' . $generic_var_name . ' = ' . wp_json_encode( $form_vars ) . ';';
                            wp_add_inline_script( 'fluent-form-submission', $inline_script );
                        }
                    }
                }
                wp_reset_postdata();
            }
        }
        
        // Get all options at once (cached)
        $options = $this->get_cached_options();
        
        // Apply Global Radius Settings
        $radius_type = $options['nc_radius_type'] ?: 'rounded';
        $radius_custom = $options['nc_radius_custom'] ?: '20';
        $padding = 12;
        
        $outer_radius = 20;
        if ( $radius_type === 'square' ) {
            $outer_radius = 0;
        } elseif ( $radius_type === 'custom' ) {
            $outer_radius = intval( $radius_custom );
        }
        
        $inner_radius = max( 0, $outer_radius - $padding );
        $radius_var = $outer_radius . 'px';
        $item_radius_var = $inner_radius . 'px';
        $bell_radius_var = ($radius_type === 'rounded') ? '50%' : $item_radius_var;
        
        // Toast Position
        $toast_pos = $options['nc_toast_position'] ?: 'top-right';
        $t_top = 'auto'; $t_bottom = 'auto'; $t_left = 'auto'; $t_right = 'auto';
        
        switch($toast_pos) {
            case 'top-left': $t_top = '20px'; $t_left = '20px'; break;
            case 'bottom-right': $t_bottom = '20px'; $t_right = '20px'; break;
            case 'bottom-left': $t_bottom = '20px'; $t_left = '20px'; break;
            case 'top-right': default: $t_top = '20px'; $t_right = '20px'; break;
        }
        
        // Colors with defaults
        $nc_bg = $options['nc_global_bg'] ?: '#ffffff';
        $nc_text = $options['nc_global_text'] ?: '#1d1d1f';
        $nc_border = $options['nc_global_border'] ?: '#e5e5e5';
        $nc_close_color = $options['nc_close_color'] ?: '#1d1d1f';
        $nc_close_bg = $options['nc_close_bg'] ?: 'rgba(0,0,0,0.05)';
        $nc_close_hover_color = $options['nc_close_hover_color'] ?: '#ff3b30';
        $nc_close_hover_bg = $options['nc_close_hover_bg'] ?: 'rgba(0,0,0,0.1)';
        $nc_bell_bg = $options['nc_bell_bg'] ?: 'transparent';
        $nc_bell_color = $options['nc_bell_color'] ?: '#000000';
        $nc_bell_hover_bg = $options['nc_bell_hover_bg'] ?: 'rgba(0,0,0,0.05)';
        $nc_bell_hover_color = $options['nc_bell_hover_color'] ?: '#007AFF';
        $nc_badge_bg = $options['nc_badge_bg'] ?: '#ff3b30';
        $nc_badge_text = $options['nc_badge_text'] ?: '#ffffff';
        $nc_btn_bg = $options['nc_global_btn_bg'] ?: '#007AFF';
        $nc_btn_text = $options['nc_global_btn_text'] ?: '#ffffff';
        $nc_btn_hover_bg = $options['nc_global_btn_hover_bg'] ?: '#0056b3';
        $nc_btn_hover_text = $options['nc_global_btn_hover_text'] ?: '#ffffff';
        $nc_topbar_bg = $options['nc_topbar_bg'] ?: '#007AFF';
        $nc_topbar_text = $options['nc_topbar_text'] ?: '#ffffff';
        $nc_topbar_btn_bg = $options['nc_topbar_btn_bg'] ?: '#ffffff';
        $nc_topbar_btn_text = $options['nc_topbar_btn_text'] ?: '#007AFF';
        $nc_countdown_bg = $options['nc_countdown_bg'] ?: 'transparent';
        $nc_countdown_value = $options['nc_countdown_value_color'] ?: '#1d1d1f';
        $nc_countdown_unit = $options['nc_countdown_unit_color'] ?: '#666666';
        $drawer_width = $options['nc_drawer_width'] ?: '400';
        
        $custom_css = ":root { 
            --nc-drawer-width: {$drawer_width}px;
            --nc-radius: {$radius_var}; 
            --nc-item-radius: {$item_radius_var};
            --nc-toast-top: {$t_top};
            --nc-toast-bottom: {$t_bottom};
            --nc-toast-left: {$t_left};
            --nc-toast-right: {$t_right};
            --nc-bg: {$nc_bg};
            --nc-text: {$nc_text};
            --nc-border: {$nc_border};
            --nc-close-color: {$nc_close_color};
            --nc-close-bg: {$nc_close_bg};
            --nc-close-hover-color: {$nc_close_hover_color};
            --nc-close-hover-bg: {$nc_close_hover_bg};
            --nc-bell-bg: {$nc_bell_bg};
            --nc-bell-color: {$nc_bell_color};
            --nc-bell-radius: {$bell_radius_var};
            --nc-bell-hover-bg: {$nc_bell_hover_bg};
            --nc-bell-hover-color: {$nc_bell_hover_color};
            --nc-badge-bg: {$nc_badge_bg};
            --nc-badge-text: {$nc_badge_text};
            --nc-btn-bg: {$nc_btn_bg};
            --nc-btn-text: {$nc_btn_text};
            --nc-btn-hover-bg: {$nc_btn_hover_bg};
            --nc-btn-hover-text: {$nc_btn_hover_text};
            --nc-topbar-bg: {$nc_topbar_bg};
            --nc-topbar-text: {$nc_topbar_text};
            --nc-topbar-btn-bg: {$nc_topbar_btn_bg};
            --nc-topbar-btn-text: {$nc_topbar_btn_text};
            --nc-countdown-bg: {$nc_countdown_bg};
            --nc-countdown-value: {$nc_countdown_value};
            --nc-countdown-unit: {$nc_countdown_unit};
        }";
        wp_add_inline_style( 'nc-style', $custom_css );
        
        // Enqueue script with defer for better PageSpeed
        wp_enqueue_script( 'nc-main', NC_PLUGIN_URL . 'assets/js/main.js', [], NC_VERSION, [
            'in_footer' => true,
            'strategy' => 'defer'
        ] );

		wp_localize_script( 'nc-main', 'ncData', [
			'root' => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
            'userId' => get_current_user_id(),
            'panelPosition' => 'right',
            'displayMode' => $options['nc_display_mode'] ?: 'drawer', 
            'drawerWidth' => $drawer_width,
            'enableSound' => $options['nc_enable_sound'] === '1', 
            'badgeType' => $options['nc_badge_type'] ?: 'count',
            'globalColors' => [
                'bg' => $nc_bg,
                'text' => $nc_text,
                'btnBg' => $nc_btn_bg,
                'btnText' => $nc_btn_text,
                'closeColor' => $nc_close_color,
                'closeBg' => $nc_close_bg,
            ],
            'topBar' => [
                'disabled' => ($options['nc_disable_topbar'] ?? '') === '1',
                'dismissible' => $options['nc_topbar_dismissible'] === '1',
                'sticky' => $options['nc_topbar_sticky'] === '1',
                'rotationSpeed' => (int)($options['nc_topbar_rotation_speed'] ?: 5) * 1000,
                'bg' => $nc_topbar_bg,
                'text' => $nc_topbar_text,
                'btnBg' => $nc_topbar_btn_bg,
                'btnText' => $nc_topbar_btn_text,
            ],
            'debugMode' => $options['nc_debug_mode'] === '1',
            'countdown' => [
                'showUnits' => ($options['nc_countdown_show_units'] ?: '1') === '1',
            ],
		] );
	}
    
    /**
     * Get all NC options with caching (reduces DB queries from ~30 to 1)
     */
    private function get_cached_options() {
        $cache_key = 'nc_all_options';
        $options = wp_cache_get( $cache_key, 'notification_centre' );
        
        if ( $options === false ) {
            global $wpdb;
            
            // Get all nc_ options in single query
            $results = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'nc_%'",
                OBJECT
            );
            
            $options = [];
            foreach ( $results as $row ) {
                $options[ $row->option_name ] = $row->option_value;
            }
            
            // Cache for 1 hour (options don't change often)
            wp_cache_set( $cache_key, $options, 'notification_centre', HOUR_IN_SECONDS );
        }
        
        return $options;
    }
    
    public function init() {
		new NC_Post_Type();
		new NC_Metaboxes();
		new NC_Rest_Api();
        new NC_OneSignal_Integration();
        new NC_Settings();
        
        // Admin assets
        add_action('admin_enqueue_scripts', function() {
             wp_enqueue_media();
             wp_enqueue_style( 'nc-admin', NC_PLUGIN_URL . 'assets/css/admin.css', [], NC_VERSION );
             
             // Enqueue WP Color Picker
             wp_enqueue_style( 'wp-color-picker' );
             wp_enqueue_script( 'nc-admin-js', NC_PLUGIN_URL . 'assets/js/admin.js', [ 'wp-color-picker' ], NC_VERSION, true );
        });
	}

	public function render_shortcode( $atts ) {
		// Get bell icon settings
        $bell_style = get_option( 'nc_bell_style', 'outline' );
        $bell_color = get_option( 'nc_bell_color', '#000000' );
        
        // Define SVG icons
        if ( $bell_style === 'solid' ) {
            $bell_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="' . esc_attr($bell_color) . '"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>';
        } else {
            $bell_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr($bell_color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>';
        }
        
        // Render bell icon only - drawer is added via wp_footer
		ob_start();
		?><div id="nc-bell-container" class="nc-bell-container"><div class="nc-bell-icon"><?php echo $bell_svg; ?><span class="nc-badge" style="display:none"></span></div></div><?php
		return ob_get_clean();
	}
    
    /**
     * Render drawer in footer to avoid wpautop issues
     */
    public function render_drawer_in_footer() {
        ?>
        <div id="nc-drawer" class="nc-drawer">
            <div class="nc-drawer-header"><h3>Powiadomienia</h3><button class="nc-close-drawer">&times;</button></div>
            <div class="nc-drawer-content"><div id="nc-notification-list"></div></div>
            <div class="nc-drawer-footer"><button id="nc-mark-all-read">Oznacz wszystkie jako przeczytane</button></div>
        </div>
        <div id="nc-overlay" class="nc-overlay"></div>
        <div id="nc-toast-container" class="nc-toast-container"></div>
        <?php
	}
    
    /**
     * Render Top Bar at the beginning of body
     */
    public function render_topbar() {
        ?>
        <div id="nc-topbar" class="nc-topbar" style="display:none;"></div>
        <?php
    }
}

Notification_Centre::get_instance();
