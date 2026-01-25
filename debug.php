<?php
/**
 * Debug Code
 * @package varnish-http-purge
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Varnish Debug
 *
 * @since 4.4
 */
class VarnishDebug {

	/**
	 * Devmode Check
	 * See if Dev Mode is active.
	 *
	 * @since 4.6.0
	 * @return bool
	 */
	public static function devmode_check() {
		$return  = false;
		$newmode = get_site_option( 'vhp_varnish_devmode', VarnishPurger::$devmode );

		if ( VHP_DEVMODE ) {
			// If the define is set, we're true.
			$return = true;
		} elseif ( isset( $newmode['active'] ) && $newmode['active'] ) {
			$return = true;
			if ( isset( $newmode['expire'] ) && $newmode['expire'] <= time() ) {
				// If expire is less than NOW, it's over.
				self::devmode_toggle( 'deactivate' );
				$return = false;
			}
		}

		return $return;
	}

	/**
	 * Toggle devmode on or off.
	 *
	 * @access public
	 * @static
	 * @param string $state (default: 'deactivate').
	 * @return bool
	 */
	public static function devmode_toggle( $state = 'deactivate' ) {
		$newmode           = get_site_option( 'vhp_varnish_devmode', VarnishPurger::$devmode );
		$newmode['expire'] = time() + DAY_IN_SECONDS;

		switch ( sanitize_text_field( $state ) ) {
			case 'activate':
				$newmode['active'] = true;
				break;
			case 'toggle':
				$newmode['active'] = ( self::devmode_check() ) ? false : true;
				break;
			case 'deactivate':
			default:
				$newmode['active'] = false;
				break;
		}

		// No matter what, when we mess with this, flush the DB caches.
		if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
			wp_cache_flush();
		}

		update_site_option( 'vhp_varnish_devmode', $newmode );

