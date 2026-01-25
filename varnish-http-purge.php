<?php
/**
 * Plugin Name: Proxy Cache Purge
 * Plugin URI: https://github.com/dvershinin/varnish-http-purge
 * Description: Automatically empty cached pages when content on your site is modified.
 * Version: 5.6.4
 * Requires at least: 5.0
 * Requires PHP: 5.6
 * Author: Mika Epstein, Danila Vershinin
 * Author URI: https://halfelf.org/
 * License: Apache License 2.0
 * License URI: https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain: varnish-http-purge
 * Network: true
 *
 * @package varnish-http-purge
 *
 * Copyright 2016-2023 Mika Epstein (email: ipstenu@halfelf.org)
 * Copyright 2023 Danila Vershinin (email: ciapnz@gmail.com)
 *
 * This file is part of Proxy Cache Purge (formerly Varnish HTTP Purge), a
 * plugin for WordPress.
 *
 * Proxy Cache Purge is free software: you can redistribute it and/or modify
 * it under the terms of the Apache License 2.0 license.
 *
 * Proxy Cache Purge is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

/**
 * Purge Class
 *
 * @since 2.0
 */
class VarnishPurger {

	/**
	 * Version Number
	 * @var string
	 */
	public static $version = '5.5.3';

	/**
	 * List of URLs to be purged
	 *
	 * (default value: array())
	 *
	 * @var array
	 * @access protected
	 */
	protected $purge_urls = array();

	/**
	 * Devmode options
	 *
	 * (default value: array())
	 *
	 * @var array
	 * @access public
	 * @static
	 */
	public static $devmode = array();

	/**
	 * Site option name for the async purge queue.
	 *
	 * @since 5.5.0
	 * @var string
	 */
	const PURGE_QUEUE_OPTION = 'vhp_varnish_purge_queue';

	/**
	 * Schema version for the async purge queue.
	 *
	 * @since 5.5.0
	 * @var int
	 */
	const PURGE_QUEUE_VERSION = 1;

	/**
	 * Maximum number of URLs to keep in the async purge queue.
	 *
	 * @since 5.5.0
	 * @var int
	 */
	const PURGE_QUEUE_MAX_URLS = 1000;

	/**
	 * Maximum number of tags to keep in the async purge queue.
	 *
	 * @since 5.5.0
	 * @var int
	 */
	const PURGE_QUEUE_MAX_TAGS = 1000;

	/**
	 * Maximum age (in seconds) before a granular queue is upgraded
	 * to a full-site purge for safety.
	 *
	 * @since 5.5.0
	 * @var int
	 */
	const PURGE_QUEUE_MAX_AGE = 900;

	/**
	 * Init
	 *
	 * @since 2.0
	 * @access public
	 */
	public function __construct() {
		defined( 'VHP_VARNISH_IP' ) || define( 'VHP_VARNISH_IP', false );
		defined( 'VHP_DEVMODE' ) || define( 'VHP_DEVMODE', false );
		defined( 'VHP_DOMAINS' ) || define( 'VHP_DOMAINS', false );
		defined( 'VHP_VARNISH_EXTRA_PURGE_HEADER' ) || define( 'VHP_VARNISH_EXTRA_PURGE_HEADER', false );
		defined( 'VHP_EXCLUDED_POST_STATUSES' ) || define( 'VHP_EXCLUDED_POST_STATUSES', false );
		defined( 'VHP_DISABLE_CRON_PURGING' ) || define( 'VHP_DISABLE_CRON_PURGING', false );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( &$this, 'settings_link' ) );

		// Development mode defaults to off.
		self::$devmode = array(
			'active' => false,
			'expire' => time(),
		);
		if ( ! get_site_option( 'vhp_varnish_devmode' ) ) {
			update_site_option( 'vhp_varnish_devmode', self::$devmode );
		}

		// Default URL is home.
		if ( ! get_site_option( 'vhp_varnish_url' ) ) {
			update_site_option( 'vhp_varnish_url', $this->the_home_url() );
		}

		// Default IP is nothing.
		if ( ! get_site_option( 'vhp_varnish_ip' ) && ! VHP_VARNISH_IP ) {
			update_site_option( 'vhp_varnish_ip', '' );
		}

		// Default Debug is the home.
		if ( ! get_site_option( 'vhp_varnish_debug' ) ) {
			update_site_option( 'vhp_varnish_debug', array( $this->the_home_url() => array() ) );
		}

		// Default Max posts to purge before purge all happens instead.
		if ( ! get_site_option( 'vhp_varnish_max_posts_before_all' ) ) {
			update_site_option( 'vhp_varnish_max_posts_before_all', 50 );
		}

