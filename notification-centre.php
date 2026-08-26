<?php
/**
 * Plugin Name: Notification Centre
 * Plugin URI:  https://agencyjnie.pl
 * Description: Advanced on-site notification center with OneSignal integration.
 * Version:     1.9.3
 * Author:      important.is
 * Text Domain: notification-centre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'NC_VERSION', '1.9.3' );
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
        require_once NC_PLUGIN_DIR . 'includes/class-nc-analytics.php';
        require_once NC_PLUGIN_DIR . 'includes/class-nc-woo-notifications.php';
	}

	private function hooks() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_shortcode( 'notification_center', [ $this, 'render_shortcode' ] );
        add_filter( 'wp_nav_menu_items', function( $items ) {
            if ( strpos( $items, '[notification_center' ) !== false ) {
                $items = preg_replace_callback( '/\[notification_center[^\]]*\]/', function( $match ) {
                    return do_shortcode( $match[0] );
                }, $items );
            }
            return $items;
        } );
        
        // Render drawer in footer to avoid wpautop adding <p> tags
        add_action( 'wp_footer', [ $this, 'render_drawer_in_footer' ] );
        
        // Render Top Bar at the beginning of body for proper positioning
        add_action( 'wp_body_open', [ $this, 'render_topbar' ] );

        // Recompute cached form-ID lists on save (moved off the frontend — see refresh_form_id_cache).
        // Must run on the GENERIC save_post at priority > 10: WP fires save_post_{type} BEFORE the
        // generic save_post, and the metabox writes nc_description on generic save_post @10 — so a
        // type-specific hook would scan the STALE description. @20 runs after the meta is written.
        add_action( 'save_post', [ $this, 'refresh_form_id_cache' ], 20 );
        // Keep the lists fresh when a notification is trashed/restored (changes the publish set).
        add_action( 'trashed_post', [ $this, 'refresh_form_id_cache' ] );
        add_action( 'untrashed_post', [ $this, 'refresh_form_id_cache' ] );
	}

    private function should_skip_frontend() {
        if ( is_admin() ) return true;
        // Page builder editor contexts render frontend in iframe but are not visitor-facing
        if ( isset( $_GET['bricks'] ) || isset( $_GET['elementor-preview'] ) || isset( $_GET['brizy-edit'] ) || isset( $_GET['ct_builder'] ) ) return true;
        return false;
    }

    public function enqueue_assets() {
        if ( $this->should_skip_frontend() ) return;

        // Front-end assets (non-render-blocking CSS)
		wp_enqueue_style( 'nc-style', NC_PLUGIN_URL . 'assets/css/style.css', [], NC_VERSION, 'print' );
        // Switch media to 'all' on load so CSS applies without blocking render
        add_filter( 'style_loader_tag', function( $html, $handle ) {
            if ( $handle === 'nc-style' ) {
                return str_replace( "media='print'", "media='print' onload=\"this.media='all'\"", $html );
            }
            return $html;
        }, 10, 2 );

        // Check for Fluent Forms in active notifications and enqueue necessary assets
        if ( function_exists( 'fluentFormMix' ) ) {
            // Form IDs are precomputed on save (refresh_form_id_cache) and stored in a
            // non-autoloaded option. The frontend only READS the option here — no WP_Query.
            $form_ids = get_option( 'nc_fluentform_ids', false );
            if ( $form_ids === false ) {
                // Fallback: option not yet built (first run / never saved). Compute once,
                // keep the transient as a short-lived cache so the frontend stays fast.
                $form_ids = get_transient( 'nc_fluentform_ids' );
                if ( $form_ids === false ) {
                    $form_ids = $this->scan_notification_form_ids(
                        '[fluentform',
                        '/\[fluentform\s+[^\]]*id=["\']?(\d+)["\']?[^\]]*\]/i'
                    );
                    set_transient( 'nc_fluentform_ids', $form_ids, HOUR_IN_SECONDS );
                }
            }

            if ( ! empty( $form_ids ) ) {
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

                // Add inline form-specific config for each discovered form
                foreach ( $form_ids as $form_id ) {
                    $form = wpFluent()->table( 'fluentform_forms' )->where( 'id', $form_id )->first();
                    if ( ! $form ) continue;

                    $generic_var_name = 'fluent_form_model_' . $form_id;
                    $form_vars = [
                        'id' => $form_id,
                        'settings' => [ 'layout' => [] ],
                        'form_instance' => '',
                        'form_id_selector' => 'fluentform_' . $form_id,
                        'rules' => [],
                    ];

                    $inline_script = 'window.' . $generic_var_name . ' = ' . wp_json_encode( $form_vars ) . ';';
                    wp_add_inline_script( 'fluent-form-submission', $inline_script );
                }
            }
        }

        // Check for Gravity Forms in active notifications and enqueue GF core assets.
        // Popups appear on pages that have no GF form natively, so GF's scripts
        // (gform.submission, spinner, gform_post_render machinery) would not load —
        // without them the re-executed inline form scripts have nothing to hook into.
        // Mirrors the Fluent Forms block above: shortcode lives in nc_description postmeta.
        if ( class_exists( 'GFForms' ) && function_exists( 'gravity_form_enqueue_scripts' ) ) {
            // Precomputed on save; frontend only reads the non-autoloaded option.
            $gf_form_ids = get_option( 'nc_gravityform_ids', false );
            if ( $gf_form_ids === false ) {
                // Fallback: option not yet built (first run / never saved).
                $gf_form_ids = get_transient( 'nc_gravityform_ids' );
                if ( $gf_form_ids === false ) {
                    // Matches both [gravityform id="X"] and [gravityforms id="X"].
                    $gf_form_ids = array_values( array_unique( array_map( 'intval', $this->scan_notification_form_ids(
                        '[gravityform',
                        '/\[gravityforms?\s+[^\]]*id=["\']?(\d+)["\']?[^\]]*\]/i'
                    ) ) ) );
                    set_transient( 'nc_gravityform_ids', $gf_form_ids, HOUR_IN_SECONDS );
                }
            }

            if ( ! empty( $gf_form_ids ) ) {
                // Force GF to output its hooks/init JS even though no form is natively on the page.
                add_filter( 'gform_force_hooks_js_output', '__return_true' );
                foreach ( $gf_form_ids as $gf_form_id ) {
                    // Skip form IDs that no longer exist — a deleted form left in the cached
                    // list would make gravity_form_enqueue_scripts() run GF internals against a
                    // null form and emit "array offset on null" warnings. Defensive: the list is
                    // rebuilt on save, but a stale entry must never break the page.
                    if ( class_exists( 'GFAPI' ) && ! GFAPI::get_form( $gf_form_id ) ) {
                        continue;
                    }
                    // Second arg true = also enqueue the AJAX submission scripts.
                    gravity_form_enqueue_scripts( $gf_form_id, true );
                }
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

        // NOTE: Notification data is intentionally NOT baked into the page HTML here.
        // This page (including this inline script) is cached by LiteSpeed for days,
        // while notification content/rules can change or expire in minutes. Baking
        // notification data into the cached HTML froze stale/broken snapshots (e.g.
        // a form render missing its assets) into the page cache for as long as the
        // page cache lived. The frontend always fetches via AJAX instead (see
        // fetchNotifications() in main.js), which hits the REST endpoint's own
        // short-lived (5 min) cache — decoupled from the full-page cache lifetime.
        $user_id = get_current_user_id();

		wp_localize_script( 'nc-main', 'ncData', [
			'root' => esc_url_raw( rest_url() ),
			'version' => NC_VERSION,
			'nonce' => wp_create_nonce( 'wp_rest' ),
            'userId' => $user_id,
            'notifications' => null,
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
                'sticky' => $options['nc_topbar_sticky'] === '1',
                'rotationSpeed' => (int)($options['nc_topbar_rotation_speed'] ?: 5) * 1000,
                'bg' => $nc_topbar_bg,
                'text' => $nc_topbar_text,
                'btnBg' => $nc_topbar_btn_bg,
                'btnText' => $nc_topbar_btn_text,
            ],
            'cacheVersion' => get_option('nc_cache_version', '0'),
            'debugMode' => $options['nc_debug_mode'] === '1',
            'timezone' => wp_timezone_string(),
            'countdown' => [
                'showUnits' => ($options['nc_countdown_show_units'] ?: '1') === '1',
            ],
            'userNotificationsEndpoint' => rest_url( 'nc/v1/user-notifications' ),
            'hasWooCommerce' => class_exists( 'WooCommerce' ),
		] );
	}
    
    /**
     * Get all NC options with caching (reduces DB queries from ~30 to 1)
     */
    private function get_cached_options() {
        $cache_key = 'nc_all_options';
        $options = get_transient( $cache_key );

        if ( $options === false ) {
            global $wpdb;

            // Get all nc_ options in single query.
            // esc_like() escapes the underscore so it matches literally instead of as a
            // single-char wildcard (a bare "nc_%" would scan far more rows than intended).
            $like = $wpdb->esc_like( 'nc_' ) . '%';
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like
                ),
                OBJECT
            );

            $options = [];
            foreach ( $results as $row ) {
                $options[ $row->option_name ] = $row->option_value;
            }

            set_transient( $cache_key, $options, HOUR_IN_SECONDS );
        }

        // Ensure all expected keys exist to avoid PHP 8 "Undefined array key" notices
        static $defaults = [
            'nc_radius_type'           => 'rounded',
            'nc_radius_custom'         => '20',
            'nc_global_bg'             => '#ffffff',
            'nc_global_text'           => '#1d1d1f',
            'nc_global_border'         => '#e5e5e5',
            'nc_close_color'           => '#1d1d1f',
            'nc_close_bg'              => 'rgba(0,0,0,0.05)',
            'nc_close_hover_color'     => '#ff3b30',
            'nc_close_hover_bg'        => 'rgba(0,0,0,0.1)',
            'nc_bell_bg'               => 'transparent',
            'nc_bell_color'            => '#000000',
            'nc_bell_hover_bg'         => 'rgba(0,0,0,0.05)',
            'nc_bell_hover_color'      => '#007AFF',
            'nc_badge_bg'              => '#ff3b30',
            'nc_badge_text'            => '#ffffff',
            'nc_global_btn_bg'         => '#007AFF',
            'nc_global_btn_text'       => '#ffffff',
            'nc_global_btn_hover_bg'   => '#0056b3',
            'nc_global_btn_hover_text' => '#ffffff',
            'nc_topbar_bg'             => '#007AFF',
            'nc_topbar_text'           => '#ffffff',
            'nc_topbar_btn_bg'         => '#ffffff',
            'nc_topbar_btn_text'       => '#007AFF',
            'nc_countdown_bg'          => 'transparent',
            'nc_countdown_value_color' => '#1d1d1f',
            'nc_countdown_unit_color'  => '#666666',
            'nc_drawer_width'          => '400',
            'nc_display_mode'          => 'drawer',
            'nc_badge_type'            => 'count',
            'nc_topbar_rotation_speed' => '5',
            'nc_countdown_show_units'  => '1',
            'nc_enable_sound'          => '',
            'nc_disable_topbar'        => '',
            'nc_topbar_sticky'         => '',
            'nc_debug_mode'            => '',
        ];

        return array_merge( $defaults, $options );
    }
    
    public function init() {
		new NC_Post_Type();
		new NC_Metaboxes();
		new NC_Rest_Api();
        new NC_OneSignal_Integration();
        new NC_Settings();
        new NC_Analytics();

        // WooCommerce per-user notifications (only if WooCommerce is active)
        if ( class_exists( 'WooCommerce' ) ) {
            new NC_Woo_Notifications();
        }

        // Cleanup expired API transients (prevents wp_options bloat).
        // Scheduling lives in schedule_events() (activation + admin_init fallback),
        // not here — this ran wp_next_scheduled() on every request.
        add_action( 'nc_cleanup_expired_transients', [ $this, 'cleanup_expired_transients' ] );

        // Admin assets — only on NC screens (post type + settings)
        add_action('admin_enqueue_scripts', function( $hook ) {
             $screen = get_current_screen();
             if ( ! $screen ) return;
             $is_nc = $screen->post_type === 'nc_notification'
                   || ( $screen->id ?? '' ) === 'nc_notification_page_nc-settings'
                   || ( $screen->id ?? '' ) === 'nc_notification_page_nc-analytics';
             if ( ! $is_nc ) return;

             wp_enqueue_media();
             wp_enqueue_style( 'nc-admin', NC_PLUGIN_URL . 'assets/css/admin.css', [], NC_VERSION );
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
		?><div id="nc-bell-container" class="nc-bell-container" role="button" aria-label="Otwórz powiadomienia" tabindex="0" aria-expanded="false"><div class="nc-bell-icon"><?php echo $bell_svg; ?><span class="nc-badge" style="display:none"></span></div></div><?php
		return ob_get_clean();
	}
    
    /**
     * Render drawer in footer to avoid wpautop issues
     */
    public function render_drawer_in_footer() {
        if ( $this->should_skip_frontend() ) return;
        ?>
        <div id="nc-drawer" class="nc-drawer" role="dialog" aria-modal="true" aria-label="Panel powiadomień">
            <div class="nc-drawer-header"><h3>Powiadomienia</h3><button class="nc-close-drawer" aria-label="Zamknij">&times;</button></div>
            <div class="nc-drawer-content"><div id="nc-notification-list"></div></div>
            <div class="nc-drawer-footer"><button id="nc-mark-all-read">Oznacz wszystkie jako przeczytane</button></div>
        </div>
        <div id="nc-overlay" class="nc-overlay"></div>
        <div id="nc-toast-container" class="nc-toast-container" aria-live="polite" role="status"></div>
        <?php
	}
    
    /**
     * Render Top Bar at the beginning of body
     */
    public function render_topbar() {
        if ( $this->should_skip_frontend() ) return;
        ?>
        <div id="nc-topbar" class="nc-topbar" role="banner" aria-label="Ogłoszenie" style="display:none;"></div>
        <?php
    }

    /**
     * Remove expired nc_api_* and nc_rate_* transients from wp_options to prevent bloat.
     * Underscores are escaped so LIKE matches them literally instead of as wildcards.
     */
    public function cleanup_expired_transients() {
        global $wpdb;

        $api_like  = $wpdb->esc_like( '_transient_timeout_nc_api_' ) . '%';
        $rate_like = $wpdb->esc_like( '_transient_timeout_nc_rate_' ) . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE a, b FROM {$wpdb->options} a
                 LEFT JOIN {$wpdb->options} b ON b.option_name = REPLACE(a.option_name, '_timeout_', '_')
                 WHERE ( a.option_name LIKE %s OR a.option_name LIKE %s )
                   AND a.option_value < UNIX_TIMESTAMP()",
                $api_like,
                $rate_like
            )
        );
    }

    /**
     * Scan published notifications for a form shortcode and return the matched form IDs.
     * Used both by the on-save recompute and the first-run frontend fallback.
     *
     * @param string $shortcode_prefix e.g. '[fluentform' (LIKE needle in nc_description meta)
     * @param string $regex            capturing regex extracting the numeric form id
     * @return array
     */
    private function scan_notification_form_ids( $shortcode_prefix, $regex ) {
        // The shortcode can live in either the structured description (nc_description)
        // or the raw HTML/CSS body (nc_raw_html, content mode 'raw') — search both.
        $query = new WP_Query( [
            'post_type'      => 'nc_notification',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => 'nc_description',
                    'value'   => $shortcode_prefix,
                    'compare' => 'LIKE',
                ],
                [
                    'key'     => 'nc_raw_html',
                    'value'   => $shortcode_prefix,
                    'compare' => 'LIKE',
                ],
            ],
        ] );

        $ids = [];
        foreach ( $query->posts as $notification_id ) {
            $haystack  = (string) get_post_meta( $notification_id, 'nc_description', true );
            $haystack .= "\n" . (string) get_post_meta( $notification_id, 'nc_raw_html', true );
            if ( preg_match_all( $regex, $haystack, $matches ) ) {
                $ids = array_merge( $ids, $matches[1] );
            }
        }

        return array_values( array_unique( $ids ) );
    }

    /**
     * Recompute the Fluent Forms / Gravity Forms ID lists and store them in
     * non-autoloaded options. Runs on save_post_nc_notification so the frontend
     * never has to run the meta_query scan itself.
     */
    public function refresh_form_id_cache( $post_id = 0 ) {
        // Skip autosaves/revisions — no meaningful content change to index.
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        // Only react to notification saves/trashes (save_post fires for every post type).
        if ( $post_id && get_post_type( $post_id ) !== 'nc_notification' ) {
            return;
        }

        $fluent = $this->scan_notification_form_ids(
            '[fluentform',
            '/\[fluentform\s+[^\]]*id=["\']?(\d+)["\']?[^\]]*\]/i'
        );
        update_option( 'nc_fluentform_ids', $fluent, false );

        $gravity = array_values( array_unique( array_map( 'intval', $this->scan_notification_form_ids(
            '[gravityform',
            '/\[gravityforms?\s+[^\]]*id=["\']?(\d+)["\']?[^\]]*\]/i'
        ) ) ) );
        update_option( 'nc_gravityform_ids', $gravity, false );

        // Drop any stale short-lived transient fallbacks so they can't shadow the fresh options.
        delete_transient( 'nc_fluentform_ids' );
        delete_transient( 'nc_gravityform_ids' );
    }

    /**
     * Register all NC cron schedules. Called from register_activation_hook and,
     * as a safe fallback for already-active installs, from admin_init (admin-only,
     * so frontend requests no longer pay the wp_next_scheduled() cost).
     */
    public static function schedule_events() {
        $events = [
            'nc_hourly_cache_purge'         => 'hourly',
            'nc_cleanup_expired_transients' => 'daily',
            'nc_cleanup_old_events'         => 'daily',
            'nc_abandoned_cart_check'       => 'hourly',
            'nc_user_notifications_cleanup' => 'daily',
        ];
        foreach ( $events as $hook => $recurrence ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event( time(), $recurrence, $hook );
            }
        }
    }
}

Notification_Centre::get_instance();

// Create tables on activation
register_activation_hook( __FILE__, [ 'NC_Analytics', 'create_table' ] );
register_activation_hook( __FILE__, [ 'NC_Woo_Notifications', 'create_table' ] );

// Register all cron schedules on activation, plus an admin-only fallback for
// installs that were already active before scheduling moved out of the request path.
register_activation_hook( __FILE__, [ 'Notification_Centre', 'schedule_events' ] );
add_action( 'admin_init', [ 'Notification_Centre', 'schedule_events' ] );

// Hourly: bump the cache version so time-based notifications expire. Bumping
// nc_cache_version is enough — the front fetches data via AJAX from the REST
// endpoint's own 5-min cache, so a site-wide litespeed_purge_all here is
// unnecessary. Full-page purge stays only where real content changes
// (invalidate_notification_caches on save).
add_action( 'nc_hourly_cache_purge', function() {
	update_option( 'nc_cache_version', time(), false );
} );
