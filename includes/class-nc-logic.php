<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NC_Logic {

	/**
	 * Main function to get valid notifications for current context
	 */
	public static function get_valid_notifications( $context = [] ) {
		// Context: ['post_id' => 123, 'url' => '.../checkout/', 'user_id' => 1]
		
		// Debug logging
		if (get_option('nc_debug_mode') === '1') {
			error_log('[NC Debug] get_valid_notifications called with context: ' . print_r($context, true));
		}
		
		$args = [
			'post_type' => 'nc_notification',
			'post_status' => 'publish',
			'posts_per_page' => 50, // Limit for performance
			'orderby' => 'date',
			'order' => 'DESC',
			'no_found_rows' => true,          // PERF: skip SQL_CALC_FOUND_ROWS (no pagination needed)
			'update_post_term_cache' => false, // PERF: taxonomy terms not used in this loop
		];

		$query = new WP_Query( $args );
		
		if (get_option('nc_debug_mode') === '1') {
			error_log('[NC Debug] WP_Query found ' . $query->found_posts . ' notifications');
		}
		
		$valid = [];

		foreach ( $query->posts as $post ) {
			$meta = get_post_meta( $post->ID );
			if ( self::is_valid( $post, $context, $meta ) ) {
				$item = self::prepare_for_api( $post, $meta );
				// PERF: compute the sort timestamp once here, not on every usort comparison.
				$item['_ts'] = strtotime( $item['date'] );
				$valid[] = $item;
			}
		}

        // Sort by Pinned (DESC) then Date (DESC)
        usort($valid, function($a, $b) {
            $pinnedA = $a['settings']['pinned'] ? 1 : 0;
            $pinnedB = $b['settings']['pinned'] ? 1 : 0;

            if ($pinnedA !== $pinnedB) {
                return $pinnedB - $pinnedA; // 1 (pinned) comes before 0
            }

            // Fallback to date (newest first) — uses precomputed timestamp
            return $b['_ts'] - $a['_ts'];
        });

		// Drop the internal sort key so it never leaks into the API payload.
		foreach ( $valid as &$item ) {
			unset( $item['_ts'] );
		}
		unset( $item );

		wp_reset_postdata();
		return $valid;
	}

	/**
	 * Check if preview mode is active (admin + ?nc_preview=1)
	 */
	private static function is_preview_mode() {
		static $preview = null;
		if ( $preview === null ) {
			$preview = ! empty( $_GET['nc_preview'] ) && current_user_can( 'manage_options' );
		}
		return $preview;
	}

	private static function is_valid( $post, $context, $meta ) {
		$id = $post->ID;
		$get = function($k) use ($meta) { return isset($meta[$k][0]) ? maybe_unserialize($meta[$k][0]) : ''; };

		// Preview mode: skip time, day, and countdown checks (admin only)
		if ( self::is_preview_mode() ) {
			// Still check page rules so you see what shows on this specific page
			if ( ! self::check_page_rules( $id, $context, $meta ) ) return false;
			return true;
		}

		// 1. Time Check (using non-deprecated method)
		$now = strtotime( current_time( 'mysql' ) );
		$from = $get('nc_active_from');
		$to = $get('nc_active_to');

		if ( $from && strtotime( $from ) > $now ) return false;
		if ( $to && strtotime( $to ) < $now ) return false;

        // 1.5 Day Exclusion Check
        $excluded_days = $get('nc_excluded_days');
        if ( is_array( $excluded_days ) && ! empty( $excluded_days ) ) {
            // date('N') returns 1 (Mon) to 7 (Sun)
            $current_day = date( 'N', $now ); 
            if ( in_array( (string)$current_day, $excluded_days ) ) {
                return false;
            }
        }

        // 1.8 Countdown Visibility Check
        if ( $get('nc_countdown_enabled') ) {
            $autohide = $get('nc_countdown_autohide');
            $type = $get('nc_countdown_type') ?: 'date';
            
            // Auto-hide when expired
            if ( $autohide ) {
                if ( $type === 'date' ) {
                    $target = $get('nc_countdown_date');
                    if ( $target && strtotime( $target ) < $now ) return false;
                } elseif ( $type === 'daily' ) {
                    $target_time = $get('nc_countdown_time') ?: '10:00';
                    $current_hm = date( 'H:i', $now );
                    if ( $current_hm > $target_time ) return false;
                }
            }

            // Check Start Time (Daily only)
            if ( $type === 'daily' ) {
                 $start_time = $get('nc_countdown_start_time');
                 if ( $start_time ) {
                    $current_hm = date( 'H:i', $now );
                    if ( $current_hm < $start_time ) return false;
                 }
            }
        }

		// 2. Audience Check
		$audience = $get('nc_audience') ?: 'all';
		$user_id = $context['user_id'] ?? 0;

		if ( $audience === 'logged_in' && $user_id === 0 ) return false;
		if ( $audience === 'guests' && $user_id !== 0 ) return false;
		if ( $audience === 'administrator' ) {
			$user = wp_get_current_user();
			if ( ! in_array( 'administrator', (array) $user->roles, true ) ) return false;
		}

		// 2.5 Device Check
		$device_target = $get('nc_device_target') ?: 'all';
		if ( $device_target === 'mobile' && ! wp_is_mobile() ) return false;
		if ( $device_target === 'desktop' && wp_is_mobile() ) return false;

        // 3. Page Rules Check
        if ( ! self::check_page_rules( $id, $context, $meta ) ) return false;

		return true;
	}

    	private static function check_page_rules( $id, $context, $meta ) {
		$rules = isset($meta['nc_rules_data'][0]) ? maybe_unserialize($meta['nc_rules_data'][0]) : '';
        
        // If no rules defined, show everywhere
		if ( empty( $rules ) || ! is_array( $rules ) ) return true;

        $has_show_rules = false;
        $has_hide_rules = false;
        $matched_show = false;
        $matched_hide = false;

        // First pass: check what types of rules we have
        foreach($rules as $rule) {
            $mode = $rule['mode'] ?? 'show';
            if($mode === 'show') $has_show_rules = true;
            if($mode === 'hide') $has_hide_rules = true;
        }
        
        // Second pass: check matches
        foreach ( $rules as $rule ) {
            $mode = $rule['mode'] ?? 'show';
            $type = $rule['type'] ?? 'all';
            $value = $rule['value'] ?? '';
            
            $is_match = false;
            
            // Check if rule matches current context
            if ( $type === 'all' ) {
                $is_match = true;
            } elseif ( $type === 'is_front_page' ) {
                $front_page_id = (int) get_option( 'page_on_front' );
                if ( ! empty( $context['post_id'] ) && $front_page_id ) {
                    $is_match = ( (int) $context['post_id'] === $front_page_id );
                } elseif ( ! empty( $context['url'] ) ) {
                    $is_match = ( untrailingslashit( $context['url'] ) === untrailingslashit( home_url() ) );
                } else {
                    $is_match = is_front_page();
                }
            } elseif ( $type === 'url' && ! empty( $context['url'] ) ) {
                $is_match = ( mb_strpos( $context['url'], $value ) !== false );
            } elseif ( $type === 'id' && ! empty( $context['post_id'] ) ) {
                $is_match = ( (string)$context['post_id'] === (string)$value );
            }
            
            if ( $is_match ) {
                if ( $mode === 'hide' ) $matched_hide = true;
                if ( $mode === 'show' ) $matched_show = true;
            }
        }
        
        // Hide rules always take priority
        if ( $matched_hide ) return false;
        
        // If has show rules, must match at least one
        if ( $has_show_rules ) return $matched_show;
        
        // No rules matched = show by default
        return true;
    }

	/**
	 * Rewrite a Gravity Forms <form> action to the site front-end URL.
	 *
	 * Used only while rendering notification bodies inside the REST context, where GF
	 * would otherwise capture the REST endpoint URL as the action (GET-only → 404 on submit).
	 */
	public static function retarget_gform_action( $form_tag ) {
		return preg_replace(
			'/(\saction=)([\'"]).*?\2/',
			'$1$2' . esc_url( home_url( '/' ) ) . '$2',
			$form_tag,
			1
		);
	}

	private static function prepare_for_api( $post, $meta = null ) {
       if ( $meta === null ) {
           $meta = get_post_meta( $post->ID );
       }
       // Safely get meta helper
       $get = function($k) use ($meta) { return $meta[$k][0] ?? ''; };
       
       // Debug logging
       if (get_option('nc_debug_mode') === '1') {
           error_log('[NC Debug] Post ID: ' . $post->ID);
           error_log('[NC Debug] nc_show_as_floating: ' . $get('nc_show_as_floating'));
           error_log('[NC Debug] nc_floating_position: ' . $get('nc_floating_position'));
           error_log('[NC Debug] nc_show_in_sidebar: ' . $get('nc_show_in_sidebar'));
       }
       
	// Gravity Forms defers each form's init <script> (the hidden-iframe AJAX handler
	// plus the gform_post_render registrar) to wp_footer, which never fires in the
	// REST context that renders this body. Force it inline so those scripts travel
	// inside the returned HTML; main.js re-executes them after the innerHTML insertion
	// (window.ncInitGravityForms). Scoped to this request only.
	// Content mode (v1.8): 'raw' renders author-supplied HTML/CSS instead of the
	// structured fields. Sanitization already happened on save (capability-gated),
	// so the body here is NOT re-run through wp_kses — doing so would strip a
	// trusted admin's <style>/<script>.
	$content_mode  = $get('nc_content_mode') ?: 'fields';
	$nc_raw_html   = $get('nc_raw_html');
	$is_raw        = ( $content_mode === 'raw' && trim( (string) $nc_raw_html ) !== '' );

	$nc_description = $get('nc_description');
	// GF defers its init <script> to wp_footer; detect the shortcode in whichever
	// source will actually be rendered (raw HTML or the structured description).
	$gf_source = $is_raw ? $nc_raw_html : $nc_description;
	if ( class_exists( 'GFForms' ) && stripos( $gf_source, '[gravityform' ) !== false ) {
		add_filter( 'gform_init_scripts_footer', '__return_false' );

		// GF bakes the CURRENT request URI into the form's action. This body is rendered
		// inside the REST request (/wp-json/nc/v1/notifications, GET-only), so the action
		// would point there and the submit would 404. Rewrite it to a normal front-end URL:
		// the iframe/submit then lands on a request where GF's maybe_process_form() runs and
		// returns the next page / confirmation. GF self-corrects the action on later pages
		// (those round-trips already happen on the front-end URL).
		if ( ! has_filter( 'gform_form_tag', 'NC_Logic::retarget_gform_action' ) ) {
			add_filter( 'gform_form_tag', 'NC_Logic::retarget_gform_action', 10, 1 );
		}
	}

	// Body: raw mode renders the author's HTML verbatim (sanitized at save time
	// depending on unfiltered_html); fields mode keeps the kses→shortcode order.
	if ( $is_raw ) {
		$body = do_shortcode( $nc_raw_html );
	} else {
		// SEC: sanitize the human-authored description with wp_kses_post BEFORE running
		// shortcodes. kses strips injected <script>/onerror/etc., while shortcode tags
		// (e.g. [gravityform ...], [fluentform ...]) are plain text that survives kses,
		// and do_shortcode() then appends the trusted form HTML+scripts AFTER kses.
		// Order (kses → shortcode) is critical: swapping it would strip the form output.
		$body = do_shortcode( wp_kses_post( $nc_description ) );
	}

	return [
		'id' => $post->ID,
		'audience' => $get('nc_audience') ?: 'all',
		// Raw mode owns the whole card — suppress the structured title so the front
		// never prepends it above the custom HTML.
		'title' => $is_raw ? '' : do_shortcode( $get('nc_title') ?: $post->post_title ),
		'title_css' => $get('nc_title_custom_css_enabled') === '1' ? $get('nc_title_custom_css') : '',
		'body' => $body,
		// content_mode / raw_scripts drive the front-end renderer (main.js):
		// raw → render body only, and re-execute <script> ONLY when raw_scripts=true
		// (author had unfiltered_html; otherwise kses already removed scripts on save).
		'content_mode' => $is_raw ? 'raw' : 'fields',
		'raw_scripts'  => ( $get('nc_raw_trusted') === '1' ),
           // Raw mode: force structural elements empty so the front doesn't append a
           // CTA button or image around the custom HTML.
           'cta_label' => $is_raw ? '' : $get('nc_cta_label'),
           'cta_url' => $is_raw ? '' : $get('nc_cta_url'),
           'cta_target' => $get('nc_cta_target') ?: '_self',
           'icon' => $is_raw ? '' : $get('nc_icon'),
           'image_url' => $is_raw ? '' : ( ( $img_id = (int) $get('nc_image_id') ) ? ( wp_get_attachment_image_url( $img_id, 'large' ) ?: '' ) : '' ),
           'type' => 'info',
           'date' => get_the_date( 'Y-m-d H:i', $post ),
           'settings' => [
               'hide_title' => $is_raw ? true : ( $get('nc_hide_title') === '1' ),
               'dismissible' => ($get('nc_pinned') === '1') ? false : ($get('nc_dismissible') === '1'),
               'pinned' => $get('nc_pinned') === '1',
               
               // Legacy Toast (keep for backward compatibility)
               'toast' => $get('nc_show_as_toast') === '1' || $get('nc_show_as_floating') === '1',
               
               // NEW Floating Settings
               'show_as_floating' => $get('nc_show_as_floating'), // '1' or ''
               'floating_position' => $get('nc_floating_position') ?: 'bottom_right',
               'floating_width' => (int)$get('nc_floating_width') ?: 0,
               'max_width_desktop' => (int)$get('nc_max_width_desktop') ?: 0,
               'max_width_mobile' => (int)$get('nc_max_width_mobile') ?: 0,
               'floating_delay' => (int)($get('nc_floating_delay') !== '' ? $get('nc_floating_delay') : 2), // Seconds
               'floating_duration' => (int)$get('nc_floating_duration'), // Seconds (0 = permanent)
               
               // Legacy Toast Fields (mapped for old JS if needed)
               'toast_width' => (int)$get('nc_toast_width') ?: (int)$get('nc_floating_width'),
               'toast_delay' => (int)($get('nc_toast_delay') ?: ($get('nc_floating_delay') ?: 2)) * 1000,
               'toast_duration' => (int)($get('nc_toast_duration') ?: $get('nc_floating_duration')) * 1000,
               'only_toast' => $get('nc_only_toast') === '1',
               
               // Sidebar
               'show_in_sidebar' => $get('nc_show_in_sidebar'),
               'sidebar_pinned' => $get('nc_sidebar_pinned') === '1',
               'sidebar_permanent' => $get('nc_sidebar_permanent') === '1',
               
               'topbar' => $get('nc_show_as_topbar') === '1',
               'topbar_position' => $get('nc_topbar_position') ?: 'above',
               'topbar_style' => $get('nc_topbar_style') ?: 'full',
               'topbar_permanent' => $get('nc_topbar_permanent') === '1',
               
               'repeat_val' => (int)($get('nc_repeat_value')),
               'repeat_unit' => $get('nc_repeat_unit') ?: 'days',

               'cap_enabled' => $get('nc_cap_enabled') === '1',
               'cap_min_hours' => (int)($get('nc_cap_min_hours') ?: 24),
               'cap_max_shows' => (int)($get('nc_cap_max_shows')),
               'cap_window_days' => (int)($get('nc_cap_window_days') ?: 30),

               'countdown' => [
                   'enabled' => $get('nc_countdown_enabled') === '1',
                   'type' => $get('nc_countdown_type') ?: 'date',
                   'date' => $get('nc_countdown_date'),
                   'time' => $get('nc_countdown_time') ?: '10:00',
                   'label' => $get('nc_countdown_label'),
                   'autohide' => $get('nc_countdown_autohide') === '1',
               ],
               'colors' => [
                   'bg' => $get('nc_bg_color') ?: '#ffffff',
                   'text' => $get('nc_text_color') ?: '#333333',
                   'accent' => $get('nc_accent_color') ?: '',
                   'btn_bg' => $get('nc_btn_bg_color') ?: '#3498db',
                   'btn_text' => $get('nc_btn_text_color') ?: '#ffffff',
               ],
               // Behavioral Triggers
               'triggers' => [
                   'delay' => $get('nc_trigger_delay') === '1',
                   'delay_seconds' => (int)($get('nc_floating_delay') ?: 0),
                   'exit_intent' => $get('nc_trigger_exit_intent') === '1',
                   'scroll_depth' => $get('nc_trigger_scroll_depth') === '1',
                   'scroll_percent' => (int)($get('nc_trigger_scroll_percent') ?: 50),
                   'time_on_page' => $get('nc_trigger_time_on_page') === '1',
                   'time_seconds' => (int)($get('nc_trigger_time_seconds') ?: 30),
                   'inactivity' => $get('nc_trigger_inactivity') === '1',
                   'idle_seconds' => (int)($get('nc_trigger_idle_seconds') ?: 15),
                   'click' => $get('nc_trigger_click') === '1',
                   'click_selector' => $get('nc_trigger_click_selector'),
               ]
           ]
	];
	}
}
