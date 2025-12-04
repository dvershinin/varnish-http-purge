<?php
/**
 * Uninstall
 * @package varnish-http-purge
*/

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

// Delete site options.
delete_site_option( 'vhp_varnish_url' );
delete_site_option( 'vhp_varnish_ip' );
delete_site_option( 'vhp_varnish_extra_purge_header_name' );
delete_site_option( 'vhp_varnish_extra_purge_header_value' );
delete_site_option( 'vhp_varnish_devmode' );
delete_site_option( 'vhp_varnish_max_posts_before_all' );
