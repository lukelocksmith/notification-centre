<?php
/**
 * Plugin Name: Notification Centre
 * Plugin URI:  https://agencyjnie.pl
 * Description: Advanced on-site notification center with OneSignal integration.
 * Version:     1.10.2
 * Author:      important.is
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: notification-centre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'NC_VERSION', '1.10.2' );
define( 'NC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Plugin Class
 */
class Notification_Centre {

	private static $instance = null;
	private $fluent_config = null;
	private $front_data = null;
	private $ssr_topbar = null;
	private $topbar_printed = false;
	private $bell_rendered = false;

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
        add_action( 'bricks_after_header', [ $this, 'render_topbar' ] );
        add_action( 'wp_footer', [ $this, 'render_topbar' ], 5 );
        add_filter( 'body_class', [ $this, 'topbar_body_class' ] );
        add_action( 'wp_footer', [ $this, 'render_customer_flag_script' ], 99 );
        add_action( 'wp_footer', [ $this, 'render_front_loader' ], 100 );

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

        // style.css and main.js are NOT printed as tags: the loader in render_front_loader()
        // fetches them on the visitor's first input, so page load (and PageSpeed, which never
        // interacts) carries no NC code. Only a small inline style (CSS variables, and the
        // top bar rules when the server printed a bar) is in the page.
        wp_register_style( 'nc-inline', false, [], NC_VERSION );
        wp_enqueue_style( 'nc-inline' );

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
                // Popups with a form show on a few pages and only after a trigger, so the
                // Fluent Forms bundle is NOT enqueued here. main.js loads it from this config
                // right before such a popup opens (loadFluentAssets).
                $models = [];
                foreach ( $form_ids as $form_id ) {
                    $form = wpFluent()->table( 'fluentform_forms' )->where( 'id', $form_id )->first();
                    if ( ! $form ) continue;
                    $models[ (string) $form_id ] = [
                        'id' => $form_id,
                        'settings' => [ 'layout' => [] ],
                        'form_instance' => '',
                        'form_id_selector' => 'fluentform_' . $form_id,
                        'rules' => [],
                    ];
                }
                if ( $models ) {
                    $ver = function ( $url ) { return add_query_arg( 'ver', FLUENTFORM_VERSION, $url ); };
                    $this->fluent_config = [
                        'css' => [
                            $ver( fluentFormMix( 'css/fluent-forms-public.css' ) ),
                            $ver( fluentFormMix( 'css/fluentform-public-default.css' ) ),
                        ],
                        'js' => $ver( fluentFormMix( 'js/form-submission.js' ) ),
                        'jquery' => includes_url( 'js/jquery/jquery.min.js' ),
                        'vars' => [
                            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                            'forms' => [],
                            'step_text' => __( 'Step %activeStep% of %totalStep% - %stepTitle%', 'fluentform' ),
                            'is_rtl' => is_rtl(),
                            'date_i18n' => [],
                            'pro_version' => defined( 'FLUENTFORMPRO_VERSION' ) ? FLUENTFORMPRO_VERSION : false,
                            'fluentform_version' => FLUENTFORM_VERSION,
                            'force_init' => false,
                            'nonce' => wp_create_nonce(),
                        ],
                        'models' => $models,
                    ];
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
        // Footer markup (drawer, overlay, toasts) has no layout until style.css arrives on the
        // first input; hidden until then so it never shows up as bare text under the footer.
        $custom_css .= "\nhtml:not(.nc-css) #nc-drawer,html:not(.nc-css) #nc-overlay,html:not(.nc-css) #nc-toast-container{display:none!important}";
        // The server-rendered top bar must have its final size at first paint, while
        // style.css loads non-blocking — so its rules go inline, only when a bar is printed.
        if ( $this->get_ssr_topbar() ) {
            $custom_css .= "\n" . file_get_contents( NC_PLUGIN_DIR . 'assets/css/topbar-critical.css' );
        }
        wp_add_inline_style( 'nc-inline', $custom_css );
        
        // Enqueue script with defer for better PageSpeed
        // Data is not baked per notification: the page is cached by LiteSpeed while
        // notifications change in minutes, so the front end fetches them from REST.
        $user_id = get_current_user_id();

		$this->front_data = [
            'assets' => [
                'js'  => add_query_arg( 'ver', NC_VERSION, NC_PLUGIN_URL . 'assets/js/main.js' ),
                'css' => add_query_arg( 'ver', NC_VERSION, NC_PLUGIN_URL . 'assets/css/style.css' ),
            ],
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
            // Per-user Woo notifications exist only when enabled — skip the fetch otherwise.
            'wooNotifications' => class_exists( 'WooCommerce' ) && get_option( 'nc_woo_enabled', '1' ) === '1',
            'fluentForms' => $this->fluent_config,
            'ssrTopbar' => (bool) $this->get_ssr_topbar(),
            // Logged-in pages are never in the shared page cache, so this flag is safe here.
            'isCustomer' => NC_Logic::is_customer( get_current_user_id() ),
            'rotation' => self::rotation_limits(),
		];
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
        $this->bell_rendered = true;
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
        // The drawer (a modal dialog for screen readers, with focusable buttons) only has a
        // purpose next to the bell. Without the bell it is not printed at all.
        if ( ! $this->bell_rendered ) {
            echo '<div id="nc-toast-container" class="nc-toast-container" aria-live="polite" role="status"></div>';
            return;
        }
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
     * Top bar items rendered into the page HTML, so the bar is in place at first paint
     * instead of being injected by JS later and pushing the content down (CLS).
     * Dismissible bars are included: the inline script next to the bar drops the ones this
     * browser already closed (localStorage) before first paint. Device-split, customers-only
     * and rotation bars are not: those are decided in the browser by main.js.
     * Also caps the page-cache TTL at the next schedule/countdown boundary.
     *
     * @return array|false
     */
    public function get_ssr_topbar() {
        if ( $this->ssr_topbar !== null ) return $this->ssr_topbar;
        if ( ! did_action( 'wp' ) || is_admin() || wp_doing_ajax() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return false;

        $this->ssr_topbar = false;
        if ( $this->should_skip_frontend() || ! empty( $_GET['nc_preview'] ) ) return false;
        $options = $this->get_cached_options();
        if ( $options['nc_disable_topbar'] === '1' ) return false;

        $scheme  = is_ssl() ? 'https://' : 'http://';
        $context = [
            'url'     => esc_url_raw( $scheme . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ) ),
            'post_id' => is_singular() ? get_queried_object_id() : 0,
            'user_id' => get_current_user_id(),
        ];

        $until = NC_Logic::seconds_until_change( $context, true );
        if ( $until !== null ) {
            do_action( 'litespeed_control_set_ttl', $until );
        }

        $items = array_values( array_filter(
            NC_Logic::get_valid_notifications( $context, true ),
            // Device split, "customers only" and rotation groups are decided in the browser,
            // which a shared cached page can't do; those bars stay on the JS path.
            function ( $n ) {
                return ( $n['settings']['device_target'] ?? 'all' ) === 'all'
                    && empty( $n['settings']['customers_only'] )
                    && empty( $n['settings']['rotation_group'] );
            }
        ) );
        if ( ! $items ) return false;
        // "Below the header" can be printed in place only on Bricks (bricks_after_header).
        // Elsewhere main.js has to move the bar, so keep those themes on the JS path.
        if ( ! defined( 'BRICKS_VERSION' ) ) {
            foreach ( $items as $n ) {
                if ( ( $n['settings']['topbar_position'] ?? 'above' ) === 'below' ) return false;
            }
        }
        usort( $items, function ( $a, $b ) {
            return ( $b['settings']['topbar_priority'] ?? 0 ) - ( $a['settings']['topbar_priority'] ?? 0 );
        } );

        $this->ssr_topbar = $items;
        return $items;
    }

    private function ssr_topbar_below() {
        $items = $this->get_ssr_topbar();
        if ( ! $items ) return false;
        foreach ( $items as $n ) {
            if ( ( $n['settings']['topbar_position'] ?? 'above' ) === 'below' ) return true;
        }
        return false;
    }

    public function topbar_body_class( $classes ) {
        if ( $this->get_ssr_topbar() ) $classes[] = 'nc-topbar-active';
        return $classes;
    }

    /**
     * Top Bar container. With a server-rendered bar placed "below" the header on Bricks,
     * it is printed on bricks_after_header; wp_footer is the fallback so the container
     * always exists exactly once.
     */
    public function render_topbar() {
        if ( $this->topbar_printed || $this->should_skip_frontend() ) return;
        $current = current_action();
        if ( $current === 'wp_body_open' && $this->ssr_topbar_below() && defined( 'BRICKS_VERSION' ) ) return;
        if ( $current === 'bricks_after_header' && ! $this->ssr_topbar_below() ) return;
        // Footer is only the safety net for "skipped body_open, Bricks hook never fired";
        // themes without wp_body_open keep their old behaviour (no container).
        if ( $current === 'wp_footer' && ! did_action( 'wp_body_open' ) ) return;

        $this->topbar_printed = true;
        $items = $this->get_ssr_topbar();
        if ( ! $items || $current === 'wp_footer' ) {
            echo '<div id="nc-topbar" class="nc-topbar" role="region" aria-label="Ogłoszenie" style="display:none;"></div>';
            return;
        }
        echo $this->topbar_markup( $items );
    }

    private static function js_esc( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', true );
    }

    private static function js_safe_url( $u ) {
        $u = trim( (string) $u );
        return preg_match( '/^(https?:|\/|#|mailto:|tel:)/i', $u ) ? self::js_esc( $u ) : '#';
    }

    /**
     * Same markup as renderTopBar() in main.js, which adopts it when the item ids match.
     */
    private function topbar_markup( $items ) {
        $options = $this->get_cached_options();
        $classes = [ 'nc-topbar' ];
        if ( $options['nc_topbar_sticky'] === '1' ) $classes[] = 'nc-topbar-sticky';
        $ids = [];
        foreach ( $items as $n ) {
            $ids[] = $n['id'];
            if ( ( $n['settings']['topbar_style'] ?? '' ) === 'compact' ) $classes[] = 'nc-topbar-compact';
            if ( ( $n['settings']['topbar_position'] ?? '' ) === 'below' ) $classes[] = 'nc-topbar-below-header';
        }
        $classes = array_unique( $classes );

        $first = $items[0]['settings']['colors'] ?? [];
        $style = 'display:flex;';
        if ( ! empty( $first['bg'] ) ) {
            $style .= 'background-color:' . self::js_esc( $first['bg'] ) . ';';
            if ( ! empty( $first['text'] ) ) $style .= 'color:' . self::js_esc( $first['text'] ) . ';';
        }

        $html = '<div id="nc-topbar" class="' . esc_attr( implode( ' ', $classes ) ) . '" role="region" aria-label="Ogłoszenie" data-ssr="' . esc_attr( implode( ',', $ids ) ) . '" style="' . $style . '">';
        $html .= '<div class="nc-topbar-inner">';
        if ( count( $items ) > 1 ) {
            $html .= '<div class="nc-topbar-dots">';
            foreach ( $items as $i => $n ) {
                $html .= '<span class="nc-topbar-dot' . ( $i === 0 ? ' active' : '' ) . '" data-index="' . $i . '"></span>';
            }
            $html .= '</div>';
        }
        foreach ( $items as $i => $n ) {
            $cs = $n['settings']['colors'] ?? [];
            $item_style = ! empty( $cs['bg'] ) ? 'background-color:' . self::js_esc( $cs['bg'] ) . '; color:' . self::js_esc( $cs['text'] ?: '#fff' ) . ';' : '';
            $dismiss = empty( $n['settings']['topbar_permanent'] )
                ? ' data-rv="' . (int) ( $n['settings']['repeat_val'] ?? 0 ) . '" data-ru="' . esc_attr( $n['settings']['repeat_unit'] ?? 'days' ) . '"'
                : ' data-perm="1"';
            $html .= '<div class="nc-topbar-item ' . ( $i === 0 ? 'active' : '' ) . '" data-id="' . (int) $n['id'] . '"' . $dismiss . ' style="' . $item_style . '">';
            $html .= '<span class="nc-topbar-title">' . self::js_esc( $n['title'] ) . '</span>';
            if ( $n['body'] !== '' && $n['body'] !== null ) {
                $html .= '<span class="nc-topbar-description">' . self::js_esc( $n['body'] ) . '</span>';
            }
            if ( ! empty( $n['settings']['countdown']['enabled'] ) ) {
                $html .= $this->countdown_markup( $n['settings']['countdown'], $options );
            }
            if ( $n['cta_label'] && $n['cta_url'] ) {
                $btn_style = ! empty( $cs['btn_bg'] ) ? ' style="background-color:' . self::js_esc( $cs['btn_bg'] ) . '; color:' . self::js_esc( $cs['btn_text'] ?: '#fff' ) . ';"' : '';
                $target = ( $n['cta_target'] ?? '' ) === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : ' target="_self"';
                $html .= '<a href="' . self::js_safe_url( $n['cta_url'] ) . '" class="nc-topbar-btn"' . $btn_style . $target . '>' . self::js_esc( $n['cta_label'] ) . '</a>';
            }
            $html .= '</div>';
        }
        $all_permanent = true;
        foreach ( $items as $n ) {
            if ( empty( $n['settings']['topbar_permanent'] ) ) $all_permanent = false;
        }
        if ( ! $all_permanent ) {
            $html .= '<button class="nc-topbar-close" type="button" title="Zamknij" aria-label="Zamknij">&times;</button>';
        }
        $html .= '</div></div>';
        // Runs at parse time, before first paint: fresh countdown digits even from a cached
        // page, and a bar whose daily cut-off already passed never flashes up.
        // It keeps ticking until main.js (loaded on first input) takes the bar over.
        $html .= '<script data-no-optimize="1" data-no-defer="1">(function(){var b=document.getElementById("nc-topbar");if(!b)return;function p(n){return(n<10?"0":"")+n}'
            // Same rule as isDismissed() in main.js: closed bars stay hidden for the repeat period, or for good.
            . 'var ds={};try{ds=JSON.parse(localStorage.getItem("nc_topbar_dismissed")||"{}")||{}}catch(e){}var U={minutes:6e4,hours:36e5,days:864e5};'
            . 'b.querySelectorAll(".nc-topbar-item:not([data-perm])").forEach(function(i){var id=i.getAttribute("data-id"),at=Array.isArray(ds)?(ds.indexOf(+id)>-1||ds.indexOf(id)>-1?Date.now():0):ds[id];if(!at)return;var rv=+i.getAttribute("data-rv");'
            . 'if(!rv||Date.now()<at+rv*(U[i.getAttribute("data-ru")]||U.days)){i.remove();b.removeAttribute("data-ssr")}});'
            . 'if(!b.querySelector(".nc-topbar-item:not([data-perm])")){var cb=b.querySelector(".nc-topbar-close");if(cb)cb.remove()}'
            // A removed slide may have been the active one: activate the first left, drop the dots.
            . 'function fix(){var its=b.querySelectorAll(".nc-topbar-item");if(its.length&&!b.querySelector(".nc-topbar-item.active")){its[0].classList.add("active");b.style.backgroundColor=its[0].style.backgroundColor;b.style.color=its[0].style.color}if(its.length<2){var dt=b.querySelector(".nc-topbar-dots");if(dt)dt.remove()}}fix();'
            . 'function t(){if(window.ncMainReady){clearInterval(x);return}b.querySelectorAll(".nc-countdown").forEach(function(c){var d=+c.getAttribute("data-target")-Date.now();'
            . 'if(d<=0&&c.getAttribute("data-autohide")==="1"){var i=c.closest(".nc-topbar-item");if(i){i.remove();b.removeAttribute("data-ssr");fix()}return}'
            . 'd=Math.max(0,Math.floor(d/1000));var h=c.querySelector(".nc-cd-hours"),m=c.querySelector(".nc-cd-minutes"),s=c.querySelector(".nc-cd-seconds");'
            . 'if(h)h.textContent=p(Math.floor(d%86400/3600));if(m)m.textContent=p(Math.floor(d%3600/60));if(s)s.textContent=p(d%60)});'
            . 'if(!b.querySelector(".nc-topbar-item")){b.style.display="none";b.removeAttribute("data-ssr");document.body.classList.remove("nc-topbar-active");clearInterval(x)}}'
            . 'var x=b.querySelector(".nc-countdown")?setInterval(t,1000):0;t()})();</script>';
        return $html;
    }

    private function countdown_markup( $cd, $options ) {
        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );
        if ( $cd['type'] === 'daily' && $cd['time'] ) {
            $target = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $cd['time'], $tz );
            if ( $target && $target <= $now ) $target = $target->modify( '+1 day' );
        } elseif ( $cd['type'] === 'date' && $cd['date'] ) {
            // A malformed date (import, manual meta edit) must not take the page down.
            try {
                $target = new DateTimeImmutable( $cd['date'], $tz );
            } catch ( Exception $e ) {
                return '';
            }
        } else {
            return '';
        }
        if ( ! $target ) return '';

