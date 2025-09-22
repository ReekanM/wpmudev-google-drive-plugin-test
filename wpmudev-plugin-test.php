<?php
/**
 * Plugin Name:       WPMU DEV Plugin Test - Forminator Developer Position
 * Description:       A plugin focused on testing coding skills.
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Version:           0.1.0
 * Author:            Reekan Mohan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpmudev-plugin-test
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Autoload vendor libraries
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Plugin constants
if ( ! defined( 'WPMUDEV_PLUGINTEST_VERSION' ) ) {
    define( 'WPMUDEV_PLUGINTEST_VERSION', '1.0.0' );
}
if ( ! defined( 'WPMUDEV_PLUGINTEST_PLUGIN_FILE' ) ) {
    define( 'WPMUDEV_PLUGINTEST_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WPMUDEV_PLUGINTEST_DIR' ) ) {
    define( 'WPMUDEV_PLUGINTEST_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPMUDEV_PLUGINTEST_URL' ) ) {
    define( 'WPMUDEV_PLUGINTEST_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WPMUDEV_PLUGINTEST_SUI_VERSION' ) ) {
    define( 'WPMUDEV_PLUGINTEST_SUI_VERSION', '2.12.23' );
}
if ( ! defined( 'WPMUDEV_PLUGINTEST_ASSETS_URL' ) ) {
    define( 'WPMUDEV_PLUGINTEST_ASSETS_URL', WPMUDEV_PLUGINTEST_URL . 'assets' );
}

// Load WP-CLI command
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once plugin_dir_path( __FILE__ ) . 'app/class-wpmudev-posts-cli.php';
}

/**
 * Main plugin class
 */
class WPMUDEV_PluginTest {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function load(): void {
        // Load textdomain
        load_plugin_textdomain(
            'wpmudev-plugin-test',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );

        // Initialize loader if exists
        if ( class_exists( 'WPMUDEV\PluginTest\Loader' ) ) {
            WPMUDEV\PluginTest\Loader::instance();
        }

        // Init Google Drive API
        $this->init_google_drive_api();
        $this->init_posts_maintenance_api();

