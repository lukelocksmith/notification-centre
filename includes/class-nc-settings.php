<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NC_Settings {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		
		// Invalidate options cache when any nc_ option is updated
		add_action( 'update_option', [ $this, 'maybe_invalidate_cache' ], 10, 1 );

		// Invalidate notification caches when a notification is saved
		add_action( 'save_post_nc_notification', [ $this, 'invalidate_notification_caches' ] );
	}
	
	/**
	 * Invalidate options cache when NC settings change
	 */
	public function maybe_invalidate_cache( $option_name ) {
		if ( strpos( $option_name, 'nc_' ) === 0 ) {
			delete_transient( 'nc_all_options' );
		}
	}

	/**
	 * Invalidate all notification-related caches when a notification is saved
	 */
	public function invalidate_notification_caches() {
		delete_transient( 'nc_fluentform_ids' );
		update_option( 'nc_cache_version', time(), false );
	}

	public function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=nc_notification',
			'Ustawienia Notification Centre',
			'Ustawienia',
			'manage_options',
			'nc-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		// Text/Select fields - sanitize with sanitize_text_field
		$text_settings = [
			'nc_radius_type', 'nc_radius_custom', 'nc_display_mode', 'nc_drawer_width',
			'nc_toast_position', 'nc_global_bg', 'nc_global_text', 'nc_global_border',
			'nc_global_btn_bg', 'nc_global_btn_text', 'nc_global_btn_hover_bg', 'nc_global_btn_hover_text',
			'nc_close_color', 'nc_close_bg', 'nc_close_hover_color', 'nc_close_hover_bg',
			'nc_bell_bg', 'nc_bell_style', 'nc_bell_color', 'nc_bell_hover_bg', 'nc_bell_hover_color',
			'nc_badge_bg', 'nc_badge_text', 'nc_badge_type',
			'nc_topbar_bg', 'nc_topbar_text', 'nc_topbar_btn_bg', 'nc_topbar_btn_text',
			'nc_countdown_bg', 'nc_countdown_value_color', 'nc_countdown_unit_color'
		];
		
		foreach ( $text_settings as $setting ) {
			register_setting( 'nc_settings_group', $setting, [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => ''
			]);
		}
		
		// Checkbox/Boolean fields - sanitize as 0/1
		$bool_settings = [
			'nc_enable_sound', 'nc_disable_topbar', 'nc_topbar_dismissible',
			'nc_topbar_sticky', 'nc_countdown_show_units', 'nc_debug_mode',
			// WooCommerce notification toggles
			'nc_woo_enabled', 'nc_woo_order_processing', 'nc_woo_order_completed',
			'nc_woo_order_on_hold', 'nc_woo_order_refunded', 'nc_woo_order_cancelled',
			'nc_woo_order_new', 'nc_woo_abandoned_cart', 'nc_woo_push_enabled',
		];
		
		foreach ( $bool_settings as $setting ) {
			register_setting( 'nc_settings_group', $setting, [
				'type' => 'string',
				'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
				'default' => ''
			]);
		}
		
		// Integer fields - sanitize as integer
		register_setting( 'nc_settings_group', 'nc_topbar_rotation_speed', [
			'type' => 'integer',
			'sanitize_callback' => 'absint',
			'default' => 5
		]);

		register_setting( 'nc_settings_group', 'nc_woo_abandoned_cart_delay', [
			'type' => 'integer',
			'sanitize_callback' => 'absint',
			'default' => 60
		]);
	}
	
	/**
	 * Sanitize checkbox value
	 */
	public function sanitize_checkbox( $value ) {
		return $value ? '1' : '';
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1>Ustawienia Notification Centre</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'nc_settings_group' );
				do_settings_sections( 'nc_settings_group' );
				
                $radius_type = get_option( 'nc_radius_type', 'rounded' );
                $radius_custom = get_option( 'nc_radius_custom', '20' );
				?>
                
                <h2>🎨 Wygląd Ogólny</h2>
                <p class="description">Ustawienia globalne stylu dla wszystkich powiadomień.</p>
                
				<table class="form-table">
					<tr valign="top">
						<th scope="row">Styl narożników</th>
						<td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="nc_radius_type" value="rounded" <?php checked( $radius_type, 'rounded' ); ?>>
                                    Zaokrąglony (Domyślny, Styl iOS)
                                </label><br>
                                <label>
                                    <input type="radio" name="nc_radius_type" value="square" <?php checked( $radius_type, 'square' ); ?>>
                                    Kwadratowy (Brak zaokrągleń)
                                </label><br>
                                <label>
                                    <input type="radio" name="nc_radius_type" value="custom" <?php checked( $radius_type, 'custom' ); ?>>
                                    Własny rozmiar
                                </label>
                            </fieldset>
						</td>
					</tr>
                    <tr valign="top" id="nc_radius_custom_row" style="<?php echo $radius_type !== 'custom' ? 'display:none;' : ''; ?>">
						<th scope="row">Własny promień (px)</th>
						<td>
                            <input type="number" name="nc_radius_custom" value="<?php echo esc_attr( $radius_custom ); ?>" class="small-text"> px
						</td>
						</td>
					</tr>
                    <tr valign="top">
                        <th scope="row">Tryb Wyświetlania</th>
                        <td>
                            <?php $display_mode = get_option( 'nc_display_mode', 'drawer' ); ?>
                            <select name="nc_display_mode">
                                <option value="drawer" <?php selected($display_mode, 'drawer'); ?>>Panel Boczny (Off-Canvas)</option>
                                <option value="dropdown" <?php selected($display_mode, 'dropdown'); ?>>Kompaktowy (Dropdown pod ikoną)</option>
                            </select>
                            <p class="description">Wybierz, czy powiadomienia mają się wysuwać z boku, czy pojawiać pod ikoną dzwonka.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Szerokość Panelu / Dropdowna (px)</th>
                        <td>
                            <input type="number" name="nc_drawer_width" value="<?php echo esc_attr( get_option( 'nc_drawer_width', '400' ) ); ?>" class="small-text"> px
                            <p class="description">Domyślnie 400px. Dotyczy szerokości wysuwanego panelu lub okienka dropdown.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Dźwięk powiadomienia</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_enable_sound" value="1" <?php checked( get_option( 'nc_enable_sound' ), 1 ); ?>>
                                Odtwarzaj subtelny dźwięk przy wyświetleniu powiadomienia (Toast)
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Pozycja powiadomień (Toast)</th>
                        <td>
                            <?php $toast_pos = get_option( 'nc_toast_position', 'top-right' ); ?>
                            <select name="nc_toast_position">
                                <option value="top-right" <?php selected($toast_pos, 'top-right'); ?>>Prawy Górny (Top-Right)</option>
                                <option value="top-left" <?php selected($toast_pos, 'top-left'); ?>>Lewy Górny (Top-Left)</option>
                                <option value="bottom-right" <?php selected($toast_pos, 'bottom-right'); ?>>Prawy Dolny (Bottom-Right)</option>
                                <option value="bottom-left" <?php selected($toast_pos, 'bottom-left'); ?>>Lewy Dolny (Bottom-Left)</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Kolor Tła (Globalny)</th>
                        <td>
                            <input type="text" name="nc_global_bg" value="<?php echo esc_attr( get_option( 'nc_global_bg', '#ffffff' ) ); ?>" class="nc-color-field" data-default-color="#ffffff">
                            <p class="description">Domyślnie biały (#ffffff). Dotyczy Off-Canvas i Toasta.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Kolor Tekstu (Globalny)</th>
                        <td>
                            <input type="text" name="nc_global_text" value="<?php echo esc_attr( get_option( 'nc_global_text', '#1d1d1f' ) ); ?>" class="nc-color-field" data-default-color="#1d1d1f">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Kolor Obramowania (Globalny)</th>
                        <td>
                            <input type="text" name="nc_global_border" value="<?php echo esc_attr( get_option( 'nc_global_border', '#e5e5e5' ) ); ?>" class="nc-color-field" data-default-color="#e5e5e5">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tło Przycisku (Globalny)</th>
                        <td>
                            <input type="text" name="nc_global_btn_bg" value="<?php echo esc_attr( get_option( 'nc_global_btn_bg', '#007AFF' ) ); ?>" class="nc-color-field" data-default-color="#007AFF">
                            <p class="description">Domyślny kolor tła przycisków CTA w powiadomieniach.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tekst Przycisku (Globalny)</th>
                        <td>
                            <input type="text" name="nc_global_btn_text" value="<?php echo esc_attr( get_option( 'nc_global_btn_text', '#ffffff' ) ); ?>" class="nc-color-field" data-default-color="#ffffff">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tło Przycisku - Hover</th>
                        <td>
                            <input type="text" name="nc_global_btn_hover_bg" value="<?php echo esc_attr( get_option( 'nc_global_btn_hover_bg', '#0056b3' ) ); ?>" class="nc-color-field" data-default-color="#0056b3">
                            <p class="description">Kolor tła przycisku po najechaniu myszą.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tekst Przycisku - Hover</th>
                        <td>
                            <input type="text" name="nc_global_btn_hover_text" value="<?php echo esc_attr( get_option( 'nc_global_btn_hover_text', '#ffffff' ) ); ?>" class="nc-color-field" data-default-color="#ffffff">
                        </td>
                    </tr>
				</table>
                
                <h2>🔔 Ikonka Dzwonka i Znacznik</h2>
                <p class="description">Stylizacja przycisku powiadomień i badge'a.</p>
                
                <table class="form-table">
                    
                    <tr valign="top">
                        <th scope="row">Przycisk Zamknięcia - Ikona (X)</th>
                        <td>
                            <input type="text" name="nc_close_color" value="<?php echo esc_attr( get_option( 'nc_close_color', '#1d1d1f' ) ); ?>" class="nc-color-field" data-default-color="#1d1d1f">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Przycisk Zamknięcia - Tło</th>
                        <td>
                            <input type="text" name="nc_close_bg" value="<?php echo esc_attr( get_option( 'nc_close_bg', 'rgba(0,0,0,0.05)' ) ); ?>" class="nc-color-field" data-default-color="rgba(0,0,0,0.05)">
                            <p class="description">Jeśli wybierzesz kolor, pamiętaj, że zaokrąglenie będzie zgodne z ustawieniem globalnym.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Przycisk Zamknięcia - Ikona Hover</th>
                        <td>
                            <input type="text" name="nc_close_hover_color" value="<?php echo esc_attr( get_option( 'nc_close_hover_color', '#ff3b30' ) ); ?>" class="nc-color-field" data-default-color="#ff3b30">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Przycisk Zamknięcia - Tło Hover</th>
                        <td>
                            <input type="text" name="nc_close_hover_bg" value="<?php echo esc_attr( get_option( 'nc_close_hover_bg', 'rgba(0,0,0,0.1)' ) ); ?>" class="nc-color-field" data-default-color="rgba(0,0,0,0.1)">
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">Tło Ikonki Dzwonka</th>
                        <td>
                            <input type="text" name="nc_bell_bg" value="<?php echo esc_attr( get_option( 'nc_bell_bg', 'transparent' ) ); ?>" class="nc-color-field" data-default-color="transparent">
                            <p class="description">Domyślnie przezroczyste.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Styl Ikonki Dzwonka</th>
                        <td>
                            <?php $bell_style = get_option( 'nc_bell_style', 'outline' ); ?>
                            <select name="nc_bell_style">
                                <option value="outline" <?php selected($bell_style, 'outline'); ?>>Outline (Obrys)</option>
                                <option value="solid" <?php selected($bell_style, 'solid'); ?>>Solid (Wypełniona)</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Kolor Ikonki Dzwonka</th>
                        <td>
                            <input type="text" name="nc_bell_color" value="<?php echo esc_attr( get_option( 'nc_bell_color', '#000000' ) ); ?>" class="nc-color-field" data-default-color="#000000">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tło Dzwonka - Hover</th>
                        <td>
                            <input type="text" name="nc_bell_hover_bg" value="<?php echo esc_attr( get_option( 'nc_bell_hover_bg', 'rgba(0,0,0,0.05)' ) ); ?>" class="nc-color-field" data-default-color="rgba(0,0,0,0.05)">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Kolor Dzwonka - Hover</th>
                        <td>
                            <input type="text" name="nc_bell_hover_color" value="<?php echo esc_attr( get_option( 'nc_bell_hover_color', '#007AFF' ) ); ?>" class="nc-color-field" data-default-color="#007AFF">
                        </td>
                    </tr>
                    
                     <tr valign="top">
                        <th scope="row">Znacznik (Badge) - Tło</th>
                        <td>
                            <input type="text" name="nc_badge_bg" value="<?php echo esc_attr( get_option( 'nc_badge_bg', '#ff3b30' ) ); ?>" class="nc-color-field" data-default-color="#ff3b30">
                        </td>
                    </tr>
                     <tr valign="top">
                        <th scope="row">Znacznik (Badge) - Tekst</th>
                        <td>
                            <input type="text" name="nc_badge_text" value="<?php echo esc_attr( get_option( 'nc_badge_text', '#ffffff' ) ); ?>" class="nc-color-field" data-default-color="#ffffff">
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">Typ Znacznika</th>
                        <td>
                             <?php $badge_type = get_option( 'nc_badge_type', 'count' ); ?>
                            <select name="nc_badge_type">
                                <option value="count" <?php selected($badge_type, 'count'); ?>>Liczba (np. 1, 9+)</option>
                                <option value="dot" <?php selected($badge_type, 'dot'); ?>>Tylko Kropka (Dot)</option>
                            </select>
                        </td>
                    </tr>
				</table>
                
                <h2>📢 Górny Pasek (Top Bar)</h2>
                <p class="description">Pasek powiadomień wyświetlany na samej górze strony, nad headerem.</p>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Wyłącz Top Bary</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_disable_topbar" value="1" <?php checked( get_option( 'nc_disable_topbar' ), '1' ); ?>>
                                Globalnie wyłącz wszystkie powiadomienia typu Top Bar
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tło paska</th>
                        <td>
                            <input type="text" name="nc_topbar_bg" value="<?php echo esc_attr( get_option( 'nc_topbar_bg', '#007AFF' ) ); ?>" class="nc-color-field" data-default-color="#007AFF">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Kolor tekstu</th>
                        <td>
                            <input type="text" name="nc_topbar_text" value="<?php echo esc_attr( get_option( 'nc_topbar_text', '#ffffff' ) ); ?>" class="nc-color-field" data-default-color="#ffffff">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tło przycisku CTA</th>
                        <td>
                            <input type="text" name="nc_topbar_btn_bg" value="<?php echo esc_attr( get_option( 'nc_topbar_btn_bg', '#ffffff' ) ); ?>" class="nc-color-field" data-default-color="#ffffff">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tekst przycisku CTA</th>
                        <td>
                            <input type="text" name="nc_topbar_btn_text" value="<?php echo esc_attr( get_option( 'nc_topbar_btn_text', '#007AFF' ) ); ?>" class="nc-color-field" data-default-color="#007AFF">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Możliwość zamknięcia</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_topbar_dismissible" value="1" <?php checked( get_option( 'nc_topbar_dismissible' ), '1' ); ?>>
                                Pokaż przycisk zamknięcia (X)
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Przypnij do góry (Sticky)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_topbar_sticky" value="1" <?php checked( get_option( 'nc_topbar_sticky' ), '1' ); ?>>
                                Pasek pozostaje widoczny podczas przewijania strony
                            </label>
                            <p class="description">Gdy wyłączone, pasek scrolluje się razem ze stroną.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Czas rotacji (sekundy)</th>
                        <td>
                            <input type="number" name="nc_topbar_rotation_speed" value="<?php echo esc_attr( get_option( 'nc_topbar_rotation_speed', '5' ) ); ?>" class="small-text" min="2" max="30"> s
                            <p class="description">Czas wyświetlania jednego komunikatu przed przełączeniem na następny (przy wielu aktywnych).</p>
                        </td>
                    </tr>
				</table>
                
                <h2>⏱️ Odliczanie (Countdown)</h2>
                <p class="description">Globalne ustawienia wyświetlania odliczania.</p>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Pokaż jednostki czasu</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_countdown_show_units" value="1" <?php checked( get_option( 'nc_countdown_show_units', '1' ), '1' ); ?>>
                                Wyświetlaj etykiety (dni, godz, min, sek) pod liczbami
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tło segmentów</th>
                        <td>
                            <input type="text" name="nc_countdown_bg" value="<?php echo esc_attr( get_option( 'nc_countdown_bg', 'transparent' ) ); ?>" class="nc-color-field" data-default-color="transparent">
                            <p class="description">Domyślnie przezroczyste.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Kolor liczb</th>
                        <td>
                            <input type="text" name="nc_countdown_value_color" value="<?php echo esc_attr( get_option( 'nc_countdown_value_color', '#1d1d1f' ) ); ?>" class="nc-color-field" data-default-color="#1d1d1f">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Kolor jednostek</th>
                        <td>
                            <input type="text" name="nc_countdown_unit_color" value="<?php echo esc_attr( get_option( 'nc_countdown_unit_color', '#666666' ) ); ?>" class="nc-color-field" data-default-color="#666666">
                            <p class="description">Kolor etykiet (dni, godz, min, sek).</p>
                        </td>
                    </tr>
				</table>

                <?php if ( class_exists( 'WooCommerce' ) ) : ?>
                <h2>🛒 Powiadomienia WooCommerce</h2>
                <p class="description">Automatyczne powiadomienia per-user o zamówieniach i porzuconym koszyku. Wyświetlane w dzwonku i jako floating popup dla zalogowanych klientów.</p>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Włącz powiadomienia WooCommerce</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_woo_enabled" value="1" <?php checked( get_option( 'nc_woo_enabled', '1' ), '1' ); ?>>
                                Globalnie włącz/wyłącz powiadomienia WooCommerce
                            </label>
                        </td>
                    </tr>
                </table>

                <h3>📦 Powiadomienia o zamówieniach</h3>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Nowe zamówienie</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_woo_order_new" value="1" <?php checked( get_option( 'nc_woo_order_new', '1' ), '1' ); ?>>
                                "Dziękujemy! Zamówienie #X zostało przyjęte"
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">W realizacji (processing)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_woo_order_processing" value="1" <?php checked( get_option( 'nc_woo_order_processing', '1' ), '1' ); ?>>
                                "Zamówienie #X jest w realizacji"
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Zrealizowane (completed)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_woo_order_completed" value="1" <?php checked( get_option( 'nc_woo_order_completed', '1' ), '1' ); ?>>
                                "Zamówienie #X zostało zrealizowane!"
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Oczekuje na płatność (on-hold)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_woo_order_on_hold" value="1" <?php checked( get_option( 'nc_woo_order_on_hold', '1' ), '1' ); ?>>
                                "Zamówienie #X oczekuje na płatność"
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Zwrot (refunded)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_woo_order_refunded" value="1" <?php checked( get_option( 'nc_woo_order_refunded', '1' ), '1' ); ?>>
                                "Zwrot za zamówienie #X został przetworzony"
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Anulowane (cancelled)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_woo_order_cancelled" value="1" <?php checked( get_option( 'nc_woo_order_cancelled', '1' ), '1' ); ?>>
                                "Zamówienie #X zostało anulowane"
                            </label>
                        </td>
                    </tr>
                </table>

                <h3>🛒 Porzucony koszyk</h3>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Powiadomienie o porzuconym koszyku</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_woo_abandoned_cart" value="1" <?php checked( get_option( 'nc_woo_abandoned_cart', '1' ), '1' ); ?>>
                                "Masz produkty w koszyku! Dokończ zakupy."
                            </label>
                            <p class="description">Wysyłane gdy zalogowany użytkownik ma produkty w koszyku i nie dokończył zakupu.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Opóźnienie (minuty)</th>
                        <td>
                            <input type="number" name="nc_woo_abandoned_cart_delay" value="<?php echo esc_attr( get_option( 'nc_woo_abandoned_cart_delay', '60' ) ); ?>" class="small-text" min="15" max="1440"> min
                            <p class="description">Czas od ostatniej aktywności w koszyku do wysłania powiadomienia. Domyślnie 60 minut. Max 1 powiadomienie na 24h per użytkownik.</p>
                        </td>
                    </tr>
                </table>

                <h3>📱 Push (OneSignal)</h3>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Push przy nowym powiadomieniu</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_woo_push_enabled" value="1" <?php checked( get_option( 'nc_woo_push_enabled', '' ), '1' ); ?>>
                                Wysyłaj push notification przez OneSignal
                            </label>
                            <?php
                            $os_settings = get_option( 'OneSignalWPSetting' );
                            if ( ! $os_settings || empty( $os_settings['app_id'] ) ) :
                            ?>
                                <p class="description" style="color: #d63638;">⚠️ OneSignal nie jest skonfigurowany. Zainstaluj i skonfiguruj plugin OneSignal, aby push działał.</p>
                            <?php else : ?>
                                <p class="description" style="color: #00a32a;">✅ OneSignal wykryty (App ID: <?php echo esc_html( substr( $os_settings['app_id'], 0, 8 ) . '...' ); ?>)</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>

                <h2>🛠️ Rozwiązywanie problemów (Debug)</h2>
                <p class="description">Narzędzia pomocne przy diagnozowaniu problemów z wyświetlaniem powiadomień.</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Tryb Debugowania</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nc_debug_mode" value="1" <?php checked( get_option( 'nc_debug_mode' ), '1' ); ?>>
                                Włącz logowanie zdarzeń do konsoli przeglądarki (Console Log)
                            </label>
                            <p class="description">Włącz tę opcję, jeśli powiadomienia się nie wyświetlają, aby zobaczyć szczegóły w konsoli (F12).</p>
                        </td>
                    </tr>
                </table>
                
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const radios = document.getElementsByName('nc_radius_type');
                    const customRow = document.getElementById('nc_radius_custom_row');
                    
                    for(let i=0; i<radios.length; i++) {
                        radios[i].addEventListener('change', function() {
                            if(this.value === 'custom') {
                                customRow.style.display = 'table-row';
                            } else {
                                customRow.style.display = 'none';
                            }
                        });
                    }
                });
                </script>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