		// Release the hounds!
		add_action( 'init', array( &$this, 'init' ) );
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'import_start', array( &$this, 'import_start' ) );
		add_action( 'import_end', array( &$this, 'import_end' ) );

		// Check if there's an upgrade
		add_action( 'upgrader_process_complete', array( &$this, 'check_upgrades' ), 10, 2 );
	}

	/**
	 * Admin Init
	 *
	 * @since 4.1
	 * @access public
	 */
	public function admin_init() {
		global $pagenow;

		// If WordPress.com Master Bar is active, show the activity box.
		if ( class_exists( 'Jetpack' ) && Jetpack::is_module_active( 'masterbar' ) ) {
			add_action( 'activity_box_end', array( $this, 'varnish_rightnow' ), 100 );
		}

		// Failure: Pre WP 4.7.
		if ( version_compare( get_bloginfo( 'version' ), '4.7', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			add_action( 'admin_notices', array( $this, 'require_wp_version_notice' ) );
			return;
		}

		// Admin notices.
		if ( current_user_can( 'manage_options' ) && 'site-health.php' !== $pagenow ) {

			// Warning: Debug is active.
			if ( VarnishDebug::devmode_check() ) {
				add_action( 'admin_notices', array( $this, 'devmode_is_active_notice' ) );
			}

			// Warning: No Pretty Permalinks!
			if ( '' === get_site_option( 'permalink_structure' ) ) {
				add_action( 'admin_notices', array( $this, 'require_pretty_permalinks_notice' ) );
			}

			// Recommendation: Cacheability Pro plugin.
			add_action( 'admin_notices', array( $this, 'cacheability_pro_notice' ) );

			// AJAX handler for dismissing Cacheability Pro notice.
			add_action( 'wp_ajax_vhp_dismiss_cacheability_notice', array( $this, 'ajax_dismiss_cacheability_notice' ) );
		}
	}

	/**
	 * Plugin Init
	 *
	 * @since 1.0
	 * @access public
	 */
	public function init() {
		global $blog_id, $wp_db_version;

		// If the DB version we detect isn't the same as the version core thinks
		// we will flush DB cache. This may cause double dumping in some cases but
		// should not be harmful.
		if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) && (int) get_option( 'db_version' ) !== $wp_db_version ) {
			wp_cache_flush();
		}

		// If Dev Mode is true, kill caching.
		if ( VarnishDebug::devmode_check() ) {
			if ( ! is_admin() ) {
				// Sessions used to break PHP caching.
				// @codingStandardsIgnoreStart
				if ( ! is_user_logged_in() && session_status() != PHP_SESSION_ACTIVE ) {
					@session_start();
				}
				// @codingStandardsIgnoreEnd

				// Add nocache to CSS and JS.
				add_filter( 'style_loader_src', array( 'VarnishDebug', 'nocache_cssjs' ), 10, 2 );
				add_filter( 'script_loader_src', array( 'VarnishDebug', 'nocache_cssjs' ), 10, 2 );
			}
		}

		// get my events.
		$events       = $this->get_register_events();
		$no_id_events = $this->get_no_id_events();

		// make sure we have events and they're in an array.
		if ( ! empty( $events ) && ! empty( $no_id_events ) ) {

			// Force it to be an array, in case someone's stupid.
			$events       = (array) $events;
			$no_id_events = (array) $no_id_events;

			// Add the action for each event.
			foreach ( $events as $event ) {
				if ( in_array( $event, $no_id_events, true ) ) {
					// These events have no post ID and, thus, will perform a full purge.
					add_action( $event, array( $this, 'execute_purge_no_id' ) );
				} else {
					add_action( $event, array( $this, 'purge_post' ), 10, 2 );
				}
			}
		}

		add_action( 'shutdown', array( $this, 'execute_purge' ) );

		// Register the async purge queue processor for WP-Cron.
		add_action( 'vhp_process_purge_queue', array( $this, 'process_purge_queue' ) );

		// Handle scheduled posts transitioning to publish (future → publish).
		// This fires when WP-Cron publishes a scheduled post and ensures the
		// cache is purged synchronously, bypassing the async queue.
		add_action( 'transition_post_status', array( $this, 'purge_on_future_to_publish' ), 10, 3 );

		// Success: Admin notice when purging.
		if ( ( isset( $_GET['vhp_flush_all'] ) && check_admin_referer( 'vhp-flush-all' ) ) ||
			( isset( $_GET['vhp_flush_do'] ) && check_admin_referer( 'vhp-flush-do' ) ) ) {
			if ( isset( $_GET['vhp_flush_do'] ) && 'devmode' === $_GET['vhp_flush_do'] && isset( $_GET['vhp_set_devmode'] ) ) {
				VarnishDebug::devmode_toggle( esc_attr( $_GET['vhp_set_devmode'] ) );
				add_action( 'admin_notices', array( $this, 'admin_message_devmode' ) );
			} else {
				add_action( 'admin_notices', array( $this, 'admin_message_purge' ) );
			}
		}

		// Add Admin Bar.
		add_action( 'admin_bar_menu', array( $this, 'varnish_rightnow_adminbar' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'custom_css' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'custom_css' ) );
	}

	/**
	 * Check if something has upgraded and try to flush the DB cache.
	 * This runs for ALL upgrades (theme, plugin, and core) to account for
	 * the complex nature that are upgrades.
	 *
	 * @param  array $upgrader_object WP_Upgrader instance (unused).
	 * @param  array $hook_extra Extra hook arguments (unused).
	 * @since 4.8
	 */
	public function check_upgrades( $upgrader_object, $hook_extra ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Pause caching if Importer was started
	 * @since 4.8
	 */
	public function import_start() {
		VarnishDebug::devmode_toggle( 'activate' );
	}

	/**
	 * Resume caching if Importer has ended
	 * @since 4.8
	 */
	public function import_end() {
		VarnishDebug::devmode_toggle( 'deactivate' );
	}

	/**
	 * Purge Message
	 * Informs of a successful purge
	 *
	 * @since 4.6
	 */
	public function admin_message_purge() {
		echo '<div id="message" class="notice notice-success fade is-dismissible"><p><strong>' . esc_html__( 'Cache emptied!', 'varnish-http-purge' ) . '</strong></p></div>';
	}

	/**
	 * Devmode Message
	 * Informs of a toggle in Devmode
	 *
	 * @since 4.6
	 */
	public function admin_message_devmode() {
		$message = ( VarnishDebug::devmode_check() ) ? __( 'Development Mode activated for the next 24 hours.', 'varnish-http-purge' ) : __( 'Development Mode deactivated.', 'varnish-http-purge' );
		echo '<div id="message" class="notice notice-success fade is-dismissible"><p><strong>' . wp_kses_post( $message ) . '</strong></p></div>';
	}

	/**
	 * Add settings link on plugin list
	 */
	public function settings_link( $links ) {
		$settings_link = '<a href="admin.php?page=varnish-page">' . __( 'Settings', 'varnish-http-purge' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Check if cron-based purging is enabled (static helper).
	 *
	 * This is safe to call without an instantiated VarnishPurger object and is
	 * primarily used by tests and admin/health UIs.
	 *
	 * @since 5.5.0
	 * @return bool
	 */
	public static function is_cron_purging_enabled_static() {
		// Allow users to force-disable cron purging via wp-config.php constant.
		if ( defined( 'VHP_DISABLE_CRON_PURGING' ) && VHP_DISABLE_CRON_PURGING ) {
			return false;
		}

		$enabled = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );

		/**
		 * Filter whether the async purge queue + WP-Cron should be used.
		 *
		 * Returning true here enables cron-mode, causing purge operations
		 * initiated by this plugin to be queued and processed in the
		 * background instead of being executed synchronously during the
		 * request.
		 *
		 * This is primarily useful for environments that run a real system
		 * cron hitting wp-cron.php, and for tests or hosts that wish to
		 * override the default behaviour.
		 *
		 * @since 5.5.0
		 *
		 * @param bool $enabled Default value based on DISABLE_WP_CRON.
		 */
		return (bool) apply_filters( 'vhp_purge_use_cron', $enabled );
	}

	/**
	 * Check if cron-based purging is enabled (instance wrapper).
	 *
	 * @since 5.5.0
	 * @return bool
	 */
	protected function is_cron_purging_enabled() {
		return self::is_cron_purging_enabled_static();
	}

	/**
	 * Determine if a given queue array is effectively empty.
	 *
	 * @since 5.5.0
	 * @param array $queue Queue data.
	 * @return bool
	 */
	protected function is_purge_queue_empty( $queue ) {
		if ( ! is_array( $queue ) ) {
			return true;
		}

		$full = ! empty( $queue['full'] );
		$urls = ( isset( $queue['urls'] ) && is_array( $queue['urls'] ) ) ? $queue['urls'] : array();
		$tags = ( isset( $queue['tags'] ) && is_array( $queue['tags'] ) ) ? $queue['tags'] : array();

		return ( ! $full && empty( $urls ) && empty( $tags ) );
	}

	/**
	 * Load and normalize the async purge queue from the site option.
	 *
	 * @since 5.5.0
	 * @return array Normalized queue structure.
	 */
	protected function get_purge_queue() {
		$queue = get_site_option( self::PURGE_QUEUE_OPTION, array() );

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$defaults = array(
			'version'         => self::PURGE_QUEUE_VERSION,
			'full'            => false,
			'urls'            => array(),
			'tags'            => array(),
			'created_at'      => 0,
			'last_updated_at' => 0,
		);

		$queue = array_merge( $defaults, $queue );

		// Normalise types.
		$queue['full'] = (bool) $queue['full'];

		if ( ! is_array( $queue['urls'] ) ) {
			$queue['urls'] = array();
		}

		if ( ! is_array( $queue['tags'] ) ) {
			$queue['tags'] = array();
		}

		$queue['urls'] = array_values( array_unique( array_filter( array_map( 'strval', $queue['urls'] ) ) ) );
		$queue['tags'] = array_values( array_unique( array_filter( array_map( 'strval', $queue['tags'] ) ) ) );

		$queue['created_at']      = (int) $queue['created_at'];
		$queue['last_updated_at'] = (int) $queue['last_updated_at'];

		return $queue;
	}

	/**
	 * Persist the async purge queue back to the site option.
	 *
	 * This runs the vhp_purge_queue_before_save filter and will delete the
	 * option entirely if the queue is effectively empty.
	 *
	 * @since 5.5.0
	 * @param array $queue Queue data.
	 */
	protected function save_purge_queue( $queue ) {
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		/**
		 * Filter the async purge queue before it is persisted.
		 *
		 * This allows advanced integrations to coalesce, add, or remove
		 * URLs/tags, or to otherwise adjust the queue semantics before
		 * it is written to the database.
		 *
		 * @since 5.5.0
		 *
		 * @param array $queue Queue data about to be saved.
		 */
		$queue = apply_filters( 'vhp_purge_queue_before_save', $queue );

		if ( $this->is_purge_queue_empty( $queue ) ) {
			delete_site_option( self::PURGE_QUEUE_OPTION );
			return;
		}

		update_site_option( self::PURGE_QUEUE_OPTION, $queue );
	}

	/**
	 * Ensure a single-run cron event is scheduled to process the queue.
	 *
	 * @since 5.5.0
	 */
	protected function ensure_purge_queue_scheduled() {
		if ( ! $this->is_cron_purging_enabled() ) {
			return;
		}

		if ( ! wp_next_scheduled( 'vhp_process_purge_queue' ) ) {
			wp_schedule_single_event( time(), 'vhp_process_purge_queue' );
		}
	}

	/**
	 * Enqueue a full-site purge into the async queue.
	 *
	 * Once a full purge is scheduled, granular URLs/tags are discarded.
	 *
	 * @since 5.5.0
	 */
	protected function enqueue_full_purge() {
		$queue = $this->get_purge_queue();

		$is_empty = $this->is_purge_queue_empty( $queue );

		$queue['full']            = true;
		$queue['urls']            = array();
		$queue['tags']            = array();
		$queue['last_updated_at'] = time();

		if ( $is_empty ) {
			$queue['created_at'] = time();
		}

		$this->save_purge_queue( $queue );
		$this->ensure_purge_queue_scheduled();
	}

	/**
	 * Enqueue specific URLs into the async purge queue.
	 *
	 * @since 5.5.0
	 * @param array $urls List of URLs to be purged.
	 */
	protected function enqueue_urls( $urls ) {
		if ( empty( $urls ) || ! is_array( $urls ) ) {
			return;
		}

		$queue = $this->get_purge_queue();

		// If a full purge is already scheduled, no need to track individual URLs.
		if ( ! empty( $queue['full'] ) ) {
			return;
		}

		$is_empty = $this->is_purge_queue_empty( $queue );

		$normalized_urls = array();
		foreach ( $urls as $url ) {
			$url = (string) $url;

			// Basic sanity check; purge_url() will validate further.
			if ( '' === $url ) {
				continue;
			}

			$normalized_urls[] = $url;
		}

		if ( empty( $normalized_urls ) ) {
			return;
		}

		$queue['urls'] = array_values(
			array_unique(
				array_merge(
					$queue['urls'],
					$normalized_urls
				)
			)
		);

		// Enforce a hard cap on queued URLs to avoid unbounded growth.
		$max_urls = (int) apply_filters( 'vhp_purge_queue_max_urls', self::PURGE_QUEUE_MAX_URLS );

		if ( $max_urls > 0 && count( $queue['urls'] ) > $max_urls ) {
			// Rather than silently dropping URLs, upgrade to a full purge.
			$queue['full'] = true;
			$queue['urls'] = array();
			$queue['tags'] = array();
		}

		$queue['last_updated_at'] = time();
		if ( $is_empty ) {
			$queue['created_at'] = time();
		}

		$this->save_purge_queue( $queue );
		$this->ensure_purge_queue_scheduled();
	}

	/**
	 * Enqueue cache tags into the async purge queue.
	 *
	 * @since 5.5.0
	 * @param array $tags List of cache tags to be purged.
	 */
	protected function enqueue_tags( $tags ) {
		if ( empty( $tags ) || ! is_array( $tags ) ) {
			return;
		}

		$queue = $this->get_purge_queue();

		// If a full purge is already scheduled, no need to track individual tags.
		if ( ! empty( $queue['full'] ) ) {
			return;
		}

		$is_empty = $this->is_purge_queue_empty( $queue );

		$normalized_tags = array();
		foreach ( $tags as $tag ) {
			$tag = trim( (string) $tag );
			if ( '' === $tag ) {
				continue;
			}
			$normalized_tags[] = $tag;
		}

		if ( empty( $normalized_tags ) ) {
			return;
		}

		$queue['tags'] = array_values(
			array_unique(
				array_merge(
					$queue['tags'],
					$normalized_tags
				)
			)
		);

		// Enforce a hard cap on queued tags to avoid unbounded growth.
		$max_tags = (int) apply_filters( 'vhp_purge_queue_max_tags', self::PURGE_QUEUE_MAX_TAGS );

		if ( $max_tags > 0 && count( $queue['tags'] ) > $max_tags ) {
			// Too many granular tags – upgrade to a full purge for safety.
			$queue['full'] = true;
			$queue['urls'] = array();
			$queue['tags'] = array();
		}

		$queue['last_updated_at'] = time();
		if ( $is_empty ) {
			$queue['created_at'] = time();
		}

		$this->save_purge_queue( $queue );
		$this->ensure_purge_queue_scheduled();
	}

	/**
	 * Require: Pretty Permalinks Message
	 * Explains you need Pretty Permalinks enabled to use this plugin
	 *
	 * @since 2.0
	 */
	public function require_pretty_permalinks_notice() {
		// translators: The URL should link to the permalinks page.
		echo wp_kses_post( '<div id="message" class="error"><p>' . sprintf( __( 'Proxy Cache Purge requires you to use custom permalinks. Please go to the <a href="%1$s">Permalinks Options Page</a> to configure them.', 'varnish-http-purge' ), esc_url( admin_url( 'options-permalink.php' ) ) ) . '</p></div>' );
	}

	/**
	 * Require: WP Version Message
	 * Explains you need WordPress 4.7+ to use this plugin
	 *
	 * @since 4.1
	 */
	public function require_wp_version_notice() {
		// translators: The URL should link to the update core page.
		echo "<div id='message' class='error'><p>" . sprintf( esc_html__( 'Proxy Cache Purge requires WordPress 4.7 or greater. Please <a href="%1$s">upgrade WordPress</a>.', 'varnish-http-purge' ), esc_url( admin_url( 'update-core.php' ) ) ) . '</p></div>';
	}

	/**
	 * Warning: Development Mode
	 * Checks if DevMode is active
	 *
	 * @since 4.6.0
	 */
	public function devmode_is_active_notice() {
		if ( VHP_DEVMODE ) {
			$message = __( 'Proxy Cache Purge Development Mode has been activated via wp-config.', 'varnish-http-purge' );
		} else {
			$devmode = get_site_option( 'vhp_varnish_devmode', self::$devmode );
			$time    = human_time_diff( time(), $devmode['expire'] );

			if ( $devmode['active'] ) {
				if ( ! is_multisite() ) {
					// translators: %1$s is the time until dev mode expires.
					// translators: %2$s is a link to the settings pages.
					$message = sprintf( __( 'Proxy Cache Purge Development Mode is active for the next %1$s. You can disable this at the <a href="%2$s">Proxy Settings Page</a>.', 'varnish-http-purge' ), $time, esc_url( admin_url( 'admin.php?page=varnish-page' ) ) );
				} else {
					// translators: %1$s is the time until dev mode expires.
					$message = sprintf( __( 'Proxy Cache Purge Development Mode is active for the next %1$s.', 'varnish-http-purge' ), $time );
				}
			}
		}

		// Only echo if there's actually a message
		if ( isset( $message ) ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( $message ) . '</p></div>';
		}
	}

	/**
	 * Cacheability Pro Recommendation Notice
	 *
	 * Shows a dismissable admin notice recommending Cacheability Pro plugin
	 * for users who don't have it installed.
	 *
	 * @since 5.6.2
	 */
	public function cacheability_pro_notice() {
		// Don't show if Cacheability Pro is already installed.
		if ( class_exists( 'Cacheability_Pro' ) ) {
			return;
		}

		// Don't show if free Cacheability is installed (has its own upsell).
		if ( class_exists( 'Cacheability' ) ) {
			return;
		}

		// Don't show if user dismissed this notice.
		if ( get_user_meta( get_current_user_id(), 'vhp_dismissed_cacheability_notice', true ) ) {
			return;
		}

		// Only show on relevant pages (dashboard, plugins, or VHP settings).
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$allowed_screens = array( 'dashboard', 'plugins', 'toplevel_page_varnish-page' );
		if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		$pro_url     = 'https://www.getpagespeed.com/cacheability-pro?ref=vhp';
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'vhp_dismiss_cacheability_notice', '1' ),
			'vhp_dismiss_cacheability_notice'
		);

		?>
		<div class="notice notice-info is-dismissible vhp-cacheability-notice">
			<p>
				<strong><?php esc_html_e( '🔥 Keep your cache warm!', 'varnish-http-purge' ); ?></strong>
				<?php esc_html_e( 'Proxy Cache Purge clears your cache — but visitors may hit a slow, uncached page.', 'varnish-http-purge' ); ?>
				<strong><?php esc_html_e( 'Cacheability Pro', 'varnish-http-purge' ); ?></strong>
				<?php esc_html_e( 'automatically warms the cache after purge, so every visitor gets a fast response.', 'varnish-http-purge' ); ?>
				<a href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener" class="button button-primary" style="margin-left: 10px;">
					<?php esc_html_e( 'Learn More', 'varnish-http-purge' ); ?>
				</a>
			</p>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('.vhp-cacheability-notice').on('click', '.notice-dismiss', function() {
				$.post(ajaxurl, {
					action: 'vhp_dismiss_cacheability_notice',
					nonce: '<?php echo esc_js( wp_create_nonce( 'vhp_dismiss_cacheability_notice' ) ); ?>'
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler for dismissing Cacheability Pro notice.
	 *
	 * @since 5.6.2
	 */
	public function ajax_dismiss_cacheability_notice() {
		check_ajax_referer( 'vhp_dismiss_cacheability_notice', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		update_user_meta( get_current_user_id(), 'vhp_dismissed_cacheability_notice', true );
		wp_send_json_success();
	}

	/**
	 * The Home URL
	 * Get the Home URL and allow it to be filterable
	 * This is for domain mapping plugins that, for some reason, don't filter
	 * on their own (including WPMU, Ron's, and so on).
	 *
	 * @since 4.0
	 */
	public static function the_home_url() {
		$home_url = apply_filters( 'vhp_home_url', home_url() );
		return $home_url;
	}

	/**
	 * Custom CSS to allow for colouring.
	 *
	 * @since 4.5.0
	 */
	public function custom_css() {
		if ( is_user_logged_in() && is_admin_bar_showing() ) {
			wp_register_style( 'varnish_http_purge', plugins_url( 'style.css', __FILE__ ), false, self::$version );
			wp_enqueue_style( 'varnish_http_purge' );
		}
	}

	/**
	 * Purge Button in the Admin Bar
	 *
	 * @access public
	 * @param mixed $admin_bar - data passed back from admin bar.
	 * @return void
	 */
	public function varnish_rightnow_adminbar( $admin_bar ) {
		global $wp;

		$can_purge    = false;
		$args         = array();
		$cache_active = ( VarnishDebug::devmode_check() ) ? __( 'Inactive', 'varnish-http-purge' ) : __( 'Active', 'varnish-http-purge' );
		// translators: %s is the state of cache.
		$cache_titled = sprintf( __( 'Cache (%s)', 'varnish-http-purge' ), $cache_active );

		if ( ( ! is_admin() && get_post() !== false && current_user_can( 'edit_published_posts' ) ) || current_user_can( 'activate_plugins' ) ) {
			// Main Array.
			$args      = array(
				array(
					'id'    => 'purge-varnish-cache',
					'title' => '<span class="ab-icon" style="background-image: url(' . self::get_icon_svg() . ') !important;"></span><span class="ab-label">' . $cache_titled . '</span>',
					'meta'  => array(
						'class' => 'varnish-http-purge',
					),
				),
			);
			$can_purge = true;
		}

		// Checking user permissions for who can and cannot use the all flush.
		if (
			// SingleSite - admins can always purge.
			( ! is_multisite() && current_user_can( 'activate_plugins' ) ) ||
			// Multisite - Network Admin can always purge.
			current_user_can( 'manage_network' ) ||
			// Multisite - Site admins can purge UNLESS it's a subfolder install and we're on site #1.
			( is_multisite() && current_user_can( 'activate_plugins' ) && ( SUBDOMAIN_INSTALL || ( ! SUBDOMAIN_INSTALL && ( BLOG_ID_CURRENT_SITE !== get_current_blog_id() ) ) ) )
			) {

			$args[] = array(
				'parent' => 'purge-varnish-cache',
				'id'     => 'purge-varnish-cache-all',
				'title'  => __( 'Purge Cache (All Pages)', 'varnish-http-purge' ),
				'href'   => wp_nonce_url( add_query_arg( 'vhp_flush_do', 'all' ), 'vhp-flush-do' ),
				'meta'   => array(
					'title' => __( 'Purge Cache (All Pages)', 'varnish-http-purge' ),
				),
			);

			// If a memcached file is found, we can do this too.
			if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
				$args[] = array(
					'parent' => 'purge-varnish-cache',
					'id'     => 'purge-varnish-cache-db',
					'title'  => __( 'Purge Database Cache', 'varnish-http-purge' ),
					'href'   => wp_nonce_url( add_query_arg( 'vhp_flush_do', 'object' ), 'vhp-flush-do' ),
					'meta'   => array(
						'title' => __( 'Purge Database Cache', 'varnish-http-purge' ),
					),
				);
			}

			// If Devmode is in the config, don't allow it to be disabled.
			if ( ! VHP_DEVMODE ) {
				// Populate enable/disable cache button.
				if ( VarnishDebug::devmode_check() ) {
					$purge_devmode_title = __( 'Restart Cache', 'varnish-http-purge' );
					$vhp_add_query_arg   = array(
						'vhp_flush_do'    => 'devmode',
						'vhp_set_devmode' => 'deactivate',
					);
				} else {
					$purge_devmode_title = __( 'Pause Cache (24h)', 'varnish-http-purge' );
					$vhp_add_query_arg   = array(
						'vhp_flush_do'    => 'devmode',
						'vhp_set_devmode' => 'activate',
					);
				}

				$args[] = array(
					'parent' => 'purge-varnish-cache',
					'id'     => 'purge-varnish-cache-devmode',
					'title'  => $purge_devmode_title,
					'href'   => wp_nonce_url( add_query_arg( $vhp_add_query_arg ), 'vhp-flush-do' ),
					'meta'   => array(
						'title' => $purge_devmode_title,
					),
				);
			}
		}

		// If we're on a front end page AND the current user can edit published posts, then they can do this.
		if ( ! is_admin() && get_post() !== false && current_user_can( 'edit_published_posts' ) ) {
			$page_url = esc_url( home_url( $wp->request ) );
			$args[]   = array(
				'parent' => 'purge-varnish-cache',
				'id'     => 'purge-varnish-cache-this',
				'title'  => __( 'Purge Cache (This Page)', 'varnish-http-purge' ),
				'href'   => wp_nonce_url( add_query_arg( 'vhp_flush_do', user_trailingslashit( $page_url ) ), 'vhp-flush-do' ),
				'meta'   => array(
					'title' => __( 'Purge Cache (This Page)', 'varnish-http-purge' ),
				),
			);
		}

		if ( $can_purge ) {
			foreach ( $args as $arg ) {
				$admin_bar->add_node( $arg );
			}
		}
	}


	/**
	 * Get the icon as SVG.
	 *
	 * Forked from Yoast SEO
	 *
	 * @access public
	 * @param bool $base64 (default: true) - Use SVG, true/false?
	 * @param string $icon_color - What color to use.
	 * @return string
	 */
	public static function get_icon_svg( $base64 = true, $icon_color = false ) {
		global $_wp_admin_css_colors;

		$fill = ( false !== $icon_color ) ? sanitize_hex_color( $icon_color ) : '#82878c';

		if ( is_admin() && false === $icon_color && get_user_option( 'admin_color' ) ) {
			$admin_colors  = json_decode( wp_json_encode( $_wp_admin_css_colors ), true );
			$current_color = get_user_option( 'admin_color' );
			$fill          = $admin_colors[ $current_color ]['icon_colors']['base'];
		}

		// Flat
		$svg = '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xml:space="preserve" width="100%" height="100%" style="fill:' . $fill . '" viewBox="0 0 36.2 34.39" role="img" aria-hidden="true" focusable="false"><g id="Layer_2" data-name="Layer 2"><g id="Layer_1-2" data-name="Layer 1"><path fill="' . $fill . '" d="M24.41,0H4L0,18.39H12.16v2a2,2,0,0,0,4.08,0v-2H24.1a8.8,8.8,0,0,1,4.09-1Z"/><path fill="' . $fill . '" d="M21.5,20.4H18.24a4,4,0,0,1-8.08,0v0H.2v8.68H19.61a9.15,9.15,0,0,1-.41-2.68A9,9,0,0,1,21.5,20.4Z"/><path fill="' . $fill . '" d="M28.7,33.85a7,7,0,1,1,7-7A7,7,0,0,1,28.7,33.85Zm-1.61-5.36h5V25.28H30.31v-3H27.09Z"/><path fill="' . $fill . '" d="M28.7,20.46a6.43,6.43,0,1,1-6.43,6.43,6.43,6.43,0,0,1,6.43-6.43M26.56,29h6.09V24.74H30.84V21.8H26.56V29m2.14-9.64a7.5,7.5,0,1,0,7.5,7.5,7.51,7.51,0,0,0-7.5-7.5ZM27.63,28V22.87h2.14v2.95h1.81V28Z"/></g></g></svg>';

		if ( $base64 ) {
			return 'data:image/svg+xml;base64,' . base64_encode( $svg );
		}

		return $svg;
	}

	/**
	 * Varnish Right Now Information
	 * This information is put on the Dashboard 'Right now' widget
	 *
	 * @since 1.0
	 */
	public function varnish_rightnow() {
		global $blog_id;
		// translators: %1$s links to the plugin's page on WordPress.org.
		$intro    = sprintf( __( '<a href="%1$s">Proxy Cache Purge</a> automatically deletes your cached posts when published or updated. When making major site changes, such as with a new theme, plugins, or widgets, you may need to manually empty the cache.', 'varnish-http-purge' ), 'https://wordpress.org/plugins/varnish-http-purge/' );
		$url      = wp_nonce_url( add_query_arg( 'vhp_flush_do', 'all' ), 'vhp-flush-do' );
		$button   = __( 'Press the button below to force it to empty your entire cache.', 'varnish-http-purge' );
		$button  .= '</p><p><span class="button"><strong><a href="' . $url . '">';
		$button  .= __( 'Empty Cache', 'varnish-http-purge' );
		$button  .= '</a></strong></span>';
		$nobutton = __( 'You do not have permission to empty the proxy cache for the whole site. Please contact your administrator.', 'varnish-http-purge' );
		if (
			// SingleSite - admins can always purge.
			( ! is_multisite() && current_user_can( 'activate_plugins' ) ) ||
			// Multisite - Network Admin can always purge.
			current_user_can( 'manage_network' ) ||
			// Multisite - Site admins can purge UNLESS it's a subfolder install and we're on site #1.
			( is_multisite() && current_user_can( 'activate_plugins' ) && ( SUBDOMAIN_INSTALL || ( ! SUBDOMAIN_INSTALL && ( BLOG_ID_CURRENT_SITE !== $blog_id ) ) ) )
		) {
			$text = $intro . ' ' . $button;
		} else {
			$text = $intro . ' ' . $nobutton;
		}
		// @codingStandardsIgnoreStart
		// This is safe to echo as it's controlled and secured above.
		// Using wp_kses will delete the icon.
		echo '<p class="varnish-rightnow">' . $text . '</p>';
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Registered Events
	 * These are when the purge is triggered
	 *
	 * @since 1.0
	 * @access protected
	 */
	protected function get_register_events() {

		// Define registered purge events.
		$actions = array(
			'autoptimize_action_cachepurged', // Compat with https://wordpress.org/plugins/autoptimize/ plugin.
			'delete_attachment',              // Delete an attachment - includes re-uploading.
			'deleted_post',                   // Delete a post.
			'edit_post',                      // Edit a post - includes leaving comments.
			'import_start',                   // When importer starts
			'import_end',                     // When importer ends
			'save_post',                      // Save a post.
			'switch_theme',                   // After a theme is changed.
			'customize_save_after',           // After Customizer is updated.
			'trashed_post',                   // Empty Trashed post.
		);

		// send back the actions array, filtered.
		// @param array $actions the actions that trigger the purge event.
		return apply_filters( 'varnish_http_purge_events', $actions );
	}

	/**
	 * Events that have no post IDs
	 * These are when a full purge is triggered
	 *
	 * @since 3.9
	 * @access protected
	 */
	protected function get_no_id_events() {

		// Define registered purge events.
		$actions = array(
			'autoptimize_action_cachepurged', // Compat with https://wordpress.org/plugins/autoptimize/ plugin.
			'import_start',                   // When importer starts
			'import_end',                     // When importer ends
			'switch_theme',                   // After a theme is changed.
			'customize_save_after',           // After Customizer is updated.
		);

		/**
		 * Send back the actions array, filtered
		 *
		 * @param array $actions the actions that trigger the purge event
		 *
		 * DEVELOPERS! USE THIS SPARINGLY! YOU'RE A GREAT BIG 💩 IF YOU USE IT FLAGRANTLY
		 * Remember to add your action to this AND varnish_http_purge_events due to shenanigans
		 */
		return apply_filters( 'varnish_http_purge_events_full', $actions );
	}

	/**
	 * Execute Purge
	 * Run the purge command for the URLs or enqueue them for async handling.
	 *
	 * @since 1.0
	 * @access protected
	 */
	public function execute_purge() {
		$purge_urls = array_unique( $this->purge_urls );
		$cron_mode  = $this->is_cron_purging_enabled();

		if ( ! empty( $purge_urls ) && is_array( $purge_urls ) ) {

			// If there are URLs to purge and it's an array, we'll likely purge.

			// Number of URLs to purge.
			$count = count( $purge_urls );

			// Max posts
			if ( defined( 'VHP_VARNISH_MAXPOSTS' ) && false !== VHP_VARNISH_MAXPOSTS ) {
				$max_posts = VHP_VARNISH_MAXPOSTS;
			} else {
				$max_posts = get_site_option( 'vhp_varnish_max_posts_before_all' );
			}

			// In cron-mode, allow specific requests to bypass the queue and run synchronously.
			if ( $cron_mode ) {
				$payload = array(
					'urls'      => $purge_urls,
					'count'     => $count,
					'max_posts' => $max_posts,
				);

				/**
				 * Decide whether this batch of URL purges should bypass the cron queue.
				 *
				 * When this filter returns true and cron-mode is enabled, the plugin
				 * will behave as it did historically: sending PURGE requests
				 * synchronously during the request instead of queuing them for
				 * WP-Cron.
				 *
				 * @since 5.5.0
				 *
				 * @param bool  $bypass  Default false.
				 * @param string $context Context string. Here: 'urls'.
				 * @param array  $payload Array with 'urls', 'count', and 'max_posts'.
				 */
				$bypass = apply_filters( 'vhp_purge_bypass_cron_for_request', false, 'urls', $payload );

				if ( $bypass ) {
					// Preserve existing behaviour.
					if ( $max_posts <= $count ) {
						// Too many URLs, purge all instead.
						$this->purge_url( $this->the_home_url() . '/?vhp-regex' );
					} else {
						// Purge each URL.
						foreach ( $purge_urls as $url ) {
							$this->purge_url( $url );
						}
					}

					return;
				}
			}

			// If there are more than vhp_varnish_max_posts_before_all URLs to purge (default 50),
			// do a purge ALL instead. Else, enqueue or purge the individual URLs.
			if ( $cron_mode ) {
				if ( $max_posts <= $count ) {
					$this->enqueue_full_purge();
				} else {
					$this->enqueue_urls( $purge_urls );
				}
			} elseif ( $max_posts <= $count ) {
					// Too many URLs, purge all instead.
					$this->purge_url( $this->the_home_url() . '/?vhp-regex' );
			} else {
				// Purge each URL.
				foreach ( $purge_urls as $url ) {
					$this->purge_url( $url );
				}
			}
		} elseif ( isset( $_GET ) ) {
			// Otherwise, if we've passed a GET call...
			// Manual purge actions always execute immediately regardless of cron
			// mode. Cron-based queuing only benefits batch operations (automatic
			// purges from post saves, etc.). Single-request manual purges have no
			// batching benefit and users expect immediate results.
			if ( isset( $_GET['vhp_flush_all'] ) && check_admin_referer( 'vhp-flush-all' ) ) {
				// Flush Cache recursive (single regex request - always immediate).
				$this->purge_url( $this->the_home_url() . '/?vhp-regex' );
			} elseif ( isset( $_GET['vhp_flush_do'] ) && check_admin_referer( 'vhp-flush-do' ) ) {
				if ( 'object' === $_GET['vhp_flush_do'] ) {
					// Flush Object Cache (with a double check).
					if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
						wp_cache_flush();
					}
				} elseif ( 'all' === $_GET['vhp_flush_do'] ) {
					// Flush Cache recursive (single regex request - always immediate).
					$this->purge_url( $this->the_home_url() . '/?vhp-regex' );
				} else {
					// Flush the URL we're on (single request - always immediate).
					$p = wp_parse_url( esc_url_raw( wp_unslash( $_GET['vhp_flush_do'] ) ) );
					if ( ! isset( $p['host'] ) ) {
						return;
					}
					$target_url = esc_url_raw( wp_unslash( $_GET['vhp_flush_do'] ) );
					$this->purge_url( $target_url );
				}
			}
		}
	}

	/**
	 * Process the async purge queue.
	 *
	 * This is invoked by the `vhp_process_purge_queue` cron hook as well as
	 * tests and WP-CLI integrations. It is safe to invoke regardless of
	 * whether cron-mode is currently enabled; if the queue is empty, it will
	 * simply no-op.
	 *
	 * @since 5.5.0
	 * @access public
	 */
	public function process_purge_queue() {
		$queue      = $this->get_purge_queue();
		$queue_at   = (int) ( isset( $queue['created_at'] ) ? $queue['created_at'] : 0 );
		$is_empty   = $this->is_purge_queue_empty( $queue );
		$start_time = microtime( true );

		$summary = array(
			'full'           => false,
			'urls_processed' => 0,
			'tags_processed' => 0,
			'queue_age'      => 0,
		);

		if ( ! $is_empty && $queue_at > 0 ) {
			$summary['queue_age'] = time() - $queue_at;
		}

		// If the queue has been sitting around for a long time with granular
		// entries, upgrade it to a full purge for safety.
		$max_age = (int) apply_filters( 'vhp_purge_queue_max_age', self::PURGE_QUEUE_MAX_AGE );
		if ( $max_age > 0 && ! empty( $summary['queue_age'] ) && empty( $queue['full'] ) ) {
			$has_granular = ( ! empty( $queue['urls'] ) || ! empty( $queue['tags'] ) );
			if ( $has_granular && $summary['queue_age'] > $max_age ) {
				$queue['full'] = true;
				$queue['urls'] = array();
				$queue['tags'] = array();
			}
		}

		// Decide what to do with the current snapshot of the queue.
		if ( ! empty( $queue['full'] ) ) {
			// Full purge wins over everything else.
			$this->purge_url( $this->the_home_url() . '/?vhp-regex' );
			$summary['full'] = true;

			// Clear the queue entirely once processed.
			$queue['full']            = false;
			$queue['urls']            = array();
			$queue['tags']            = array();
			$queue['created_at']      = 0;
			$queue['last_updated_at'] = time();
			$this->save_purge_queue( $queue );
		} elseif ( ! $is_empty ) {
			// Granular URLs and/or tags.

			// Tags are processed as a single batch; batching into header-sized
			// patterns is handled inside purge_tags().
			if ( ! empty( $queue['tags'] ) && is_array( $queue['tags'] ) ) {
				$this->purge_tags( $queue['tags'] );
				$summary['tags_processed'] = count( $queue['tags'] );
				$queue['tags']             = array();
			}

			$urls = ( isset( $queue['urls'] ) && is_array( $queue['urls'] ) ) ? $queue['urls'] : array();

			// Process URLs in chunks to avoid excessively long cron runs.
			$max_urls_per_run = (int) apply_filters( 'vhp_purge_queue_max_urls_per_run', 200 );
			if ( $max_urls_per_run <= 0 ) {
				$max_urls_per_run = 200;
			}

			$urls_to_process = array_slice( $urls, 0, $max_urls_per_run );

			foreach ( $urls_to_process as $url ) {
				$this->purge_url( $url );
			}

			$summary['urls_processed'] = count( $urls_to_process );

			// Remove processed URLs from the queue.
			$queue['urls']            = array_slice( $urls, $summary['urls_processed'] );
			$queue['last_updated_at'] = time();

			if ( $this->is_purge_queue_empty( $queue ) ) {
				$queue['created_at'] = 0;
			}

			$this->save_purge_queue( $queue );

			// If there is still work to do, schedule another run.
			if ( ! $this->is_purge_queue_empty( $queue ) ) {
				$this->ensure_purge_queue_scheduled();
			}
		}

		$duration = microtime( true ) - $start_time;

		update_site_option( 'vhp_varnish_last_queue_run', time() );

		$summary['duration'] = $duration;

		/**
		 * Fires after an async purge queue run has completed.
		 *
		 * This action is primarily intended for logging and metrics.
		 *
		 * @since 5.5.0
		 *
		 * @param array $queue_snapshot Queue snapshot as loaded at the start of the run.
		 * @param array $summary        Summary statistics about the processing.
		 */
		do_action( 'vhp_purge_queue_after_process', $queue, $summary );
	}

	/**
	 * Purge URL
	 * Parse the URL for proxy proxies
	 *
	 * @since 1.0
	 * @param array $url - The url to be purged.
	 * @access protected
	 */
	public static function purge_url( $url ) {

		// Bail early if someone sent a non-URL
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return;
		}

		$p = wp_parse_url( $url );

		// Bail early if there's no host since some plugins are weird.
		if ( ! isset( $p['host'] ) ) {
			return;
		}

		// Determine if we're using regex to flush all pages or not.
		$pregex         = '';
		$x_purge_method = 'default';

		if ( isset( $p['query'] ) && ( 'vhp-regex' === $p['query'] ) ) {
			$pregex         = '.*';
			$x_purge_method = 'regex';
		}

		// Build a varniship to sail. ⛵️
		$varniship = ( VHP_VARNISH_IP !== false ) ? VHP_VARNISH_IP : get_site_option( 'vhp_varnish_ip' );

		// If there are commas, and for whatever reason this didn't become an array
		// properly, force it.
		if ( ! is_array( $varniship ) && strpos( $varniship, ',' ) !== false ) {
			$varniship = array_map( 'trim', explode( ',', $varniship ) );
		}

		// Now apply filters
		if ( is_array( $varniship ) ) {
			// To each ship:
			$ship_count = count( $varniship );
			for ( $i = 0; $i < $ship_count; $i++ ) {
				$varniship[ $i ] = apply_filters( 'vhp_varnish_ip', $varniship[ $i ] );
			}
		} else {
			// To the only ship:
			$varniship = apply_filters( 'vhp_varnish_ip', $varniship );
		}

		// Determine the path.
		$path = ( isset( $p['path'] ) ) ? $p['path'] : '';

		/**
		 * Schema filter
		 *
		 * Allows default http:// schema to be changed to https
		 * varnish_http_purge_schema()
		 *
		 * @since 3.7.3
		 */

		// This is a very annoying check for DreamHost who needs to default to HTTPS without breaking
		// people who've been around before.
		$server_hostname = gethostname();
		switch ( substr( $server_hostname, 0, 3 ) ) {
			case 'dp-':
				$schema_type = 'https://';
				break;
			default:
				$schema_type = 'http://';
				break;
		}
		$schema = apply_filters( 'varnish_http_purge_schema', $schema_type );

		// When we have Varnish IPs, we use them in lieu of hosts.
		if ( isset( $varniship ) && ! empty( $varniship ) ) {
			$all_hosts = ( ! is_array( $varniship ) ) ? array( $varniship ) : $varniship;
		} else {
			// The default is the main host, converted into an array.
			$all_hosts = array( $p['host'] );
		}

		// Since the ship is always an array now, let's loop.
		foreach ( $all_hosts as $one_host ) {

			/**
			 * Allow setting of ports in host name
			 * Credit: davidbarratt - https://github.com/Ipstenu/varnish-http-purge/pull/38/
			 *
			 * (default value: $p['host'])
			 *
			 * @var string
			 * @access public
			 * @since 4.4.0
			 */
			$host_headers = $p['host'];

			// If the URL to be purged has a port, we're going to re-use it.
			if ( isset( $p['port'] ) ) {
				$host_headers .= ':' . $p['port'];
			}

			$parsed_url = $url;

			// Filter URL based on the Proxy IP for nginx compatibility
			if ( 'localhost' === $one_host ) {
				$parsed_url = str_replace( $p['host'], 'localhost', $parsed_url );
			}

			// Create path to purge.
			$purgeme = $schema . $one_host . $path . $pregex;

			// Check the queries...
			if ( ! empty( $p['query'] ) && 'vhp-regex' !== $p['query'] ) {
				$purgeme .= '?' . $p['query'];
			}

			/**
			 * Filter the purge path
			 *
			 * Allows dynamically changing the purge cache for custom purge location
			 * or systems not supporting .* regex purge for example
			 *
			 * @since 5.1
			 */
			$purgeme = apply_filters( 'vhp_purgeme_path', $purgeme, $schema, $one_host, $path, $pregex, $p );

			$default_headers = array(
				'host'           => $host_headers,
				'X-Purge-Method' => $x_purge_method,
			);
			if ( VHP_VARNISH_EXTRA_PURGE_HEADER && strpos( VHP_VARNISH_EXTRA_PURGE_HEADER, ':' ) !== false ) {
				// If this is set, extract name/value.
				$header_parts        = explode( ':', VHP_VARNISH_EXTRA_PURGE_HEADER, 2 );
				$custom_header_name  = trim( $header_parts[0] );
				$custom_header_value = ( isset( $header_parts[1] ) ) ? trim( $header_parts[1] ) : '';
				if ( ! empty( $custom_header_name ) && ! empty( $custom_header_value ) ) {
					$default_headers[ $custom_header_name ] = $custom_header_value;
				}
			} elseif ( get_site_option( 'vhp_varnish_extra_purge_header_value' ) && get_site_option( 'vhp_varnish_extra_purge_header_name' ) ) {
				$custom_header_name  = trim( get_site_option( 'vhp_varnish_extra_purge_header_name' ) );
				$custom_header_value = trim( get_site_option( 'vhp_varnish_extra_purge_header_value' ) );
				if ( ! empty( $custom_header_name ) && ! empty( $custom_header_value ) ) {
					$default_headers[ $custom_header_name ] = $custom_header_value;
				}
			}

			/**
			 * Filters the HTTP headers to send with a PURGE request.
			 *
			 * @since 4.1
			 */
			$headers = apply_filters(
				'varnish_http_purge_headers',
				$default_headers
			);

			// Send response.
			// SSL Verify is required here since Varnish is HTTP only, but proxies are a thing.
			$response = wp_remote_request(
				$purgeme,
				array(
					'sslverify' => false,
					'method'    => 'PURGE',
					'headers'   => $headers,
				)
			);

			do_action( 'after_purge_url', $parsed_url, $purgeme, $response, $headers );
		}
	}

	/**
	 * Purge Tags
	 *
	 * @since 5.4.0
	 * @param array $tags - The tags to be purged.
	 * @access public
	 */
	public function purge_tags( $tags ) {
		// Bail early if no tags.
		if ( empty( $tags ) ) {
			return;
		}

		// Unique tags only.
		$tags = array_unique( array_filter( array_map( 'strval', $tags ) ) );

		if ( empty( $tags ) ) {
			return;
		}

		/**
		 * Allow filtering of tags prior to batching and purging.
		 *
		 * @since 5.4.0
		 *
		 * @param array $tags List of cache tags to purge.
		 */
		$tags = apply_filters( 'vhp_purge_tags', $tags );

		if ( empty( $tags ) || ! is_array( $tags ) ) {
			return;
		}

		/**
		 * Maximum length (in bytes) of the header value used for tag patterns.
		 * Defaults to 7680 bytes, which is a safe value below common Varnish
		 * and HTTP header limits, and helps avoid oversized BAN expressions.
		 *
		 * @since 5.4.0
		 *
		 * @param int $max_header_size Maximum header size in bytes.
		 */
		$max_header_size = (int) apply_filters( 'vhp_purge_tags_max_header_size', 7680 );
		if ( $max_header_size <= 0 ) {
			$max_header_size = 7680;
		}

		// Build batched patterns like "tag-one|tag-two|tag-three" to be used in a single BAN.
		$patterns     = array();
		$current_tags = array();
		$current_size = 0;
		foreach ( $tags as $tag ) {
			$tag        = trim( (string) $tag );
			$tag_length = strlen( $tag );
			if ( '' === $tag || 0 === $tag_length ) {
				continue;
			}

			// Extra 1 byte for the '|' delimiter when there are existing tags in the batch.
			$additional = $tag_length + ( empty( $current_tags ) ? 0 : 1 );

			// If adding this tag would exceed the header size, flush the current batch first.
			if ( $current_size > 0 && ( $current_size + $additional ) > $max_header_size ) {
				$patterns[]   = implode( '|', $current_tags );
				$current_tags = array();
				$current_size = 0;
				$additional   = $tag_length; // first tag in the new batch, no delimiter yet.
			}

			$current_tags[] = $tag;
			$current_size  += $additional;
		}

		if ( ! empty( $current_tags ) ) {
			$patterns[] = implode( '|', $current_tags );
		}

		if ( empty( $patterns ) ) {
			return;
		}

		// Build a varniship to sail. ⛵️
		$varniship = ( VHP_VARNISH_IP !== false ) ? VHP_VARNISH_IP : get_site_option( 'vhp_varnish_ip' );

		// If there are commas, and for whatever reason this didn't become an array
		// properly, force it.
		if ( ! is_array( $varniship ) && strpos( $varniship, ',' ) !== false ) {
			$varniship = array_map( 'trim', explode( ',', $varniship ) );
		}

		// Now apply filters
		if ( is_array( $varniship ) ) {
			// To each ship:
			$ship_count = count( $varniship );
			for ( $i = 0; $i < $ship_count; $i++ ) {
				$varniship[ $i ] = apply_filters( 'vhp_varnish_ip', $varniship[ $i ] );
			}
		} else {
			// To the only ship:
			$varniship = apply_filters( 'vhp_varnish_ip', $varniship );
		}

		// This is a very annoying check for DreamHost who needs to default to HTTPS without breaking
		// people who've been around before.
		$server_hostname = gethostname();
		switch ( substr( $server_hostname, 0, 3 ) ) {
			case 'dp-':
				$schema_type = 'https://';
				break;
			default:
				$schema_type = 'http://';
				break;
		}
		$schema = apply_filters( 'varnish_http_purge_schema', $schema_type );

		// Get home URL parts
		$p = wp_parse_url( $this->the_home_url() );

		// When we have Varnish IPs, we use them in lieu of hosts.
		if ( isset( $varniship ) && ! empty( $varniship ) ) {
			$all_hosts = ( ! is_array( $varniship ) ) ? array( $varniship ) : $varniship;
		} else {
			// The default is the main host, converted into an array.
			$all_hosts = array( $p['host'] );
		}

		// Since the ship is always an array now, let's loop.
		foreach ( $all_hosts as $one_host ) {

			$host_headers = $p['host'];

			// If the URL to be purged has a port, we're going to re-use it.
			if ( isset( $p['port'] ) ) {
				$host_headers .= ':' . $p['port'];
			}

			// Filter URL based on the Proxy IP for nginx compatibility.
			// Note: For localhost (nginx), no URL rewrite is needed for tag-based purges.

			// Create path to purge.
			$purgeme = $schema . $one_host . '/';

			// Send one PURGE per pattern so VCL can invalidate by regex in a single BAN.
			foreach ( $patterns as $pattern ) {
				/**
				 * Filters the HTTP headers to send with a PURGE request.
				 *
				 * @since 4.1
				 */
				$headers = apply_filters(
					'varnish_http_purge_headers',
					array(
						'host'                 => $host_headers,
						'X-Purge-Method'       => 'tags',
						'X-Cache-Tags-Pattern' => $pattern,
					)
				);

				// Send response.
				// SSL Verify is required here since Varnish is HTTP only, but proxies are a thing.
				$response = wp_remote_request(
					$purgeme,
					array(
						'sslverify' => false,
						'method'    => 'PURGE',
						'headers'   => $headers,
					)
				);

				/**
				 * Fires after a tag-pattern purge request has been sent.
				 *
				 * @since 5.4.0
				 *
				 * @param array  $tags     Full list of tags requested for purge.
				 * @param string $pattern  The pattern string used for this PURGE (e.g. "tag-one|tag-two").
				 * @param string $purgeme  The URL that was purged.
				 * @param mixed  $response The response from wp_remote_request().
				 * @param array  $headers  The headers sent with the PURGE request.
				 */
				do_action( 'after_purge_tags', $tags, $pattern, $purgeme, $response, $headers );
			}
		}
	}

	/**
	 * Purge on Scheduled Post Publish
	 *
	 * When a scheduled post transitions from 'future' to 'publish' (typically
	 * via WP-Cron), this method ensures the cache is purged synchronously.
	 * This bypasses the async queue because we're already in a cron context
	 * and the user expects immediate cache invalidation.
	 *
	 * Additionally, this method purges the shortlink URL (?p=XXX) which may
	 * have been cached with a 404 response while the post was scheduled.
	 *
	 * @since 5.5.0
	 * @access public
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function purge_on_future_to_publish( $new_status, $old_status, $post ) {
		// Only act on future → publish transitions (scheduled posts being published).
		if ( 'future' !== $old_status || 'publish' !== $new_status ) {
			return;
		}

		// Bail if not a valid post object.
		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			return;
		}

		$post_id = $post->ID;

		// Skip invalid post types.
		$invalid_post_type = array( 'nav_menu_item', 'revision' );
		$this_post_type    = get_post_type( $post_id );
		if ( in_array( $this_post_type, $invalid_post_type, true ) ) {
			return;
		}

		// If using tag-based purging, purge by tags synchronously.
		if ( get_site_option( 'vhp_varnish_use_tags' ) && class_exists( 'VarnishTags' ) ) {
			$tags = VarnishTags::get_purge_tags_for_post( $post_id );
			$this->purge_tags( $tags );

			// Also purge the shortlink URL which may have cached 404/redirect.
			$shortlink = $this->the_home_url() . '/?p=' . $post_id;
			$this->purge_url( $shortlink );

			return;
		}

		// Generate purge URLs for this post using the existing logic, but store
		// them temporarily so we can purge synchronously.
		$original_purge_urls = $this->purge_urls;
		$this->purge_urls    = array();

		// Call purge_post to populate $this->purge_urls with the URLs to purge.
		$this->purge_post( $post_id );

		$urls_to_purge    = array_unique( $this->purge_urls );
		$this->purge_urls = $original_purge_urls;

		// Also add the shortlink URL which may have been cached with a 404 or
		// redirect while the post was in 'future' status.
		$shortlink       = $this->the_home_url() . '/?p=' . $post_id;
		$urls_to_purge[] = $shortlink;
		$urls_to_purge   = array_unique( $urls_to_purge );

		// Purge each URL synchronously (bypass the cron queue).
		if ( ! empty( $urls_to_purge ) ) {
			// Check against max posts limit.
			$count = count( $urls_to_purge );
			if ( defined( 'VHP_VARNISH_MAXPOSTS' ) && false !== VHP_VARNISH_MAXPOSTS ) {
				$max_posts = VHP_VARNISH_MAXPOSTS;
			} else {
				$max_posts = get_site_option( 'vhp_varnish_max_posts_before_all' );
			}

			if ( $max_posts <= $count ) {
				// Too many URLs, purge all instead.
				$this->purge_url( $this->the_home_url() . '/?vhp-regex' );
			} else {
				foreach ( $urls_to_purge as $url ) {
					$this->purge_url( $url );
				}
			}
		}
	}

	/**
	 * Purge - No IDs
	 * Flush the whole cache
	 *
	 * @access public
	 * @param mixed $post_id The post ID that triggered this (unused, kept for hook compatibility).
	 * @return void
	 * @since 3.9
	 */
	public function execute_purge_no_id( $post_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$listofurls = array();

		array_push( $listofurls, $this->the_home_url() . '/?vhp-regex' );

		// Now flush all the URLs we've collected provided the array isn't empty.
		if ( ! empty( $listofurls ) ) {
			foreach ( $listofurls as $url ) {
				array_push( $this->purge_urls, $url );
			}
		}

		do_action( 'after_full_purge' );
	}

	/**
	 * Generate URLs
	 *
	 * Generates a list of URLs that should be purged, based on the post ID
	 * passed through. Useful for when you're trying to get a post to flush
	 * another post.
	 *
	 * @access public
	 * @param mixed $post_id - The ID of the post to be purged.
	 * @return array()
	 */
	public function generate_urls( $post_id ) {
		$this->purge_post( $post_id );
		return $this->purge_urls;
	}

	/**
	 * Purge Post
	 * Flush the post
	 *
	 * @since 1.0
	 * @param array $post_id - The ID of the post to be purged.
	 * @access public
	 */
	public function purge_post( $post_id ) {

		/**
		 * If this is a valid post we want to purge the post,
		 * the home page and any associated tags and categories
		 */
		$valid_post_status = array( 'publish', 'private', 'trash', 'pending', 'draft' );

		// Allow excluding specific statuses via wp-config define (string with commas or array).
		if ( defined( 'VHP_EXCLUDED_POST_STATUSES' ) && false !== VHP_EXCLUDED_POST_STATUSES && ! empty( VHP_EXCLUDED_POST_STATUSES ) ) {
			$excluded_statuses = VHP_EXCLUDED_POST_STATUSES;
			if ( is_string( $excluded_statuses ) ) {
				$excluded_statuses = array_map( 'trim', explode( ',', $excluded_statuses ) );
			}
			if ( is_array( $excluded_statuses ) ) {
				$valid_post_status = array_values( array_diff( $valid_post_status, $excluded_statuses ) );
			}
		}

		/**
		 * Filter the list of valid post statuses that trigger purge URL generation.
		 *
		 * @since 5.3.0
		 * @param array $valid_post_status Current list of valid statuses.
		 * @param int   $post_id           Post ID being purged.
		 */
		$valid_post_status = apply_filters( 'varnish_http_purge_valid_post_statuses', $valid_post_status, $post_id );
		$this_post_status  = get_post_status( $post_id );

		// Not all post types are created equal.
		$invalid_post_type   = array( 'nav_menu_item', 'revision' );
		$noarchive_post_type = array( 'post', 'page' );
		$this_post_type      = get_post_type( $post_id );

		/**
		 * Determine the route for the rest API
		 * This will need to be revisited if WP updates the version.
		 * Future me: Consider an array? 4.7-?? use v2, and then adapt from there?
		 */
		if ( version_compare( get_bloginfo( 'version' ), '4.7', '>=' ) ) {
			$json_disabled  = false;
			$json_disablers = array(
				'disable-json-api/disable-json-api.php',
			);

			foreach ( $json_disablers as $json_plugin ) {
				if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $json_plugin ) ) {
					$json_disabled = true;
				}
			}

			// If json is NOT disabled...
			if ( ! $json_disabled ) {
				$rest_api_route = 'wp/v2';
			}
		}

		// array to collect all our URLs.
		$listofurls = array();

		// Verify we have a permalink and that we're a valid post status and type.
		if ( false !== get_permalink( $post_id ) && in_array( $this_post_status, $valid_post_status, true ) && ! in_array( $this_post_type, $invalid_post_type, true ) ) {

			// If we're using tags, purge by tags and return.
			if ( get_site_option( 'vhp_varnish_use_tags' ) && class_exists( 'VarnishTags' ) ) {
				$tags = VarnishTags::get_purge_tags_for_post( $post_id );

				if ( $this->is_cron_purging_enabled() ) {
					$payload = array(
						'post_id' => $post_id,
						'tags'    => $tags,
					);

					/**
					 * Decide whether this tag-based purge for a post should bypass the cron queue.
					 *
					 * When this filter returns true and cron-mode is enabled, the plugin
					 * will send tag-based PURGE requests synchronously instead of queuing
					 * them for WP-Cron.
					 *
					 * @since 5.5.0
					 *
					 * @param bool  $bypass  Default false.
					 * @param string $context Context string. Here: 'post_tags'.
					 * @param array  $payload Array with 'post_id' and 'tags'.
					 */
					$bypass = apply_filters( 'vhp_purge_bypass_cron_for_request', false, 'post_tags', $payload );

					if ( $bypass ) {
						$this->purge_tags( $tags );
					} else {
						$this->enqueue_tags( $tags );
					}
				} else {
					$this->purge_tags( $tags );
				}

				return;
			}

			// Post URL.
			array_push( $listofurls, get_permalink( $post_id ) );

			/**
			 * JSON API Permalink for the post based on type
			 * We only want to do this if the rest_base exists
			 * But we apparently have to force it for posts and pages (seriously?)
			 */
			if ( isset( $rest_api_route ) ) {
				$post_type_object = get_post_type_object( $this_post_type );
				$rest_permalink   = false;
				if ( isset( $post_type_object->rest_base ) ) {
					$rest_permalink = get_rest_url() . $rest_api_route . '/' . $post_type_object->rest_base . '/' . $post_id . '/';
				} elseif ( 'post' === $this_post_type ) {
					$rest_permalink = get_rest_url() . $rest_api_route . '/posts/' . $post_id . '/';
				} elseif ( 'page' === $this_post_type ) {
					$rest_permalink = get_rest_url() . $rest_api_route . '/pages/' . $post_id . '/';
				}

				if ( isset( $rest_permalink ) ) {
					if ( is_string( $rest_permalink ) && '' !== $rest_permalink ) {
						array_push( $listofurls, $rest_permalink );
					}
				}

				// Category purge based on Donnacha's work in WP Super Cache.
				$categories = get_the_category( $post_id );
				if ( $categories ) {
					foreach ( $categories as $cat ) {
						array_push(
							$listofurls,
							get_category_link( $cat->term_id ),
							get_rest_url() . $rest_api_route . '/categories/' . $cat->term_id . '/'
						);
					}
				}

				// Tag purge based on Donnacha's work in WP Super Cache.
				$tags = get_the_tags( $post_id );
				if ( $tags ) {
					foreach ( $tags as $tag ) {
						array_push(
							$listofurls,
							get_tag_link( $tag->term_id ),
							get_rest_url() . $rest_api_route . '/tags/' . $tag->term_id . '/'
						);
					}
				}
				// Custom Taxonomies: Only show if the taxonomy is public.
				$taxonomies = get_post_taxonomies( $post_id );
				if ( $taxonomies ) {
					foreach ( $taxonomies as $taxonomy ) {
						$features = (array) get_taxonomy( $taxonomy );
						if ( $features['public'] ) {
							$terms = wp_get_post_terms( $post_id, $taxonomy );
							foreach ( $terms as $term ) {
								array_push(
									$listofurls,
									get_term_link( $term ),
									get_rest_url() . $rest_api_route . '/' . ( isset( $features['rest_base'] ) && ! empty( $features['rest_base'] ) ? $features['rest_base'] : $term->taxonomy ) . '/' . $term->term_id . '/'
								);
							}
						}
					}
				}

				// If the post is a post, we have more things to flush
				// Pages and Woo Things don't need all this.
				if ( $this_post_type && 'post' === $this_post_type ) {
					// Author URLs:
					$author_id = get_post_field( 'post_author', $post_id );
					array_push(
						$listofurls,
						get_author_posts_url( $author_id ),
						get_author_feed_link( $author_id ),
						get_rest_url() . $rest_api_route . '/users/' . $author_id . '/'
					);

					// Feeds:
					array_push(
						$listofurls,
						get_bloginfo_rss( 'rdf_url' ),
						get_bloginfo_rss( 'rss_url' ),
						get_bloginfo_rss( 'rss2_url' ),
						get_bloginfo_rss( 'atom_url' ),
						get_bloginfo_rss( 'comments_rss2_url' ),
						get_post_comments_feed_link( $post_id )
					);
				}
			}

			// Add in AMP permalink for official WP AMP plugin:
			// https://wordpress.org/plugins/amp/
			if ( function_exists( 'amp_get_permalink' ) ) {
				array_push( $listofurls, amp_get_permalink( $post_id ) );
			}

			// Regular AMP url for posts if ant of the following are active:
			// https://wordpress.org/plugins/accelerated-mobile-pages/
			if ( defined( 'AMPFORWP_AMP_QUERY_VAR' ) ) {
				array_push( $listofurls, get_permalink( $post_id ) . 'amp/' );
			}

			// Also clean URL for trashed post.
			if ( 'trash' === $this_post_status ) {
				$trashpost = get_permalink( $post_id );
				$trashpost = str_replace( '__trashed', '', $trashpost );
				array_push( $listofurls, $trashpost, $trashpost . 'feed/' );
			}

			// Archives and their feeds.
			if ( $this_post_type && ! in_array( $this_post_type, $noarchive_post_type, true ) ) {
				array_push(
					$listofurls,
					get_post_type_archive_link( get_post_type( $post_id ) ),
					get_post_type_archive_feed_link( get_post_type( $post_id ) )
					// Need to add in JSON?
				);
			}

			// Home Pages and (if used) posts page.
			array_push(
				$listofurls,
				get_rest_url(),
				user_trailingslashit( $this->the_home_url() )
			);
			if ( 'page' === get_site_option( 'show_on_front' ) ) {
				// Ensure we have a page_for_posts setting to avoid empty URL.
				if ( get_site_option( 'page_for_posts' ) ) {
					array_push( $listofurls, get_permalink( get_site_option( 'page_for_posts' ) ) );
				}
			}
		} else {
			// We're not sure how we got here, but bail instead of processing anything else.
			return;
		}

		// If the array isn't empty, proceed.
		if ( empty( $listofurls ) ) {
			return;
		} else {
			// Strip off query variables
			$listofurls = array_map(
				function ( $url ) {
					return strtok( $url, '?' );
				},
				$listofurls
			);

			// If the DOMAINS setup is defined, we duplicate the URLs
			if ( false !== VHP_DOMAINS ) {
				// Split domains into an array
				$domains = explode( ',', VHP_DOMAINS );
				$newurls = array();

				// Loop through all the domains
				foreach ( $domains as $a_domain ) {
					foreach ( $listofurls as $url ) {
						// If the URL contains the filtered home_url, and is NOT equal to the domain we're trying to replace, we will add it to the new urls
						if ( false !== strpos( $url, $this->the_home_url() ) && $this->the_home_url() !== $a_domain ) {
							$newurls[] = str_replace( $this->the_home_url(), $a_domain, $url );
						}
						// If the URL contains the raw home_url, and is NOT equal to the domain we're trying to replace, we will add it to the new urls
						if ( false !== strpos( $url, home_url() ) && home_url() !== $a_domain ) {
							$newurls[] = str_replace( home_url(), $a_domain, $url );
						}
					}
				}

				// Merge all the URLs
				array_push( $listofurls, ...$newurls );
			}

			// Make sure each URL only gets purged once, eh?
			$purgeurls = array_unique( $listofurls, SORT_REGULAR );

			// Flush all the URLs
			foreach ( $purgeurls as $url ) {
				array_push( $this->purge_urls, $url );
			}
		}

		/*
		 * Filter to add or remove urls to the array of purged urls
		 * @param array $purge_urls the urls (paths) to be purged
		 * @param int $post_id the id of the new/edited post
		 */
		$this->purge_urls = apply_filters( 'vhp_purge_urls', $this->purge_urls, $post_id );
	}

	// @codingStandardsIgnoreStart
	/*
	 * These have all been name changed to proper names, but just in case...
	 */
	public function getRegisterEvents() {
		self::get_register_events();
	}
	public function getNoIDEvents() {
		self::get_no_id_events();
	}
	public function executePurge() {
		self::execute_purge();
	}
	public function purgeNoID( $post_id ) {
		self::execute_purge_no_id( $post_id );
	}
	public function purgeURL( $url ) {
		self::purge_url( $url );
	}
	public function purgePost( $post_id ) {
		self::purge_post( $post_id );
	}
	// @codingStandardsIgnoreEnd
}

/**
 * Purge via WP-CLI
 *
 * @since 3.8
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	include 'wp-cli.php';
}

// Preventing people from forking this and hurting themselve by having two versions, though it may not work.
if ( ! class_exists( 'VarnishStatus' ) ) {
	/*
	* Settings Pages
	*
	* @since 4.0
	*/
	// The settings PAGES aren't needed on the network admin page
	if ( ! is_network_admin() ) {
		require_once 'settings.php';
	}

	require_once 'debug.php';
	require_once 'health-check.php';
	require_once 'varnish-tags.php';

	// Always instantiate VarnishTags; the option check is done per-request in add_headers()
	// to allow dynamic toggling without restarting PHP.
	new VarnishTags();

	$purger = new VarnishPurger();
}