		return $newmode['active'];
	}

	/**
	 * Append the ?nocache parameter to JS and CSS files
	 *
	 * @access public
	 * @static
	 * @param mixed $src - URL of CSS or JS file.
	 * @return url
	 * @since 4.6.0
	 */
	public static function nocache_cssjs( $src ) {
		$src = remove_query_arg( 'ver', $src );
		$src = add_query_arg( 'nocache', '', $src );
		return $src;
	}

	/**
	 * Validate URL.
	 *
	 * @access public
	 * @static
	 * @param mixed $input - The URL to validate.
	 * @return string One of: 'empty', 'domain', 'invalid', 'valid'.
	 * @since 4.6.0
	 */
	public static function is_url_valid( $input ) {

		$default = esc_url( VarnishPurger::the_home_url() );

		if ( ! empty( $input ) ) {
			$parsed_input = wp_parse_url( $input );
			if ( empty( $parsed_input['scheme'] ) ) {
				// If input starts with '/', treat it as a path on the current domain.
				if ( 0 === strpos( $input, '/' ) ) {
					$input = untrailingslashit( VarnishPurger::the_home_url() ) . $input;
				} else {
					// Otherwise, assume it's a hostname without scheme.
					$schema_input = ( is_ssl() ) ? 'https://' : 'http://';
					$input        = $schema_input . $input;
				}
			}
		}

		if ( empty( $input ) ) {
			$output = 'empty';
		} elseif ( wp_parse_url( $default, PHP_URL_HOST ) !== wp_parse_url( $input, PHP_URL_HOST ) ) {
			$output = 'domain';
		} elseif ( ! filter_var( $input, FILTER_VALIDATE_URL ) ) {
			$output = 'invalid';
		} else {
			$output = 'valid';
		}

		return $output;
	}

	/**
	 * Get Remote URL.
	 *
	 * Makes two HTTP requests to the URL with a configurable delay between them
	 * to ensure the cache has time to populate Age headers.
	 *
	 * @access public
	 * @static
	 * @param string $url (default: '') - The URL to fetch.
	 * @return array|string Response array from wp_remote_get(), or 'fail' on error.
	 * @since 4.4.0
	 */
	public static function remote_get( $url = '' ) {

		// Make sure it's not a bad entry.
		$url = esc_url( $url );

		// Allow URL translation for environments where the public URL isn't
		// reachable from the server (e.g., Docker containers).
		// The filter receives the URL and should return the internal URL to use.
		$internal_url = apply_filters( 'vhp_debug_check_url', $url );
		$internal_url = esc_url( $internal_url );

		$args = array(
			'timeout'     => 30,
			'redirection' => 10,
		);

		// If we're using a different internal URL, we need to send the Host header
		// to ensure the server handles the request correctly, and disable redirects
		// since the redirect would go to the unreachable public URL.
		// The cache headers we need are present on the redirect response itself.
		if ( $internal_url !== $url ) {
			$parsed = wp_parse_url( $url );
			if ( ! empty( $parsed['host'] ) ) {
				$args['headers'] = array(
					'Host' => $parsed['host'],
				);
			}
			// Disable redirects - the redirect would go to the public URL which
			// isn't reachable from this environment. We check headers on the
			// redirect response instead.
			$args['redirection'] = 0;
			$url                 = $internal_url;
		}

		// Lazy run twice to make sure we get a primed cache page.
		$response1 = wp_remote_get( $url, $args );

		// If this fails, we're going to assume bad things...
		if ( is_wp_error( $response1 ) ) {
			return 'fail';
		}

		// Because the 'Age' header is an important check, wait before fetching again.
		// This delay ensures the cache has time to populate the Age header.
		$cache_check_delay = (int) apply_filters( 'vhp_debug_cache_check_delay', 2 );
		if ( $cache_check_delay > 0 ) {
			sleep( $cache_check_delay );
		}

		$response2 = wp_remote_get( $url, $args );

		// And if this fails, we again assume badly.
		if ( is_wp_error( $response2 ) ) {
			return 'fail';
		}

		return $response2;
	}

	/**
	 * Detect whether the cache advertises support for cache tags.
	 *
	 * This uses only standard surrogate headers as per the Edge Architecture
	 * specification (e.g. the Surrogate-Capability request header),
	 * optionally overridden by a wp-config define.
	 *
	 * Example of a Surrogate-Capability header that advertises cache tags:
	 *
	 *   Surrogate-Capability: vhp="Surrogate/1.0 tags/1"
	 *
	 * Administrators can explicitly force detection on or off by defining
	 * VHP_VARNISH_TAGS in wp-config.php, which is useful when /wp-admin/
	 * is served directly (bypassing the surrogate) but the public site
	 * still uses cache tags.
	 *
	 * @since 5.4.0
	 *
	 * @access public
	 * @static
	 * @return bool True if support is advertised or forced, false otherwise.
	 */
	public static function cache_tags_advertised() {

		// Hard override via wp-config.
		if ( defined( 'VHP_VARNISH_TAGS' ) ) {
			/**
			 * Filter whether cache tags support is advertised when
			 * explicitly overridden by VHP_VARNISH_TAGS.
			 *
			 * @since 5.4.0
			 *
			 * @param bool $forced Current forced value.
			 */
			return (bool) apply_filters( 'varnish_http_purge_cache_tags_advertised', (bool) VHP_VARNISH_TAGS );
		}

		// Best-effort header collection for the current request.
		$headers = array();

		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
		} else {
			foreach ( $_SERVER as $key => $value ) {
				if ( 0 === strpos( $key, 'HTTP_' ) ) {
					$name             = str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $key, 5 ) ) ) ) );
					$headers[ $name ] = $value;
				}
			}
		}

		$surrogate_capability = '';
		foreach ( $headers as $name => $value ) {
			if ( strtolower( $name ) === 'surrogate-capability' ) {
				$surrogate_capability = $value;
				break;
			}
		}
		if ( empty( $surrogate_capability ) && isset( $_SERVER['HTTP_SURROGATE_CAPABILITY'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Header value parsed below, not output directly.
			$surrogate_capability = wp_unslash( $_SERVER['HTTP_SURROGATE_CAPABILITY'] );
		}

		$supported = false;

		if ( ! empty( $surrogate_capability ) && is_string( $surrogate_capability ) ) {
			// Parse according to Edge Architecture Surrogate-Capability grammar.
			// Example: abc="Surrogate/1.0 tags/1", def="Surrogate/1.0"
			$sets = explode( ',', $surrogate_capability );

			foreach ( $sets as $set ) {
				$set = trim( $set );
				if ( false === strpos( $set, '=' ) ) {
					continue;
				}

				// We only need the capabilities part, not the device identifier.
				$parts    = array_map( 'trim', explode( '=', $set, 2 ) );
				$caps_raw = isset( $parts[1] ) ? $parts[1] : '';

				// Strip quotes around the capabilities list.
				$caps_raw = trim( $caps_raw, "\"' \t" );
				if ( '' === $caps_raw ) {
					continue;
				}

				$caps = preg_split( '/\s+/', $caps_raw );
				if ( empty( $caps ) ) {
					continue;
				}

				foreach ( $caps as $cap ) {
					$cap = strtolower( trim( $cap ) );

					// Look for commonly used tokens that imply tag support.
					if (
						0 === strpos( $cap, 'xkey/' ) ||
						0 === strpos( $cap, 'tags/' ) ||
						0 === strpos( $cap, 'cache-tags/' )
					) {
						$supported = true;
						break 2; // Break out of both loops.
					}
				}
			}
		}

		/**
		 * Filter whether cache tags support is advertised.
		 *
		 * This allows environments to explicitly signal support even when
		 * Surrogate-Capability parsing is insufficient.
		 *
		 * @since 5.4.0
		 *
		 * @param bool $supported Current detection result.
		 */
		$supported = (bool) apply_filters( 'varnish_http_purge_cache_tags_advertised', $supported );

		return $supported;
	}

	/**
	 * Basic checks that should stop a scan
	 *
	 * Performs preflight validation on the HTTP response to determine
	 * if the debug scan should proceed.
	 *
	 * @since 4.4.0.
	 *
	 * @access public
	 * @static
	 * @param mixed $response - Response from wp_remote_get().
	 * @return array {
	 *     @type bool   $preflight Whether the scan can proceed.
	 *     @type string $message   Status message explaining the result.
	 * }
	 */
	public static function preflight( $response ) {

		// Defaults.
		$preflight = true;
		$message   = __( 'Success', 'varnish-http-purge' );

		if ( is_wp_error( $response ) ) {
			$preflight = false;
			$message   = __( 'This request cannot be performed: ', 'varnish-http-purge' );
			$message  .= $response->get_error_message();
		} elseif ( 404 === wp_remote_retrieve_response_code( $response ) ) {
			$preflight = false;
			$message   = __( 'This URL does not resolve properly. Either it was not found or it redirects incorrectly.', 'varnish-http-purge' );
		}

		$return = array(
			'preflight' => $preflight,
			'message'   => $message,
		);

		return $return;
	}

	/**
	 * Check for remote IP
	 *
	 * @since 4.4.0
	 *
	 * @access public
	 * @static
	 * @param mixed $headers - headers from wp_remote_get.
	 * @return string|false IP address string, 'cloudflare' identifier, or false if not found.
	 */
	public static function remote_ip( $headers ) {

		// Check CF-Connecting-IP header first (Cloudflare's real client IP).
		// This takes priority because it's the most reliable source when behind Cloudflare.
		$cf_connecting_ip = self::get_header( $headers, 'CF-Connecting-IP' );
		if ( null !== $cf_connecting_ip && filter_var( $cf_connecting_ip, FILTER_VALIDATE_IP ) ) {
			return $cf_connecting_ip;
		}

		// Check X-Forwarded-For header (supports both IPv4 and IPv6).
		$x_forwarded_for = self::get_header( $headers, 'X-Forwarded-For' );
		if ( null !== $x_forwarded_for ) {
			// X-Forwarded-For can contain multiple IPs; take the first one.
			$forwarded_ips = explode( ',', $x_forwarded_for );
			$first_ip      = trim( $forwarded_ips[0] );
			if ( filter_var( $first_ip, FILTER_VALIDATE_IP ) ) {
				return $first_ip;
			}
		}

		// Check for Cloudflare via Server header (fallback detection).
		$server_header = self::get_header( $headers, 'Server' );
		$server_header = is_array( $server_header ) ? implode( ' ', $server_header ) : $server_header;
		if ( null !== $server_header && strpos( $server_header, 'cloudflare' ) !== false ) {
			return 'cloudflare';
		}

		return false;
	}

	/**
	 * Get a header value case-insensitively.
	 *
	 * Works with both CaseInsensitiveDictionary (from wp_remote_retrieve_headers)
	 * and plain arrays (used in tests).
	 *
	 * @since 5.3.0
	 *
	 * @access private
	 * @static
	 * @param array|object $headers - Headers collection.
	 * @param string       $name    - Header name to look up.
	 * @return string|null Header value or null if not found.
	 */
	private static function get_header( $headers, $name ) {
		// CaseInsensitiveDictionary handles case internally.
		if ( isset( $headers[ $name ] ) ) {
			return $headers[ $name ];
		}
		// For plain arrays, try common case variants.
		$lower = strtolower( $name );
		if ( isset( $headers[ $lower ] ) ) {
			return $headers[ $lower ];
		}
		// Try with each word capitalized (e.g., "X-Cache-Status").
		$title = implode( '-', array_map( 'ucfirst', explode( '-', $lower ) ) );
		if ( isset( $headers[ $title ] ) ) {
			return $headers[ $title ];
		}
		return null;
	}

	/**
	 * Results on the Varnish calls
	 *
	 * Analyzes HTTP headers to determine cache status and service type.
	 *
	 * @since 4.4.0
	 *
	 * @access public
	 * @static
	 * @param array|false $headers - Headers from wp_remote_retrieve_headers(), or false if unavailable.
	 * @return array {
	 *     @type string $icon    Status icon: 'awesome', 'good', 'warning', 'bad', or 'notice'.
	 *     @type string $message Human-readable status message.
	 * }
	 */
	public static function varnish_results( $headers = false ) {

		$return = array();

		// If we have headers...
		if ( ! $headers ) {
			$kronk = false;
		} else {
			$kronk = true;

			// Get some basic truthy/falsy from the headers.
			// Headers used by both.
			$x_varnish_header_name = apply_filters( 'varnish_http_purge_x_varnish_header_name', 'X-Varnish' );
			$x_varnish_value       = self::get_header( $headers, $x_varnish_header_name );
			$x_varnish             = ( null !== $x_varnish_value );
			$date_header           = self::get_header( $headers, 'Date' );
			$x_date                = ( null !== $date_header && strtotime( $date_header ) !== false );
			$age_header            = self::get_header( $headers, 'Age' );
			$x_age                 = ( null !== $age_header );

			// Is this Nginx or not?
			$server_header = self::get_header( $headers, 'Server' );
			$server_header = is_array( $server_header ) ? implode( ' ', $server_header ) : $server_header;
			$x_nginx       = ( null !== $server_header && ( strpos( $server_header, 'nginx' ) !== false || strpos( $server_header, 'openresty' ) !== false ) );

			// Headers used by Nginx.
			// X-Varnish contains transaction IDs, not HIT/MISS:
			// - Single ID (e.g., "32770") = MISS (only this request's ID)
			// - Two IDs (e.g., "32770 32771") = HIT (this request + cached request's ID)
			$x_varn_hit = false;
			if ( $x_varnish && null !== $x_varnish_value ) {
				$varnish_value = is_array( $x_varnish_value )
					? implode( ' ', $x_varnish_value )
					: $x_varnish_value;
				// Count space-separated transaction IDs - 2+ means cache hit.
				$transaction_ids = preg_split( '/\s+/', trim( $varnish_value ) );
				$x_varn_hit      = ( count( $transaction_ids ) >= 2 );
			}

			// Headers used ONLY by Apache/Varnish.
			$x_cacheable_value = self::get_header( $headers, 'X-Cacheable' );
			$x_cachable        = ( null !== $x_cacheable_value && strpos( $x_cacheable_value, 'YES' ) !== false );
			$x_age_vapc        = ( $x_age && null !== $age_header && (int) $age_header > 0 );

			// Optional Headers - Via header containing "varnish" (case-insensitive check for "arnish").
			$x_via      = false;
			$via_header = self::get_header( $headers, 'Via' );
			if ( null !== $via_header ) {
				if ( is_array( $via_header ) ) {
					foreach ( $via_header as $header_via ) {
						if ( strpos( $header_via, 'arnish' ) !== false ) {
							$x_via = true;
							break;
						}
					}
				} else {
					$x_via = ( strpos( $via_header, 'arnish' ) !== false );
				}
			}

			// X-Cache header (common alternative to X-Varnish, used by many hosts).
			$x_cache_hit   = false;
			$x_cache_value = self::get_header( $headers, 'X-Cache' );
			if ( null !== $x_cache_value ) {
				$x_cache_str = is_array( $x_cache_value ) ? implode( ' ', $x_cache_value ) : $x_cache_value;
				$x_cache_hit = ( strpos( $x_cache_str, 'HIT' ) !== false );
			}

			// X-Cache-Status header (used by some Nginx setups).
			$x_cache_status_value = self::get_header( $headers, 'X-Cache-Status' );
			$x_cache_status_hit   = ( null !== $x_cache_status_value && strpos( $x_cache_status_value, 'HIT' ) !== false );

			// X-Proxy-Cache header.
			$x_proxy_cache_value = self::get_header( $headers, 'X-Proxy-Cache' );
			$x_p_cache           = ( null !== $x_proxy_cache_value && strpos( $x_proxy_cache_value, 'HIT' ) !== false );

			// Is Cacheable? Check X-Varnish first, fall back to X-Cache, X-Cache-Status, X-Proxy-Cache, or Via header.
			$has_cache_indicator = ( $x_varnish || $x_cache_hit || $x_cache_status_hit || $x_p_cache || $x_via );
			$is_cachable         = ( $has_cache_indicator && $x_age );
			$still_cachable      = true;

			// Which service are we?
			// For "still_cachable", we accept any of these as proof caching works:
			// - X-Cacheable: YES (traditional Varnish/Apache setup)
			// - X-Cache: HIT (common modern setup)
			// - Age > 0 (universal proof of caching)
			$cache_working = ( $x_cachable || $x_cache_hit || $x_age_vapc );

			// Detection priority:
			// 1. X-Varnish header → Varnish (even if behind Nginx)
			// 2. Via header contains "varnish" → Varnish
			// 3. Nginx server + X-Cache/X-Cache-Status/X-Proxy-Cache HIT → Nginx FastCGI Cache
			// 4. X-Cache HIT alone → generic Proxy Cache
			$cache_service = false;
			if ( $x_varnish ) {
				// X-Varnish header is the definitive Varnish indicator.
				$cache_service  = __( 'Varnish', 'varnish-http-purge' );
				$still_cachable = ( $is_cachable && $cache_working );
			} elseif ( $x_via ) {
				// Via header contains "varnish" - Varnish is in the chain.
				$cache_service  = __( 'Varnish', 'varnish-http-purge' );
				$still_cachable = $x_age_vapc;
			} elseif ( $x_nginx && ( $x_cache_hit || $x_cache_status_hit || $x_p_cache ) ) {
				// Nginx with its own caching (FastCGI cache, proxy_cache, etc.).
				$cache_service  = __( 'Nginx', 'varnish-http-purge' );
				$still_cachable = $x_age_vapc || $x_cache_hit || $x_cache_status_hit || $x_p_cache;
			} elseif ( $x_cache_hit || $x_cache_status_hit || $x_p_cache ) {
				// Generic proxy cache (CDN, other reverse proxy).
				$cache_service  = __( 'Proxy Cache', 'varnish-http-purge' );
				$still_cachable = $x_age_vapc || $x_cache_hit || $x_cache_status_hit || $x_p_cache;
			}

			// Determine the default message.
			if ( false !== $cache_service ) {
				// translators: %1$s is the type of caching service detected (i.e. nginx or varnish).
				$return['message'] = sprintf( __( 'Your %1$s caching service appears to be running properly.', 'varnish-http-purge' ), $cache_service );
				$return['icon']    = 'good';
			}
		}

		if ( ! $kronk ) {
			$return['icon']    = 'bad';
			$return['message'] = __( 'Your site is not responding. If this happens again, please contact your webhost.', 'varnish-http-purge' );
		} elseif ( ! $cache_service ) {
			$return['icon']    = 'warning';
			$return['message'] = __( 'No known cache service has been detected on your site. We look for X-Varnish, X-Cache, or Via headers containing "varnish". This does not mean caching isn\'t working — some configurations don\'t expose these headers. The plugin will still send purge requests to your configured cache IP when content changes.', 'varnish-http-purge' );
		} elseif ( $is_cachable && $still_cachable ) {
			$return['icon'] = 'awesome';
		} else {
			// translators: %1$s is the type of caching service detected (i.e. nginx, varnish, or proxy cache).
			$return['message'] = sprintf( __( 'We detected that the %1$s caching service is running, but we are unable to determine that it\'s working. Make sure your server returns an Age header with a value greater than 0.', 'varnish-http-purge' ), $cache_service );
			$return['icon']    = 'warning';
		}

		return $return;
	}

	/**
	 * Remote IP
	 *
	 * Results on if we have a proxy going on and what that means.
	 *
	 * @since 4.4.0
	 *
	 * @access public
	 * @static
	 * @param string|false $remote_ip - IP detected from headers, 'cloudflare', or false.
	 * @param string       $varniship - Configured Varnish IP address.
	 * @param array        $headers   - Headers from wp_remote_retrieve_headers().
	 * @return array {
	 *     @type string $icon    Status icon: 'awesome', 'good', 'warning', 'bad', or 'notice'.
	 *     @type string $message Human-readable status message.
	 * }
	 */
	public static function remote_ip_results( $remote_ip, $varniship, $headers ) {
		$return        = false;
		$server_header = self::get_header( $headers, 'Server' );
		$server_header = is_array( $server_header ) ? implode( ' ', $server_header ) : $server_header;
		$x_nginx       = ( null !== $server_header && ( strpos( $server_header, 'nginx' ) !== false || strpos( $server_header, 'openresty' ) !== false ) );

		if ( $x_nginx && 'localhost' === $varniship ) {
			// This is a pretty DreamHost specific check. If other hosts want to use it,
			// or extend it, please let me know.
			$return = array(
				'message' => __( 'Your Nginx Proxy is set up correctly.', 'varnish-http-purge' ),
				'icon'    => 'awesome',
			);
		} elseif ( false === $remote_ip && ! empty( $varniship ) ) {
			$return = array(
				// translators: %s is an IP address.
				'message' => sprintf( __( 'Your Proxy IP address is set to %s but a proxy (like Cloudflare or Sucuri) has not been detected. This is mostly harmless, but if you have issues with your cache not emptying when you make a post, you may need to remove the IP. Please check with your webhost or server admin before doing so.', 'varnish-http-purge' ), $varniship ),
				'icon'    => 'notice',
			);
		} elseif ( false !== $remote_ip && 'cloudflare' !== $remote_ip && $remote_ip !== $varniship ) {
			$return = array(
				'message' => __( 'You\'re using a custom Varnish IP that doesn\'t appear to match your server IP address. If you\'re using multiple caching servers or IPv6, this is fine. Please make sure you\'ve properly configured it according to your webhost\'s specifications.', 'varnish-http-purge' ),
				'icon'    => 'notice',
			);
		} else {
			$return = array(
				'message' => __( 'Your server IP setup looks good.', 'varnish-http-purge' ),
				'icon'    => 'awesome',
			);
		}

		return $return;
	}

	/**
	 * Server Details
	 *
	 * Includes nginx, hhvm, cloudflare, and more
	 *
	 * @since 4.4.0.
	 *
	 * @access public
	 * @static
	 * @param mixed $headers - headers from wp_remote_get
	 * @return array
	 */
	public static function server_results( $headers ) {

		$return = array();

		$server_header = self::get_header( $headers, 'Server' );
		$x_powered_by  = self::get_header( $headers, 'X-Powered-By' );
		$x_hacker      = self::get_header( $headers, 'X-hacker' );
		$x_backend     = self::get_header( $headers, 'X-Backend' );

		// Normalize to strings (headers can be arrays with multiple values).
		$server_header = is_array( $server_header ) ? implode( ' ', $server_header ) : $server_header;
		$x_powered_by  = is_array( $x_powered_by ) ? implode( ' ', $x_powered_by ) : $x_powered_by;
		$x_hacker      = is_array( $x_hacker ) ? implode( ' ', $x_hacker ) : $x_hacker;
		$x_backend     = is_array( $x_backend ) ? implode( ' ', $x_backend ) : $x_backend;

		if ( null !== $server_header ) {
			// Apache.
			if ( strpos( $server_header, 'Apache' ) !== false && strpos( $server_header, 'cloudflare' ) === false ) {
				$return['Apache'] = array(
					'icon'    => 'awesome',
					'message' => __( 'Your server is running Apache.', 'varnish-http-purge' ),
				);
			}

			// nginx.
			if ( strpos( $server_header, 'nginx' ) !== false && strpos( $server_header, 'cloudflare' ) === false ) {
				$return['Nginx'] = array(
					'icon'    => 'awesome',
					'message' => __( 'Your server is running Nginx.', 'varnish-http-purge' ),
				);
			}

			// Cloudflare.
			if ( strpos( $server_header, 'cloudflare' ) !== false ) {
				$return['CloudFlare'] = array(
					'icon'    => 'warning',
					'message' => __( 'CloudFlare has been detected. Make sure you configure WordPress properly by adding your Cache IP and to flush the CloudFlare cache if you see inconsistencies.', 'varnish-http-purge' ),
				);
			}

			// HHVM: Note, WP is dropping support.
			if ( null !== $x_powered_by && strpos( $x_powered_by, 'HHVM' ) !== false ) {
				$return['HHVM'] = array(
					'icon'    => 'warning',
					'message' => __( 'You are running HHVM which is no longer supported by WordPress. As such, this plugin does not officially support it either.', 'varnish-http-purge' ),
				);
			}

			if ( strpos( $server_header, 'Pagely' ) !== false ) {
				$return['Pagely'] = array(
					'icon'    => 'good',
					'message' => __( 'This site is hosted on Pagely. The results of this scan may not be accurate.', 'varnish-http-purge' ),
				);
			}
		}

		if ( null !== $x_powered_by && strpos( $x_powered_by, 'DreamPress' ) !== false ) {
			$return['DreamHost'] = array(
				'icon'    => 'awesome',
				'message' => __( 'This site is hosted on DreamHost (as DreamPress). The results of this scan will be accurate.', 'varnish-http-purge' ),
			);
		}

		if ( null !== $x_hacker ) {
			$return['WordPress.com'] = array(
				'icon'    => 'bad',
				'message' => __( 'This site is hosted on WordPress.com. The results of this scan may not be accurate.', 'varnish-http-purge' ),
			);
		}

		if ( null !== $x_backend && strpos( $x_backend, 'wpaas_web_' ) !== false ) {
			$return['GoDaddy'] = array(
				'icon'    => 'good',
				'message' => __( 'This site is hosted on GoDaddy. The results of this scan may not be accurate.', 'varnish-http-purge' ),
			);
		}

		return $return;
	}

	/**
	 * Results on GZIP
	 *
	 * @since 4.4.0
	 *
	 * @access public
	 * @static
	 * @param mixed $headers - headers from wp_remote_get.
	 * @return array Empty array if no compression detected, otherwise array with result.
	 */
	public static function gzip_results( $headers ) {

		$return = array();

		$content_encoding = self::get_header( $headers, 'Content-Encoding' );
		$vary_header      = self::get_header( $headers, 'Vary' );

		// Normalize to strings (headers can be arrays with multiple values).
		$content_encoding_str = is_array( $content_encoding ) ? implode( ' ', $content_encoding ) : $content_encoding;
		$vary_header_str      = is_array( $vary_header ) ? implode( ' ', $vary_header ) : $vary_header;

		// GZip.
		if ( ( null !== $content_encoding_str && strpos( $content_encoding_str, 'gzip' ) !== false ) || ( null !== $vary_header_str && strpos( $vary_header_str, 'gzip' ) !== false ) ) {
			$return = array(
				'icon'    => 'good',
				'message' => __( 'Your site is compressing content and making the internet faster.', 'varnish-http-purge' ),
			);
		}

		// Fastly (detected via X-Served-By or Via headers).
		$fastly_detected = false;
		$x_served_by     = self::get_header( $headers, 'X-Served-By' );
		$via_header      = self::get_header( $headers, 'Via' );

		// Normalize to strings (headers can be arrays with multiple values).
		$x_served_by_str = is_array( $x_served_by ) ? implode( ' ', $x_served_by ) : $x_served_by;
		$via_value       = is_array( $via_header ) ? implode( ' ', $via_header ) : $via_header;

		if ( null !== $x_served_by_str && strpos( $x_served_by_str, 'cache-' ) !== false ) {
			$fastly_detected = true;
		} elseif ( null !== $via_value && strpos( strtolower( $via_value ), 'fastly' ) !== false ) {
			$fastly_detected = true;
		}

		if ( $fastly_detected ) {
			$return = array(
				'icon'    => 'good',
				'message' => __( 'Fastly is speeding up your site. Remember to empty all caches in all locations when necessary.', 'varnish-http-purge' ),
			);
		}

		return $return;
	}

	/**
	 * Cookies break Varnish. Sometimes.
	 *
	 * @since 4.4.0
	 *
	 * @access public
	 * @static
	 * @param mixed $headers - headers from wp_remote_get.
	 * @return array
	 */
	public static function cookie_results( $headers ) {

		$return = array();

		$set_cookie = self::get_header( $headers, 'Set-Cookie' );

		// Early check. If there are no cookies, skip!
		if ( null === $set_cookie ) {
			$return['No Cookies'] = array(
				'icon'    => 'awesome',
				'message' => __( 'No active cookies have been detected on your site. You may safely ignore any warnings about cookies set by plugins or themes, as your server has properly accounted for them.', 'varnish-http-purge' ),
			);
		} else {
			// We have at least one cookie, so let's set this now.
			$return['Cookies Found'] = array(
				'icon'    => 'warning',
				'message' => __( 'Cookies have been detected. Unless your caching service is configured properly for the specific cookies, it may not cache properly. Please contact your webhost or administrator with information about the cookies found.', 'varnish-http-purge' ),
			);

			// Let's check our known bad cookies.
			$json_path = plugin_dir_path( __FILE__ ) . 'debugger/cookies.json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
			$json_data = file_exists( $json_path ) ? file_get_contents( $json_path ) : false;
			$cookies   = $json_data ? json_decode( $json_data ) : null;

			if ( empty( $cookies ) ) {
				if ( WP_DEBUG ) {
					$error_msg              = ( false === $json_data )
						? __( 'Error: Cookie data file not found.', 'varnish-http-purge' )
						: __( 'Error: Cookie data could not be parsed.', 'varnish-http-purge' );
					$return['cookie-error'] = array(
						'icon'    => 'warning',
						'message' => $error_msg,
					);
				}
				return $return; // Bail if the data was empty for some reason.
			}

			foreach ( $cookies as $cookie => $info ) {
				$has_cookie = false;

				// Check if the cookie name is present in any Set-Cookie value.
				if ( is_array( $set_cookie ) ) {
					foreach ( $set_cookie as $set_cookie_value ) {
						if ( strpos( $set_cookie_value, $info->cookie ) !== false ) {
							$has_cookie = true;
							break;
						}
					}
				} else {
					$has_cookie = ( strpos( $set_cookie, $info->cookie ) !== false );
				}

				if ( $has_cookie ) {
					$return[ 'Cookie: ' . $cookie ] = array(
						'icon'    => $info->type,
						'message' => $info->message,
					);
				}
			}
		}

		return $return;
	}

	/**
	 * Cache
	 *
	 * Checking Age, Max Age, Cache Control, Pragma and more
	 *
	 * @since 4.4.0
	 *
	 * @access public
	 * @static
	 * @param mixed $headers - headers from wp_remote_get.
	 * @return array
	 */
	public static function cache_results( $headers ) {

		$return = array();

		// Cache Control.
		$cache_control = self::get_header( $headers, 'Cache-Control' );
		if ( null !== $cache_control ) {

			if ( is_array( $cache_control ) ) {
				$no_cache = array_search( 'no-cache', $cache_control, true );
				$max_age  = array_search( 'max-age=0', $cache_control, true );
			} else {
				$no_cache = strpos( $cache_control, 'no-cache' );
				$max_age  = strpos( $cache_control, 'max-age=0' );
			}

			// No-Cache Set.
			// Note: strpos returns 0 if found at position 0, so we must check !== false.
			if ( false !== $no_cache ) {
				$return['No Cache Header'] = array(
					'icon'    => 'bad',
					'message' => __( 'The header Cache-Control is returning "no-cache", which means visitors will never get cached pages.', 'varnish-http-purge' ),
				);
			}

			// Max-Age is 0.
			// Note: strpos returns 0 if found at position 0, so we must check !== false.
			if ( false !== $max_age ) {
				$return['max_age'] = array(
					'icon'    => 'bad',
					'message' => __( 'The header Cache-Control is returning "max-age=0", which means a page can be no older than 0 seconds before it needs to regenerate the cache.', 'varnish-http-purge' ),
				);
			}
		}

		// Age Headers.
		$age_header = self::get_header( $headers, 'Age' );
		if ( null === $age_header ) {
			$return['Age Headers'] = array(
				'icon'    => 'bad',
				'message' => __( 'Your domain does not report an "Age" header, making it impossible to determine if the page is actually serving from cache.', 'varnish-http-purge' ),
			);
		} elseif ( (int) $age_header <= 0 ) {
			$age_value             = (int) $age_header;
			$return['Age Headers'] = array(
				'icon'    => 'warning',
				// translators: %s is a number indicating how many seconds old the content is.
				'message' => sprintf( __( 'The "Age" header is returning %s. This typically means the page was just cached (cache miss) or refreshed. If other cache indicators like X-Cache show "HIT", your cache is likely working and this will increase on subsequent requests. If this persists across multiple checks, the URL may be excluded from caching or a plugin is preventing caching.', 'varnish-http-purge' ), $age_value ),
			);
		} else {
			$return['Age Headers'] = array(
				'icon'    => 'awesome',
				'message' => __( 'Your site is returning proper "Age" headers.', 'varnish-http-purge' ),
			);
		}

		// Pragma.
		$pragma_header = self::get_header( $headers, 'Pragma' );
		$pragma_header = is_array( $pragma_header ) ? implode( ' ', $pragma_header ) : $pragma_header;
		if ( null !== $pragma_header && strpos( $pragma_header, 'no-cache' ) !== false ) {
			$return['Pragma Headers'] = array(
				'icon'    => 'bad',
				'message' => __( 'The header Pragma is set to to "no-cache" which means visitors will never get cached content.', 'varnish-http-purge' ),
			);
		}

		// X-Cache-Status.
		$x_cache_status = self::get_header( $headers, 'X-Cache-Status' );
		if ( null !== $x_cache_status && strpos( $x_cache_status, 'MISS' ) !== false ) {
			$return['X-Cache-Status'] = array(
				'icon'    => 'bad',
				'message' => __( 'X-Cache-Status missed, which means your site was not able to serve this page as cached.', 'varnish-http-purge' ),
			);
		}

		// Mod-PageSpeed.
		$x_mod_pagespeed = self::get_header( $headers, 'X-Mod-Pagespeed' );
		$x_cacheable     = self::get_header( $headers, 'X-Cacheable' );
		if ( null !== $x_mod_pagespeed ) {
			if ( null !== $x_cacheable && strpos( $x_cacheable, 'YES:Forced' ) !== false ) {
				$return['Mod Pagespeed'] = array(
					'icon'    => 'good',
					'message' => __( 'Mod Pagespeed is active and configured to work properly with caching services.', 'varnish-http-purge' ),
				);
			} else {
				$return['Mod Pagespeed'] = array(
					'icon'    => 'bad',
					'message' => __( 'Mod Pagespeed is active but your caching headers may not be correct. This may be a false negative if other parts of your site are overwriting headers. Fix all other errors listed, then come back to this. If you are still having errors, you will need to look into using .htaccess or Nginx to override the Pagespeed headers.', 'varnish-http-purge' ),
				);
			}
		}

		// Cloudflare.
		$cf_cache_status = self::get_header( $headers, 'CF-Cache-Status' );
		if ( null !== $cf_cache_status ) {

			switch ( $cf_cache_status ) {
				case 'MISS':
					$return['CloudFlare Cache'] = array(
						'icon'    => 'warning',
						'message' => __( 'CloudFlare reported this page as not cached. That may be okay. If it goes away when you re-run this check, you\'re fine.', 'varnish-http-purge' ),
					);
					break;
				case 'EXPIRED':
				case 'STALE':
				case 'REVALIDATED':
				case 'UPDATING':
					$return['CloudFlare Cache'] = array(
						'icon'    => 'notice',
						// translators: %s is the CloudFlare cache status (e.g., EXPIRED, STALE).
						'message' => sprintf( __( 'CloudFlare cache status: %s. The cache entry was stale or expired and is being refreshed.', 'varnish-http-purge' ), $cf_cache_status ),
					);
					break;
				case 'BYPASS':
					$return['CloudFlare Cache'] = array(
						'icon'    => 'warning',
						'message' => __( 'CloudFlare is bypassing cache for this resource. Check your page rules or cache settings if this is unexpected.', 'varnish-http-purge' ),
					);
					break;
				case 'DYNAMIC':
					$return['CloudFlare Cache'] = array(
						'icon'    => 'good',
						'message' => __( 'CloudFlare is caching properly.', 'varnish-http-purge' ),
					);
					break;
				case 'HIT':
				case 'HIT from Backend':
					$return['CloudFlare Cache'] = array(
						'icon'    => 'warning',
						'message' => __( 'CloudFlare is caching however you appear to be using Automatic Platform Optimization (APO). You may face issues with emptying cache on Varnish and APO depending on your webhost. If you find that saving posts takes an exceptionally long time, or does not appear to update content, try disabling APO.', 'varnish-http-purge' ),
					);
					break;
				default:
					$return['CloudFlare Cache'] = array(
						'icon'    => 'notice',
						// translators: %s is the CloudFlare cache status.
						'message' => sprintf( __( 'CloudFlare cache status: %s.', 'varnish-http-purge' ), $cf_cache_status ),
					);
					break;
			}
		}

		return $return;
	}

	/**
	 * Bad Themes
	 *
	 * Themes known to be problematic
	 *
	 * @since 4.5.0
	 *
	 * @access public
	 * @static
	 * @return array
	 */
	public static function bad_themes_results() {

		$return = array();

		// Let's check our known bad themes.
		$json_path = plugin_dir_path( __FILE__ ) . 'debugger/themes.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		$json_data = file_exists( $json_path ) ? file_get_contents( $json_path ) : false;
		$themes    = $json_data ? json_decode( $json_data ) : null;

		if ( empty( $themes ) ) {
			if ( WP_DEBUG ) {
				$error_msg             = ( false === $json_data )
					? __( 'Error: Theme data file not found.', 'varnish-http-purge' )
					: __( 'Error: Theme data could not be parsed.', 'varnish-http-purge' );
				$return['Theme Check'] = array(
					'icon'    => 'warning',
					'message' => $error_msg,
				);
			}
			return $return; // Bail early.
		}

		// Check all the themes. If one of the questionable ones are active, warn.
		// Theme directories are typically lowercase, so check both original and lowercase.
		foreach ( $themes as $theme => $info ) {
			$theme_slug = strtolower( $theme );
			$my_theme   = wp_get_theme( $theme_slug );

			// Fallback to original case if lowercase doesn't exist.
			if ( ! $my_theme->exists() ) {
				$my_theme   = wp_get_theme( $theme );
				$theme_slug = $theme;
			}

			if ( $my_theme->exists() ) {
				$active  = ( strtolower( get_template() ) === strtolower( $theme_slug ) );
				$message = $info->message . ' (';
				$warning = $info->type;

				if ( $active ) {
					$message .= __( 'Active', 'varnish-http-purge' );
				} else {
					$message .= __( 'Inactive', 'varnish-http-purge' );
					$warning  = 'notice';
				}
				$message .= ')';

				$return[ 'Theme: ' . ucfirst( $theme ) ] = array(
					'icon'    => $warning,
					'message' => $message,
				);
			}
		}

		// If no questionable themes are found, let the user know with a success message.
		if ( empty( $return ) ) {
			$return['Theme Check'] = array(
				'icon'    => 'good',
				'message' => __( 'No installed themes were found on the known conflicts list.', 'varnish-http-purge' ),
			);
		}

		return $return;
	}

	/**
	 * Bad Plugins
	 *
	 * Plugins known to be problematic
	 *
	 * @since 4.5.0
	 *
	 * @access public
	 * @static
	 * @return array
	 */
	public static function bad_plugins_results() {

		$return   = array();
		$messages = array(
			'addon'        => __( 'This plugin may require add-ons to ensure full compatibility. Please check their documentation.', 'varnish-http-purge' ),
			'incompatible' => __( 'This plugin has unexpected results with caching, making things not function properly.', 'varnish-http-purge' ),
			'translation'  => __( 'Translation plugins that use cookies and/or sessions prevent most server side caching from running properly.', 'varnish-http-purge' ),
			'sessions'     => __( 'This plugin uses sessions, which conflicts with server side caching.', 'varnish-http-purge' ),
			'cookies'      => __( 'This plugin uses cookies, which may prevent server side caching.', 'varnish-http-purge' ),
			'cache'        => __( 'This type of caching plugin does not work well with server side caching.', 'varnish-http-purge' ),
			'ancient'      => __( 'This plugin is not up to date with WordPress best practices and breaks caching.', 'varnish-http-purge' ),
			'removed'      => __( 'This plugin was removed from WordPress.org and we do not recommend its use.', 'varnish-http-purge' ),
			'maybe'        => __( 'This plugin is usually fine, but can be configured in a way that breaks caching. Please resolve all other errors. If this is the only one left, and caching is running, you may safely ignore this message.', 'varnish-http-purge' ),
			'maybe-cache'  => __( 'This plugin is usually fine, however it has been known to have issues with caching. Sometimes its pages will not be properly updated. This is being worked on, but has no ETA for resolution.', 'varnish-http-purge' ),
		);

		$json_path = plugin_dir_path( __FILE__ ) . 'debugger/plugins.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		$json_data = file_exists( $json_path ) ? file_get_contents( $json_path ) : false;
		$plugins   = $json_data ? json_decode( $json_data ) : null;

		if ( empty( $plugins ) ) {
			if ( WP_DEBUG ) {
				$error_msg              = ( false === $json_data )
					? __( 'Error: Plugin data file not found.', 'varnish-http-purge' )
					: __( 'Error: Plugin data could not be parsed.', 'varnish-http-purge' );
				$return['Plugin Check'] = array(
					'icon'    => 'warning',
					'message' => $error_msg,
				);
			}
			return $return; // Bail early.
		}

		// Check all the plugins. If one of the questionable ones are active, warn.
		foreach ( $plugins as $plugin => $info ) {
			if ( file_exists( plugin_dir_path( __DIR__ ) . $info->path ) ) {
				// Safely get message for reason, with fallback for unknown reasons.
				$message = isset( $messages[ $info->reason ] ) ? $messages[ $info->reason ] : __( 'This plugin may have compatibility issues with caching.', 'varnish-http-purge' );
				$warning = 'notice';
				$active  = __( 'Inactive', 'varnish-http-purge' );

				// If the plugin is inactive, change the warning.
				if ( is_plugin_active( $info->path ) ) {
					$warning = $info->type;
					$active  = __( 'Active', 'varnish-http-purge' );
				}

				$return[ 'Plugin: ' . ucfirst( $plugin ) ] = array(
					'icon'    => $warning,
					'message' => $message . ' (' . $active . ')',
				);
			}
		}

		// If no questionable plugins are found, let the user know with a success message.
		if ( empty( $return ) ) {
			$return['Plugin Check'] = array(
				'icon'    => 'good',
				'message' => __( 'No installed plugins were found on the known conflicts list.', 'varnish-http-purge' ),
			);
		}

		return $return;
	}

	/**
	 * Get all the results
	 *
	 * Collect everything, get all the data spit it out.
	 *
	 * @since 4.4.0
	 *
	 * @access public
	 * @static
	 * @param mixed $headers - results from wp_remote_get.
	 * @param mixed $remote_ip - IP address detected.
	 * @param mixed $varniship - IP address defined.
	 * @return array
	 */
	public static function get_all_the_results( $headers, $remote_ip, $varniship ) {
		$output = array();

		// Preface with Debugging Warning.
		if ( self::devmode_check() ) {
			$output['Development Mode'] = array(
				'icon'    => 'notice',
				'message' => __( 'NOTICE: Caching is disabled while Development Mode is active.', 'varnish-http-purge' ),
			);
		}

		// Basic Checks.
		$output['Cache Service'] = self::varnish_results( $headers );
		$output['Remote IP']     = self::remote_ip_results( $remote_ip, $varniship, $headers );

		// Server Results.
		$server_results = self::server_results( $headers );

		// Cache Results.
		$cache_results = self::cache_results( $headers );

		// Cookies.
		$cookie_results = self::cookie_results( $headers );

		// GZIP / Compression.
		$gzip_results = self::gzip_results( $headers );

		// Plugins that don't play nicely with Varnish.
		$bad_plugins_results = self::bad_plugins_results();

		// Themes that don't play nicely with Varnish.
		$bad_themes_results = self::bad_themes_results();

		// Update Output.
		$output = array_merge( $output, $server_results, $cache_results, $cookie_results, $bad_plugins_results, $bad_themes_results );

		// Add GZIP results if present.
		if ( ! empty( $gzip_results ) ) {
			$output['Compression'] = $gzip_results;
		}

		// Update site option data.
		$debug_log = get_site_option( 'vhp_varnish_debug' );
		if ( ! is_array( $debug_log ) ) {
			$debug_log = array();
		}
		$debug_log[ VarnishPurger::the_home_url() ] = $output;
		update_site_option( 'vhp_varnish_debug', $debug_log );

		return $output;
	}
}
