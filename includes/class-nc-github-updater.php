<?php
/**
 * GitHub Updater — automatyczne aktualizacje z GitHub
 *
 * @package Notification_Centre
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NC_GitHub_Updater {

    private $github_user = 'lukelocksmith';
    private $github_repo = 'notification-centre';
    private $plugin_basename;
    private $current_version;
    private $plugin_slug;
    private $github_response = null;

    public function __construct() {
        $this->plugin_basename = plugin_basename( dirname( __FILE__, 2 ) . '/notification-centre.php' );
        $this->plugin_slug     = dirname( $this->plugin_basename );
        $this->current_version = NC_VERSION;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
        add_action( 'admin_init', array( $this, 'maybe_clear_cache' ) );
    }

    private function get_github_token() {
        return get_option( 'nc_github_token', '' );
    }

    private function get_github_data() {
        if ( null !== $this->github_response ) {
            return $this->github_response;
        }

        $cached = get_transient( 'nc_github_update_data' );
        if ( false !== $cached ) {
            if ( 'no_data' === $cached || ! is_object( $cached ) || ! isset( $cached->tag_name ) ) {
                $this->github_response = false;
                return false;
            }
            $this->github_response = $cached;
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_user,
            $this->github_repo
        );

        $args = array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ),
            'timeout' => 15,
        );

        $token = $this->get_github_token();
        if ( ! empty( $token ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Fallback: tags API
            $url = sprintf(
                'https://api.github.com/repos/%s/%s/tags',
                $this->github_user,
                $this->github_repo
            );
            $response = wp_remote_get( $url, $args );

            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                $this->github_response = false;
                set_transient( 'nc_github_update_data', 'no_data', HOUR_IN_SECONDS );
                return false;
            }

            $tags = json_decode( wp_remote_retrieve_body( $response ) );
            if ( empty( $tags ) || ! is_array( $tags ) ) {
                $this->github_response = false;
                set_transient( 'nc_github_update_data', 'no_data', HOUR_IN_SECONDS );
                return false;
            }

            $data = (object) array(
                'tag_name'     => $tags[0]->name,
                'body'         => '',
                'published_at' => '',
                'zipball_url'  => $tags[0]->zipball_url,
            );
        } else {
            $data = json_decode( wp_remote_retrieve_body( $response ) );
        }

        if ( empty( $data ) || ! isset( $data->tag_name ) ) {
            $this->github_response = false;
            set_transient( 'nc_github_update_data', false, HOUR_IN_SECONDS );
            return false;
        }

        $this->github_response = $data;
        set_transient( 'nc_github_update_data', $data, 6 * HOUR_IN_SECONDS );
        return $data;
    }

    private function normalize_version( $version ) {
        return ltrim( $version, 'vV' );
    }

    private function get_download_url( $github_data ) {
        $url = isset( $github_data->zipball_url ) ? $github_data->zipball_url : '';

        if ( ! empty( $github_data->assets ) && is_array( $github_data->assets ) ) {
            foreach ( $github_data->assets as $asset ) {
                if ( isset( $asset->browser_download_url ) && substr( $asset->name, -4 ) === '.zip' ) {
                    $url = $asset->browser_download_url;
                    break;
                }
            }
        }

        $token = $this->get_github_token();
        if ( ! empty( $token ) && ! empty( $url ) ) {
            $url = add_query_arg( 'access_token', $token, $url );
        }

        return $url;
    }

    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $github_data = $this->get_github_data();
        if ( false === $github_data ) {
            return $transient;
        }

        $remote_version = $this->normalize_version( $github_data->tag_name );

        if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
            $transient->response[ $this->plugin_basename ] = (object) array(
                'slug'         => $this->plugin_slug,
                'plugin'       => $this->plugin_basename,
                'new_version'  => $remote_version,
                'url'          => sprintf( 'https://github.com/%s/%s', $this->github_user, $this->github_repo ),
                'package'      => $this->get_download_url( $github_data ),
                'requires'     => '5.8',
                'requires_php' => '7.4',
            );
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $github_data = $this->get_github_data();
        if ( false === $github_data ) {
            return $result;
        }

        return (object) array(
            'name'              => 'Notification Centre',
            'slug'              => $this->plugin_slug,
            'version'           => $this->normalize_version( $github_data->tag_name ),
            'author'            => '<a href="https://agencyjnie.pl">Agencyjnie</a>',
            'homepage'          => sprintf( 'https://github.com/%s/%s', $this->github_user, $this->github_repo ),
            'short_description' => 'Advanced on-site notification center with OneSignal integration.',
            'sections'          => array(
                'changelog' => ! empty( $github_data->body ) ? nl2br( esc_html( $github_data->body ) ) : '<p>Brak informacji o zmianach.</p>',
            ),
            'download_link'     => $this->get_download_url( $github_data ),
            'requires'          => '5.8',
            'requires_php'      => '7.4',
            'last_updated'      => isset( $github_data->published_at ) ? $github_data->published_at : '',
        );
    }

    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
            return $response;
        }

        $install_directory = plugin_dir_path( dirname( __FILE__, 2 ) ) . $this->plugin_slug . '/';

        if ( isset( $result['destination'] ) && $result['destination'] !== $install_directory ) {
            $wp_filesystem->move( $result['destination'], $install_directory );
            $result['destination'] = $install_directory;
        }

        activate_plugin( $this->plugin_basename );
        return $response;
    }

    public function maybe_clear_cache() {
        if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
            delete_transient( 'nc_github_update_data' );
        }
    }
}

// Inicjalizacja + pole w ustawieniach
add_action( 'admin_init', function() {
    new NC_GitHub_Updater();

    // Dodaj pole GitHub Token do ustawień NC
    register_setting( 'nc_settings', 'nc_github_token', 'sanitize_text_field' );

    add_settings_section(
        'nc_github_section',
        'Auto-aktualizacje z GitHub',
        function() {
            echo '<p>Konfiguracja automatycznych aktualizacji wtyczki z prywatnego repozytorium GitHub.</p>';
        },
        'nc_settings'
    );

    add_settings_field(
        'nc_github_token',
        'GitHub Token',
        function() {
            $token = get_option( 'nc_github_token', '' );
            $masked = ! empty( $token ) ? str_repeat( '*', 20 ) : '';
            ?>
            <input type="password" name="nc_github_token" value="<?php echo esc_attr( $masked ); ?>"
                   class="regular-text" placeholder="ghp_..." autocomplete="off" />
            <p class="description">
                Wymagany dla prywatnych repozytoriów.
                <a href="https://github.com/settings/tokens/new?scopes=repo&description=Notification+Centre+Updater" target="_blank">Utwórz token</a>
                <br><strong>Aktualna wersja:</strong> <?php echo esc_html( NC_VERSION ); ?>
            </p>
            <?php
        },
        'nc_settings',
        'nc_github_section'
    );
});

// Sanityzacja tokena - nie nadpisuj zamaskowaną wartością
add_filter( 'pre_update_option_nc_github_token', function( $new_value, $old_value ) {
    if ( strpos( $new_value, '***' ) !== false ) {
        return $old_value;
    }
    return sanitize_text_field( $new_value );
}, 10, 2 );
