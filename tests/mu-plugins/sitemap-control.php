<?php
/*
Plugin Name: Sitemap Test Control (MU)
Description: REST endpoints and filters for the XML sitemap purge tests
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Sitemap page size override, so tests can paginate a sitemap with a few posts.
add_filter( 'wpseo_sitemap_entries_per_page', function( $per_page ) {
	$override = (int) get_site_option( 'vhp_test_sitemap_per_page', 0 );
	return $override > 0 ? $override : $per_page;
} );

// Force the sitemap purge feature on or off regardless of the detected plugin.
add_filter( 'vhp_purge_sitemaps', function( $enabled ) {
	$force = get_site_option( 'vhp_test_sitemap_force', '' );
	if ( 'on' === $force ) {
		return true;
	}
	if ( 'off' === $force ) {
		return false;
	}
	return $enabled;
} );

// Record every PURGE request the plugin sends while the log is switched on.
add_action( 'after_purge_url', function( $parsed_url, $purgeme, $response, $headers ) {
	if ( ! get_site_option( 'vhp_test_purge_log_on' ) ) {
		return;
	}
	$log   = get_site_option( 'vhp_test_purge_log', array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array(
		'url'    => $purgeme,
		'method' => isset( $headers['X-Purge-Method'] ) ? $headers['X-Purge-Method'] : '',
	);
	update_site_option( 'vhp_test_purge_log', $log );
}, 10, 4 );

add_action( 'rest_api_init', function() {
	$open = array( 'permission_callback' => '__return_true' );

	// Activate or deactivate Yoast SEO (installed by tests/setup.sh).
	register_rest_route( 'test/v1', '/sitemap/yoast', array(
		'methods'  => 'POST',
		'callback' => function( WP_REST_Request $req ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$plugin = 'wordpress-seo/wp-seo.php';
			if ( $req->get_param( 'active' ) ) {
				$result = activate_plugin( $plugin );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			} else {
				deactivate_plugins( $plugin );
			}
			return array( 'ok' => true, 'active' => is_plugin_active( $plugin ) );
		},
	) + $open );

	// Set or clear the test overrides: {per_page: int, force: "on"|"off"|""}.
	register_rest_route( 'test/v1', '/sitemap/config', array(
		'methods'  => 'POST',
		'callback' => function( WP_REST_Request $req ) {
			$per_page = (int) $req->get_param( 'per_page' );
			$force    = (string) $req->get_param( 'force' );
			if ( $per_page > 0 ) {
				update_site_option( 'vhp_test_sitemap_per_page', $per_page );
			} else {
				delete_site_option( 'vhp_test_sitemap_per_page' );
			}
			if ( in_array( $force, array( 'on', 'off' ), true ) ) {
				update_site_option( 'vhp_test_sitemap_force', $force );
			} else {
				delete_site_option( 'vhp_test_sitemap_force' );
			}
			return array( 'ok' => true, 'per_page' => $per_page, 'force' => $force );
		},
	) + $open );

	// Sitemap purge URLs: for one post (?post_id=) or the whole site (?all=1).
	register_rest_route( 'test/v1', '/sitemap/urls', array(
		'methods'  => 'GET',
		'callback' => function( WP_REST_Request $req ) {
			$post_id = (int) $req->get_param( 'post_id' );
			$urls    = $post_id > 0 ? VarnishSitemaps::urls_for_post( $post_id ) : VarnishSitemaps::all_urls();
			return array( 'ok' => true, 'enabled' => VarnishSitemaps::is_enabled(), 'urls' => $urls );
		},
	) + $open );

	// What "wp varnish purge --sitemaps" does: purge every sitemap URL.
	register_rest_route( 'test/v1', '/sitemap/purge-all', array(
		'methods'  => 'POST',
		'callback' => function() {
			$urls = VarnishSitemaps::all_urls();
			foreach ( $urls as $url ) {
				VarnishPurger::purge_url( $url );
			}
			return array( 'ok' => true, 'urls' => $urls );
		},
	) + $open );

	// Change a post's status through the normal WordPress API.
	register_rest_route( 'test/v1', '/sitemap/post-status', array(
		'methods'  => 'POST',
		'callback' => function( WP_REST_Request $req ) {
			$post_id = (int) $req->get_param( 'post_id' );
			$status  = (string) $req->get_param( 'status' );
			if ( 'trash' === $status ) {
				$result = wp_trash_post( $post_id );
			} else {
				$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ), true );
			}
			if ( is_wp_error( $result ) || ! $result ) {
				return new WP_Error( 'status_failed', 'Status change failed', array( 'status' => 500 ) );
			}
			return array( 'ok' => true, 'status' => get_post_status( $post_id ) );
		},
	) + $open );

	// Rename a term.
	register_rest_route( 'test/v1', '/sitemap/term', array(
		'methods'  => 'POST',
		'callback' => function( WP_REST_Request $req ) {
			$result = wp_update_term(
				(int) $req->get_param( 'term_id' ),
				(string) $req->get_param( 'taxonomy' ),
				array( 'name' => (string) $req->get_param( 'name' ) )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true );
		},
	) + $open );

	// Update the admin user's profile.
	register_rest_route( 'test/v1', '/sitemap/user', array(
		'methods'  => 'POST',
		'callback' => function( WP_REST_Request $req ) {
			$result = wp_update_user( array( 'ID' => 1, 'description' => (string) $req->get_param( 'description' ) ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true );
		},
	) + $open );

	// Save one Yoast SEO option key, as Yoast itself does: {key, value}.
	register_rest_route( 'test/v1', '/sitemap/yoast-option', array(
		'methods'  => 'POST',
		'callback' => function( WP_REST_Request $req ) {
			if ( ! class_exists( 'WPSEO_Options' ) ) {
				return new WP_Error( 'no_yoast', 'Yoast SEO is not active', array( 'status' => 400 ) );
			}
			$key = (string) $req->get_param( 'key' );
			WPSEO_Options::set( $key, $req->get_param( 'value' ) );
			return array( 'ok' => true, 'value' => WPSEO_Options::get( $key ) );
		},
	) + $open );

	// Start a fresh PURGE log.
	register_rest_route( 'test/v1', '/purge-log/reset', array(
		'methods'  => 'POST',
		'callback' => function( WP_REST_Request $req ) {
			update_site_option( 'vhp_test_purge_log', array() );
			if ( false === $req->get_param( 'enabled' ) ) {
				delete_site_option( 'vhp_test_purge_log_on' );
			} else {
				update_site_option( 'vhp_test_purge_log_on', 1 );
			}
			return array( 'ok' => true );
		},
	) + $open );

	// Read the PURGE log.
	register_rest_route( 'test/v1', '/purge-log', array(
		'methods'  => 'GET',
		'callback' => function() {
			$log = get_site_option( 'vhp_test_purge_log', array() );
			return array( 'ok' => true, 'log' => is_array( $log ) ? array_values( $log ) : array() );
		},
	) + $open );
} );
