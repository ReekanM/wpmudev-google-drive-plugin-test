<?php
/**
 * Posts Maintenance REST endpoints + background batch runner.
 *
 * Routes:
 *  POST  /wp-json/wpmudev/v1/posts-scan/start    => start a new scan (returns scan_id)
 *  GET   /wp-json/wpmudev/v1/posts-scan/status   => get status for scan_id
 *  GET   /wp-json/wpmudev/v1/posts-scan/list     => list recent scans
 *
 * Background batches driven by WP-Cron action 'wpmudev_posts_scan_batch'.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register routes
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'wpmudev/v1', '/posts-scan/start', [
        'methods'             => 'POST',
        'callback'            => 'wpmudev_posts_scan_start',
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'args'                => [
            'post_types' => [
                'required' => false,
                'validate_callback' => function( $v ) { return is_array( $v ) || is_null( $v ); },
            ],
            'batch_size' => [
                'required' => false,
                'validate_callback' => function( $v ) { return is_numeric( $v ) || is_null( $v ); },
            ],
        ],
    ] );

    register_rest_route( 'wpmudev/v1', '/posts-scan/status', [
        'methods'             => 'GET',
        'callback'            => 'wpmudev_posts_scan_status',
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'args'                => [
            'scan_id' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );

    register_rest_route( 'wpmudev/v1', '/posts-scan/list', [
        'methods'             => 'GET',
        'callback'            => 'wpmudev_posts_scan_list',
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
    ] );
} );

/**
 * Start a new scan.
 * Returns: { success: true, scan_id: '...' }
 */
function wpmudev_posts_scan_start( WP_REST_Request $request ) {
    $body = $request->get_json_params();

    $post_types = isset( $body['post_types'] ) && is_array( $body['post_types'] ) ? array_map( 'sanitize_text_field', $body['post_types'] ) : [ 'post', 'page' ];
    // Validate against public types
    $public_types = get_post_types( [ 'public' => true ], 'names' );
    $post_types   = array_values( array_intersect( $public_types, $post_types ) );
    if ( empty( $post_types ) ) {
        return new WP_Error( 'invalid_post_types', 'No valid public post types provided', [ 'status' => 400 ] );
    }

    $batch_size = isset( $body['batch_size'] ) ? absint( $body['batch_size'] ) : 50;
    $batch_size = max( 1, min( 500, $batch_size ) ); // sane limits

    // compute total (sum published counts)
    $total = 0;
    foreach ( $post_types as $pt ) {
        $counts = wp_count_posts( $pt );
        $total += isset( $counts->publish ) ? intval( $counts->publish ) : 0;
    }

    $scan_id = 'scan_' . time() . '_' . wp_generate_password( 6, false, false );

    $status = [
        'id'           => $scan_id,
        'post_types'   => $post_types,
        'total'        => $total,
        'processed'    => 0,
        'per_page'     => $batch_size,
        'page'         => 1,
        'status'       => 'queued', // queued / running / completed / failed
        'started_at'   => current_time( 'mysql' ),
        'last_run_at'  => '',
        'message'      => '',
    ];

    update_option( 'wpmudev_posts_scan_' . $scan_id, $status );

    // keep history (last 10)
    $history = get_option( 'wpmudev_posts_scan_history', [] );
    array_unshift( $history, [
        'id'         => $scan_id,
        'started_at' => $status['started_at'],
        'post_types' => $post_types,
        'total'      => $total,
    ] );
    $history = array_slice( $history, 0, 10 );
    update_option( 'wpmudev_posts_scan_history', $history );

    // schedule first batch immediately (1 second)
    if ( ! wp_next_scheduled( 'wpmudev_posts_scan_batch', [ $scan_id ] ) ) {
        wp_schedule_single_event( time() + 1, 'wpmudev_posts_scan_batch', [ $scan_id ] );
    }

    return rest_ensure_response( [ 'success' => true, 'scan_id' => $scan_id ] );
}

/**
 * Status endpoint
 */
function wpmudev_posts_scan_status( WP_REST_Request $request ) {
    $scan_id = $request->get_param( 'scan_id' );
    if ( empty( $scan_id ) ) {
        return new WP_Error( 'missing_scan_id', 'scan_id is required', [ 'status' => 400 ] );
    }
    $data = get_option( 'wpmudev_posts_scan_' . $scan_id );
    if ( false === $data ) {
        return new WP_Error( 'not_found', 'Scan not found', [ 'status' => 404 ] );
    }
    return rest_ensure_response( $data );
}

/**
 * List recent scans
 */
function wpmudev_posts_scan_list( WP_REST_Request $request ) {
    $history = get_option( 'wpmudev_posts_scan_history', [] );
    return rest_ensure_response( [ 'success' => true, 'history' => $history ] );
}

/**
 * Hook executed by WP-Cron for each batch.
 * This function processes a batch and reschedules itself until done.
 */
add_action( 'wpmudev_posts_scan_batch', 'wpmudev_posts_scan_batch_handler', 10, 1 );

function wpmudev_posts_scan_batch_handler( $scan_id ) {
    $opt_key = 'wpmudev_posts_scan_' . $scan_id;
    $status  = get_option( $opt_key, false );
    if ( ! $status || ! is_array( $status ) ) {
        return;
    }

    // mark running
    $status['status']      = 'running';
    $status['last_run_at'] = current_time( 'mysql' );
    update_option( $opt_key, $status );

    $page      = max( 1, intval( $status['page'] ) );
    $per_page  = max( 1, intval( $status['per_page'] ) );
    $post_types = (array) $status['post_types'];

    // Query the next batch (paged)
    $query = new WP_Query( [
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );

    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post_id ) {
            // update the post meta
            update_post_meta( intval( $post_id ), 'wpmudev_test_last_scan', current_time( 'mysql' ) );
            $status['processed'] += 1;
        }
    }

    // advance page
    $status['page'] = $status['page'] + 1;

    // determine completion
    if ( $status['processed'] >= $status['total'] || 0 === $status['total'] ) {
        $status['status']      = 'completed';
        $status['completed_at'] = current_time( 'mysql' );
        $status['message']     = 'Scan completed';
        update_option( $opt_key, $status );
        return;
    }

    // save progress
    $status['message'] = sprintf( 'Processed %d of %d', $status['processed'], $status['total'] );
    update_option( $opt_key, $status );

    // schedule next batch in 1 second
    wp_schedule_single_event( time() + 1, 'wpmudev_posts_scan_batch', [ $scan_id ] );

    // cleanup
    wp_reset_postdata();
}