        $ms   = $target->getTimestamp() * 1000;
        $left = max( 0, $target->getTimestamp() - $now->getTimestamp() );
        $units = ( $options['nc_countdown_show_units'] ?: '1' ) === '1';
        $seg = function ( $cls, $val, $unit ) use ( $units ) {
            return '<div class="nc-countdown-segment"><span class="nc-countdown-value ' . $cls . '">' . $val . '</span>' . ( $units ? '<span class="nc-countdown-unit">' . $unit . '</span>' : '' ) . '</div>';
        };
        $days = intdiv( $left, 86400 );
        $html  = '<div class="nc-countdown' . ( $left <= 0 ? ' expired' : '' ) . '" data-target="' . $ms . '" data-type="' . esc_attr( $cd['type'] ) . '" data-time="' . esc_attr( $cd['time'] ?? '' ) . '" data-autohide="' . ( ! empty( $cd['autohide'] ) ? '1' : '' ) . '">';
        $html .= '<div class="nc-countdown-timer">';
        if ( $days > 0 ) {
            $html .= $seg( 'nc-cd-days', $days, 'dni' ) . '<span class="nc-countdown-separator">:</span>';
        }
        $html .= $seg( 'nc-cd-hours', sprintf( '%02d', intdiv( $left % 86400, 3600 ) ), 'godz' ) . '<span class="nc-countdown-separator">:</span>';
        $html .= $seg( 'nc-cd-minutes', sprintf( '%02d', intdiv( $left % 3600, 60 ) ), 'min' ) . '<span class="nc-countdown-separator">:</span>';
        $html .= $seg( 'nc-cd-seconds', sprintf( '%02d', $left % 60 ), 'sek' );
        $html .= '</div></div>';
        return $html;
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
     * ncData plus a ~0.5 KB loader. The loader injects style.css and main.js on the first
     * mousemove / pointer / touch / key / wheel / scroll, and records the first input that
     * ends LCP (window.ncFirstInput) so main.js knows it already happened. A click on the
     * bell before main.js is ready is replayed once it is.
     */
    public function render_front_loader() {
        if ( ! $this->front_data || $this->should_skip_frontend() ) return;
        $json = wp_json_encode( $this->front_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
        ?>
<script id="nc-data" data-no-optimize="1" data-no-defer="1" data-cfasync="false">var ncData=<?php echo $json; ?>;</script>
<script id="nc-loader" data-no-optimize="1" data-no-defer="1" data-cfasync="false">(function(w,d){var L=['pointermove','mousemove','pointerdown','touchstart','keydown','wheel','scroll'],I=['pointerdown','touchstart','keydown','wheel'],o={capture:true,passive:true},done=false;
function load(){if(done)return;done=true;L.forEach(function(t){w.removeEventListener(t,load,o)});var a=w.ncData.assets,l=d.createElement('link');l.rel='stylesheet';l.href=a.css;l.onload=function(){d.documentElement.classList.add('nc-css')};d.head.appendChild(l);var s=d.createElement('script');s.src=a.js;s.async=true;d.body.appendChild(s)}
function input(){if(!w.ncFirstInput)w.ncFirstInput=Date.now();I.forEach(function(t){w.removeEventListener(t,input,o)})}
I.forEach(function(t){w.addEventListener(t,input,o)});L.forEach(function(t){w.addEventListener(t,load,o)});
d.addEventListener('click',function(e){if(!w.ncMainReady&&e.target.closest&&e.target.closest('#nc-bell-container')){w.ncPendingBell=true;e.preventDefault()}},true)})(window,document);</script>
        <?php
    }

    /**
     * Shared limit of a rotation group: at most one of its notifications is shown per
     * page, they take turns, and the group as a whole respects this cap.
     */
    public static function rotation_limits() {
        return apply_filters( 'nc_rotation_limits', [ 'minHours' => 24, 'maxShows' => 3, 'windowDays' => 7 ] );
    }

    private function customer_audience_used() {
        $used = get_option( 'nc_customer_audience_used', null );
        if ( $used === null ) {
            $used = $this->scan_customer_audience();
            update_option( 'nc_customer_audience_used', $used, false );
        }
        return $used === '1';
    }

    private function scan_customer_audience() {
        $q = new WP_Query( [
            'post_type' => 'nc_notification', 'post_status' => 'publish', 'posts_per_page' => 1,
            'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [ [ 'key' => 'nc_audience', 'value' => 'customers' ] ],
        ] );
        return $q->posts ? '1' : '0';
    }

    /**
     * Customer marker for the "customers only" audience: cookie nc_customer=1 for a year,
     * set on the order-received page and for logged-in customers, and only with consent
     * (Marketing: the marker exists to target promotions) in the GetTerms banner; removed
     * when that consent is withdrawn.
     * Without a known consent record nothing is set. The removal-only variant is static,
     * so it is safe on publicly cached pages.
     */
    public function render_customer_flag_script() {
        if ( $this->should_skip_frontend() || ! $this->customer_audience_used() ) return;
        $mark = ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
            || NC_Logic::is_customer( get_current_user_id() );
        ?>
<script id="nc-customer-flag">(function(mark){var N='nc_customer',L='wdf_klient';
function consent(){try{var c=JSON.parse(localStorage.getItem('getterms_cookie_consent')||'null');if(!c||!c.cookie_preferences)return null;var p=c.cookie_preferences;return !!p.Marketing}catch(e){return null}}
function has(n){return document.cookie.indexOf(n+'=1')!==-1}
function del(n){document.cookie=n+'=; max-age=0; path=/; SameSite=Lax; Secure'}
function run(){var ok=consent();if(ok===false){if(has(N))del(N);if(has(L))del(L);return true}
if(ok===true&&mark){if(!has(N))document.cookie=N+'=1; max-age=31536000; path=/; SameSite=Lax; Secure';return true}return false}
if(run()||!mark)return;var n=0,t=setInterval(function(){if(run()||++n>300)clearInterval(t)},2000)})(<?php echo $mark ? 'true' : 'false'; ?>);</script>
        <?php
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

        update_option( 'nc_customer_audience_used', $this->scan_customer_audience(), false );

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

// Deactivated plugin: its cron jobs must not keep firing without handlers.
register_deactivation_hook( __FILE__, function () {
	foreach ( [ 'nc_hourly_cache_purge', 'nc_cleanup_expired_transients', 'nc_cleanup_old_events', 'nc_abandoned_cart_check', 'nc_user_notifications_cleanup' ] as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
} );
add_action( 'admin_init', [ 'Notification_Centre', 'schedule_events' ] );

// Hourly: bump the cache version so time-based notifications expire. Bumping
// nc_cache_version is enough — the front fetches data via AJAX from the REST
// endpoint's own 5-min cache, so a site-wide litespeed_purge_all here is
// unnecessary. Full-page purge stays only where real content changes
// (invalidate_notification_caches on save).
add_action( 'nc_hourly_cache_purge', function() {
	update_option( 'nc_cache_version', time(), false );
} );
