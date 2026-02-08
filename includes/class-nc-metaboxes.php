<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NC_Metaboxes {

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_custom_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_custom_meta' ] );
	}

	public function add_custom_meta_boxes() {
		add_meta_box(
			'nc_settings_box',
			'Ustawienia Notyfikacji',
			[ $this, 'render_settings_box' ],
			'nc_notification',
			'normal',
			'high'
		);
	}

    public function render_settings_box( $post ) {
		// Data
        $cta_label = get_post_meta( $post->ID, 'nc_cta_label', true );
        $cta_url = get_post_meta( $post->ID, 'nc_cta_url', true );
        $icon = get_post_meta( $post->ID, 'nc_icon', true );
        
        // Behavioral
        $active_from = get_post_meta( $post->ID, 'nc_active_from', true );
        $active_to = get_post_meta( $post->ID, 'nc_active_to', true );
        $dismissible = get_post_meta( $post->ID, 'nc_dismissible', true ); // '1' or ''
        
        // Old Legacy Fields (migration handled in JS if needed, but here we read new fields)
        $show_in_sidebar = get_post_meta( $post->ID, 'nc_show_in_sidebar', true );
        // Default to true ONLY for truly new posts (auto-draft without any save history)
        // Once a post is saved, respect the stored value even if empty.
        if($show_in_sidebar === '' && get_post_status($post->ID) === 'auto-draft') {
             $show_in_sidebar = '1';
        }
        
        $sidebar_pinned = get_post_meta( $post->ID, 'nc_sidebar_pinned', true );
        $sidebar_permanent = get_post_meta( $post->ID, 'nc_sidebar_permanent', true );
        
        $show_as_floating = get_post_meta( $post->ID, 'nc_show_as_floating', true );
        $floating_position = get_post_meta( $post->ID, 'nc_floating_position', true ) ?: 'bottom_right';
        $floating_width = get_post_meta( $post->ID, 'nc_floating_width', true );
        $floating_delay = get_post_meta( $post->ID, 'nc_floating_delay', true ) ?: '2';
        $floating_duration = get_post_meta( $post->ID, 'nc_floating_duration', true ) ?: '0';
        
        $show_as_topbar = get_post_meta( $post->ID, 'nc_show_as_topbar', true );
        $topbar_position = get_post_meta( $post->ID, 'nc_topbar_position', true ) ?: 'above';
        $topbar_permanent = get_post_meta( $post->ID, 'nc_topbar_permanent', true );
        
        // Audience
        $audience = get_post_meta( $post->ID, 'nc_audience', true ) ?: 'all';
        $rules = get_post_meta( $post->ID, 'nc_rules', true ); // JSON string
        
        
        
        wp_nonce_field( 'nc_save_meta', 'nc_meta_nonce' );
		?>
		<style>
            .nc-row { margin-bottom: 20px; border-bottom:1px solid #f0f0f1; padding-bottom:20px; }
            .nc-label { display:inline-block; width: 180px; font-weight:600; vertical-align:top; }
            .nc-input { width: 100%; max-width: 400px; }
            .nc-sub-option { margin-left: 25px; margin-bottom: 5px; display: block; color: #50575e; }
        </style>
        
        <!-- SECTION 1: TYPE -->
        <div class="nc-row">
            <h3>1. Typ Powiadomienia</h3>
            <p class="description">Wybierz gdzie i jak powiadomienie ma się wyświetlać. Możesz wybrać wiele opcji.</p>
            
            <!-- SIDEBAR -->
            <div style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:10px; border-radius:4px;">
                <label style="font-size:14px; font-weight:600;">
                    <input type="checkbox" name="nc_show_in_sidebar" id="nc_show_in_sidebar" value="1" <?php checked($show_in_sidebar, '1'); ?>>
                    Notification Center (Szuflada z boku)
                </label>
                <div id="nc-sidebar-options" style="margin-top:10px; padding-left:25px; <?php echo $show_in_sidebar ? '' : 'display:none;'; ?>">
                     <label class="nc-sub-option">
                        <input type="checkbox" name="nc_sidebar_pinned" value="1" <?php checked($sidebar_pinned, '1'); ?>>
                        Przypnij na górze listy
                    </label>
                    <label class="nc-sub-option">
                        <input type="checkbox" name="nc_sidebar_permanent" value="1" <?php checked($sidebar_permanent, '1'); ?>>
                        Bez możliwości zamknięcia (Permanentne)
                    </label>
                </div>
            </div>

            <!-- FLOATING (TOAST/POPUP) -->
            <div style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:10px; border-radius:4px;">
                <label style="font-size:14px; font-weight:600;">
                    <input type="checkbox" name="nc_show_as_floating" id="nc_show_as_floating" value="1" <?php checked($show_as_floating, '1'); ?>>
                    Pływające powiadomienie (Toast / Popup)
                </label>
                <div id="nc-floating-options" style="margin-top:10px; padding-left:25px; <?php echo $show_as_floating ? '' : 'display:none;'; ?>">
                    <p>
                        <label class="nc-label">Pozycja</label>
                        <select name="nc_floating_position">
                            <option value="bottom_right" <?php selected($floating_position, 'bottom_right'); ?>>Prawy Dolny</option>
                            <option value="top_right" <?php selected($floating_position, 'top_right'); ?>>Prawy Górny</option>
                            <option value="bottom_left" <?php selected($floating_position, 'bottom_left'); ?>>Lewy Dolny</option>
                            <option value="top_left" <?php selected($floating_position, 'top_left'); ?>>Lewy Górny</option>
                            <option value="center" <?php selected($floating_position, 'center'); ?>>Środek Ekranu (Popup)</option>
                        </select>
                    </p>
                    <p>
                        <label class="nc-label">Szerokość (px)</label>
                        <input type="number" name="nc_floating_width" value="<?php echo esc_attr($floating_width); ?>" placeholder="Domyślna">
                    </p>
                    <p>
                        <label class="nc-label">Czas trwania (s)</label>
                        <input type="number" name="nc_floating_duration" value="<?php echo esc_attr($floating_duration); ?>">
                        <span class="description">0 = nie znika automatycznie</span>
                    </p>
                    
                    <!-- Behavioral Triggers -->
                    <div style="margin-top:15px; padding-top:15px; border-top:1px dashed #ddd;">
                        <p style="font-weight:600; margin-bottom:10px;">Kiedy wyświetlić? (wybierz co najmniej jeden):</p>
                        
                        <?php
                        $trigger_delay = get_post_meta($post->ID, 'nc_trigger_delay', true);
                        // Default to delay if no triggers set (backward compatibility)
                        if ($trigger_delay === '' && get_post_status($post->ID) === 'auto-draft') {
                            $trigger_delay = '1';
                        }
                        $trigger_exit = get_post_meta($post->ID, 'nc_trigger_exit_intent', true);
                        $trigger_scroll = get_post_meta($post->ID, 'nc_trigger_scroll_depth', true);
                        $trigger_scroll_percent = get_post_meta($post->ID, 'nc_trigger_scroll_percent', true) ?: '50';
                        $trigger_time = get_post_meta($post->ID, 'nc_trigger_time_on_page', true);
                        $trigger_time_seconds = get_post_meta($post->ID, 'nc_trigger_time_seconds', true) ?: '30';
                        $trigger_idle = get_post_meta($post->ID, 'nc_trigger_inactivity', true);
                        $trigger_idle_seconds = get_post_meta($post->ID, 'nc_trigger_idle_seconds', true) ?: '15';
                        $trigger_click = get_post_meta($post->ID, 'nc_trigger_click', true);
                        $trigger_click_selector = get_post_meta($post->ID, 'nc_trigger_click_selector', true);
                        ?>
                        
                        <label class="nc-sub-option">
                            <input type="checkbox" name="nc_trigger_delay" value="1" <?php checked($trigger_delay, '1'); ?>>
                            <strong>Opóźnienie</strong> - po
                            <input type="number" name="nc_floating_delay" value="<?php echo esc_attr($floating_delay); ?>" style="width:60px" min="0"> sekundach
                        </label>
                        
                        <label class="nc-sub-option">
                            <input type="checkbox" name="nc_trigger_exit_intent" value="1" <?php checked($trigger_exit, '1'); ?>>
                            <strong>Exit Intent</strong> - gdy użytkownik chce opuścić stronę
                        </label>
                        
                        <label class="nc-sub-option">
                            <input type="checkbox" name="nc_trigger_scroll_depth" value="1" <?php checked($trigger_scroll, '1'); ?>>
                            <strong>Scroll Depth</strong> - po przewinięciu
                            <input type="number" name="nc_trigger_scroll_percent" value="<?php echo esc_attr($trigger_scroll_percent); ?>" style="width:60px" min="1" max="100">% strony
                        </label>
                        
                        <label class="nc-sub-option">
                            <input type="checkbox" name="nc_trigger_time_on_page" value="1" <?php checked($trigger_time, '1'); ?>>
                            <strong>Czas na stronie</strong> - po
                            <input type="number" name="nc_trigger_time_seconds" value="<?php echo esc_attr($trigger_time_seconds); ?>" style="width:60px" min="1"> sekundach
                        </label>
                        
                        <label class="nc-sub-option">
                            <input type="checkbox" name="nc_trigger_inactivity" value="1" <?php checked($trigger_idle, '1'); ?>>
                            <strong>Brak aktywności</strong> - po
                            <input type="number" name="nc_trigger_idle_seconds" value="<?php echo esc_attr($trigger_idle_seconds); ?>" style="width:60px" min="1"> sekundach bez ruchu
                        </label>
                        
                        <label class="nc-sub-option">
                            <input type="checkbox" name="nc_trigger_click" value="1" <?php checked($trigger_click, '1'); ?>>
                            <strong>Kliknięcie</strong> - po kliknięciu w element:
                            <input type="text" name="nc_trigger_click_selector" value="<?php echo esc_attr($trigger_click_selector); ?>" placeholder=".my-button, #special-link" style="width:200px">
                        </label>
                    </div>
                </div>
            </div>
            
             <!-- TOP BAR -->
            <div style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:10px; border-radius:4px;">
                <label style="font-size:14px; font-weight:600;">
                    <input type="checkbox" name="nc_show_as_topbar" id="nc_show_as_topbar" value="1" <?php checked($show_as_topbar, '1'); ?>>
                    Pasek na górze (Top Bar)
                </label>
                <div id="nc-topbar-options" style="margin-top:10px; padding-left:25px; <?php echo $show_as_topbar ? '' : 'display:none;'; ?>">
                     <p>
                        <label class="nc-label">Pozycja</label>
                        <select name="nc_topbar_position">
                            <option value="above" <?php selected($topbar_position, 'above'); ?>>Nad headerem</option>
                            <option value="below" <?php selected($topbar_position, 'below'); ?>>Pod headerem</option>
                        </select>
                    </p>
                    <p>
                        <label class="nc-label">Styl szerokości</label>
                        <?php $topbar_style = get_post_meta($post->ID, 'nc_topbar_style', true) ?: 'full'; ?>
                        <select name="nc_topbar_style">
                            <option value="full" <?php selected($topbar_style, 'full'); ?>>Pełna szerokość (Full Width)</option>
                            <option value="compact" <?php selected($topbar_style, 'compact'); ?>>Kompaktowy (Container Width)</option>
                        </select>
                        <span class="description" style="display:block; margin-left:184px; margin-top:5px; color:#888;">Kompaktowy: szerokość kontenera, zaokrąglony i z marginesem.</span>
                    </p>
                    <label class="nc-sub-option">
                        <input type="checkbox" name="nc_topbar_permanent" value="1" <?php checked($topbar_permanent, '1'); ?>>
                        Bez możliwości zamknięcia (Permanentne)
                    </label>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($){
                function toggleSection(id, checked) {
                    if(checked) $(id).slideDown();
                    else $(id).slideUp();
                }
                
                $('#nc_show_in_sidebar').change(function(){ toggleSection('#nc-sidebar-options', this.checked); });
                $('#nc_show_as_floating').change(function(){ toggleSection('#nc-floating-options', this.checked); });
                $('#nc_show_as_topbar').change(function(){ toggleSection('#nc-topbar-options', this.checked); });
            });
            </script>
        </div>


        <!-- SECTION 2: CONTENT -->
        <div class="nc-row">
            <h3>2. Przycisk (CTA)</h3>
            <p>
                <label class="nc-label">Etykieta przycisku</label>
                <input type="text" name="nc_cta_label" value="<?php echo esc_attr($cta_label); ?>" class="regular-text">
            </p>
            <p>
                <label class="nc-label">URL przycisku</label>
                <input type="text" name="nc_cta_url" value="<?php echo esc_attr($cta_url); ?>" class="regular-text">
                <p class="description" style="margin-left:184px; margin-top:5px; color:#888;">np. <code>https://google.com</code> lub <code>/kontakt</code></p>
            </p>
        </div>
        
        <!-- SECTION 3: RULES -->
        <div class="nc-row">
                <h3>3. Targetowanie (Reguły)</h3>
                <p>
                    <label class="nc-label">Kto widzi?</label>
                    <select name="nc_audience">
                        <option value="all" <?php selected($audience, 'all'); ?>>Wszyscy</option>
                        <option value="logged_in" <?php selected($audience, 'logged_in'); ?>>Tylko zalogowani</option>
                        <option value="guests" <?php selected($audience, 'guests'); ?>>Tylko goście</option>
                    </select>
                </p>
                
                <h4>Gdzie pokazywać?</h4>
                <div id="nc-rules-wrapper">
                    <?php 
                        $rules = get_post_meta( $post->ID, 'nc_rules_data', true );
                        if ( ! is_array( $rules ) ) $rules = []; 
                    ?>
                    
                    <div id="nc-rules-list">
                        <?php foreach($rules as $index => $rule): ?>
                            <div class="nc-rule-item" style="margin-bottom:10px; padding:10px; background:#f9f9f9; border:1px solid #ddd; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                <select name="nc_rules_data[<?php echo $index; ?>][mode]">
                                    <option value="show" <?php selected(isset($rule['mode']) ? $rule['mode'] : '', 'show'); ?>>Pokaż na</option>
                                    <option value="hide" <?php selected(isset($rule['mode']) ? $rule['mode'] : '', 'hide'); ?>>Nie pokazuj na</option>
                                </select>
                                
                                <select name="nc_rules_data[<?php echo $index; ?>][type]" class="nc-rule-type">
                                    <option value="all" <?php selected(isset($rule['type']) ? $rule['type'] : '', 'all'); ?>>Cała witryna</option>
                                    <option value="is_front_page" <?php selected(isset($rule['type']) ? $rule['type'] : '', 'is_front_page'); ?>>Strona Główna</option>
                                    <option value="id" <?php selected(isset($rule['type']) ? $rule['type'] : '', 'id'); ?>>Wybrana Strona/Wpis</option>
                                    <option value="url" <?php selected(isset($rule['type']) ? $rule['type'] : '', 'url'); ?>>URL zawiera</option>
                                </select>
                                
                                <div class="nc-rule-value-container" style="display:<?php echo (isset($rule['type']) && in_array($rule['type'], ['all', 'is_front_page'])) ? 'none' : 'block'; ?>;">
                                    <?php 
                                        $val = isset($rule['value']) ? $rule['value'] : ''; 
                                        $type = isset($rule['type']) ? $rule['type'] : '';
                                    ?>
                                    <!-- URL Input -->
                                    <input type="text" name="nc_rules_data[<?php echo $index; ?>][value_url]" value="<?php echo $type === 'url' ? esc_attr($val) : ''; ?>" placeholder="Wpisz fragment URL" class="nc-input-url" style="display:<?php echo $type === 'url' ? 'block' : 'none'; ?>;">
                                    
                                    <!-- Page Select -->
                                    <select name="nc_rules_data[<?php echo $index; ?>][value_id]" class="nc-input-id" style="display:<?php echo $type === 'id' ? 'block' : 'none'; ?>; max-width:200px;">
                                        <option value="">-- Wybierz stronę --</option>
                                        <?php 
                                            // Ideally cache this or fetch via AJAX for large sites. For MVP fetch all pages/posts.
                                            $pages = get_pages(); 
                                            foreach($pages as $p) {
                                                echo '<option value="'.$p->ID.'" '.selected($val, $p->ID, false).'>Strona: '.$p->post_title.'</option>';
                                            }
                                            // Posts?
                                            $posts = get_posts(['numberposts' => 50]);
                                            foreach($posts as $p) {
                                                echo '<option value="'.$p->ID.'" '.selected($val, $p->ID, false).'>Wpis: '.$p->post_title.'</option>';
                                            }
                                        ?>
                                    </select>
                                </div>
                                
                                <button type="button" class="button nc-remove-rule">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <button type="button" class="button" id="nc-add-rule">+ Dodaj regułę</button>
                    <!-- Template for new rule -->
                    <script type="text/template" id="nc-rule-template">
                        <div class="nc-rule-item" style="margin-bottom:10px; padding:10px; background:#f9f9f9; border:1px solid #ddd; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <select name="nc_rules_data[{index}][mode]">
                                <option value="show">Pokaż na</option>
                                <option value="hide">Nie pokazuj na</option>
                            </select>
                            
                            <select name="nc_rules_data[{index}][type]" class="nc-rule-type">
                                <option value="all">Cała witryna</option>
                                <option value="is_front_page">Strona Główna</option>
                                <option value="id">Wybrana Strona/Wpis</option>
                                <option value="url">URL zawiera</option>
                            </select>
                            
                            <div class="nc-rule-value-container" style="display:none;">
                                <input type="text" name="nc_rules_data[{index}][value_url]" value="" placeholder="Wpisz fragment URL" class="nc-input-url" style="display:none;">
                                <select name="nc_rules_data[{index}][value_id]" class="nc-input-id" style="display:none; max-width:200px;">
                                    <option value="">-- Wybierz stronę --</option>
                                    <?php 
                                        // Repeal the options here for JS template
                                        // Note: In a cleaner impl we'd clone the options from a hidden source or use data attribute
                                         $pages = get_pages(); 
                                            foreach($pages as $p) {
                                                echo '<option value="'.$p->ID.'">Strona: '.esc_js($p->post_title).'</option>';
                                            }
                                            $posts = get_posts(['numberposts' => 50]);
                                            foreach($posts as $p) {
                                                echo '<option value="'.$p->ID.'">Wpis: '.esc_js($p->post_title).'</option>';
                                            }
                                    ?>
                                </select>
                            </div>
                            
                            <button type="button" class="button nc-remove-rule">&times;</button>
                        </div>
                    </script>
                </div>
                
                <script>
                jQuery(document).ready(function($){
                    let ruleIndex = <?php echo isset($rules) ? count($rules) : 0; ?>;
                    
                    $('#nc-add-rule').click(function(){
                        const tpl = $('#nc-rule-template').html().replace(/{index}/g, ruleIndex);
                        $('#nc-rules-list').append(tpl);
                        ruleIndex++;
                    });
                    
                    $(document).on('click', '.nc-remove-rule', function(){
                        $(this).closest('.nc-rule-item').remove();
                    });
                    
                    $(document).on('change', '.nc-rule-type', function(){
                        const val = $(this).val();
                        const container = $(this).siblings('.nc-rule-value-container');
                        const inputUrl = container.find('.nc-input-url');
                        const inputId = container.find('.nc-input-id');
                        
                        inputUrl.hide();
                        inputId.hide();
                        
                        if(['all', 'is_front_page'].includes(val)) {
                            container.hide();
                        } else {
                            container.show();
                            if(val === 'url') inputUrl.show();
                            if(val === 'id') inputId.show();
                        }
                    });
                });
                </script>
            </div>


        <!-- SECTION 3: SCHEDULE & TIME -->
        <div class="nc-row">
            <h3>3. Harmonogram i Czas</h3>
            <div style="display:flex; flex-wrap:wrap; gap:20px;">
                <!-- Column 1: Active Period -->
                <div style="flex:1; min-width:300px;">
                    <h4 style="margin-top:0;">Globalne Ramy (Opcjonalne)</h4>
                    <p>
                        <label class="nc-label">Aktywna od</label>
                        <input type="datetime-local" name="nc_active_from" value="<?php echo esc_attr($active_from); ?>">
                    </p>
                    <p>
                        <label class="nc-label">Aktywna do</label>
                        <input type="datetime-local" name="nc_active_to" value="<?php echo esc_attr($active_to); ?>">
                    </p>
                    
                    <h4 style="margin-top:20px;">Tryb Czasowy / Odliczanie</h4>
                    <?php 
                        $countdown_enabled = get_post_meta( $post->ID, 'nc_countdown_enabled', true );
                        $countdown_type = get_post_meta( $post->ID, 'nc_countdown_type', true ) ?: 'date';
                        $countdown_date = get_post_meta( $post->ID, 'nc_countdown_date', true );
                        $countdown_time = get_post_meta( $post->ID, 'nc_countdown_time', true ) ?: '10:00';
                        $countdown_label = get_post_meta( $post->ID, 'nc_countdown_label', true );
                        $countdown_autohide = get_post_meta( $post->ID, 'nc_countdown_autohide', true );
                        $countdown_start_time = get_post_meta( $post->ID, 'nc_countdown_start_time', true ) ?: '';
                    ?>
                    <p>
                         <label class="nc-label">Rodzaj czasu</label>
                         <select name="nc_countdown_type" id="nc_countdown_type">
                            <option value="none" <?php selected($countdown_enabled !== '1'); ?>>Zawsze widoczne (Brak odliczania)</option>
                            <option value="date" <?php selected($countdown_enabled === '1' && $countdown_type === 'date'); ?>>Do konkretnej daty (Event)</option>
                            <option value="daily" <?php selected($countdown_enabled === '1' && $countdown_type === 'daily'); ?>>Codziennie w godzinach (Happy Hours)</option>
                        </select>
                        <input type="hidden" name="nc_countdown_enabled" id="nc_countdown_enabled" value="<?php echo $countdown_enabled; ?>">
                    </p>
                    
                    <div id="nc-time-settings" style="<?php echo $countdown_enabled ? '' : 'display:none;'; ?> margin-left:10px; border-left:2px solid #ddd; padding-left:15px;">
                         <p id="nc-date-row" style="<?php echo $countdown_type === 'daily' ? 'display:none;' : ''; ?>">
                            <label class="nc-label">Data końcowa</label>
                            <input type="datetime-local" name="nc_countdown_date" value="<?php echo esc_attr($countdown_date); ?>">
                        </p>
                        
                        <div id="nc-daily-row" style="<?php echo $countdown_type === 'date' ? 'display:none;' : ''; ?>">
                             <p>
                                <label class="nc-label">Godzina startu</label>
                                <input type="time" name="nc_countdown_start_time" value="<?php echo esc_attr($countdown_start_time); ?>">
                            </p>
                            <p>
                                <label class="nc-label">Godzina końca</label>
                                <input type="time" name="nc_countdown_time" value="<?php echo esc_attr($countdown_time); ?>">
                            </p>
                        </div>
                        
                        <p>
                            <label class="nc-label">Etykieta licznika</label>
                             <input type="text" name="nc_countdown_label" value="<?php echo esc_attr($countdown_label); ?>" placeholder="np. Do końca:">
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="nc_countdown_autohide" value="1" <?php checked($countdown_autohide, '1'); ?>>
                                Ukryj powiadomienie, gdy czas minie
                            </label>
                        </p>
                    </div>
                </div>
                
                <!-- Column 2: Exclusions & Frequency -->
                <div style="flex:1; min-width:300px;">
                    <h4 style="margin-top:0;">Dni Tygodnia (Wykluczenia)</h4>
                     <?php 
                        $excluded_days = get_post_meta( $post->ID, 'nc_excluded_days', true );
                        if (!is_array($excluded_days)) $excluded_days = [];
                        $days_of_week = [
                            1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek', 5 => 'Piątek', 6 => 'Sobota', 7 => 'Niedziela'
                        ];
                    ?>
                    <div style="display:flex; flex-direction:column; gap:5px;">
                     <?php foreach($days_of_week as $num => $name): ?>
                        <label>
                            <input type="checkbox" name="nc_excluded_days[]" value="<?php echo $num; ?>" <?php checked(in_array((string)$num, $excluded_days)); ?>>
                            Nie pokazuj w: <strong><?php echo $name; ?></strong>
                        </label>
                     <?php endforeach; ?>
                    </div>
                    
                    <h4 style="margin-top:20px;">Częstotliwość (Powtarzanie)</h4>
                    <?php 
                        $repeat_val = get_post_meta( $post->ID, 'nc_repeat_value', true ); 
                        $repeat_unit = get_post_meta( $post->ID, 'nc_repeat_unit', true ) ?: 'days'; 
                    ?>
                    <p>
                        <label class="nc-label" style="width:auto; margin-right:10px;">Pokaż ponownie po:</label>
                        <input type="number" name="nc_repeat_value" value="<?php echo esc_attr($repeat_val); ?>" style="width: 70px;">
                        <select name="nc_repeat_unit">
                            <option value="minutes" <?php selected($repeat_unit, 'minutes'); ?>>Minut</option>
                            <option value="hours" <?php selected($repeat_unit, 'hours'); ?>>Godzin</option>
                            <option value="days" <?php selected($repeat_unit, 'days'); ?>>Dni</option>
                        </select>
                    </p>
                    <p class="description">
                        Jeśli ustawisz <strong>1 Dzień</strong>, powiadomienie wróci do użytkownika następnego dnia po zamknięciu.
                        <br>0 lub puste = po zamknięciu nie wraca nigdy.
                    </p>
                </div>
            </div>
             <script>
            (function($) {
                $('#nc_countdown_type').change(function() {
                    const val = $(this).val();
                    const enabled = (val !== 'none');
                    $('#nc_countdown_enabled').val(enabled ? '1' : '');
                    
                    if(enabled) {
                        $('#nc-time-settings').slideDown();
                        if(val === 'daily') {
                            $('#nc-date-row').hide();
                            $('#nc-daily-row').show();
                        } else {
                            // date
                            $('#nc-date-row').show();
                            $('#nc-daily-row').hide();
                        }
                    } else {
                        $('#nc-time-settings').slideUp();
                    }
                });
            })(jQuery);
            </script>
        </div>
        
        <!-- SECTION 4: APPEARANCE -->
        <div class="nc-row">
            <h3>4. Wygląd</h3>
            <p class="description">Pozostaw puste, aby użyć kolorów globalnych.</p>
            <?php 
                // Get global defaults
                $global_bg = get_option( 'nc_global_bg', '#ffffff' );
                $global_text = get_option( 'nc_global_text', '#1d1d1f' );
                $global_btn_bg = get_option( 'nc_global_btn_bg', '#007AFF' );
                $global_btn_text = get_option( 'nc_global_btn_text', '#ffffff' );
                
                // Get per-notification values (or use global defaults)
                $bg_color = get_post_meta( $post->ID, 'nc_bg_color', true ) ?: $global_bg;
                $text_color = get_post_meta( $post->ID, 'nc_text_color', true ) ?: $global_text;
                $btn_bg_color = get_post_meta( $post->ID, 'nc_btn_bg_color', true ) ?: $global_btn_bg;
                $btn_text_color = get_post_meta( $post->ID, 'nc_btn_text_color', true ) ?: $global_btn_text;
            ?>
            <p>
                <label class="nc-label">Tło powiadomienia</label>
                <input type="text" name="nc_bg_color" value="<?php echo esc_attr($bg_color); ?>" class="nc-color-field" data-default-color="#ffffff">
            </p>
            <p>
                <label class="nc-label">Kolor tekstu</label>
                <input type="text" name="nc_text_color" value="<?php echo esc_attr($text_color); ?>" class="nc-color-field" data-default-color="#333333">
            </p>
             <p>
                <label class="nc-label">Tło przycisku</label>
                <input type="text" name="nc_btn_bg_color" value="<?php echo esc_attr($btn_bg_color); ?>" class="nc-color-field" data-default-color="#007AFF">
            </p>
             <p>
                <label class="nc-label">Tekst przycisku</label>
                <input type="text" name="nc_btn_text_color" value="<?php echo esc_attr($btn_text_color); ?>" class="nc-color-field" data-default-color="#ffffff">
            </p>
            
            <p>
                <label class="nc-label">Ikona</label>
                <input type="text" name="nc_icon" id="nc_icon_field" value="<?php echo esc_attr($icon); ?>" class="regular-text" placeholder="URL, Emoji lub Dashicons">
                <button type="button" class="button nc-upload-icon-btn">Wybierz</button>
            </p>
            
            <hr>
        </div>
        
		<?php
	}

	public function save_custom_meta( $post_id ) {
		if ( ! isset( $_POST['nc_meta_nonce'] ) || ! wp_verify_nonce( $_POST['nc_meta_nonce'], 'nc_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

        // Text/Select fields
        $fields = [
            'nc_cta_url', 'nc_cta_label', 'nc_icon', 'nc_active_from', 'nc_active_to', 'nc_audience', 
            'nc_floating_delay', 'nc_floating_duration', 'nc_floating_width', 'nc_floating_position',
            'nc_repeat_value', 'nc_repeat_unit',
            'nc_bg_color', 'nc_text_color', 'nc_btn_bg_color', 'nc_btn_text_color',
            'nc_countdown_type', 'nc_countdown_date', 'nc_countdown_time', 'nc_countdown_label', 'nc_countdown_start_time',
            'nc_topbar_position', 'nc_topbar_style'
        ];
        
        foreach($fields as $field) {
            if(isset($_POST[$field])) {
                update_post_meta( $post_id, $field, sanitize_text_field( $_POST[$field] ) );
            } else {
                delete_post_meta( $post_id, $field );
            }
        }
        
         // Handle Rules Array
         if(isset($_POST['nc_rules_data']) && is_array($_POST['nc_rules_data'])) {
             $rules = [];
             foreach($_POST['nc_rules_data'] as $index => $rule) {
                 if(!is_array($rule)) continue;
                 
                 // Sanitize each field individually
                 $sanitized_rule = [
                     'mode' => isset($rule['mode']) ? sanitize_text_field($rule['mode']) : 'show',
                     'type' => isset($rule['type']) ? sanitize_text_field($rule['type']) : 'all',
                 ];
                 
                 // Consolidate value from type-specific fields
                 if($sanitized_rule['type'] === 'id' && isset($rule['value_id'])) {
                     $sanitized_rule['value'] = absint($rule['value_id']);
                 } elseif($sanitized_rule['type'] === 'url' && isset($rule['value_url'])) {
                     $sanitized_rule['value'] = sanitize_text_field($rule['value_url']);
                 } else {
                     $sanitized_rule['value'] = isset($rule['value']) ? sanitize_text_field($rule['value']) : '';
                 }
                 
                 $rules[] = $sanitized_rule;
             }
             update_post_meta($post_id, 'nc_rules_data', $rules);
         } else {
             delete_post_meta($post_id, 'nc_rules_data');
         }

        // Handle Excluded Days
        if(isset($_POST['nc_excluded_days']) && is_array($_POST['nc_excluded_days'])) {
             $sanitized_days = array_map('sanitize_text_field', $_POST['nc_excluded_days']);
             update_post_meta($post_id, 'nc_excluded_days', $sanitized_days);
        } else {
             delete_post_meta($post_id, 'nc_excluded_days');
        }
        
        // Checkboxes
        $checkboxes = [
            'nc_show_in_sidebar', 'nc_sidebar_pinned', 'nc_sidebar_permanent',
            'nc_show_as_floating',
            'nc_show_as_topbar', 'nc_topbar_permanent',
            'nc_countdown_enabled', 'nc_countdown_autohide',
            // Behavioral triggers
            'nc_trigger_delay', 'nc_trigger_exit_intent', 'nc_trigger_scroll_depth', 
            'nc_trigger_time_on_page', 'nc_trigger_inactivity', 'nc_trigger_click'
        ];
        foreach($checkboxes as $cb) {
            update_post_meta($post_id, $cb, isset($_POST[$cb]) ? '1' : '');
        }
        
        // Trigger config fields (text/number)
        $trigger_fields = [
            'nc_trigger_scroll_percent', 'nc_trigger_time_seconds', 
            'nc_trigger_idle_seconds', 'nc_trigger_click_selector'
        ];
        foreach($trigger_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
        
        // Backward compatibility: 
        // If show_as_floating is set, mapping to old toast popup values for safety in case other parts code rely on it abruptly?
        // Actually, better to just rely on new fields in main.js
        // But let's clear old flags to avoid confusion if we ever revert?
        // No, keep it simple. We only save new fields.
	}
}
