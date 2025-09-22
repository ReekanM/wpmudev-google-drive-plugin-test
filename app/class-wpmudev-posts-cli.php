<?php
// File: app/class-wpmudev-posts-cli.php

if ( defined( 'WP_CLI' ) && WP_CLI ) {

    class WPMUDEV_Posts_CLI {

        /**
         * Run a posts maintenance scan.
         *
         * ## OPTIONS
         *
         * [--post_types=<types>]
         * : Comma-separated list of post types to scan.
         * Default: post,page
         *
         * [--batch_size=<number>]
         * : Number of posts per batch. Default: 50
         *
         * ## EXAMPLES
         *
         *     wp posts-maintenance scan --post_types=post,page
         *     wp posts-maintenance scan --post_types=page --batch_size=10
         *
         * @when after_wp_load
         */
        public function scan( $args, $assoc_args ) {
            $post_types = isset( $assoc_args['post_types'] )
                ? explode( ',', $assoc_args['post_types'] )
                : [ 'post', 'page' ];

            $batch_size = isset( $assoc_args['batch_size'] )
                ? intval( $assoc_args['batch_size'] )
                : 50;

            WP_CLI::log( sprintf(
                'Starting Posts Maintenance scan for types: %s (batch size: %d)',
                implode( ', ', $post_types ),
                $batch_size
            ) );

            $request = new WP_REST_Request( 'POST', '/wpmudev/v1/posts-scan/start' );
            $request->set_body_params( [
                'post_types' => $post_types,
                'batch_size' => $batch_size,
            ] );

            $response = wpmudev_posts_scan_start( $request );

            if ( is_wp_error( $response ) ) {
                WP_CLI::error( $response->get_error_message() );
            }

            $data    = $response->get_data();
            $scan_id = $data['scan_id'];

            WP_CLI::success( "Scan started. ID: {$scan_id}" );

            $progress   = null;
            $last_total = 0;

            while ( true ) {
                $status_req = new WP_REST_Request( 'GET', '/wpmudev/v1/posts-scan/status' );
                $status_req->set_query_params( [ 'scan_id' => $scan_id ] );
                $status_res = wpmudev_posts_scan_status( $status_req );

                if ( is_wp_error( $status_res ) ) {
                    WP_CLI::error( $status_res->get_error_message() );
                }

                $status = $status_res->get_data();

                if ( ! $progress ) {
                    $progress = \WP_CLI\Utils\make_progress_bar(
                        'Scanning posts',
                        $status['total']
                    );
                }

                $processed_now = $status['processed'] - $last_total;
                if ( $processed_now > 0 ) {
                    $progress->tick( $processed_now );
                    $last_total = $status['processed'];
                }

                if ( $status['status'] === 'completed' ) {
                    $progress->finish();
                    WP_CLI::success( sprintf(
                        'Scan completed! Processed %d of %d posts.',
                        $status['processed'],
                        $status['total']
                    ) );
                    break;
                }

                wpmudev_posts_scan_batch_handler( $scan_id );
                usleep( 200000 ); // 0.2s
            }
        }

        /**
         * List recent scans.
         *
         * ## EXAMPLES
         *
         *     wp posts-maintenance list
         *
         * @when after_wp_load
         */
        public function list( $args, $assoc_args ) {
            $req = new WP_REST_Request( 'GET', '/wpmudev/v1/posts-scan/list' );
            $res = wpmudev_posts_scan_list( $req );

            if ( is_wp_error( $res ) ) {
                WP_CLI::error( $res->get_error_message() );
            }

            $data = $res->get_data();

            if ( empty( $data['history'] ) ) {
                WP_CLI::log( 'No previous scans recorded.' );
                return;
            }

            $items = [];
            foreach ( $data['history'] as $scan ) {
                $items[] = [
                    'ID'        => $scan['id'],
                    'Started'   => $scan['started_at'],
                    'Types'     => implode( ',', $scan['post_types'] ),
                    'Total'     => $scan['total'],
                ];
            }

            \WP_CLI\Utils\format_items( 'table', $items, [ 'ID', 'Started', 'Types', 'Total' ] );
        }
    }

    WP_CLI::add_command( 'posts-maintenance', 'WPMUDEV_Posts_CLI' );
}
