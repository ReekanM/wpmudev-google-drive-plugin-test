<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPMUDEV_Posts_Maintenance_Page {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function register_admin_page() {
        add_menu_page(
            __( 'Posts Maintenance', 'wpmudev-plugin-test' ),
            __( 'Posts Maintenance', 'wpmudev-plugin-test' ),
            'manage_options',
            'wpmudev-posts-maintenance',
            [ $this, 'render_admin_page' ],
            'dashicons-hammer',
            81
        );
    }

    public function enqueue_scripts( $hook ) {
        // only load on our admin page
        if ( 'toplevel_page_wpmudev-posts-maintenance' !== $hook ) {
            return;
        }

        // compiled assets produced by webpack (assets/js/postsmaintenance.min.js)
        $script_path = WPMUDEV_PLUGINTEST_DIR . 'assets/js/postsmaintenance.min.js';
        $script_url  = WPMUDEV_PLUGINTEST_URL . 'assets/js/postsmaintenance.min.js';

        if ( ! file_exists( $script_path ) ) {
            // no built JS present; bail silently (in dev you should build)
            return;
        }

        // gather available public post types (slug => label)
        $pt_slugs = get_post_types( [ 'public' => true ], 'names' );
        $pt_map   = [];
        foreach ( $pt_slugs as $pt ) {
            $obj          = get_post_type_object( $pt );
            $pt_map[ $pt ] = $obj && isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : $pt;
        }

        wp_enqueue_script(
            'wpmudev-posts-maintenance',
            $script_url,
            [ 'wp-element', 'wp-components' ],
            filemtime( $script_path ),
            true
        );

        wp_localize_script(
            'wpmudev-posts-maintenance',
            'wpmudevPostsMaintenance',
            [
                'root'               => esc_url_raw( rest_url() ),
                'nonce'              => wp_create_nonce( 'wp_rest' ),
                'availablePostTypes' => $pt_map,
                'defaultBatchSize'   => 50,
            ]
        );

        // enqueue optional CSS if created by build
        $css_path = WPMUDEV_PLUGINTEST_DIR . 'assets/css/postsmaintenance.min.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'wpmudev-posts-maintenance',
                WPMUDEV_PLUGINTEST_URL . 'assets/css/postsmaintenance.min.css',
                [],
                filemtime( $css_path )
            );
        }
    }

    public function render_admin_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Posts Maintenance', 'wpmudev-plugin-test' ) . '</h1>';
        echo '<div id="posts-maintenance-app"></div>';
        echo '</div>';
    }
}
