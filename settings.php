<?php
/**
 * Settings Code
 * @package varnish-http-purge
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Status Class
 *
 * @since 4.0
 */
class VarnishStatus {
	/**
	 * Construct
	 * Fires when class is constructed, adds init hook
	 *
	 * @since 4.0
	 */
	public function __construct() {
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_filter( 'admin_footer_text', array( &$this, 'admin_footer' ), 1, 2 );
		add_action( 'wp_ajax_vhp_cache_test', array( &$this, 'ajax_cache_test' ) );

		// Bypass purging for cache test posts.
		add_filter( 'varnish_http_purge_valid_post_statuses', array( &$this, 'skip_purge_for_test_posts' ), 10, 2 );
	}

	/**
	 * Skip purging for cache test posts
	 *
	 * Returns empty array to prevent any purge URLs from being generated
	 * for posts that are part of the cache testing workflow.
	 *
	 * @param array $statuses Valid post statuses.
	 * @param int   $post_id  Post ID.
	 * @return array
	 */
	public function skip_purge_for_test_posts( $statuses, $post_id ) {
		if ( get_post_meta( $post_id, '_vhp_cache_test', true ) ) {
			return array(); // Return empty to skip purging.
		}
		return $statuses;
	}

	/**
	 * Admin init Callback
	 *
	 * @since 4.0
	 */
	public function admin_init() {
		$this->register_settings();
		$this->register_check_caching();
	}

	/**
	 * Admin Menu Callback
	 *
	 * @since 4.0
	 */
	public function admin_menu() {
		add_menu_page( __( 'Proxy Cache Purge', 'varnish-http-purge' ), __( 'Proxy Cache', 'varnish-http-purge' ), 'manage_options', 'varnish-page', array( &$this, 'settings_page' ), VarnishPurger::get_icon_svg( true, '#82878c' ), 75 );
		add_submenu_page( 'varnish-page', __( 'Proxy Cache Purge', 'varnish-http-purge' ), __( 'Settings', 'varnish-http-purge' ), 'manage_options', 'varnish-page', array( &$this, 'settings_page' ) );
		add_submenu_page( 'varnish-page', __( 'Check Caching', 'varnish-http-purge' ), __( 'Check Caching', 'varnish-http-purge' ), 'manage_options', 'varnish-check-caching', array( &$this, 'check_caching_page' ) );
	}

	/**
	 * Register Settings
	 *
	 * @since 4.0.2
	 */
	public function register_settings() {
		// Development Mode Settings.
		register_setting( 'vhp-settings-devmode', 'vhp_varnish_devmode', array( &$this, 'settings_devmode_sanitize' ) );
		add_settings_section( 'vhp-settings-devmode-section', __( 'Development Mode Settings', 'varnish-http-purge' ), array( &$this, 'options_settings_devmode' ), 'varnish-devmode-settings' );
		add_settings_field( 'varnish_devmode', __( 'Development Mode', 'varnish-http-purge' ), array( &$this, 'settings_devmode_callback' ), 'varnish-devmode-settings', 'vhp-settings-devmode-section' );

		// Purge Method settings (Cache Tags)
		register_setting( 'vhp-settings-tags', 'vhp_varnish_use_tags', array( &$this, 'settings_tags_sanitize' ) );
		add_settings_section( 'vhp-settings-tags-section', __( 'Purge Method', 'varnish-http-purge' ), array( &$this, 'options_settings_tags' ), 'varnish-tags-settings' );
		add_settings_field( 'varnish_use_tags', __( 'Use Cache Tags', 'varnish-http-purge' ), array( &$this, 'settings_tags_callback' ), 'varnish-tags-settings', 'vhp-settings-tags-section' );

		// Purge All settings.
		register_setting( 'vhp-settings-maxposts', 'vhp_varnish_max_posts_before_all', array( &$this, 'settings_maxposts_sanitize' ) );
		add_settings_section( 'vhp-settings-maxposts-section', __( 'Maximum Individual URLs before Full Purge', 'varnish-http-purge' ), array( &$this, 'options_settings_maxposts' ), 'varnish-maxposts-settings' );
		add_settings_field( 'varnish_maxposts', __( 'Set Max URLs', 'varnish-http-purge' ), array( &$this, 'settings_maxposts_callback' ), 'varnish-maxposts-settings', 'vhp-settings-maxposts-section' );

		// IP Settings.
		register_setting( 'vhp-settings-ip', 'vhp_varnish_ip', array( &$this, 'settings_ip_sanitize' ) );
		add_settings_section( 'vhp-settings-ip-section', __( 'Configure Custom IP', 'varnish-http-purge' ), array( &$this, 'options_settings_ip' ), 'varnish-ip-settings' );
		add_settings_field( 'varnish_ip', __( 'Set Custom IP', 'varnish-http-purge' ), array( &$this, 'settings_ip_callback' ), 'varnish-ip-settings', 'vhp-settings-ip-section' );

		// Purge Headers settings.
		register_setting( 'vhp-settings-purgeheader', 'vhp_varnish_extra_purge_header_name', array( &$this, 'settings_purgeheaders_name_sanitize' ) );
		register_setting( 'vhp-settings-purgeheader', 'vhp_varnish_extra_purge_header_value', array( &$this, 'settings_purgeheaders_value_sanitize' ) );
		add_settings_section( 'vhp-settings-purgeheader-section', __( 'Purge Headers', 'varnish-http-purge' ), array( &$this, 'options_settings_purgeheaders' ), 'varnish-purgeheader-settings' );
		add_settings_field( 'varnish_purgeheaders_name', __( 'Set Purge Header Name', 'varnish-http-purge' ), array( &$this, 'settings_purgeheaders_name_callback' ), 'varnish-purgeheader-settings', 'vhp-settings-purgeheader-section' );
		add_settings_field( 'varnish_purgeheaders_value', __( 'Set Purge Header Value', 'varnish-http-purge' ), array( &$this, 'settings_purgeheaders_value_callback' ), 'varnish-purgeheader-settings', 'vhp-settings-purgeheader-section' );
	}

	/**
	 * Options Settings - Dev Mode
	 *
	 * @since 4.6
	 */
	public function options_settings_devmode() {
		?>
		<p><a name="#configuredevmode"></a><?php esc_html_e( 'In Development Mode, WordPress will prevent visitors from seeing cached content on your site. You can enable this for 24 hours, after which it will automatically disable itself. This will make your site run slower, so please use with caution.', 'varnish-http-purge' ); ?></p>
		<p><?php echo wp_kses_post( __( 'If you need to activate development mode for extended periods of time, you can add <code>define( \'VHP_DEVMODE\', true );</code> in your wp-config file.', 'varnish-http-purge' ) ); ?></p>
		<?php
	}

	/**
	 * Settings Dev Mode Callback
	 *
	 * @since 4.0
	 */
	public function settings_devmode_callback() {

		$devmode = get_site_option( 'vhp_varnish_devmode', VarnishPurger::$devmode );
		$active  = ( isset( $devmode['active'] ) ) ? $devmode['active'] : false;
		$active  = ( VHP_DEVMODE ) ? true : $active;
		$expire  = time() + DAY_IN_SECONDS;
		?>
		<input type="hidden" name="vhp_varnish_devmode[expire]" value="<?php echo esc_attr( $expire ); ?>" />
		<input type="checkbox" id="vhp_varnish_devmode_active" name="vhp_varnish_devmode[active]" value="true" <?php disabled( VHP_DEVMODE ); ?> <?php checked( $active, true ); ?> />
		<label for="vhp_varnish_devmode_active">
			<?php
			if ( $active && isset( $devmode['expire'] ) && ! VHP_DEVMODE ) {
				$timestamp = date_i18n( get_site_option( 'date_format' ), $devmode['expire'] ) . ' @ ' . date_i18n( get_site_option( 'time_format' ), $devmode['expire'] );
				// translators: %s is the time (in hours) until Development Mode expires.
				printf( esc_html__( 'Development Mode is active until %s. It will automatically disable after that time.', 'varnish-http-purge' ), esc_html( $timestamp ) );
			} elseif ( VHP_DEVMODE ) {
				esc_attr_e( 'Development Mode has been activated via wp-config and cannot be deactivated here.', 'varnish-http-purge' );
			} else {
				esc_attr_e( 'Activate Development Mode', 'varnish-http-purge' );
			}
			?>
		</label>
		<?php
	}

	/**
	 * Sanitization and validation for Dev Mode
	 *
	 * @param mixed $input - the input to be sanitized.
	 * @since 4.6.0
	 */
	public function settings_devmode_sanitize( $input ) {

		$output      = array();
		$expire      = time() + DAY_IN_SECONDS;
		$set_message = __( 'Something has gone wrong!', 'varnish-http-purge' );
		$set_type    = 'error';

		if ( empty( $input ) ) {
			return array(); // Return empty array instead of void.
		} else {
			$output['active'] = ( isset( $input['active'] ) ) ? $input['active'] : false;
			// Form input is always a string, so use is_numeric() instead of is_int().
			$output['expire'] = ( isset( $input['expire'] ) && is_numeric( $input['expire'] ) ) ? (int) $input['expire'] : $expire;
			$set_message      = ( $output['active'] ) ? __( 'Development Mode activated for the next 24 hours.', 'varnish-http-purge' ) : __( 'Development Mode deactivated.', 'varnish-http-purge' );
			$set_type         = 'updated';
		}

		// If it's true then we're activating so let's kill the cache.
		if ( $output['active'] ) {
			VarnishPurger::purge_url( VarnishPurger::the_home_url() . '/?vhp-regex' );
		}

		add_settings_error( 'vhp_varnish_devmode', 'varnish-devmode', $set_message, $set_type );
		return $output;
	}

