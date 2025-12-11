<?php
/**
 * Health Check Code
 * @package varnish-http-purge
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

// Health Check Test
add_filter( 'site_status_tests', 'vhp_add_site_status_tests' );

function vhp_add_site_status_tests( $tests ) {
	$tests['direct']['proxy_cache_purge_caching'] = array(
		'label' => __( 'Proxy Cache Purge Status', 'varnish-http-purge' ),
		'test'  => 'vhp_site_status_caching_test',
	);
	return $tests;
}

function vhp_site_status_caching_test() {

	// Check the debug log.
	$debug_log     = get_site_option( 'vhp_varnish_debug' );
	$debug_results = array();
	foreach ( $debug_log as $site => $results ) {
		foreach ( $results as $item => $content ) {
			$sitename = ( VarnishPurger::the_home_url() !== $site ) ? 'Site: ' . $site . '<br />' : '';
			// Log cache not working.
			if ( 'Cache Service' === $item && 'notice' === $content['icon'] ) {
				$debug_results[ $item ] = $sitename . $content['message'];
			}
			// Log all critical warnings.
			if ( isset( $content['icon'] ) && 'bad' === $content['icon'] ) {
				$debug_results[ $item ] = $sitename . $content['message'];
			}
		}
	}

	// Defaults, all is good:
	$result = array(
		'label'       => __( 'Proxy Cache Purge is working', 'varnish-http-purge' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Performance', 'varnish-http-purge' ),
			'color' => 'blue',
		),
		'description' => sprintf(
			'<p>%s</p>',
			__( 'Caching can help load your site more quickly for visitors. You\'re doing great!', 'varnish-http-purge' )
		),
		'actions'     => sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=varnish-check-caching' ) ),
			__( 'Check Caching Status', 'varnish-http-purge' )
		),
		'test'        => 'caching_plugin',
	);

	// If we're in dev mode....
	if ( VarnishDebug::devmode_check() ) {
		$result['status']      = 'recommended';
		$result['label']       = __( 'Proxy Cache Purge is in development mode', 'varnish-http-purge' );
		$result['description'] = sprintf(
			'<p>%s</p>',
			__( 'Proxy Cache Purge is active but in dev mode, which means it will not serve cached content to your users. If this is intentional, carry on. Otherwise you should re-enable caching.', 'varnish-http-purge' )
		);
		$result['actions']     = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=varnish-page' ) ),
			__( 'Enable Caching' )
		);
	} elseif ( ! empty( $debug_results ) && '' !== $debug_results ) {
		$count = count( $debug_results );
		$desc  = sprintf(
			/* translators: %d: Number of caching issues reported. */
			_n(
				'The most recent cache status check reported %d issue.',
				'The most recent cache status check reported %d issues.',
				$count,
				'varnish-http-purge'
			),
			$count
		);

		$result['status'] = 'critical';
		// Translators: %d is the number of issues reported
		$result['label']        = sprintf( __( 'Proxy Cache Purge has reported caching errors (%s)', 'varnish-http-purge' ), $count );
		$result['description']  = sprintf(
			'<p>%s</p>',
			$desc
		);
		$result['description'] .= '<ul>';
		foreach ( $debug_results as $key => $value ) {
			$result['description'] .= '<li><strong>' . $key . '</strong>: ' . $value . '</li>';
		}
		$result['description'] .= '</ul>';
	} elseif ( class_exists( 'VarnishPurger' ) && VarnishPurger::is_cron_purging_enabled_static() ) {
		// Inspect the async purge queue when cron-mode is enabled and the
		// basic cache checks look healthy.
		$queue = get_site_option( VarnishPurger::PURGE_QUEUE_OPTION, array() );

		$full       = ( isset( $queue['full'] ) && $queue['full'] );
		$urls       = ( isset( $queue['urls'] ) && is_array( $queue['urls'] ) ) ? $queue['urls'] : array();
		$tags       = ( isset( $queue['tags'] ) && is_array( $queue['tags'] ) ) ? $queue['tags'] : array();
		$created_at = isset( $queue['created_at'] ) ? (int) $queue['created_at'] : 0;

		$urls_count = count( $urls );
		$tags_count = count( $tags );

		$has_backlog = ( $full || $urls_count || $tags_count );
		$queue_age   = 0;

		if ( $has_backlog && $created_at > 0 ) {
			$queue_age = time() - $created_at;
		}

		$max_age    = (int) apply_filters( 'vhp_purge_queue_health_max_age', 15 * MINUTE_IN_SECONDS );
		$max_items  = (int) apply_filters( 'vhp_purge_queue_health_max_items', 500 );
		$item_count = $urls_count + $tags_count;

		$age_exceeded  = ( $max_age > 0 && $queue_age > $max_age );
		$size_exceeded = ( $max_items > 0 && $item_count > $max_items );
		$raise_warning = ( $has_backlog && ( $age_exceeded || $size_exceeded ) );

		if ( $raise_warning ) {
			$result['status'] = 'recommended';
			$result['label']  = __( 'Proxy Cache Purge queue may be stuck', 'varnish-http-purge' );

			$details = sprintf(
				/* translators: 1: URL count, 2: tag count. */
				__( 'The async purge queue currently holds %1$d URLs and %2$d cache tags.', 'varnish-http-purge' ),
				(int) $urls_count,
				(int) $tags_count
			);

			if ( $queue_age > 0 ) {
				$details .= ' ';
				$details .= sprintf(
					/* translators: %s: Human-readable time difference. */
					__( 'The oldest entry has been queued for %s.', 'varnish-http-purge' ),
					human_time_diff( $created_at, time() )
				);
			}

			$result['description'] = sprintf(
				'<p>%s</p><p>%s</p>',
				esc_html__( 'Proxy Cache Purge is configured to run purges in the background via WP-Cron. A large or very old queue can indicate that WP-Cron or your system cron is not running as expected.', 'varnish-http-purge' ),
				esc_html( $details )
			);

			$result['actions'] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=varnish-page' ) ),
				esc_html__( 'Review Proxy Cache Purge settings', 'varnish-http-purge' )
			);
		}
	}

	return $result;
}
