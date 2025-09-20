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

        // Enqueue admin scripts
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_drive_script' ] );
    }

    private function init_google_drive_api(): void {
        require_once WPMUDEV_PLUGINTEST_DIR . 'app/endpoints/v1/class-googledrive-rest.php';
        if ( class_exists( '\WPMUDEV\PluginTest\Endpoints\V1\Drive_API' ) ) {
            $drive_api = \WPMUDEV\PluginTest\Endpoints\V1\Drive_API::instance();
            $drive_api->init();
        }
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
}

// Initialize the plugin
add_action( 'plugins_loaded', function() {
    WPMUDEV_PluginTest::get_instance()->load();
}, 9 );

// Flush rewrite rules on activation (to avoid 404 on REST routes)
register_activation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );

if (!wp_next_scheduled('wpmudev_daily_posts_scan')) {
    wp_schedule_event(time(), 'daily', 'wpmudev_daily_posts_scan');
}

add_action('wpmudev_daily_posts_scan', function() {
    $post_types = ['post','page'];
    foreach($post_types as $pt){
        $query = new WP_Query(['post_type'=>$pt,'post_status'=>'publish','posts_per_page'=>-1]);
        foreach($query->posts as $post) {
            update_post_meta($post->ID, 'wpmudev_test_last_scan', current_time('mysql'));
        }
    }
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wpmudev_daily_posts_scan');
});