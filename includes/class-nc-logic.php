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
			'order' => 'DESC'
		];

		$query = new WP_Query( $args );
		
		if (get_option('nc_debug_mode') === '1') {
			error_log('[NC Debug] WP_Query found ' . $query->found_posts . ' notifications');
		}
		
		$valid = [];

		foreach ( $query->posts as $post ) {
			if ( self::is_valid( $post, $context ) ) {
				$valid[] = self::prepare_for_api( $post );
			}
		}

        // Sort by Pinned (DESC) then Date (DESC)
        usort($valid, function($a, $b) {
            $pinnedA = $a['settings']['pinned'] ? 1 : 0;
            $pinnedB = $b['settings']['pinned'] ? 1 : 0;
            
            if ($pinnedA !== $pinnedB) {
                return $pinnedB - $pinnedA; // 1 (pinned) comes before 0
            }
            
            // Fallback to date (newest first)
            return strtotime($b['date']) - strtotime($a['date']);
        });

		wp_reset_postdata();
		return $valid;
	}

	private static function is_valid( $post, $context ) {
		$id = $post->ID;

		// 1. Time Check (using non-deprecated method)
		$now = strtotime( current_time( 'mysql' ) );
		$from = get_post_meta( $id, 'nc_active_from', true );
		$to = get_post_meta( $id, 'nc_active_to', true );

		if ( $from && strtotime( $from ) > $now ) return false;
		if ( $to && strtotime( $to ) < $now ) return false;

        // 1.5 Day Exclusion Check
        $excluded_days = get_post_meta( $id, 'nc_excluded_days', true );
        if ( is_array( $excluded_days ) && ! empty( $excluded_days ) ) {
            // date('N') returns 1 (Mon) to 7 (Sun)
            $current_day = date( 'N', $now ); 
            if ( in_array( (string)$current_day, $excluded_days ) ) {
                return false;
            }
        }

        // 1.8 Countdown Visibility Check
        if ( get_post_meta( $id, 'nc_countdown_enabled', true ) ) {
            $autohide = get_post_meta( $id, 'nc_countdown_autohide', true );
            $type = get_post_meta( $id, 'nc_countdown_type', true ) ?: 'date';
            
            // Auto-hide when expired
            if ( $autohide ) {
                if ( $type === 'date' ) {
                    $target = get_post_meta( $id, 'nc_countdown_date', true );
                    if ( $target && strtotime( $target ) < $now ) return false;
                } elseif ( $type === 'daily' ) {
                    $target_time = get_post_meta( $id, 'nc_countdown_time', true ) ?: '10:00';
                    $current_hm = date( 'H:i', $now );
                    if ( $current_hm > $target_time ) return false;
                }
            }

            // Check Start Time (Daily only)
            if ( $type === 'daily' ) {
                 $start_time = get_post_meta( $id, 'nc_countdown_start_time', true );
                 if ( $start_time ) {
                    $current_hm = date( 'H:i', $now );
                    if ( $current_hm < $start_time ) return false;
                 }
            }
        }

		// 2. Audience Check
		$audience = get_post_meta( $id, 'nc_audience', true ) ?: 'all';
		$user_id = $context['user_id'] ?? 0;

		if ( $audience === 'logged_in' && $user_id === 0 ) return false;
		if ( $audience === 'guests' && $user_id !== 0 ) return false;
        // Roles check logic placeholder
        
        // 3. Page Rules Check
        if ( ! self::check_page_rules( $id, $context ) ) return false;

		return true;
	}

    	private static function check_page_rules( $id, $context ) {
		$rules = get_post_meta( $id, 'nc_rules_data', true );
        
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
                $is_match = is_front_page();
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

	private static function prepare_for_api( $post ) {
       $meta = get_post_meta( $post->ID );
       // Safely get meta helper
       $get = function($k) use ($meta) { return $meta[$k][0] ?? ''; };
       
       // Debug logging
       if (get_option('nc_debug_mode') === '1') {
           error_log('[NC Debug] Post ID: ' . $post->ID);
           error_log('[NC Debug] nc_show_as_floating: ' . $get('nc_show_as_floating'));
           error_log('[NC Debug] nc_floating_position: ' . $get('nc_floating_position'));
           error_log('[NC Debug] nc_show_in_sidebar: ' . $get('nc_show_in_sidebar'));
       }
       
	return [
		'id' => $post->ID,
		'title' => do_shortcode( $post->post_title ),
		'body' => apply_filters( 'the_content', $post->post_content ),
           'cta_label' => $get('nc_cta_label'),
           'cta_url' => $get('nc_cta_url'),
           'icon' => $get('nc_icon'),
           'type' => 'info',
           'date' => get_the_date( 'Y-m-d H:i', $post ),
           'settings' => [
               'dismissible' => ($get('nc_pinned') === '1') ? false : ($get('nc_dismissible') === '1'),
               'pinned' => $get('nc_pinned') === '1',
               
               // Legacy Toast (keep for backward compatibility)
               'toast' => $get('nc_show_as_toast') === '1' || $get('nc_show_as_floating') === '1',
               
               // NEW Floating Settings
               'show_as_floating' => $get('nc_show_as_floating'), // '1' or ''
               'floating_position' => $get('nc_floating_position') ?: 'bottom_right',
               'floating_width' => (int)$get('nc_floating_width') ?: 0,
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