	/**
	 * Options Settings - Tags
	 *
	 * @since 5.4.0
	 */
	public function options_settings_tags() {
		$supported = false;
		$source    = 'auto';

		// If VHP_VARNISH_TAGS is defined, treat it as an explicit override for detection.
		if ( defined( 'VHP_VARNISH_TAGS' ) ) {
			$supported = (bool) VHP_VARNISH_TAGS;
			$source    = $supported ? 'forced_on' : 'forced_off';
		} elseif ( class_exists( 'VarnishDebug' ) && method_exists( 'VarnishDebug', 'cache_tags_advertised' ) ) {
			$supported = VarnishDebug::cache_tags_advertised();
			$source    = $supported ? 'advertised' : 'none';
		}

		?>
		<p><a name="#configuretags"></a><?php esc_html_e( 'By default, the plugin purges specific URLs when content is updated. Modern cache setups support "Cache Tags" (also known as Surrogate Keys), which allow for more efficient and reliable purging. Enabling this option will replace URL-based purging with Tag-based purging.', 'varnish-http-purge' ); ?></p>
		<p><strong><?php esc_html_e( 'BETA:', 'varnish-http-purge' ); ?></strong> <?php esc_html_e( 'Cache Tags / Surrogate Keys support is experimental and should be enabled only after verifying that your cache (for example, Varnish) is correctly configured and tested in your environment.', 'varnish-http-purge' ); ?></p>
		<p><?php esc_html_e( 'This requires your cache layer to advertise support via standard Surrogate-Capability headers (for example, Surrogate-Capability: vhp="Surrogate/1.0 tags/1"), or you can explicitly force support using the VHP_VARNISH_TAGS define in wp-config.php.', 'varnish-http-purge' ); ?></p>
		<?php
		// Status message about detection / override.
		if ( $supported ) {
			echo '<p class="description" style="color:#46b450;">';
			if ( 'advertised' === $source ) {
				esc_html_e( 'Your cache server told WordPress that it supports Cache Tags / Surrogate Keys (via the Surrogate-Capability header). You can safely enable or disable this option.', 'varnish-http-purge' );
			} elseif ( 'forced_on' === $source ) {
				esc_html_e( 'Cache Tags / Surrogate Keys support has been forced on via the VHP_VARNISH_TAGS define in wp-config.php.', 'varnish-http-purge' );
			}
			echo '</p>';
		} else {
			echo '<p class="description">';
			if ( 'forced_off' === $source ) {
				esc_html_e( 'Cache Tags / Surrogate Keys support has been explicitly disabled via the VHP_VARNISH_TAGS define in wp-config.php.', 'varnish-http-purge' );
			} else {
				esc_html_e( 'Your cache server did not report support for Cache Tags / Surrogate Keys. To enable this setting, configure your cache to send a Surrogate-Capability header that advertises tag support (for example, Surrogate-Capability: vhp="Surrogate/1.0 tags/1") or define VHP_VARNISH_TAGS in wp-config.php.', 'varnish-http-purge' );
			}
			echo '</p>';
		}
		?>
		<div class="vhp-accordion vhp-accordion-vcl">
			<button type="button" class="vhp-accordion-toggle" aria-expanded="false" aria-controls="vhp-accordion-panel-vcl">
				<span class="dashicons dashicons-arrow-right" aria-hidden="true"></span>
				<span class="vhp-accordion-label"><?php esc_html_e( 'View VCL Snippet (tags via BAN)', 'varnish-http-purge' ); ?></span>
			</button>
			<div id="vhp-accordion-panel-vcl" class="vhp-accordion-panel" hidden>
			<pre style="background:#f0f0f0;padding:10px;overflow:auto;">
sub vcl_recv {
	if (req.method == "PURGE") {
		# ... acl check ...
		# Optional: validate a control header sent by the plugin (for example
		# via the VHP_VARNISH_EXTRA_PURGE_HEADER constant or the "Purge Headers" settings).
		# Adjust the header name and value to match your environment.
		#
		# if (req.http.X-Control-Key != "YOUR_CONTROL_KEY_HERE") {
		#     return (synth(403, "Forbidden"));
		# }

		if (req.http.X-Purge-Method == "tags" && req.http.X-Cache-Tags-Pattern) {
			ban("obj.http.X-Cache-Tags ~ " + req.http.X-Cache-Tags-Pattern);
			return (synth(200, "Banned by tags pattern"));
		}
	}
}
			</pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings Tags Callback
	 *
	 * @since 5.4.0
	 */
	public function settings_tags_callback() {
		$use_tags  = get_site_option( 'vhp_varnish_use_tags' );
		$supported = false;
		$source    = 'auto';

		// If VHP_VARNISH_TAGS is defined, treat it as an explicit override for detection.
		if ( defined( 'VHP_VARNISH_TAGS' ) ) {
			$supported = (bool) VHP_VARNISH_TAGS;
			$source    = $supported ? 'forced_on' : 'forced_off';
		} elseif ( class_exists( 'VarnishDebug' ) && method_exists( 'VarnishDebug', 'cache_tags_advertised' ) ) {
			$supported = VarnishDebug::cache_tags_advertised();
			$source    = $supported ? 'advertised' : 'none';
		}

		$disabled = ! $supported;
		?>
		<label for="vhp_varnish_use_tags">
			<input type="checkbox" id="vhp_varnish_use_tags" name="vhp_varnish_use_tags" value="1" <?php checked( $use_tags, 1 ); ?> <?php disabled( $disabled ); ?> />
			<?php esc_html_e( 'Enable Cache Tags (Surrogate Keys)', 'varnish-http-purge' ); ?>
		</label>
		<?php
		if ( $supported ) {
			echo '<p class="description" style="color:#46b450;">';
			if ( 'advertised' === $source ) {
				esc_html_e( 'Your cache server told WordPress that it supports Cache Tags / Surrogate Keys (via the Surrogate-Capability header). You can safely enable or disable this option.', 'varnish-http-purge' );
			} elseif ( 'forced_on' === $source ) {
				esc_html_e( 'Cache Tags / Surrogate Keys support has been forced on via the VHP_VARNISH_TAGS define in wp-config.php.', 'varnish-http-purge' );
			}
			echo '</p>';
		} else {
			echo '<p class="description">';
			if ( 'forced_off' === $source ) {
				esc_html_e( 'Cache Tags / Surrogate Keys support has been explicitly disabled via the VHP_VARNISH_TAGS define in wp-config.php.', 'varnish-http-purge' );
			} else {
				esc_html_e( 'Your cache server did not report support for Cache Tags / Surrogate Keys. To enable this setting, configure your cache to send a Surrogate-Capability header that advertises tag support (for example, Surrogate-Capability: vhp="Surrogate/1.0 tags/1") or define VHP_VARNISH_TAGS in wp-config.php.', 'varnish-http-purge' );
			}
			echo '</p>';
		}
	}

	/**
	 * Sanitization for Tags
	 *
	 * @since 5.4.0
	 */
	public function settings_tags_sanitize( $input ) {
		$supported = false;

		if ( class_exists( 'VarnishDebug' ) && method_exists( 'VarnishDebug', 'cache_tags_advertised' ) ) {
			$supported = VarnishDebug::cache_tags_advertised();
		}

		// If support is not advertised, force the option off.
		if ( ! $supported ) {
			return 0;
		}

		return ( isset( $input ) && 1 === (int) $input ) ? 1 : 0;
	}

	/**
	 * Options Settings - Max Posts before Purge All
	 *
	 * @since 4.0
	 */
	public function options_settings_maxposts() {
		?>
		<p><a name="#configuremaxposts"></a><?php esc_html_e( 'Since it\'s possible to purge multiple URLs in sequence, there can be cases where too many URLs are queued at a time. In order to minimize disruption, the plugin has a limit of how many URLs can be queued before it runs a "purge all" instead. You can customize that value here.', 'varnish-http-purge' ); ?></strong></p>
		<?php
	}

	/**
	 * Settings - Max Posts before purge All
	 *
	 * @since 4.0
	 */
	public function settings_maxposts_callback() {

		$disabled = false;
		if ( defined( 'VHP_VARNISH_MAXPOSTS' ) && false !== VHP_VARNISH_MAXPOSTS ) {
			$disabled  = true;
			$max_posts = VHP_VARNISH_MAXPOSTS;
		} else {
			$max_posts = get_site_option( 'vhp_varnish_max_posts_before_all' );
		}

		?>
		<input type="number" id="vhp_varnish_max_posts_before_all" name="vhp_varnish_max_posts_before_all" value="<?php echo esc_attr( $max_posts ); ?>" size="5" <?php disabled( $disabled, true ); ?> />
		<label for="vhp_varnish_max_posts_before_all">&nbsp;
		<?php
		if ( $disabled ) {
			esc_html_e( 'A maximum value has been defined in your wp-config file, so it is not editable in settings.', 'varnish-http-purge' );
		} else {
			esc_html_e( 'The default value is "50" URLs. It is not recommended to set this above 75.', 'varnish-http-purge' );
		}
		echo '</label>';
	}

	/**
	 * Sanitization and validation for Max Posts before purge All
	 *
	 * @param mixed $input - the input to be sanitized.
	 * @since 4.0
	 */
	public function settings_maxposts_sanitize( $input ) {

		// default settings.
		$output      = get_site_option( 'vhp_varnish_max_posts_before_all' );
		$set_message = __( 'You have entered an invalid number.', 'varnish-http-purge' );
		$set_type    = 'error';

		if ( empty( $input ) ) {
			// No input, return existing value to prevent data loss.
			return $output;
		} elseif ( is_numeric( $input ) ) {
			// If it's numeric, update.
			$set_message = __( 'Number of Maximum URLs before a purge have been updated.', 'varnish-http-purge' );
			$set_type    = 'updated';
			$output      = (int) $input;
		}

		add_settings_error( 'vhp_varnish_max_posts_before_all', 'varnish-maxposts', $set_message, $set_type );
		return $output;
	}

	/**
	 * Options Settings - IP Address
	 *
	 * @since 4.0
	 */
	public function options_settings_ip() {
		?>
		<p><a name="#configureip"></a><?php esc_html_e( 'There are cases when a custom IP Address is needed to for the plugin to properly communicate with the cache service. If you\'re using a CDN like Cloudflare or a Firewall Proxy like Sucuri, or your cache is Nginx based, you may need to customize this setting.', 'varnish-http-purge' ); ?></p>
		<p><?php esc_html_e( 'Normally your Proxy Cache IP is the IP address of the server where your caching service (i.e. Varnish or Nginx) is installed. It must an address used by your cache service. If you use multiple IPs, or have customized your ACLs, you\'ll need to pick one that doesn\'t conflict with your other settings. For example, if you have your proxy cache listening on a public and private IP, pick the private. On the other hand, if you told Varnish to listen on 0.0.0.0 (i.e. "listen on every interface you can") you would need to check what IP you set your purge ACL to allow (commonly 127.0.0.1 aka localhost), and use that (i.e. 127.0.0.1 or localhost).', 'varnish-http-purge' ); ?></p>
		<p><?php esc_html_e( 'You may use multiple IPs by separating them with a comma (,). If you do so, all IPs will be sent purge requests. This can be useful if you\'re using round robin DNS entries or hosting multiple proxy cache solutions (i.e. Nginx and Varnish).', 'varnish-http-purge' ); ?></p>
		<p><?php esc_html_e( 'If your webhost set the service up for you, as is the case with DreamPress or WP Engine, ask them for the specifics.', 'varnish-http-purge' ); ?></p>
		<p><strong><?php esc_html_e( 'If you aren\'t sure what to do, contact your webhost or server admin before making any changes.', 'varnish-http-purge' ); ?></strong></p>
		<?php
	}

	/**
	 * Settings IP Callback
	 *
	 * @since 4.0
	 */
	public function settings_ip_callback() {

		$disabled = false;
		if ( defined( 'VHP_VARNISH_IP' ) && false !== VHP_VARNISH_IP ) {
			$disabled  = true;
			$varniship = VHP_VARNISH_IP;
		} else {
			$varniship = get_site_option( 'vhp_varnish_ip' );
		}

		if ( is_array( $varniship ) ) {
			$list_varniship = implode( ',', $varniship );
		} else {
			$list_varniship = $varniship;
		}

		?>
		<input type="text" id="vhp_varnish_ip" name="vhp_varnish_ip" value="<?php echo esc_attr( $list_varniship ); ?>" size="25" <?php disabled( $disabled, true ); ?> />
		<label for="vhp_varnish_ip">&nbsp;
		<?php

		if ( $disabled ) {
			esc_html_e( 'A Proxy Cache IP has been defined in your wp-config file, so it is not editable in settings.', 'varnish-http-purge' );
		} else {
			echo '<br />';
			esc_html_e( 'Examples: ', 'varnish-http-purge' );
			echo '<br /><code>123.45.67.89</code><br /><code>localhost</code><br /><code>12.34.56.78, 23.45.67.89</code>';
		}

		echo '</label>';
	}

	/**
	 * Sanitization and validation for IP
	 *
	 * @param mixed $input - the input to be sanitized.
	 * @since 4.0
	 */
	public function settings_ip_sanitize( $input ) {

		$output      = '';
		$set_message = __( 'You have entered an invalid IP address.', 'varnish-http-purge' );
		$set_type    = 'error';

		if ( empty( $input ) ) {
			// Empty input is valid - clears the IP setting.
			return '';
		} elseif ( strpos( $input, ',' ) !== false ) {
			// Turn IPs into an array
			$ips       = array_map( 'trim', explode( ',', $input ) );
			$valid_ips = array();

			foreach ( $ips as $ip ) {
				$valid_ips[] = sanitize_text_field( $ip );
			}
			// If all the IPs are valid, then we can carry on.
			if ( ! empty( $valid_ips ) ) {
				$set_message = __( 'Proxy Cache IPs Updated', 'varnish-http-purge' );
				$set_type    = 'updated';
				$output      = implode( ', ', $valid_ips );
			}
		} else {
			$set_message = __( 'Proxy Cache IP Updated', 'varnish-http-purge' );
			$set_type    = 'updated';
			$output      = sanitize_text_field( $input );
		}

		add_settings_error( 'vhp_varnish_ip', 'varnish-ip', $set_message, $set_type );
		return $output;
	}

	/**
	 * Options Settings - Purge Headers
	 */
	public function options_settings_purgeheaders() {
		?>
		<p><a id="configurepurgeheaders"></a>
			<strong><?php esc_html_e( 'Advanced:', 'varnish-http-purge' ); ?></strong>
			<?php esc_html_e( 'Only configure this if your hosting provider or cache service explicitly documents a required control or Authorization header for PURGE requests. If you are not sure, leave these fields empty.', 'varnish-http-purge' ); ?>
		</p>
		<?php
	}

	/**
	 * Settings - Purge Headers Name
	 */
	public function settings_purgeheaders_name_callback() {

		$disabled = false;
		if ( defined( 'VHP_VARNISH_EXTRA_PURGE_HEADER' ) && false !== VHP_VARNISH_EXTRA_PURGE_HEADER ) {
			$disabled    = true;
			$header_name = explode( ':', VHP_VARNISH_EXTRA_PURGE_HEADER )[0];
		} else {
			$header_name = get_site_option( 'vhp_varnish_extra_purge_header_name' );
		}

		?>
		<input type="text" id="vhp_varnish_extra_purge_header_name" name="vhp_varnish_extra_purge_header_name" value="<?php echo esc_attr( $header_name ); ?>" size="25" <?php disabled( $disabled, true ); ?> />
		<label for="vhp_varnish_extra_purge_header_name">&nbsp;
		<?php
		if ( $disabled ) {
			esc_html_e( 'The header has been defined in your wp-config file, so it is not editable in settings.', 'varnish-http-purge' );
		}
		echo '</label>';
	}

	/**
	 * Settings - Purge Headers Value
	 */
	public function settings_purgeheaders_value_callback() {

		$disabled = false;
		if ( defined( 'VHP_VARNISH_EXTRA_PURGE_HEADER' ) && false !== VHP_VARNISH_EXTRA_PURGE_HEADER ) {
			$disabled     = true;
			$header_value = '';

			if ( strpos( VHP_VARNISH_EXTRA_PURGE_HEADER, ':' ) !== false ) {
				$parts = explode( ':', VHP_VARNISH_EXTRA_PURGE_HEADER, 2 );
				if ( isset( $parts[1] ) ) {
					$header_value = trim( $parts[1] );
				}
			}

			// If the constant is malformed (no value part), fall back to the stored option
			// so that the field still displays something meaningful.
			if ( '' === $header_value ) {
				$header_value = get_site_option( 'vhp_varnish_extra_purge_header_value' );
			}
		} else {
			$header_value = get_site_option( 'vhp_varnish_extra_purge_header_value' );
		}

		?>
		<input type="password" id="vhp_varnish_extra_purge_header_value" name="vhp_varnish_extra_purge_header_value" value="<?php echo esc_attr( $header_value ); ?>" size="25" <?php disabled( $disabled, true ); ?> autocomplete="off" />
		<label for="vhp_varnish_extra_purge_header_value">&nbsp;
		<?php
		if ( $disabled ) {
			esc_html_e( 'The value has been defined in your wp-config file, so it is not editable in settings.', 'varnish-http-purge' );
		}
		echo '</label>';
	}

	public function settings_purgeheaders_name_sanitize( $input ) {
		$output      = '';
		$set_message = '';
		$set_type    = 'updated';

		if ( ! is_string( $input ) || '' === trim( $input ) ) {
			// Treat empty or non-string input as a request to clear the header.
			$set_message = __( 'Purge header name cleared.', 'varnish-http-purge' );
			$output      = '';
		} else {
			$set_message = __( 'Purge header name updated.', 'varnish-http-purge' );
			$output      = sanitize_text_field( $input );
		}

		if ( $set_message ) {
			add_settings_error( 'vhp_varnish_extra_purge_header_name', 'varnish-purgeheader', $set_message, $set_type );
		}

		return $output;
	}

	public function settings_purgeheaders_value_sanitize( $input ) {
		$output      = '';
		$set_message = '';
		$set_type    = 'updated';

		if ( ! is_string( $input ) || '' === trim( $input ) ) {
			// Treat empty or non-string input as a request to clear the header value.
			$set_message = __( 'Purge header value cleared.', 'varnish-http-purge' );
			$output      = '';
		} else {
			$set_message = __( 'Purge header value updated.', 'varnish-http-purge' );
			$output      = sanitize_text_field( $input );
		}

		if ( $set_message ) {
			add_settings_error( 'vhp_varnish_extra_purge_header_value', 'varnish-purgeheader', $set_message, $set_type );
		}

		return $output;
	}

	/**
	 * Register Check Caching
	 *
	 * @since 4.0
	 */
	public function register_check_caching() {
		register_setting( 'varnish-http-purge-url', 'vhp_varnish_url', array( &$this, 'varnish_url_sanitize' ) );
		// Empty title since the tab already provides the heading.
		add_settings_section( 'varnish-url-settings-section', '', array( &$this, 'options_check_caching_scan' ), 'varnish-url-settings' );
		add_settings_field( 'varnish_url', __( 'Check A URL On Your Site: ', 'varnish-http-purge' ), array( &$this, 'check_caching_callback' ), 'varnish-url-settings', 'varnish-url-settings-section' );
	}

	/**
	 * Options Callback - URL Scanner
	 *
	 * @since 4.0
	 */
	public function options_check_caching_scan() {
		// If there's no post made, let's not...
		// @codingStandardsIgnoreStart
		if ( ! isset( $_REQUEST['settings-updated'] ) || ! $_REQUEST['settings-updated'] ) {
			return;
		}
		// @codingStandardsIgnoreEnd

		// Set icons.
		$icons = array(
			'awesome' => '<span class="dashicons dashicons-heart" style="color:#46B450;"></span>',
			'good'    => '<span class="dashicons dashicons-thumbs-up" style="color:#00A0D2;"></span>',
			'warning' => '<span class="dashicons dashicons-warning" style="color:#FFB900"></span>',
			'notice'  => '<span class="dashicons dashicons-flag" style="color:#826EB4;">',
			'bad'     => '<span class="dashicons dashicons-thumbs-down" style="color:#DC3232;"></span>',
		);

		// Get the base URL to start.
		$url        = esc_url( VarnishPurger::the_home_url() );
		$varnishurl = get_site_option( 'vhp_varnish_url', $url );

		// Is this a good URL?
		$valid_url = VarnishDebug::is_url_valid( $varnishurl );
		if ( 'valid' === $valid_url ) {
			// Get the response and headers.
			$remote_get = VarnishDebug::remote_get( $varnishurl );
			$headers    = wp_remote_retrieve_headers( $remote_get );

			// Preflight checklist.
			$preflight = VarnishDebug::preflight( $remote_get );

			// Check for Remote IP.
			$remote_ip = VarnishDebug::remote_ip( $headers );

			// Get the IP.
			if ( defined( 'VHP_VARNISH_IP' ) && false !== VHP_VARNISH_IP ) {
				$varniship = VHP_VARNISH_IP;
			} else {
				$varniship = get_site_option( 'vhp_varnish_ip' );
			}
			?>

			<h4>
			<?php
				// translators: %s is the URL someone asked to scan.
				printf( esc_html__( 'Results for %s ', 'varnish-http-purge' ), esc_url_raw( $varnishurl ) );
			?>
			</h4>

			<table class="wp-list-table widefat fixed posts">

			<?php
			// If we failed the preflight checks, we fail.
			if ( ! $preflight['preflight'] ) {
				?>
				<tr>
					<td width="40px"><?php echo wp_kses_post( $icons['bad'] ); ?></td>
					<td><?php echo wp_kses_post( $preflight['message'] ); ?></td>
				</tr>
				<?php
			} else {
				// We passed the checks, let's get the data!
				$output = VarnishDebug::get_all_the_results( $headers, $remote_ip, $varniship );

				foreach ( $output as $subject => $item ) {
					if ( $item && is_array( $item ) ) {
						?>
							<tr>
								<td width="20px"><?php echo wp_kses_post( $icons[ $item['icon'] ] ); ?></td>
								<td width="180px"><strong><?php echo wp_kses_post( $subject ); ?></strong></td>
								<td><?php echo wp_kses_post( $item['message'] ); ?></td>
							</tr>
							<?php
					}
				}
			}
			?>
			</table>

			<?php
			if ( false !== $preflight['preflight'] ) {
				?>
				<h4><?php esc_html_e( 'Technical Details', 'varnish-http-purge' ); ?></h4>
				<table class="wp-list-table widefat fixed posts">
					<?php
					if ( ! empty( $headers[0] ) ) {
						echo '<tr><td width="200px">&nbsp;</td><td>' . wp_kses_post( $headers[0] ) . '</td></tr>';
					}
					foreach ( $headers as $header => $key ) {
						if ( '0' !== $header ) {
							if ( is_array( $key ) ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
								$content = print_r( $key, true );
							} else {
								$content = esc_html( $key );
							}
							echo '<tr><td width="200px" style="text-align:right;">' . wp_kses_post( ucfirst( $header ) ) . ':</td><td>' . wp_kses_post( $content ) . '</td></tr>';
						}
					}
					?>
				</table>
				<?php
			}
		}
	}

	/**
	 * URL Callback
	 *
	 * @since 4.0
	 */
	public function check_caching_callback() {
		$url        = esc_url( VarnishPurger::the_home_url() );
		$varnishurl = get_site_option( 'vhp_varnish_url', $url );
		echo '<input type="text" id="vhp_varnish_url" name="vhp_varnish_url" value="' . esc_url( $varnishurl ) . '" size="50" />';
	}

	/**
	 * Sanitization and validation for URL
	 *
	 * @param mixed $input - the input to be sanitized.
	 * @since 4.0
	 */
	public function varnish_url_sanitize( $input ) {

		// Defaults values.
		$output   = esc_url( VarnishPurger::the_home_url() );
		$set_type = 'error';

		if ( empty( $input ) ) {
			$set_message = __( 'You must enter a URL from your own domain to scan.', 'varnish-http-purge' );
		} else {
			$valid_url = VarnishDebug::is_url_valid( esc_url( $input ) );

			switch ( $valid_url ) {
				case 'empty':
				case 'domain':
					$set_message = __( 'You must provide a URL on your own domain to scan.', 'varnish-http-purge' );
					break;
				case 'invalid':
					$set_message = __( 'You have entered an invalid URL address.', 'varnish-http-purge' );
					break;
				case 'valid':
					$set_type    = 'updated';
					$set_message = __( 'URL Scanned.', 'varnish-http-purge' );
					$output      = esc_url( $input );
					break;
				default:
					$set_message = __( 'An unknown error has occurred.', 'varnish-http-purge' );
					break;
			}
		}

		if ( isset( $set_message ) ) {
			add_settings_error( 'vhp_varnish_url', 'varnish-url', $set_message, $set_type );
		}
		return $output;
	}

	/**
	 * Call settings page
	 *
	 * @since 4.0
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<?php settings_errors(); ?>
			<h1><?php esc_html_e( 'Proxy Cache Purge Settings', 'varnish-http-purge' ); ?></h1>

			<p><?php esc_html_e( 'Proxy Cache Purge can empty the cache for different server based caching systems, including Varnish and nginx. For most users, there should be no configuration necessary as the plugin is intended to work silently, behind the scenes.', 'varnish-http-purge' ); ?></p>

			<?php
			if ( ! is_multisite() ) {
				// Background purge queue status (shown only when cron-mode is active).
				if ( class_exists( 'VarnishPurger' ) && VarnishPurger::is_cron_purging_enabled_static() ) {
					$queue = get_site_option( VarnishPurger::PURGE_QUEUE_OPTION, array() );

					$full        = ( isset( $queue['full'] ) && $queue['full'] );
					$urls        = ( isset( $queue['urls'] ) && is_array( $queue['urls'] ) ) ? $queue['urls'] : array();
					$tags        = ( isset( $queue['tags'] ) && is_array( $queue['tags'] ) ) ? $queue['tags'] : array();
					$created_at  = isset( $queue['created_at'] ) ? (int) $queue['created_at'] : 0;
					$last_run_at = (int) get_site_option( 'vhp_varnish_last_queue_run', 0 );

					$urls_count = count( $urls );
					$tags_count = count( $tags );
					?>
					<div class="notice notice-info" style="margin-top:1em;">
						<p><strong><?php esc_html_e( 'Background purge queue (WP-Cron)', 'varnish-http-purge' ); ?></strong></p>
						<p>
							<?php esc_html_e( 'Because DISABLE_WP_CRON is enabled, purge requests are queued and processed by WP-Cron instead of running during admin/page requests.', 'varnish-http-purge' ); ?>
						</p>
						<ul>
							<li>
								<?php
								printf(
									/* translators: %s is Yes/No. */
									esc_html__( 'Full-site purge queued: %s', 'varnish-http-purge' ),
									$full ? esc_html__( 'Yes', 'varnish-http-purge' ) : esc_html__( 'No', 'varnish-http-purge' )
								);
								?>
							</li>
							<li>
								<?php
								printf(
									/* translators: %d is a count of URLs. */
									esc_html__( 'Queued URLs: %d', 'varnish-http-purge' ),
									(int) $urls_count
								);
								?>
							</li>
							<li>
								<?php
								printf(
									/* translators: %d is a count of tags. */
									esc_html__( 'Queued cache tags: %d', 'varnish-http-purge' ),
									(int) $tags_count
								);
								?>
							</li>
							<?php if ( $created_at > 0 ) : ?>
								<li>
									<?php
									printf(
										/* translators: %s is a human-readable time difference. */
										esc_html__( 'Queue age: %s', 'varnish-http-purge' ),
										esc_html( human_time_diff( $created_at, time() ) )
									);
									?>
								</li>
							<?php endif; ?>
							<?php if ( $last_run_at > 0 ) : ?>
								<li>
									<?php
									printf(
										/* translators: %s is a human-readable time difference. */
										esc_html__( 'Last queue run: %s ago', 'varnish-http-purge' ),
										esc_html( human_time_diff( $last_run_at, time() ) )
									);
									?>
								</li>
							<?php endif; ?>
						</ul>
					</div>
					<?php
				}
				// Show notice when cron purging is explicitly disabled via constant.
				if ( defined( 'VHP_DISABLE_CRON_PURGING' ) && VHP_DISABLE_CRON_PURGING ) {
					?>
					<div class="notice notice-info" style="margin-top:1em;">
						<p><strong><?php esc_html_e( 'Background purging disabled', 'varnish-http-purge' ); ?></strong></p>
						<p>
							<?php esc_html_e( 'The VHP_DISABLE_CRON_PURGING constant is set to true in your wp-config.php. Cache purges will execute immediately instead of being queued for background processing.', 'varnish-http-purge' ); ?>
						</p>
					</div>
					<?php
				}
				?>
				<form action="options.php" method="POST" >
				<?php
					settings_fields( 'vhp-settings-devmode' );
					do_settings_sections( 'varnish-devmode-settings' );
					submit_button( __( 'Save Devmode Settings', 'varnish-http-purge' ), 'primary' );
				?>
				</form>

				<form action="options.php" method="POST" >
				<?php
					settings_fields( 'vhp-settings-tags' );
					do_settings_sections( 'varnish-tags-settings' );
					submit_button( __( 'Save Purge Method', 'varnish-http-purge' ), 'primary' );
				?>
				</form>

				<form action="options.php" method="POST" >
				<?php
					settings_fields( 'vhp-settings-maxposts' );
					do_settings_sections( 'varnish-maxposts-settings' );
					submit_button( __( 'Save Maxposts Settings', 'varnish-http-purge' ), 'primary' );
				?>
				</form>

				<form action="options.php" method="POST" >
				<?php
					settings_fields( 'vhp-settings-ip' );
					do_settings_sections( 'varnish-ip-settings' );
					submit_button( __( 'Save IP Settings', 'varnish-http-purge' ), 'secondary' );
				?>
				</form>

				<form action="options.php" method="POST" >
					<?php settings_fields( 'vhp-settings-purgeheader' ); ?>
					<div class="vhp-accordion vhp-accordion-purgeheaders">
						<button type="button" class="vhp-accordion-toggle" aria-expanded="false" aria-controls="vhp-accordion-panel-purgeheaders">
							<span class="dashicons dashicons-arrow-right" aria-hidden="true"></span>
							<span class="vhp-accordion-label"><?php esc_html_e( 'Advanced: Purge Headers', 'varnish-http-purge' ); ?></span>
						</button>
						<div id="vhp-accordion-panel-purgeheaders" class="vhp-accordion-panel" hidden>
							<?php do_settings_sections( 'varnish-purgeheader-settings' ); ?>
						</div>
					</div>
					<?php submit_button( __( 'Save Purge Header Settings', 'varnish-http-purge' ), 'primary' ); ?>
				</form>
				<?php
			} else {
				?>
				<p><?php esc_html_e( 'Editing these settings via the Dashboard is disabled on Multisite as incorrect edits can prevent your network from loading entirely. You can toggle debug mode globally using the admin toolbar option, and you should define your Proxy IP directly into your wp-config file for best results.', 'varnish-http-purge' ); ?></p>
				<p><?php esc_html_e( 'The cache check page remains available to assist you in determining if pages on your site are properly cached by your server.', 'varnish-http-purge' ); ?></p>
				<?php
			}
			?>
		</div>
		<style>
		.vhp-accordion {
			margin-top: 1.5em;
			margin-bottom: 0.5em;
		}
		.vhp-accordion .vhp-accordion-toggle {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			font-size: 14px;
			font-weight: 600;
			margin: 0 0 0.5em 0;
			background: none;
			border: 0;
			padding: 0;
			color: #1d2327;
			cursor: pointer;
		}
		.vhp-accordion .vhp-accordion-toggle:hover {
			color: #2271b1;
		}
		.vhp-accordion .vhp-accordion-toggle .dashicons {
			font-size: 16px;
			line-height: 1;
			transition: transform 0.15s ease-in-out;
		}
		.vhp-accordion .vhp-accordion-panel {
			margin-left: 1.5em;
		}
		</style>
		<script>
		( function() {
			document.addEventListener( 'DOMContentLoaded', function() {
				var accordions = document.querySelectorAll( '.vhp-accordion' );
				if ( ! accordions.length ) {
					return;
				}

				accordions.forEach( function( container ) {
					var button = container.querySelector( '.vhp-accordion-toggle' );
					var panel  = container.querySelector( '.vhp-accordion-panel' );
					if ( ! button || ! panel ) {
						return;
					}
					var icon = button.querySelector( '.dashicons' );

					button.addEventListener( 'click', function() {
						var expanded = button.getAttribute( 'aria-expanded' ) === 'true';
						button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
						panel.hidden = expanded;

						if ( icon ) {
							icon.classList.toggle( 'dashicons-arrow-right', expanded );
							icon.classList.toggle( 'dashicons-arrow-down', ! expanded );
						}
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Call the Check Caching
	 *
	 * @since 4.6.0
	 */
	public function check_caching_page() {
		?>
		<div class="wrap">

			<?php settings_errors(); ?>
			<h1><?php esc_html_e( 'Is Caching Working?', 'varnish-http-purge' ); ?></h1>

			<nav class="nav-tab-wrapper vhp-tabs">
				<a href="#vhp-tab-e2e" class="nav-tab nav-tab-active" data-tab="vhp-tab-e2e">
					<span class="dashicons dashicons-superhero-alt"></span>
					<?php esc_html_e( 'End-to-End Test', 'varnish-http-purge' ); ?>
				</a>
				<a href="#vhp-tab-headers" class="nav-tab" data-tab="vhp-tab-headers">
					<span class="dashicons dashicons-visibility"></span>
					<?php esc_html_e( 'Header Analysis', 'varnish-http-purge' ); ?>
				</a>
			</nav>

			<!-- Tab 1: End-to-End Cache Test -->
			<div id="vhp-tab-e2e" class="vhp-tab-content vhp-tab-active">
				<h2><?php esc_html_e( 'End-to-End Cache & Purge Test', 'varnish-http-purge' ); ?></h2>
			<p><?php esc_html_e( 'This comprehensive test verifies that caching AND purging are both working correctly. It creates a test post, verifies it gets cached, modifies it, confirms the cache serves stale content, triggers a purge, and verifies fresh content is served.', 'varnish-http-purge' ); ?></p>

			<?php
			// Show current configuration.
			$varniship  = ( VHP_VARNISH_IP !== false ) ? VHP_VARNISH_IP : get_site_option( 'vhp_varnish_ip' );
			$ip_display = empty( $varniship ) ? __( '(not configured - using site hostname)', 'varnish-http-purge' ) : esc_html( is_array( $varniship ) ? implode( ', ', $varniship ) : $varniship );
			?>
			<p><strong><?php esc_html_e( 'Cache Target:', 'varnish-http-purge' ); ?></strong> <?php echo esc_html( $ip_display ); ?></p>

			<button type="button" id="vhp-cache-test-btn" class="button button-primary button-hero">
				<span class="dashicons dashicons-superhero" style="margin-top: 4px;"></span>
				<?php esc_html_e( 'Run Full Cache Test', 'varnish-http-purge' ); ?>
			</button>

			<div id="vhp-cache-test-container" style="margin-top: 2em; display: none;">
				<div class="vhp-test-steps">
					<div class="vhp-step" data-step="connectivity">
						<div class="vhp-step-icon"><span class="dashicons dashicons-cloud"></span></div>
						<div class="vhp-step-content">
							<div class="vhp-step-title"><?php esc_html_e( 'Connectivity Test', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-desc"><?php esc_html_e( 'Verify connection to cache server', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-details"></div>
						</div>
						<div class="vhp-step-status"></div>
					</div>

					<div class="vhp-step" data-step="create_post">
						<div class="vhp-step-icon"><span class="dashicons dashicons-edit-page"></span></div>
						<div class="vhp-step-content">
							<div class="vhp-step-title"><?php esc_html_e( 'Create Test Post', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-desc"><?php esc_html_e( 'Create a temporary post with unique content', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-details"></div>
						</div>
						<div class="vhp-step-status"></div>
					</div>

					<div class="vhp-step" data-step="prime_cache">
						<div class="vhp-step-icon"><span class="dashicons dashicons-database-add"></span></div>
						<div class="vhp-step-content">
							<div class="vhp-step-title"><?php esc_html_e( 'Prime Cache', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-desc"><?php esc_html_e( 'Request the post to populate the cache', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-details"></div>
						</div>
						<div class="vhp-step-status"></div>
					</div>

					<div class="vhp-step" data-step="verify_caching">
						<div class="vhp-step-icon"><span class="dashicons dashicons-saved"></span></div>
						<div class="vhp-step-content">
							<div class="vhp-step-title"><?php esc_html_e( 'Verify Caching', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-desc"><?php esc_html_e( 'Modify post and confirm cache serves stale content', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-details"></div>
						</div>
						<div class="vhp-step-status"></div>
					</div>

					<div class="vhp-step" data-step="trigger_purge">
						<div class="vhp-step-icon"><span class="dashicons dashicons-trash"></span></div>
						<div class="vhp-step-content">
							<div class="vhp-step-title"><?php esc_html_e( 'Trigger Purge', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-desc"><?php esc_html_e( 'Send PURGE request to invalidate cached content', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-details"></div>
						</div>
						<div class="vhp-step-status"></div>
					</div>

					<div class="vhp-step" data-step="verify_purge">
						<div class="vhp-step-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="vhp-step-content">
							<div class="vhp-step-title"><?php esc_html_e( 'Verify Purge', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-desc"><?php esc_html_e( 'Confirm fresh content is now served', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-details"></div>
						</div>
						<div class="vhp-step-status"></div>
					</div>

					<div class="vhp-step" data-step="cleanup">
						<div class="vhp-step-icon"><span class="dashicons dashicons-dismiss"></span></div>
						<div class="vhp-step-content">
							<div class="vhp-step-title"><?php esc_html_e( 'Cleanup', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-desc"><?php esc_html_e( 'Delete test post and temporary data', 'varnish-http-purge' ); ?></div>
							<div class="vhp-step-details"></div>
						</div>
						<div class="vhp-step-status"></div>
					</div>
				</div>

				<div id="vhp-test-summary" class="vhp-test-summary" style="display: none;"></div>
			</div>

			<style>
			.vhp-test-steps {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				overflow: hidden;
			}
			.vhp-step {
				display: flex;
				align-items: flex-start;
				padding: 16px 20px;
				border-bottom: 1px solid #f0f0f1;
				transition: background-color 0.3s ease;
			}
			.vhp-step:last-child {
				border-bottom: none;
			}
			.vhp-step.is-active {
				background: linear-gradient(90deg, #f0f6fc 0%, #fff 100%);
			}
			.vhp-step.is-success {
				background: linear-gradient(90deg, #edfaef 0%, #fff 100%);
			}
			.vhp-step.is-error {
				background: linear-gradient(90deg, #fcf0f1 0%, #fff 100%);
			}
			.vhp-step.is-warning {
				background: linear-gradient(90deg, #fef8ee 0%, #fff 100%);
			}
			.vhp-step-icon {
				width: 40px;
				height: 40px;
				border-radius: 50%;
				background: #f0f0f1;
				display: flex;
				align-items: center;
				justify-content: center;
				margin-right: 16px;
				flex-shrink: 0;
				transition: all 0.3s ease;
			}
			.vhp-step-icon .dashicons {
				font-size: 20px;
				width: 20px;
				height: 20px;
				color: #646970;
			}
			.vhp-step.is-active .vhp-step-icon {
				background: #2271b1;
				animation: vhp-pulse 1.5s infinite;
			}
			.vhp-step.is-active .vhp-step-icon .dashicons {
				color: #fff;
			}
			.vhp-step.is-success .vhp-step-icon {
				background: #00a32a;
			}
			.vhp-step.is-success .vhp-step-icon .dashicons {
				color: #fff;
			}
			.vhp-step.is-error .vhp-step-icon {
				background: #d63638;
			}
			.vhp-step.is-error .vhp-step-icon .dashicons {
				color: #fff;
			}
			.vhp-step.is-warning .vhp-step-icon {
				background: #dba617;
			}
			.vhp-step.is-warning .vhp-step-icon .dashicons {
				color: #fff;
			}
			@keyframes vhp-pulse {
				0%, 100% { box-shadow: 0 0 0 0 rgba(34, 113, 177, 0.4); }
				50% { box-shadow: 0 0 0 10px rgba(34, 113, 177, 0); }
			}
			.vhp-step-content {
				flex: 1;
				min-width: 0;
			}
			.vhp-step-title {
				font-weight: 600;
				color: #1d2327;
				margin-bottom: 2px;
			}
			.vhp-step-desc {
				color: #646970;
				font-size: 13px;
			}
			.vhp-step-details {
				margin-top: 8px;
				font-size: 12px;
				color: #50575e;
				font-family: monospace;
				max-height: 0;
				overflow: hidden;
				transition: max-height 0.3s ease;
			}
			.vhp-step-details.is-visible {
				max-height: 200px;
			}
			.vhp-step-details code {
				background: #f6f7f7;
				padding: 2px 6px;
				border-radius: 3px;
				display: inline-block;
				margin: 2px 0;
			}
			.vhp-step-status {
				width: 28px;
				text-align: center;
				flex-shrink: 0;
			}
			.vhp-step-status .spinner {
				float: none;
				margin: 0;
			}
			.vhp-step-status .dashicons {
				font-size: 24px;
				width: 24px;
				height: 24px;
			}
			.vhp-test-summary {
				margin-top: 20px;
				padding: 20px;
				border-radius: 4px;
				text-align: center;
			}
			.vhp-test-summary.is-success {
				background: linear-gradient(135deg, #00a32a 0%, #007017 100%);
				color: #fff;
			}
			.vhp-test-summary.is-error {
				background: linear-gradient(135deg, #d63638 0%, #8a2424 100%);
				color: #fff;
			}
			.vhp-test-summary.is-warning {
				background: linear-gradient(135deg, #dba617 0%, #996800 100%);
				color: #fff;
			}
			.vhp-test-summary h3 {
				margin: 0 0 8px 0;
				font-size: 18px;
				color: inherit;
			}
			.vhp-test-summary p {
				margin: 0;
				opacity: 0.9;
			}
			.vhp-test-summary .dashicons {
				font-size: 48px;
				width: 48px;
				height: 48px;
				margin-bottom: 10px;
			}
			#vhp-cache-test-btn .dashicons {
				vertical-align: middle;
				margin-right: 4px;
			}
			</style>

			<script>
			(function() {
				document.addEventListener('DOMContentLoaded', function() {
					var btn = document.getElementById('vhp-cache-test-btn');
					var container = document.getElementById('vhp-cache-test-container');
					var summary = document.getElementById('vhp-test-summary');
					var nonce = '<?php echo esc_js( wp_create_nonce( 'vhp_cache_test' ) ); ?>';

					if (!btn) return;

					var steps = ['connectivity', 'create_post', 'prime_cache', 'verify_caching', 'trigger_purge', 'verify_purge', 'cleanup'];
					var testData = {};

					function getStepEl(step) {
						return document.querySelector('.vhp-step[data-step="' + step + '"]');
					}

					function setStepStatus(step, status, details) {
						var el = getStepEl(step);
						if (!el) return;

						el.classList.remove('is-active', 'is-success', 'is-error', 'is-warning');
						var statusEl = el.querySelector('.vhp-step-status');
						var detailsEl = el.querySelector('.vhp-step-details');

						if (status === 'active') {
							el.classList.add('is-active');
							statusEl.innerHTML = '<span class="spinner is-active"></span>';
						} else if (status === 'success') {
							el.classList.add('is-success');
							statusEl.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>';
						} else if (status === 'error') {
							el.classList.add('is-error');
							statusEl.innerHTML = '<span class="dashicons dashicons-warning" style="color:#d63638;"></span>';
						} else if (status === 'warning') {
							el.classList.add('is-warning');
							statusEl.innerHTML = '<span class="dashicons dashicons-flag" style="color:#dba617;"></span>';
						} else {
							statusEl.innerHTML = '';
						}

						if (details) {
							detailsEl.innerHTML = details;
							detailsEl.classList.add('is-visible');
						}
					}

					function resetSteps() {
						steps.forEach(function(step) {
							var el = getStepEl(step);
							if (el) {
								el.classList.remove('is-active', 'is-success', 'is-error', 'is-warning');
								el.querySelector('.vhp-step-status').innerHTML = '';
								var detailsEl = el.querySelector('.vhp-step-details');
								detailsEl.innerHTML = '';
								detailsEl.classList.remove('is-visible');
							}
						});
						summary.style.display = 'none';
						summary.className = 'vhp-test-summary';
					}

					async function runStep(step) {
						setStepStatus(step, 'active');

						var formData = new FormData();
						formData.append('action', 'vhp_cache_test');
						formData.append('nonce', nonce);
						formData.append('step', step);

						try {
							var response = await fetch(ajaxurl, {
								method: 'POST',
								body: formData,
								credentials: 'same-origin'
							});
							var data = await response.json();

							if (!data.success) {
								throw new Error(data.data ? data.data.message : 'Unknown error');
							}

							return data.data;
						} catch (err) {
							throw err;
						}
					}

					function formatHeaders(headers) {
						if (!headers || Object.keys(headers).length === 0) return '';
						var html = '';
						for (var key in headers) {
							if (headers.hasOwnProperty(key)) {
								html += '<code>' + key + ': ' + headers[key] + '</code> ';
							}
						}
						return html;
					}

					async function runAllTests() {
						btn.disabled = true;
						container.style.display = 'block';
						resetSteps();
						testData = {};

						var finalStatus = 'success';
						var finalMessage = '';

						try {
							// Step 1: Connectivity
							var conn = await runStep('connectivity');
							if (conn.all_ok) {
								setStepStatus('connectivity', 'success', '<?php echo esc_js( __( 'Connected to:', 'varnish-http-purge' ) ); ?> <code>' + conn.target_ip + '</code>');
							} else {
								setStepStatus('connectivity', 'error', '<?php echo esc_js( __( 'Connection failed', 'varnish-http-purge' ) ); ?>');
								throw new Error('<?php echo esc_js( __( 'Cannot connect to cache server. Check your Cache IP configuration.', 'varnish-http-purge' ) ); ?>');
							}

							// Step 2: Create test post
							var post = await runStep('create_post');
							testData.post_id = post.post_id;
							testData.post_url = post.post_url;
							setStepStatus('create_post', 'success', '<?php echo esc_js( __( 'Created post:', 'varnish-http-purge' ) ); ?> <a href="' + post.post_url + '" target="_blank">#' + post.post_id + '</a>');

							// Step 3: Prime cache
							var prime = await runStep('prime_cache');
							var primeDetails = '<?php echo esc_js( __( 'HTTP', 'varnish-http-purge' ) ); ?> ' + prime.status_code;
							if (Object.keys(prime.cache_headers).length > 0) {
								primeDetails += '<br>' + formatHeaders(prime.cache_headers);
							}
							setStepStatus('prime_cache', 'success', primeDetails);

							// Step 4: Verify caching works
							await new Promise(r => setTimeout(r, 1500)); // Wait for cache to settle
							var verify = await runStep('verify_caching');

							if (verify.caching_works) {
								var cacheDetails = '<?php echo esc_js( __( 'Cache is serving stale content', 'varnish-http-purge' ) ); ?> ✓';
								if (Object.keys(verify.cache_headers).length > 0) {
									cacheDetails += '<br>' + formatHeaders(verify.cache_headers);
								}
								setStepStatus('verify_caching', 'success', cacheDetails);
							} else if (verify.has_new_content) {
								setStepStatus('verify_caching', 'warning', '<?php echo esc_js( __( 'Cache is NOT active! Fresh content served immediately. Your server may not be caching, or this URL is excluded from caching.', 'varnish-http-purge' ) ); ?>');
								finalStatus = 'warning';
								finalMessage = '<?php echo esc_js( __( 'Caching does not appear to be active for this content. The cache server is reachable, but content changes are immediately visible without purging. Check your cache configuration.', 'varnish-http-purge' ) ); ?>';
							} else {
								setStepStatus('verify_caching', 'warning', '<?php echo esc_js( __( 'Unexpected content state. Cache behavior unclear.', 'varnish-http-purge' ) ); ?>');
								finalStatus = 'warning';
							}

							// Step 5: Trigger purge
							var purge = await runStep('trigger_purge');
							setStepStatus('trigger_purge', 'success', '<?php echo esc_js( __( 'PURGE sent for:', 'varnish-http-purge' ) ); ?> <code>' + purge.post_url + '</code>');

							// Step 6: Verify purge
							await new Promise(r => setTimeout(r, 1000)); // Wait for purge to propagate
							var verifyPurge = await runStep('verify_purge');

							if (verifyPurge.purge_works) {
								var purgeDetails = '<?php echo esc_js( __( 'Fresh content now being served', 'varnish-http-purge' ) ); ?> ✓';
								if (verifyPurge.attempts > 1) {
									purgeDetails += ' <?php echo esc_js( __( '(after', 'varnish-http-purge' ) ); ?> ' + verifyPurge.attempts + ' <?php echo esc_js( __( 'requests)', 'varnish-http-purge' ) ); ?>';
								}
								if (Object.keys(verifyPurge.cache_headers).length > 0) {
									purgeDetails += '<br>' + formatHeaders(verifyPurge.cache_headers);
								}
								setStepStatus('verify_purge', 'success', purgeDetails);

								if (finalStatus === 'success') {
									if (verifyPurge.is_softpurge || verifyPurge.attempts > 2) {
										finalMessage = '<?php echo esc_js( __( 'Excellent! Your cache is working correctly. It appears you are using softpurge or grace-based caching, which serves stale content briefly while fetching fresh content in the background. This is optimal for production!', 'varnish-http-purge' ) ); ?>';
									} else {
										finalMessage = '<?php echo esc_js( __( 'Excellent! Your cache is working perfectly. Content is being cached AND purges are invalidating stale content correctly.', 'varnish-http-purge' ) ); ?>';
									}
								}
							} else {
								var errorMsg = '<?php echo esc_js( __( 'Purge verification timed out after', 'varnish-http-purge' ) ); ?> ' + verifyPurge.attempts + ' <?php echo esc_js( __( 'attempts.', 'varnish-http-purge' ) ); ?>';
								setStepStatus('verify_purge', 'warning', errorMsg);
								if (finalStatus === 'success') {
									finalStatus = 'warning';
									finalMessage = '<?php echo esc_js( __( 'Caching works, but purge verification timed out. If you use softpurge with a long grace period, this may be expected. The PURGE request was accepted but fresh content was not served within our timeout. Your purges may still be working - check your Varnish logs or try manually.', 'varnish-http-purge' ) ); ?>';
								}
							}

							// Step 7: Cleanup
							await runStep('cleanup');
							setStepStatus('cleanup', 'success', '<?php echo esc_js( __( 'Test post deleted', 'varnish-http-purge' ) ); ?>');

						} catch (err) {
							finalStatus = 'error';
							finalMessage = err.message || '<?php echo esc_js( __( 'Test failed unexpectedly.', 'varnish-http-purge' ) ); ?>';

							// Still try to cleanup
							try {
								await runStep('cleanup');
								setStepStatus('cleanup', 'success', '<?php echo esc_js( __( 'Cleaned up', 'varnish-http-purge' ) ); ?>');
							} catch (e) {
								// Ignore cleanup errors
							}
						}

						// Show summary
						summary.style.display = 'block';
						summary.classList.add('is-' + finalStatus);

						var icon, title;
						if (finalStatus === 'success') {
							icon = 'dashicons-yes-alt';
							title = '<?php echo esc_js( __( 'All Tests Passed!', 'varnish-http-purge' ) ); ?>';
						} else if (finalStatus === 'warning') {
							icon = 'dashicons-warning';
							title = '<?php echo esc_js( __( 'Partial Success', 'varnish-http-purge' ) ); ?>';
						} else {
							icon = 'dashicons-dismiss';
							title = '<?php echo esc_js( __( 'Test Failed', 'varnish-http-purge' ) ); ?>';
						}

						summary.innerHTML = '<span class="dashicons ' + icon + '"></span><h3>' + title + '</h3><p>' + finalMessage + '</p>';

						btn.disabled = false;
					}

					btn.addEventListener('click', runAllTests);
				});
			})();
			</script>

			</div><!-- end vhp-tab-e2e -->

			<!-- Tab 2: Header Analysis (Legacy) -->
			<div id="vhp-tab-headers" class="vhp-tab-content">
				<h2><?php esc_html_e( 'Check Caching Status', 'varnish-http-purge' ); ?></h2>
				<p><?php esc_html_e( 'This tool analyzes HTTP headers returned by your server to detect caching. It relies on heuristics and may not detect all cache configurations.', 'varnish-http-purge' ); ?></p>
				<p><em><?php esc_html_e( 'Note: Header analysis can show false negatives if your cache doesn\'t expose standard headers. Use the End-to-End Test for definitive results.', 'varnish-http-purge' ); ?></em></p>

				<form action="options.php" method="POST" >
				<?php
					settings_fields( 'varnish-http-purge-url' );
					do_settings_sections( 'varnish-url-settings' );
					submit_button( __( 'Check URL', 'varnish-http-purge' ), 'primary' );
				?>
				</form>
			</div><!-- end vhp-tab-headers -->

			<style>
			.vhp-tabs {
				margin-bottom: 0;
				border-bottom: 1px solid #c3c4c7;
			}
			.vhp-tabs .nav-tab {
				display: inline-flex;
				align-items: center;
				gap: 6px;
			}
			.vhp-tabs .nav-tab .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
			}
			.vhp-tab-content {
				display: none;
				padding: 20px 0;
			}
			.vhp-tab-content.vhp-tab-active {
				display: block;
			}
			</style>

			<script>
			(function() {
				document.addEventListener('DOMContentLoaded', function() {
					var tabs = document.querySelectorAll('.vhp-tabs .nav-tab');
					var contents = document.querySelectorAll('.vhp-tab-content');
					var storageKey = 'vhp-cache-check-active-tab';

					function activateTab(targetId, updateHash) {
						if (!targetId) {
							return;
						}

						tabs.forEach(function(t) { t.classList.remove('nav-tab-active'); });
						contents.forEach(function(c) { c.classList.remove('vhp-tab-active'); });

						var target = document.getElementById(targetId);
						var activeTab = document.querySelector('.nav-tab[data-tab="' + targetId + '"]');

						if (activeTab) {
							activeTab.classList.add('nav-tab-active');
						}
						if (target) {
							target.classList.add('vhp-tab-active');
						}

						try {
							sessionStorage.setItem(storageKey, targetId);
						} catch (err) {
							// Ignore storage issues (for example, private mode).
						}

						if (updateHash) {
							history.replaceState(null, '', '#' + targetId);
						}
					}

					tabs.forEach(function(tab) {
						tab.addEventListener('click', function(e) {
							e.preventDefault();
							var targetId = this.getAttribute('data-tab');
							activateTab(targetId, true);
						});
					});

				// Check URL hash on load
				var hash = window.location.hash.replace('#', '');
				var storedTab = null;
				try {
					storedTab = sessionStorage.getItem(storageKey);
				} catch (err) {
					// Ignore storage issues (for example, private mode).
				}

				// If settings-updated is in URL, the Header Analysis form was just submitted,
				// so we should show that tab to display the results.
				var urlParams = new URLSearchParams(window.location.search);
				var settingsUpdated = urlParams.get('settings-updated');

				if (hash && document.getElementById(hash)) {
					activateTab(hash, false);
				} else if (settingsUpdated) {
					// Form was submitted, show the Headers tab where results appear.
					activateTab('vhp-tab-headers', true);
				} else if (storedTab && document.getElementById(storedTab)) {
					activateTab(storedTab, false);
				}
				});
			})();
			</script>

		</div>
		<?php
	}

	/**
	 * When user is on one of our admin pages, display footer text
	 * that graciously asks them to rate us.
	 *
	 * @since 4.6.4
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	public function admin_footer( $text ) {

		global $current_screen;

		if ( ! empty( $current_screen->parent_base ) && strpos( $current_screen->parent_base, 'varnish-page' ) !== false ) {
			$review_url       = 'https://wordpress.org/support/plugin/varnish-http-purge/reviews/?filter=5#new-post';
			$getpagespeed_url = 'https://www.getpagespeed.com/';
			$footer_text      = sprintf(
				wp_kses(
					/* translators: $1$s - GetPageSpeed URL; $2$s - plugin name; $3$s - WP.org review link; $4$s - WP.org review link. */
					__( 'Maintained by <a href="%1$s" target="_blank" rel="noopener noreferrer">GetPageSpeed</a>. Please rate %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">&#9733;&#9733;&#9733;&#9733;&#9733;</a> on <a href="%4$s" target="_blank" rel="noopener">WordPress.org</a> to help us spread the word.', 'varnish-http-purge' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				),
				$getpagespeed_url,
				'<strong>Proxy Cache Purge</strong>',
				$review_url,
				$review_url
			);
			$text = $footer_text;
		}

		return $text;
	}

	/**
	 * AJAX handler for comprehensive cache test
	 *
	 * Performs step-by-step end-to-end cache verification:
	 * 1. Connectivity test
	 * 2. Create test post
	 * 3. Prime cache
	 * 4. Verify caching works (stale content)
	 * 5. Trigger purge
	 * 6. Verify purge works (fresh content)
	 * 7. Cleanup
	 *
	 * @since 5.5.3
	 */
	public function ajax_cache_test() {
		// Check permissions and nonce.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'varnish-http-purge' ) ) );
		}

		check_ajax_referer( 'vhp_cache_test', 'nonce' );

		$step = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : '';

		switch ( $step ) {
			case 'connectivity':
				$this->cache_test_connectivity();
				break;
			case 'create_post':
				$this->cache_test_create_post();
				break;
			case 'prime_cache':
				$this->cache_test_prime_cache();
				break;
			case 'verify_caching':
				$this->cache_test_verify_caching();
				break;
			case 'trigger_purge':
				$this->cache_test_trigger_purge();
				break;
			case 'verify_purge':
				$this->cache_test_verify_purge();
				break;
			case 'cleanup':
				$this->cache_test_cleanup();
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Invalid test step.', 'varnish-http-purge' ) ) );
		}
	}

	/**
	 * Step 1: Test connectivity to cache server
	 */
	private function cache_test_connectivity() {
		$url = esc_url( VarnishPurger::the_home_url() );
		$p   = wp_parse_url( $url );

		if ( ! isset( $p['host'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not parse home URL.', 'varnish-http-purge' ) ) );
		}

		$varniship = ( VHP_VARNISH_IP !== false ) ? VHP_VARNISH_IP : get_site_option( 'vhp_varnish_ip' );

		if ( ! is_array( $varniship ) && ! empty( $varniship ) && strpos( $varniship, ',' ) !== false ) {
			$varniship = array_map( 'trim', explode( ',', $varniship ) );
		}

		if ( isset( $varniship ) && ! empty( $varniship ) ) {
			$all_hosts = ( ! is_array( $varniship ) ) ? array( $varniship ) : $varniship;
		} else {
			$all_hosts = array( $p['host'] );
		}

		$results = array();

		foreach ( $all_hosts as $one_host ) {
			$host_headers    = $p['host'];
			$server_hostname = gethostname();
			$schema          = ( substr( $server_hostname, 0, 3 ) === 'dp-' ) ? 'https://' : 'http://';
			$path            = isset( $p['path'] ) ? $p['path'] : '/';
			$purgeme         = $schema . $one_host . $path;

			$response = wp_remote_request(
				$purgeme,
				array(
					'sslverify' => false,
					'method'    => 'PURGE',
					'headers'   => array( 'host' => $host_headers ),
					'timeout'   => 10,
				)
			);

			$result = array( 'host' => $one_host );

			if ( is_wp_error( $response ) ) {
				$result['success'] = false;
				$result['error']   = $response->get_error_message();
				$result['code']    = 0;
			} else {
				$code              = wp_remote_retrieve_response_code( $response );
				$result['success'] = ( $code >= 200 && $code < 500 );
				$result['code']    = $code;
			}

			$results[] = $result;
		}

		$all_ok = ! empty( $results ) && array_reduce(
			$results,
			function ( $carry, $r ) {
				return $carry && $r['success'];
			},
			true
		);

		wp_send_json_success(
			array(
				'results'   => $results,
				'all_ok'    => $all_ok,
				'target_ip' => is_array( $all_hosts ) ? implode( ', ', $all_hosts ) : $all_hosts,
			)
		);
	}

	/**
	 * Step 2: Create a test post with unique content
	 */
	private function cache_test_create_post() {
		$unique_id      = wp_generate_uuid4();
		$unique_content = 'VHP_CACHE_TEST_MARKER_' . $unique_id;

		$post_id = wp_insert_post(
			array(
				'post_title'   => 'VHP Cache Test - ' . gmdate( 'Y-m-d H:i:s' ),
				'post_content' => $unique_content,
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'meta_input'   => array(
					'_vhp_cache_test'    => true,
					'_vhp_test_marker'   => $unique_content,
					'_vhp_test_original' => $unique_content,
				),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Store the test post ID for cleanup.
		update_option( 'vhp_cache_test_post_id', $post_id );
		update_option( 'vhp_cache_test_marker', $unique_content );

		$post_url = get_permalink( $post_id );

		wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'post_url' => $post_url,
				'marker'   => $unique_content,
			)
		);
	}

	/**
	 * Translate URL for internal Docker/container networking.
	 *
	 * Uses the vhp_debug_check_url filter to translate public URLs
	 * to internal URLs that are reachable from within the container.
	 *
	 * @param string $url The public URL.
	 * @return array{url: string, args: array} Internal URL and request args.
	 */
	private function translate_url_for_internal_request( $url ) {
		$internal_url = apply_filters( 'vhp_debug_check_url', $url );
		$internal_url = esc_url( $internal_url );

		$args = array(
			'sslverify' => false,
			'timeout'   => 15,
		);

		// If URL was translated, add Host header.
		if ( $internal_url !== $url ) {
			$parsed = wp_parse_url( $url );
			if ( ! empty( $parsed['host'] ) ) {
				$host = $parsed['host'];
				if ( ! empty( $parsed['port'] ) ) {
					$host .= ':' . $parsed['port'];
				}
				$args['headers'] = array( 'Host' => $host );
			}
		}

		return array(
			'url'  => $internal_url,
			'args' => $args,
		);
	}

	/**
	 * Step 3: Prime the cache by requesting the post
	 */
	private function cache_test_prime_cache() {
		$post_id = get_option( 'vhp_cache_test_post_id' );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Test post not found.', 'varnish-http-purge' ) ) );
		}

		$post_url = get_permalink( $post_id );
		$marker   = get_option( 'vhp_cache_test_marker' );

		// Translate URL for internal networking (Docker, etc.).
		$request = $this->translate_url_for_internal_request( $post_url );

		// Make multiple requests to ensure cache is primed.
		for ( $i = 0; $i < 3; $i++ ) {
			$response = wp_remote_get( $request['url'], $request['args'] );

			if ( ! is_wp_error( $response ) ) {
				break;
			}
			usleep( 500000 ); // 0.5 second delay between retries.
		}

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$body    = wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$code    = wp_remote_retrieve_response_code( $response );

		// Check if our marker is in the response.
		$marker_found = ( strpos( $body, $marker ) !== false );

		// Get cache-related headers.
		$cache_headers = array();
		$header_names  = array( 'x-cache', 'x-varnish', 'age', 'via', 'x-cache-status', 'cf-cache-status' );
		foreach ( $header_names as $name ) {
			if ( isset( $headers[ $name ] ) ) {
				$cache_headers[ $name ] = $headers[ $name ];
			}
		}

		wp_send_json_success(
			array(
				'post_url'      => $post_url,
				'status_code'   => $code,
				'marker_found'  => $marker_found,
				'cache_headers' => $cache_headers,
			)
		);
	}

	/**
	 * Step 4: Modify post directly in DB (bypassing purge) and verify caching
	 */
	private function cache_test_verify_caching() {
		global $wpdb;

		$post_id = get_option( 'vhp_cache_test_post_id' );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Test post not found.', 'varnish-http-purge' ) ) );
		}

		$original_marker = get_option( 'vhp_cache_test_marker' );
		$new_marker      = 'VHP_CACHE_TEST_UPDATED_' . wp_generate_uuid4();

		// Update post content directly in DB to bypass WordPress hooks (and thus purge).
		// We must also update post_modified so that conditional requests (If-Modified-Since)
		// from Varnish's background fetch after softpurge will get fresh content, not 304.
		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => $new_marker,
				'post_modified'     => $now,
				'post_modified_gmt' => $now_gmt,
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		// Store the new marker.
		update_option( 'vhp_cache_test_new_marker', $new_marker );

		// Clear WordPress object cache to ensure we're not getting cached DB results.
		clean_post_cache( $post_id );

		// Wait a moment for DB to sync.
		usleep( 500000 );

		// Now fetch the page - it should still show the OLD content if caching works.
		$post_url = get_permalink( $post_id );
		$request  = $this->translate_url_for_internal_request( $post_url );
		$response = wp_remote_get( $request['url'], $request['args'] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$body    = wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );

		$has_old_content = ( strpos( $body, $original_marker ) !== false );
		$has_new_content = ( strpos( $body, $new_marker ) !== false );

		// Get Age header if present.
		$age_header = isset( $headers['age'] ) ? (int) $headers['age'] : null;

		// Caching is working if we see the OLD content (stale/cached).
		$caching_works = $has_old_content && ! $has_new_content;

		// Get cache headers for display.
		$cache_headers = array();
		$header_names  = array( 'x-cache', 'x-varnish', 'age', 'via', 'x-cache-status' );
		foreach ( $header_names as $name ) {
			if ( isset( $headers[ $name ] ) ) {
				$cache_headers[ $name ] = $headers[ $name ];
			}
		}

		wp_send_json_success(
			array(
				'caching_works'   => $caching_works,
				'has_old_content' => $has_old_content,
				'has_new_content' => $has_new_content,
				'age_header'      => $age_header,
				'cache_headers'   => $cache_headers,
				'original_marker' => $original_marker,
				'new_marker'      => $new_marker,
			)
		);
	}

	/**
	 * Step 5: Trigger purge for the test post
	 */
	private function cache_test_trigger_purge() {
		$post_id = get_option( 'vhp_cache_test_post_id' );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Test post not found.', 'varnish-http-purge' ) ) );
		}

		$post_url = get_permalink( $post_id );

		// Call the purge method directly.
		VarnishPurger::purge_url( $post_url );

		wp_send_json_success(
			array(
				'post_url'   => $post_url,
				'purge_sent' => true,
			)
		);
	}

	/**
	 * Step 6: Verify purge worked (fresh content is served)
	 *
	 * Note: This handles both regular purge and softpurge configurations.
	 * Softpurge marks content as stale but serves it during grace period
	 * while fetching fresh content in background.
	 *
	 * For softpurge, the flow is:
	 * 1. PURGE marks object stale (TTL=0, grace intact)
	 * 2. First visit triggers background fetch (but returns stale content)
	 * 3. Background fetch completes
	 * 4. Subsequent visits get fresh content
	 *
	 * So we need to: trigger fetch, wait, then verify.
	 */
	private function cache_test_verify_purge() {
		$post_id    = get_option( 'vhp_cache_test_post_id' );
		$new_marker = get_option( 'vhp_cache_test_new_marker' );

		if ( ! $post_id || ! $new_marker ) {
			wp_send_json_error( array( 'message' => __( 'Test data not found.', 'varnish-http-purge' ) ) );
		}

		$post_url = get_permalink( $post_id );
		$request  = $this->translate_url_for_internal_request( $post_url );

		// Step 1: Make a "trigger" request immediately after purge.
		// For softpurge, this triggers the background fetch even though
		// it will return stale content.
		wp_remote_get( $request['url'], $request['args'] );

		// Step 2: Wait for background fetch to complete.
		// Backend fetch typically takes 0.5-2 seconds.
		sleep( 2 );

		// Step 3: Now check if fresh content is served.
		// Make multiple attempts in case of slow backend or network latency.
		$purge_works    = false;
		$max_attempts   = 5;
		$cache_headers  = array();
		$has_new_marker = false;
		$is_softpurge   = false;
		$actual_attempt = 0;

		for ( $i = 0; $i < $max_attempts; $i++ ) {
			$actual_attempt = $i + 1;
			$response       = wp_remote_get( $request['url'], $request['args'] );

			if ( is_wp_error( $response ) ) {
				sleep( 1 );
				continue;
			}

			$body    = wp_remote_retrieve_body( $response );
			$headers = wp_remote_retrieve_headers( $response );

			$has_new_marker = ( strpos( $body, $new_marker ) !== false );

			// Collect cache headers.
			$header_names = array( 'x-cache', 'x-varnish', 'age', 'via', 'x-cache-status' );
			foreach ( $header_names as $name ) {
				if ( isset( $headers[ $name ] ) ) {
					$cache_headers[ $name ] = $headers[ $name ];
				}
			}

			// Check for softpurge indicators.
			// Age header at 0 or very low after our trigger request indicates softpurge worked.
			if ( isset( $headers['age'] ) && (int) $headers['age'] <= 2 ) {
				$is_softpurge = true;
			}

			if ( $has_new_marker ) {
				$purge_works = true;
				break;
			}

			// Wait before retry. Increasing delay.
			sleep( 1 + $i );
		}

		wp_send_json_success(
			array(
				'purge_works'     => $purge_works,
				'has_new_content' => $has_new_marker,
				'cache_headers'   => $cache_headers,
				'attempts'        => $actual_attempt,
				'is_softpurge'    => $is_softpurge,
			)
		);
	}

	/**
	 * Step 7: Cleanup - delete test post and options
	 */
	private function cache_test_cleanup() {
		$post_id = get_option( 'vhp_cache_test_post_id' );

		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}

		delete_option( 'vhp_cache_test_post_id' );
		delete_option( 'vhp_cache_test_marker' );
		delete_option( 'vhp_cache_test_new_marker' );

		wp_send_json_success( array( 'cleaned' => true ) );
	}
}

new VarnishStatus();
