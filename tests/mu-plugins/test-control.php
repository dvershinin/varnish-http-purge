<?php
/*
Plugin Name: Test Control (MU)
Description: Minimal REST endpoints to aid e2e/pytest tests
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Register test CPT and taxonomy on init so they're available for all requests.
add_action( 'init', function() {
	register_post_type( 'book', array(
		'label'        => 'Books',
		'public'       => true,
		'show_in_rest' => true,
		'rest_base'    => 'items',
		'supports'     => array( 'title', 'editor' ),
		'has_archive'  => true,
	) );

	register_taxonomy( 'genre', array( 'book' ), array(
		'label'        => 'Genres',
		'public'       => true,
		'show_in_rest' => true,
		'rest_base'    => 'genres',
		'hierarchical' => false,
	) );
} );

// Prevent Varnish from caching test control API responses.
add_filter( 'rest_pre_serve_request', function( $served, $result, $request ) {
    $route = $request->get_route();
    if ( strpos( $route, '/test/v1/' ) === 0 ) {
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
    }
    return $served;
}, 10, 3 );

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

            // Force clear WordPress object cache to ensure all PHP-FPM workers
            // see the updated option value on their next request.
            // This is necessary because workers may have cached the old value
            // in their alloptions cache from previous requests.
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'vhp_varnish_use_tags', 'options' );
            wp_cache_delete( 'vhp_varnish_use_tags', 'site-options' );

            // Verify the option was actually set by reading it back.
            $actual = get_site_option( 'vhp_varnish_use_tags' );

            return array(
                'ok'      => true,
                'enabled' => $enabled,
                'actual'  => $actual,
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

    // Update post content directly in DB, bypassing WordPress hooks (and thus cache purge).
    // This is used by cache behavior tests to verify caching works.
    register_rest_route( 'test/v1', '/update-post-bypass', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            global $wpdb;
            $post_id = intval( $req->get_param( 'post_id' ) );
            $content = $req->get_param( 'content' );

            if ( ! $post_id || ! is_string( $content ) ) {
                return new WP_Error( 'bad_params', 'post_id and content required', array( 'status' => 400 ) );
            }

            // Update directly in DB to bypass save_post hooks.
            // We must also update post_modified so that conditional requests (If-Modified-Since)
            // from Varnish's background fetch after softpurge will get fresh content, not 304.
            $now     = current_time( 'mysql' );
            $now_gmt = current_time( 'mysql', true );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update(
                $wpdb->posts,
                array(
                    'post_content'      => $content,
                    'post_modified'     => $now,
                    'post_modified_gmt' => $now_gmt,
                ),
                array( 'ID' => $post_id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );

            if ( false === $result ) {
                return new WP_Error( 'update_failed', 'DB update failed', array( 'status' => 500 ) );
            }

            // Clear object cache but don't trigger any hooks.
            clean_post_cache( $post_id );

            return array( 'ok' => true, 'post_id' => $post_id );
        },
        'permission_callback' => '__return_true',
    ) );

    // Delete a post (for cleanup in tests).
    register_rest_route( 'test/v1', '/delete-post', array(
        'methods' => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $post_id = intval( $req->get_param( 'post_id' ) );

            if ( ! $post_id ) {
                return new WP_Error( 'bad_params', 'post_id required', array( 'status' => 400 ) );
            }

            $result = wp_delete_post( $post_id, true ); // Force delete, bypass trash.

            if ( ! $result ) {
                return new WP_Error( 'delete_failed', 'Delete failed', array( 'status' => 500 ) );
            }

            return array( 'ok' => true, 'deleted' => $post_id );
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

// Test endpoint to execute WP-CLI varnish commands and capture output.
// This allows pytest to test CLI functionality without direct shell access.
add_action( 'rest_api_init', function() {
    register_rest_route( 'test/v1', '/wp-cli/varnish', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            // Check if WP-CLI is available (it won't be in a web request context)
            // Instead, we simulate what the CLI would do by calling the same methods.
            if ( ! class_exists( 'VarnishPurger' ) ) {
                return new WP_Error( 'no_purger', 'VarnishPurger class not available', array( 'status' => 500 ) );
            }

            $subcommand = $req->get_param( 'subcommand' );
            $url        = $req->get_param( 'url' );
            $all        = (bool) $req->get_param( 'all' );
            $url_only   = (bool) $req->get_param( 'url_only' );
            $wildcard   = (bool) $req->get_param( 'wildcard' );
            $tag        = $req->get_param( 'tag' );

            $captured = array();
            $capture_callback = function( $headers ) use ( &$captured ) {
                $captured[] = $headers;
                return $headers;
            };

            add_filter( 'varnish_http_purge_headers', $capture_callback, 9999 );

            $vp = new VarnishPurger();
            $result = array(
                'ok'         => true,
                'subcommand' => $subcommand,
            );

            if ( 'purge' === $subcommand ) {
                // Handle tag-based purging.
                if ( ! empty( $tag ) ) {
                    $vp->purge_tags( array( sanitize_text_field( $tag ) ) );
                    $result['type']    = 'tag';
                    $result['tag']     = $tag;
                    $result['message'] = 'Purged by tag: ' . $tag;
                } elseif ( $all || empty( $url ) ) {
                    // Full site purge.
                    $purge_url = $vp->the_home_url() . '/?vhp-regex';
                    VarnishPurger::purge_url( $purge_url );
                    $result['type']      = 'full';
                    $result['purge_url'] = $purge_url;
                    $result['message']   = 'Purged entire site cache';
                } elseif ( $url_only ) {
                    // Purge exact URL only.
                    VarnishPurger::purge_url( esc_url( $url ) );
                    $result['type']      = 'url_only';
                    $result['purge_url'] = $url;
                    $result['message']   = 'Purged exact URL: ' . $url;
                } else {
                    // Default: wildcard purge for URL.
                    $purge_url = rtrim( esc_url( $url ), '/' ) . '/?vhp-regex';
                    VarnishPurger::purge_url( $purge_url );
                    $result['type']      = 'wildcard';
                    $result['purge_url'] = $purge_url;
                    $result['message']   = 'Purged URL with wildcard: ' . $url;
                }
            } else {
                $result['ok']    = false;
                $result['error'] = 'Unknown subcommand: ' . $subcommand;
            }

            remove_filter( 'varnish_http_purge_headers', $capture_callback, 9999 );

            $result['captured_headers'] = $captured;
            return $result;
        },
        'permission_callback' => '__return_true',
    ) );
} );

// Test endpoint for VarnishDebug cache detection logic.
// Allows testing varnish_results() with custom headers.
add_action( 'rest_api_init', function() {
    register_rest_route( 'test/v1', '/debug/varnish-results', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( ! class_exists( 'VarnishDebug' ) ) {
                return new WP_Error( 'no_debug', 'VarnishDebug class not available', array( 'status' => 500 ) );
            }

            $headers = $req->get_param( 'headers' );
            if ( ! is_array( $headers ) ) {
                $headers = array();
            }

            $result = VarnishDebug::varnish_results( $headers );
            return array(
                'ok'      => true,
                'headers' => $headers,
                'result'  => $result,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Test cache_results() for Cache-Control, Age, Pragma checks.
    register_rest_route( 'test/v1', '/debug/cache-results', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( ! class_exists( 'VarnishDebug' ) ) {
                return new WP_Error( 'no_debug', 'VarnishDebug class not available', array( 'status' => 500 ) );
            }

            $headers = $req->get_param( 'headers' );
            if ( ! is_array( $headers ) ) {
                $headers = array();
            }

            $result = VarnishDebug::cache_results( $headers );
            return array(
                'ok'      => true,
                'headers' => $headers,
                'result'  => $result,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Test cookie_results() for cookie detection.
    register_rest_route( 'test/v1', '/debug/cookie-results', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( ! class_exists( 'VarnishDebug' ) ) {
                return new WP_Error( 'no_debug', 'VarnishDebug class not available', array( 'status' => 500 ) );
            }

            $headers = $req->get_param( 'headers' );
            if ( ! is_array( $headers ) ) {
                $headers = array();
            }

            $result = VarnishDebug::cookie_results( $headers );
            return array(
                'ok'      => true,
                'headers' => $headers,
                'result'  => $result,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Test gzip_results() for compression detection.
    register_rest_route( 'test/v1', '/debug/gzip-results', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( ! class_exists( 'VarnishDebug' ) ) {
                return new WP_Error( 'no_debug', 'VarnishDebug class not available', array( 'status' => 500 ) );
            }

            $headers = $req->get_param( 'headers' );
            if ( ! is_array( $headers ) ) {
                $headers = array();
            }

            $result = VarnishDebug::gzip_results( $headers );
            return array(
                'ok'      => true,
                'headers' => $headers,
                'result'  => $result,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Test server_results() for server detection.
    register_rest_route( 'test/v1', '/debug/server-results', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( ! class_exists( 'VarnishDebug' ) ) {
                return new WP_Error( 'no_debug', 'VarnishDebug class not available', array( 'status' => 500 ) );
            }

            $headers = $req->get_param( 'headers' );
            if ( ! is_array( $headers ) ) {
                $headers = array();
            }

            $result = VarnishDebug::server_results( $headers );
            return array(
                'ok'      => true,
                'headers' => $headers,
                'result'  => $result,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Test remote_ip() for IP detection from headers.
    register_rest_route( 'test/v1', '/debug/remote-ip', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( ! class_exists( 'VarnishDebug' ) ) {
                return new WP_Error( 'no_debug', 'VarnishDebug class not available', array( 'status' => 500 ) );
            }

            $headers = $req->get_param( 'headers' );
            if ( ! is_array( $headers ) ) {
                $headers = array();
            }

            $result = VarnishDebug::remote_ip( $headers );
            return array(
                'ok'        => true,
                'headers'   => $headers,
                'remote_ip' => $result,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Test remote_get() which actually fetches from current site.
    register_rest_route( 'test/v1', '/debug/remote-get', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( ! class_exists( 'VarnishDebug' ) ) {
                return new WP_Error( 'no_debug', 'VarnishDebug class not available', array( 'status' => 500 ) );
            }

            $url = $req->get_param( 'url' );
            if ( ! is_string( $url ) || '' === $url ) {
                $url = home_url( '/' );
            }

            $response = VarnishDebug::remote_get( $url );

            if ( 'fail' === $response ) {
                return array(
                    'ok'     => false,
                    'url'    => $url,
                    'error'  => 'Request failed',
                );
            }

            $headers = wp_remote_retrieve_headers( $response );
            // Convert to associative array.
            $headers_array = array();
            if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
                $headers_array = $headers->getAll();
            } elseif ( is_array( $headers ) ) {
                $headers_array = $headers;
            }

            $varnish_results = VarnishDebug::varnish_results( $headers );

            return array(
                'ok'              => true,
                'url'             => $url,
                'status_code'     => wp_remote_retrieve_response_code( $response ),
                'headers'         => $headers_array,
                'varnish_results' => $varnish_results,
            );
        },
        'permission_callback' => '__return_true',
    ) );
} );

// Test endpoints for bug fixes verification.
add_action( 'rest_api_init', function() {
    // Test devmode notice logic: ensures notice is returned when devmode is active.
    register_rest_route( 'test/v1', '/devmode-notice-check', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $action = $req->get_param( 'action' ); // 'activate', 'deactivate', 'check'

            if ( ! class_exists( 'VarnishDebug' ) || ! class_exists( 'VarnishPurger' ) ) {
                return new WP_Error( 'no_classes', 'Required classes not available', array( 'status' => 500 ) );
            }

            if ( 'activate' === $action ) {
                VarnishDebug::devmode_toggle( 'activate' );
            } elseif ( 'deactivate' === $action ) {
                VarnishDebug::devmode_toggle( 'deactivate' );
            }

            $is_active = VarnishDebug::devmode_check();
            $devmode_option = get_site_option( 'vhp_varnish_devmode', VarnishPurger::$devmode );

            // Simulate what devmode_is_active_notice() does - capture the message logic.
            $notice_would_display = false;
            $notice_message = '';

            if ( defined( 'VHP_DEVMODE' ) && VHP_DEVMODE ) {
                $notice_would_display = true;
                $notice_message = 'activated via wp-config';
            } else {
                // This is the fixed logic - should be $devmode['active'], not ! $devmode['active']
                if ( isset( $devmode_option['active'] ) && $devmode_option['active'] ) {
                    $notice_would_display = true;
                    $notice_message = 'active for next ' . human_time_diff( time(), $devmode_option['expire'] );
                }
            }

            return array(
                'ok'                   => true,
                'devmode_check'        => $is_active,
                'option_active'        => isset( $devmode_option['active'] ) ? (bool) $devmode_option['active'] : false,
                'option_expire'        => isset( $devmode_option['expire'] ) ? (int) $devmode_option['expire'] : 0,
                'notice_would_display' => $notice_would_display,
                'notice_message'       => $notice_message,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Test Site Health debug log handling with malformed data.
    register_rest_route( 'test/v1', '/health-check-debug-log', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $test_data = $req->get_param( 'debug_log' );

            // Save malformed or valid test data to the option.
            if ( null !== $test_data ) {
                update_site_option( 'vhp_varnish_debug', $test_data );
            }

            // Now call the health check function and capture if it errors.
            $error_occurred = false;
            $result = null;

            try {
                if ( function_exists( 'vhp_site_status_caching_test' ) ) {
                    $result = vhp_site_status_caching_test();
                }
            } catch ( Exception $e ) {
                $error_occurred = true;
            } catch ( Error $e ) {
                $error_occurred = true;
            }

            return array(
                'ok'             => true,
                'error_occurred' => $error_occurred,
                'result_status'  => is_array( $result ) && isset( $result['status'] ) ? $result['status'] : null,
                'result_label'   => is_array( $result ) && isset( $result['label'] ) ? $result['label'] : null,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Test settings sanitization with edge cases.
    // Note: We simulate the sanitization logic directly instead of calling the
    // VarnishStatus methods, because those methods call add_settings_error()
    // which requires admin context.
    register_rest_route( 'test/v1', '/test-settings-sanitize', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            $setting_type = $req->get_param( 'type' ); // 'maxposts', 'ip', 'devmode'
            $input_value  = $req->get_param( 'value' );

            switch ( $setting_type ) {
                case 'maxposts':
                    // Simulate settings_maxposts_sanitize logic.
                    $existing = (int) get_site_option( 'vhp_varnish_max_posts_before_all', 50 );

                    if ( empty( $input_value ) ) {
                        // Fixed: now returns existing value instead of void.
                        $result = $existing;
                    } elseif ( is_numeric( $input_value ) ) {
                        $result = (int) $input_value;
                    } else {
                        $result = $existing; // Invalid, keep existing.
                    }

                    return array(
                        'ok'       => true,
                        'type'     => 'maxposts',
                        'input'    => $input_value,
                        'result'   => $result,
                        'existing' => $existing,
                    );

                case 'ip':
                    // Simulate settings_ip_sanitize logic.
                    if ( empty( $input_value ) ) {
                        // Fixed: now returns empty string instead of void.
                        $result = '';
                    } elseif ( strpos( $input_value, ',' ) !== false ) {
                        $ips = array_map( 'trim', explode( ',', $input_value ) );
                        $result = implode( ', ', array_map( 'sanitize_text_field', $ips ) );
                    } else {
                        $result = sanitize_text_field( $input_value );
                    }

                    return array(
                        'ok'     => true,
                        'type'   => 'ip',
                        'input'  => $input_value,
                        'result' => $result,
                    );

                case 'devmode':
                    // Simulate settings_devmode_sanitize logic.
                    $expire = time() + DAY_IN_SECONDS;

                    if ( empty( $input_value ) ) {
                        // Fixed: now returns empty array instead of void.
                        $result = array();
                    } else {
                        $result = array(
                            'active' => isset( $input_value['active'] ) ? (bool) $input_value['active'] : false,
                            // Fixed: now uses is_numeric() instead of is_int() for form input.
                            'expire' => ( isset( $input_value['expire'] ) && is_numeric( $input_value['expire'] ) )
                                ? (int) $input_value['expire']
                                : $expire,
                        );
                    }

                    return array(
                        'ok'     => true,
                        'type'   => 'devmode',
                        'input'  => $input_value,
                        'result' => $result,
                    );

                default:
                    return new WP_Error( 'unknown_type', 'Unknown setting type', array( 'status' => 400 ) );
            }
        },
        'permission_callback' => '__return_true',
    ) );

    // Test uninstall options cleanup - verify which options exist.
    register_rest_route( 'test/v1', '/check-plugin-options', array(
        'methods'  => 'GET',
        'callback' => function( WP_REST_Request $req ) {
            // List of all options the plugin should clean up.
            $expected_options = array(
                'vhp_varnish_url',
                'vhp_varnish_ip',
                'vhp_varnish_extra_purge_header_name',
                'vhp_varnish_extra_purge_header_value',
                'vhp_varnish_devmode',
                'vhp_varnish_max_posts_before_all',
                'vhp_varnish_use_tags',
                'vhp_varnish_debug',
                'vhp_varnish_purge_queue',
                'vhp_varnish_last_queue_run',
            );

            $existing_options = array();
            foreach ( $expected_options as $opt ) {
                $val = get_site_option( $opt );
                if ( false !== $val ) {
                    $existing_options[ $opt ] = true;
                }
            }

            return array(
                'ok'               => true,
                'expected_options' => $expected_options,
                'existing_options' => $existing_options,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    // Create all plugin options for uninstall testing.
    register_rest_route( 'test/v1', '/create-plugin-options', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            // Create all options so we can verify uninstall cleans them.
            update_site_option( 'vhp_varnish_url', 'http://example.com/' );
            update_site_option( 'vhp_varnish_ip', '127.0.0.1' );
            update_site_option( 'vhp_varnish_extra_purge_header_name', 'X-Test-Header' );
            update_site_option( 'vhp_varnish_extra_purge_header_value', 'test-value' );
            update_site_option( 'vhp_varnish_devmode', array( 'active' => false, 'expire' => time() ) );
            update_site_option( 'vhp_varnish_max_posts_before_all', 50 );
            update_site_option( 'vhp_varnish_use_tags', 0 );
            update_site_option( 'vhp_varnish_debug', array( 'http://example.com/' => array() ) );
            update_site_option( 'vhp_varnish_purge_queue', array( 'full' => false, 'urls' => array(), 'tags' => array() ) );
            update_site_option( 'vhp_varnish_last_queue_run', time() );

            return array( 'ok' => true, 'message' => 'All plugin options created' );
        },
        'permission_callback' => '__return_true',
    ) );
} );

// Test endpoint to simulate manual purge actions (admin bar clicks).
// This exercises the execute_purge() GET param handling to verify manual purges
// are always immediate regardless of cron mode.
add_action( 'rest_api_init', function() {
    register_rest_route( 'test/v1', '/simulate-manual-purge', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            if ( ! class_exists( 'VarnishPurger' ) ) {
                return new WP_Error( 'no_purger', 'VarnishPurger class not available', array( 'status' => 500 ) );
            }

            $type = $req->get_param( 'type' ); // 'all' or 'url'
            $url  = $req->get_param( 'url' );

            // Set up as admin user for nonce validation.
            $admin = get_user_by( 'login', 'admin' );
            if ( $admin ) {
                wp_set_current_user( $admin->ID );
            }

            // Capture any purge requests.
            $captured = array();
            $capture_callback = function( $headers ) use ( &$captured ) {
                $captured[] = $headers;
                return $headers;
            };
            add_filter( 'varnish_http_purge_headers', $capture_callback, 9999 );

            // Create a VarnishPurger instance.
            $vp = new VarnishPurger();

            // Simulate the GET parameters and nonce that execute_purge() checks.
            // We create the nonce and set up $_GET to trigger the manual purge path.
            if ( 'all' === $type ) {
                // Simulate vhp_flush_all click.
                $nonce = wp_create_nonce( 'vhp-flush-all' );
                $_GET['vhp_flush_all'] = '1';
                $_GET['_wpnonce']      = $nonce;
                $_REQUEST['_wpnonce']  = $nonce;
            } elseif ( 'url' === $type && ! empty( $url ) ) {
                // Simulate vhp_flush_do=<url> click.
                $nonce = wp_create_nonce( 'vhp-flush-do' );
                $_GET['vhp_flush_do'] = $url;
                $_GET['_wpnonce']     = $nonce;
                $_REQUEST['_wpnonce'] = $nonce;
            } else {
                return new WP_Error( 'bad_type', 'type must be "all" or "url" (with url param)', array( 'status' => 400 ) );
            }

            // Call execute_purge() which checks $_GET and performs the purge.
            $vp->execute_purge();

            // Clean up GET params.
            unset( $_GET['vhp_flush_all'], $_GET['vhp_flush_do'], $_GET['_wpnonce'] );
            unset( $_REQUEST['_wpnonce'] );

            remove_filter( 'varnish_http_purge_headers', $capture_callback, 9999 );

            // Check the queue status to verify purge was immediate (not queued).
            $queue = get_site_option( VarnishPurger::PURGE_QUEUE_OPTION, array() );

            // Normalize queue structure for consistent JSON output.
            $queue_normalized = array(
                'full' => ! empty( $queue['full'] ),
                'urls' => isset( $queue['urls'] ) && is_array( $queue['urls'] ) ? array_values( $queue['urls'] ) : array(),
                'tags' => isset( $queue['tags'] ) && is_array( $queue['tags'] ) ? array_values( $queue['tags'] ) : array(),
            );

            return array(
                'ok'               => true,
                'type'             => $type,
                'purge_captured'   => count( $captured ) > 0,
                'captured_count'   => count( $captured ),
                'captured_headers' => $captured,
                'queue_after'      => $queue_normalized,
            );
        },
        'permission_callback' => '__return_true',
    ) );
} );

// Reset all plugin options to known defaults for test isolation.
add_action( 'rest_api_init', function() {
    register_rest_route( 'test/v1', '/reset-options', array(
        'methods'  => 'POST',
        'callback' => function( WP_REST_Request $req ) {
            // Delete all plugin options first to ensure clean state.
            $options_to_delete = array(
                'vhp_varnish_url',
                'vhp_varnish_ip',
                'vhp_varnish_extra_purge_header_name',
                'vhp_varnish_extra_purge_header_value',
                'vhp_varnish_devmode',
                'vhp_varnish_max_posts_before_all',
                'vhp_varnish_use_tags',
                'vhp_varnish_debug',
                'vhp_varnish_purge_queue',
                'vhp_varnish_last_queue_run',
                'vhp_varnish_force_cron_mode',
            );

            foreach ( $options_to_delete as $opt ) {
                delete_site_option( $opt );
            }

            // Set known default values.
            $home_url = home_url( '/' );
            update_site_option( 'vhp_varnish_url', $home_url );
            update_site_option( 'vhp_varnish_ip', '' );
            update_site_option( 'vhp_varnish_devmode', array( 'active' => false, 'expire' => 0 ) );
            update_site_option( 'vhp_varnish_max_posts_before_all', 50 );
            update_site_option( 'vhp_varnish_use_tags', 0 );
            update_site_option( 'vhp_varnish_debug', array( $home_url => array() ) );

            // Ensure cron mode is off by default for predictable test behavior.
            update_site_option( 'vhp_varnish_force_cron_mode', 'off' );

            // Clear WordPress object cache to ensure all PHP-FPM workers see the
            // updated option values on their next request. This is critical for
            // test isolation - without this, workers may serve responses with
            // stale option values from previous tests.
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            foreach ( $options_to_delete as $opt ) {
                wp_cache_delete( $opt, 'options' );
                wp_cache_delete( $opt, 'site-options' );
            }

            return array(
                'ok'       => true,
                'reset'    => $options_to_delete,
                'defaults' => array(
                    'vhp_varnish_url'                => $home_url,
                    'vhp_varnish_ip'                 => '',
                    'vhp_varnish_devmode'            => array( 'active' => false, 'expire' => 0 ),
                    'vhp_varnish_max_posts_before_all' => 50,
                    'vhp_varnish_use_tags'           => 0,
                    'vhp_varnish_force_cron_mode'    => 'off',
                ),
            );
        },
        'permission_callback' => '__return_true',
    ) );
} );

// Test endpoint to exercise the admin bar rendering (varnish_rightnow_adminbar).
// This ensures the code path with get_current_blog_id() and permission checks runs without error.
add_action( 'rest_api_init', function() {
    register_rest_route( 'test/v1', '/adminbar-render', array(
        'methods'  => 'GET',
        'callback' => function( WP_REST_Request $req ) {
            // Set up as admin user to exercise permission checks.
            $admin = get_user_by( 'login', 'admin' );
            if ( $admin ) {
                wp_set_current_user( $admin->ID );
            }

            // Create a mock admin bar object to capture nodes.
            $nodes = array();
            $mock_admin_bar = new class( $nodes ) {
                private $nodes;
                public function __construct( &$nodes ) {
                    $this->nodes = &$nodes;
                }
                public function add_node( $args ) {
                    $this->nodes[] = $args;
                }
            };

            // Call the admin bar method.
            $purger = new VarnishPurger();
            $purger->varnish_rightnow_adminbar( $mock_admin_bar );

            return array(
                'ok'         => true,
                'nodes'      => $nodes,
                'node_count' => count( $nodes ),
                'multisite'  => is_multisite(),
                'blog_id'    => get_current_blog_id(),
            );
        },
        'permission_callback' => '__return_true',
    ) );
} );

