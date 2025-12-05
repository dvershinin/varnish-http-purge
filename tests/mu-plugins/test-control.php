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
            $title   = $req->get_param( 'title' ) ?: 'Test Title';
            $content = $req->get_param( 'content' ) ?: 'Test Content';
            $status  = $req->get_param( 'status' ) ?: 'publish';
            $type    = $req->get_param( 'type' ) ?: 'post';

            $postarr = array(
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => $status,
                'post_type'    => $type,
            );

            // Allow tests to explicitly control scheduling fields for future posts.
            // Convert any parseable date format to MySQL datetime format (YYYY-MM-DD HH:MM:SS).
            $date = $req->get_param( 'date' );
            if ( is_string( $date ) && '' !== $date ) {
                $ts = strtotime( $date );
                if ( false !== $ts ) {
                    $postarr['post_date'] = gmdate( 'Y-m-d H:i:s', $ts );
                }
            }

            $date_gmt = $req->get_param( 'date_gmt' );
            if ( is_string( $date_gmt ) && '' !== $date_gmt ) {
                $ts = strtotime( $date_gmt );
                if ( false !== $ts ) {
                    $postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
                }
            }

            $post_id = wp_insert_post( $postarr );
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

    // Control cron-based purge mode for tests.
    register_rest_route( 'test/v1', '/cron-mode', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $mode = $req->get_param( 'mode' );
            if ( ! is_string( $mode ) ) {
                $mode = 'auto';
            }
            $mode = strtolower( $mode );

            switch ( $mode ) {
                case 'force_on':
                    update_site_option( 'vhp_varnish_force_cron_mode', 'on' );
                    break;
                case 'force_off':
                    update_site_option( 'vhp_varnish_force_cron_mode', 'off' );
                    break;
                default:
                    delete_site_option( 'vhp_varnish_force_cron_mode' );
                    $mode = 'auto';
                    break;
            }

            $enabled = false;
            if ( class_exists( 'VarnishPurger' ) ) {
                $enabled = VarnishPurger::is_cron_purging_enabled_static();
            }

            return array(
                'ok'      => true,
                'mode'    => $mode,
                'enabled' => (bool) $enabled,
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

    // Inspect the async purge queue for tests.
    register_rest_route( 'test/v1', '/purge-queue', array(
        'methods'  => 'GET',
        'callback' => function( WP_REST_Request $req ) {
            $queue = array();
            if ( class_exists( 'VarnishPurger' ) ) {
                $queue = get_site_option( VarnishPurger::PURGE_QUEUE_OPTION, array() );
            }
            if ( ! is_array( $queue ) ) {
                $queue = array();
            }

            $full            = ( isset( $queue['full'] ) && $queue['full'] );
            $urls            = ( isset( $queue['urls'] ) && is_array( $queue['urls'] ) ) ? array_values( $queue['urls'] ) : array();
            $tags            = ( isset( $queue['tags'] ) && is_array( $queue['tags'] ) ) ? array_values( $queue['tags'] ) : array();
            $created_at      = isset( $queue['created_at'] ) ? (int) $queue['created_at'] : 0;
            $last_updated_at = isset( $queue['last_updated_at'] ) ? (int) $queue['last_updated_at'] : 0;

            return array(
                'ok'    => true,
                'queue' => array(
                    'full'            => $full,
                    'urls'            => $urls,
                    'tags'            => $tags,
                    'created_at'      => $created_at,
                    'last_updated_at' => $last_updated_at,
                ),
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Clear the async purge queue between tests.
    register_rest_route( 'test/v1', '/purge-queue/clear', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( class_exists( 'VarnishPurger' ) ) {
                delete_site_option( VarnishPurger::PURGE_QUEUE_OPTION );
            }
            return array( 'ok' => true );
        },
        'permission_callback' => '__return_true',
    ) );

    // Run the async purge queue processor and capture any PURGE headers that would be sent.
    register_rest_route( 'test/v1', '/run-cron-processor', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( ! class_exists( 'VarnishPurger' ) ) {
                return new WP_Error( 'no_purger', 'VarnishPurger class not available', array( 'status' => 500 ) );
            }

            $captures = array();
            $callback = function( $headers ) use ( &$captures ) {
                $captures[] = $headers;
                return $headers;
            };

            add_filter( 'varnish_http_purge_headers', $callback, 9999 );

            $vp = new VarnishPurger();
            $vp->process_purge_queue();

            remove_filter( 'varnish_http_purge_headers', $callback, 9999 );

            $queue_after = get_site_option( VarnishPurger::PURGE_QUEUE_OPTION, array() );

            return array(
                'ok'          => true,
                'headers'     => $captures,
                'queue_after' => $queue_after,
                'last_run'    => (int) get_site_option( 'vhp_varnish_last_queue_run', 0 ),
            );
        },
        'permission_callback' => '__return_true',
    ) );
} );

// Debug endpoint: trace what URLs would be purged for a scheduled post publish.
add_action( 'rest_api_init', function() {
    register_rest_route( 'test/v1', '/debug-scheduled-purge/(?P<id>\d+)', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $post_id = intval( $req['id'] );
            $post = get_post( $post_id );

            if ( ! $post ) {
                return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
            }

            $result = array(
                'post_id' => $post_id,
                'status'  => $post->post_status,
                'type'    => $post->post_type,
                'url'     => get_permalink( $post_id ),
            );

            // Check if VarnishPurger is available.
            if ( ! class_exists( 'VarnishPurger' ) ) {
                $result['error'] = 'VarnishPurger class not found';
                return $result;
            }

            // Trace URL generation.
            $vp = new VarnishPurger();
            $urls = $vp->generate_urls( $post_id );
            $result['generated_urls'] = $urls;

            // Simulate what purge_on_future_to_publish would do.
            $result['method_exists'] = method_exists( $vp, 'purge_on_future_to_publish' );

            return $result;
        },
        'permission_callback' => '__return_true',
    ) );

    // Endpoint to simulate the transition and capture purge actions.
    register_rest_route( 'test/v1', '/simulate-publish/(?P<id>\d+)', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $post_id = intval( $req['id'] );
            $post = get_post( $post_id );

            if ( ! $post ) {
                return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
            }

            // Capture purge URLs.
            $captured_urls = array();
            $capture_callback = function( $parsed_url, $purgeme, $response, $headers ) use ( &$captured_urls ) {
                $captured_urls[] = array(
                    'url'     => $parsed_url,
                    'purgeme' => $purgeme,
                    'status'  => is_wp_error( $response ) ? 'error' : wp_remote_retrieve_response_code( $response ),
                );
            };
            add_action( 'after_purge_url', $capture_callback, 10, 4 );

            // If post is 'future', transition it to 'publish'.
            $old_status = $post->post_status;
            if ( 'future' === $old_status ) {
                wp_publish_post( $post_id );
                $post = get_post( $post_id );
            }

            remove_action( 'after_purge_url', $capture_callback, 10 );

            return array(
                'post_id'       => $post_id,
                'old_status'    => $old_status,
                'new_status'    => $post->post_status,
                'captured_urls' => $captured_urls,
            );
        },
        'permission_callback' => '__return_true',
    ) );
} );

// Allow forcing cron-mode on/off in tests via a site option.
add_filter( 'vhp_purge_use_cron', function( $enabled ) {
    $forced = get_site_option( 'vhp_varnish_force_cron_mode', '' );

    if ( 'on' === $forced ) {
        return true;
    }

    if ( 'off' === $forced ) {
        return false;
    }

    return $enabled;
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


