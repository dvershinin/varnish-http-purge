<?php
/*
Plugin Name: Test Control (MU)
Description: Minimal REST endpoints to aid e2e/pytest tests
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', function() {
    register_rest_route( 'test/v1', '/permalinks', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $structure = $req->get_param('structure');
            if ( ! is_string( $structure ) ) {
                return new WP_Error( 'bad_structure', 'structure must be string', array( 'status' => 400 ) );
            }
            update_option( 'permalink_structure', $structure );
            flush_rewrite_rules( true );
            return array( 'ok' => true, 'structure' => get_option('permalink_structure') );
        },
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'test/v1', '/adminbar-purge-url', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $page_url = $req->get_param('page_url');
            $mode = $req->get_param('mode'); // 'old' or 'new'
            if ( ! is_string( $page_url ) || empty( $page_url ) ) {
                return new WP_Error( 'bad_page_url', 'page_url must be provided', array( 'status' => 400 ) );
            }
            if ( $mode !== 'old' && $mode !== 'new' ) {
                return new WP_Error( 'bad_mode', 'mode must be old or new', array( 'status' => 400 ) );
            }
            // Generate nonce in the context of admin user so it validates when used by logged-in admin
            $admin = get_user_by( 'login', 'admin' );
            if ( $admin ) {
                wp_set_current_user( $admin->ID );
            }
            $target = ( $mode === 'old' ) ? trailingslashit( $page_url ) : user_trailingslashit( $page_url );
            $href = wp_nonce_url( add_query_arg( 'vhp_flush_do', $target ), 'vhp-flush-do' );
            return array( 'href' => $href );
        },
        'permission_callback' => '__return_true',
    ) );

    // Directly simulate the admin bar purge effect without nonces.
    register_rest_route( 'test/v1', '/adminbar-purge-exec', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $page_url = $req->get_param('page_url');
            $mode = $req->get_param('mode'); // 'old' or 'new'
            if ( ! is_string( $page_url ) || empty( $page_url ) ) {
                return new WP_Error( 'bad_page_url', 'page_url must be provided', array( 'status' => 400 ) );
            }
            if ( $mode !== 'old' && $mode !== 'new' ) {
                return new WP_Error( 'bad_mode', 'mode must be old or new', array( 'status' => 400 ) );
            }
            $target = ( $mode === 'old' ) ? trailingslashit( $page_url ) : user_trailingslashit( $page_url );
            if ( class_exists('VarnishPurger') ) {
                VarnishPurger::purge_url( $target );
            }
            return array( 'ok' => true, 'purged' => $target );
        },
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'test/v1', '/post', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $title = $req->get_param('title') ?: 'Test Title';
            $content = $req->get_param('content') ?: 'Test Content';
            $post_id = wp_insert_post( array(
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => 'publish',
            ) );
            if ( is_wp_error( $post_id ) ) {
                return $post_id;
            }
            return array( 'id' => $post_id, 'url' => get_permalink( $post_id ) );
        },
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'test/v1', '/purge', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $all = (bool) $req->get_param('all');
            $url = $req->get_param('url');
            if ( $all ) {
                $target = home_url() . '/?vhp-regex';
                if ( class_exists('VarnishPurger') ) {
                    VarnishPurger::purge_url( $target );
                }
                return array( 'ok' => true, 'purged' => $target );
            }
            if ( ! is_string( $url ) || empty( $url ) ) {
                return new WP_Error( 'bad_url', 'url must be provided', array( 'status' => 400 ) );
            }
            if ( class_exists('VarnishPurger') ) {
                VarnishPurger::purge_url( esc_url_raw( $url ) );
            }
            return array( 'ok' => true, 'purged' => $url );
        },
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'test/v1', '/post/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => function( WP_REST_Request $req ) {
            $id = intval( $req['id'] );
            $content = $req->get_param('content') ?: 'Updated Content';
            $ok = wp_update_post( array( 'ID' => $id, 'post_content' => $content ) );
            if ( is_wp_error( $ok ) || 0 === $ok ) {
                return new WP_Error( 'update_failed', 'Update failed', array( 'status' => 500 ) );
            }
            return array( 'id' => $id, 'url' => get_permalink( $id ) );
        },
        'permission_callback' => '__return_true',
    ) );
} );


