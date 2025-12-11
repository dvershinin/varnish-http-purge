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
			return; // do nothing.
		} else {
			$output['active'] = ( isset( $input['active'] ) ) ? $input['active'] : false;
			$output['expire'] = ( isset( $input['expire'] ) && is_int( $input['expire'] ) ) ? $input['expire'] : $expire;
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
			// No input, do nothing.
			return;
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
			return; // do nothing.
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
		add_settings_section( 'varnish-url-settings-section', __( 'Check Caching Status', 'varnish-http-purge' ), array( &$this, 'options_check_caching_scan' ), 'varnish-url-settings' );
		add_settings_field( 'varnish_url', __( 'Check A URL On Your Site: ', 'varnish-http-purge' ), array( &$this, 'check_caching_callback' ), 'varnish-url-settings', 'varnish-url-settings-section' );
	}

	/**
	 * Options Callback - URL Scanner
	 *
	 * @since 4.0
	 */
	public function options_check_caching_scan() {
		?>
		<p><?php esc_html_e( 'This feature performs a check of the most common issues that prevents your site from caching properly. This feature is provided to help you in resolve potential conflicts on your own. When filing an issue with your web-host, we recommend you include the output in your ticket.', 'varnish-http-purge' ); ?></p>
		<?php

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

			<form action="options.php" method="POST" >
			<?php
				settings_fields( 'varnish-http-purge-url' );
				do_settings_sections( 'varnish-url-settings' );
				submit_button( __( 'Check URL', 'varnish-http-purge' ), 'primary' );
			?>
			</form>

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
}

new VarnishStatus();