       // Hooks
        add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_drive_script' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_posts_maintenance_script' ] );
    }

    private function init_google_drive_api(): void {
        require_once WPMUDEV_PLUGINTEST_DIR . 'app/endpoints/v1/class-googledrive-rest.php';
        if ( class_exists( '\WPMUDEV\PluginTest\Endpoints\V1\Drive_API' ) ) {
            $drive_api = \WPMUDEV\PluginTest\Endpoints\V1\Drive_API::instance();
            $drive_api->init();
        }
    }

    private function init_posts_maintenance_api(): void {
        require_once WPMUDEV_PLUGINTEST_DIR . 'app/endpoints/v1/class-posts-maintenance-rest.php';
    }

    /**
     * Register admin page Posts Maintenance
     */
    public function register_admin_pages(): void {

        // Posts Maintenance page
        add_menu_page(
            __( 'Posts Maintenance', 'wpmudev-plugin-test' ),
            __( 'Posts Maintenance', 'wpmudev-plugin-test' ),
            'manage_options',
            'wpmudev-posts-maintenance',
            [ $this, 'render_posts_maintenance_page' ],
            'dashicons-admin-tools',
            65
        );
    }
    
     /**
     * Output container for Posts Maintenance React app
     */
    public function render_posts_maintenance_page(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Posts Maintenance', 'wpmudev-plugin-test' ) . '</h1>';
        echo '<div id="wpmudev-posts-maintenance-app"></div></div>';
    }

    /**
     * Enqueue React JS script for Google Drive page
     */
    public function enqueue_drive_script(): void {
        wp_enqueue_script(
            'wpmudev-drive-script',
            WPMUDEV_PLUGINTEST_URL . 'src/googledrive-page/main.jsx',
            [ 'wp-element', 'wp-components' ],
            WPMUDEV_PLUGINTEST_VERSION,
            true
        );

        wp_localize_script(
            // 'wpmudev-drive-test-js',
			'wpmudev-drive-script',
            'wpmudevDriveTest',
            [
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'dom_element_id' => 'wpmudev-drive-app',
                'authStatus' => false,
                'hasCredentials' => false,
            ]
        );
    }

     /**
     * Enqueue Posts Maintenance admin script only on its screen
     */
    public function enqueue_posts_maintenance_script( $hook = '' ): void {
        if ( 'toplevel_page_wpmudev-posts-maintenance' !== $hook ) {
            return;
        }

        $script_path = WPMUDEV_PLUGINTEST_DIR . 'assets/js/postsmaintenance.min.js';
        $style_path  = WPMUDEV_PLUGINTEST_DIR . 'assets/css/postsmaintenance.min.css';

        if ( ! file_exists( $script_path ) ) {
            return;
        }

        wp_enqueue_script(
            'wpmudev-posts-maintenance',
            WPMUDEV_PLUGINTEST_URL . 'assets/js/postsmaintenance.min.js',
            [ 'wp-element', 'wp-components' ],
            filemtime( $script_path ),
            true
        );

        if ( file_exists( $style_path ) ) {
            wp_enqueue_style(
                'wpmudev-posts-maintenance',
                WPMUDEV_PLUGINTEST_URL . 'assets/css/postsmaintenance.min.css',
                [],
                filemtime( $style_path )
            );
        }

        // gather public post types (objects so we can get labels)
        $available_post_types = get_post_types( [ 'public' => true ], 'objects' );

        // remove attachments (media) if present
        if ( isset( $available_post_types['attachment'] ) ) {
            unset( $available_post_types['attachment'] );
        }

        // build slug => label map for JS
        $post_types = [];
        foreach ( $available_post_types as $slug => $obj ) {
            // Try singular_name, then label, then fallback to slug
            if ( isset( $obj->labels ) && ! empty( $obj->labels->singular_name ) ) {
                $label = $obj->labels->singular_name;
            } elseif ( ! empty( $obj->label ) ) {
                $label = $obj->label;
            } else {
                $label = $slug;
            }

            $post_types[ $slug ] = $label;
        }

        wp_localize_script(
            'wpmudev-posts-maintenance',
            'wpmudevPostsMaintenance',
            [
                'root'              => esc_url_raw( rest_url() ),
                'nonce'             => wp_create_nonce( 'wp_rest' ),
                'availablePostTypes'=> $post_types,
                'defaultBatchSize'  => 50,
            ]
        );

    }
}

// Initialize the plugin
add_action( 'plugins_loaded', function() {
    WPMUDEV_PluginTest::get_instance()->load();
}, 9 );

// Flush rewrite rules on activation (to avoid 404 on REST routes)
register_activation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );

// Posts Maintenance - Daily Scan //
// Schedule wrapper that starts a scan daily (if not scheduled)
register_activation_hook( WPMUDEV_PLUGINTEST_PLUGIN_FILE, function() {
    if ( ! wp_next_scheduled( 'wpmudev_daily_posts_scan_wrapper' ) ) {
        wp_schedule_event( time(), 'daily', 'wpmudev_daily_posts_scan_wrapper' );
    }
} );

add_action( 'wpmudev_daily_posts_scan_wrapper', function() {
    // we programmatically call the start scan function to create a background job
    // Use the same default post types (post, page). We call the function directly
    $request = new WP_REST_Request( 'POST', '/wpmudev/v1/posts-scan/start' );
    $request->set_body_params( [ 'post_types' => [ 'post', 'page' ], 'batch_size' => 100 ] );
    // Bypass permissions since CRON runs in background, but ensure proper checks inside function if needed.
    wpmudev_posts_scan_start( $request );
} );

// Clear on deactivation
register_deactivation_hook( WPMUDEV_PLUGINTEST_PLUGIN_FILE, function() {
    wp_clear_scheduled_hook( 'wpmudev_daily_posts_scan_wrapper' );
} );
