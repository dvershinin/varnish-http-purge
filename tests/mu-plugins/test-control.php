<?php
/*
Plugin Name: Test Control (MU)
Description: Minimal REST endpoints to aid e2e/pytest tests
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', function() {
    // Setup a custom post type and taxonomy for testing REST base handling
    register_rest_route( 'test/v1', '/setup-cpt', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            register_post_type( 'book', array(
                'label' => 'Books',
                'public' => true,
                'show_in_rest' => true,
                'rest_base' => 'items',
                'supports' => array( 'title', 'editor' ),
                'has_archive' => true,
            ) );

            register_taxonomy( 'genre', array( 'book' ), array(
                'label' => 'Genres',
                'public' => true,
                'show_in_rest' => true,
                'rest_base' => 'genres',
                'hierarchical' => false,
            ) );

            flush_rewrite_rules( false );
            return array( 'ok' => true );
        },
        'permission_callback' => '__return_true',
    ) );

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
            $status = $req->get_param('status') ?: 'publish';
            $type = $req->get_param('type') ?: 'post';
            $post_id = wp_insert_post( array(
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => $status,
                'post_type' => $type,
            ) );
            if ( is_wp_error( $post_id ) ) {
                return $post_id;
            }
            // Optionally set tags
            $tag_ids = array();
            $tags = $req->get_param('tags');
            if ( is_array( $tags ) && ! empty( $tags ) ) {
                foreach ( $tags as $tag_name ) {
                    $term = wp_insert_term( sanitize_text_field( $tag_name ), 'post_tag' );
                    if ( ! is_wp_error( $term ) ) {
                        $tag_ids[] = intval( $term['term_id'] );
                    }
                }
                if ( ! empty( $tag_ids ) ) {
                    wp_set_post_terms( $post_id, $tag_ids, 'post_tag', false );
                }
            }
            // Optionally set genres (custom taxonomy)
            $genre_ids = array();
            $genres = $req->get_param('genres');
            if ( is_array( $genres ) && ! empty( $genres ) ) {
                foreach ( $genres as $genre_name ) {
                    $term = wp_insert_term( sanitize_text_field( $genre_name ), 'genre' );
                    if ( ! is_wp_error( $term ) ) {
                        $genre_ids[] = intval( $term['term_id'] );
                    }
                }
                if ( ! empty( $genre_ids ) ) {
                    wp_set_post_terms( $post_id, $genre_ids, 'genre', false );
                }
            }

            return array( 'id' => $post_id, 'url' => get_permalink( $post_id ), 'tag_ids' => $tag_ids, 'genre_ids' => $genre_ids );
        },
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'test/v1', '/purge', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $all = (bool) $req->get_param('all');
            $url = $req->get_param('url');
            // If URL omitted, allow triggering generate_urls to inspect duplicates
            $post_id = $req->get_param('post_id');
            if ( $all ) {
                $target = home_url() . '/?vhp-regex';
                if ( class_exists('VarnishPurger') ) {
                    VarnishPurger::purge_url( $target );
                }
                return array( 'ok' => true, 'purged' => $target );
            }
            if ( is_string( $url ) && ! empty( $url ) ) {
                if ( class_exists('VarnishPurger') ) {
                    VarnishPurger::purge_url( esc_url_raw( $url ) );
                }
                return array( 'ok' => true, 'purged' => $url );
            }
            if ( class_exists('VarnishPurger') && is_numeric( $post_id ) ) {
                // Ensure CPT and taxonomy used in tests are registered in this request
                $ptype = get_post_type( intval( $post_id ) );
                if ( 'book' === $ptype && ! post_type_exists( 'book' ) ) {
                    register_post_type( 'book', array(
                        'label' => 'Books',
                        'public' => true,
                        'show_in_rest' => true,
                        'rest_base' => 'items',
                        'supports' => array( 'title', 'editor' ),
                        'has_archive' => true,
                    ) );
                }
                if ( ! taxonomy_exists( 'genre' ) ) {
                    register_taxonomy( 'genre', array( 'book' ), array(
                        'label' => 'Genres',
                        'public' => true,
                        'show_in_rest' => true,
                        'rest_base' => 'genres',
                        'hierarchical' => false,
                    ) );
                }
                $vp = new VarnishPurger();
                $urls = $vp->generate_urls( intval( $post_id ) );
                return array( 'ok' => true, 'generated' => $urls );
            }
            return new WP_Error( 'bad_request', 'url or post_id must be provided', array( 'status' => 400 ) );
        },
        'permission_callback' => '__return_true',
    ) );

    // Toggle tag-based purge mode for tests.
    register_rest_route( 'test/v1', '/tags-mode', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $enabled = (bool) $req->get_param( 'enabled' );
            update_site_option( 'vhp_varnish_use_tags', $enabled ? 1 : 0 );
            return array(
                'ok'      => true,
                'enabled' => $enabled,
            );
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

            // Configure custom purge header name/value for tests.
    register_rest_route( 'test/v1', '/purge-header-options', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $name  = $req->get_param( 'name' );
            $value = $req->get_param( 'value' );

            // When either value is not a string, treat this as a reset to defaults.
            if ( ! is_string( $name ) || ! is_string( $value ) ) {
                        delete_site_option( 'vhp_varnish_extra_purge_header_name' );
                        delete_site_option( 'vhp_varnish_extra_purge_header_value' );
            } else {
                $name  = trim( $name );
                $value = trim( $value );

                if ( '' === $name || '' === $value ) {
                            delete_site_option( 'vhp_varnish_extra_purge_header_name' );
                            delete_site_option( 'vhp_varnish_extra_purge_header_value' );
                } else {
                            update_site_option( 'vhp_varnish_extra_purge_header_name', sanitize_text_field( $name ) );
                            update_site_option( 'vhp_varnish_extra_purge_header_value', sanitize_text_field( $value ) );
                }
            }

            return array(
                'ok'    => true,
                        'name'  => get_site_option( 'vhp_varnish_extra_purge_header_name' ),
                        'value' => get_site_option( 'vhp_varnish_extra_purge_header_value' ),
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Inspect headers that would be sent with a PURGE request.
    register_rest_route( 'test/v1', '/purge-headers', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $url = $req->get_param( 'url' );
            if ( ! is_string( $url ) || '' === $url ) {
                $url = home_url( '/' );
            }

            $captured = null;
            $callback = function( $headers ) use ( &$captured ) {
                $captured = $headers;
                return $headers;
            };

            add_filter( 'varnish_http_purge_headers', $callback, 9999 );

            if ( class_exists( 'VarnishPurger' ) ) {
                VarnishPurger::purge_url( esc_url_raw( $url ) );
            }

            remove_filter( 'varnish_http_purge_headers', $callback, 9999 );

            if ( ! is_array( $captured ) ) {
                return new WP_Error( 'no_headers', 'Failed to capture purge headers', array( 'status' => 500 ) );
            }

            return array(
                'ok'      => true,
                'url'     => $url,
                'headers' => $captured,
            );
        },
        'permission_callback' => '__return_true',
    ) );
} );

// Keep tag-pattern headers deliberately small in tests to exercise batching logic.
add_filter( 'vhp_purge_tags_max_header_size', function( $max ) {
    // Use a very small limit so posts with many tags must be split
    // across multiple X-Cache-Tags-Pattern values, exercising the
    // plugin's tag-pattern batching used for BAN-based purges.
    $limit = 64;
    if ( is_numeric( $max ) && (int) $max > 0 && (int) $max < $limit ) {
        // Respect an even smaller test override if provided.
        return (int) $max;
    }
    return $limit;
} );


