<?php
/**
 * WP-CLI code
 * @package varnish-http-purge
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

// Bail if WP-CLI is not present.
if ( ! defined( 'WP_CLI' ) ) {
	return;
}

// Only load if the class doesn't exist.
if ( ! class_exists( 'WP_CLI_Varnish_Command' ) ) {

	/**
	 * WP CLI Commands for Varnish.
	 *
	 * @extends WP_CLI_Command
	 */
	class WP_CLI_Varnish_Command extends WP_CLI_Command {


		/**
		 * wildcard
		 *
		 * (default value: false)
		 *
		 * @var bool
		 * @access private
		 */
		private $wildcard = false;


		/**
		 * __construct function.
		 *
		 * @access public
		 * @return void
		 */
		public function __construct() {
			$this->varnish_purge = new VarnishPurger();
		}

		/**
		 * Forces cache to purge.
		 *
		 * ## OPTIONS
		 *
		 * [<url>]
		 * : Specify a URL to purge. If omitted, purges the entire site cache.
		 *
		 * [--all]
		 * : Explicitly purge the entire site cache (same as running without arguments).
		 *
		 * [--wildcard]
		 * : Include all subfolders and files below the specified URL.
		 *   This is the default behavior when a URL is provided.
		 *
		 * [--url-only]
		 * : Purge only the exact URL specified, without wildcard matching.
		 *
		 * [--tag=<tag>]
		 * : Purge by cache tag (requires Cache Tags mode to be enabled).
		 *   Common tags: p-{id} (post), pt-{type} (post type), t-{id} (term),
		 *   a-{id} (author), home, blog, archive, feed.
		 *
		 * ## EXAMPLES
		 *
		 *      # Purge the entire site cache
		 *      wp varnish purge
		 *      wp varnish purge --all
		 *
		 *      # Purge a specific URL and everything below it
		 *      wp varnish purge https://example.com/hello-world/
		 *
		 *      # Purge only the exact URL (no wildcard)
		 *      wp varnish purge https://example.com/hello-world/ --url-only
		 *
		 *      # Purge all theme files
		 *      wp varnish purge https://example.com/wp-content/themes/ --wildcard
		 *
		 *      # Purge by cache tag (requires Cache Tags mode)
		 *      wp varnish purge --tag=p-123
		 *      wp varnish purge --tag=pt-post
		 *      wp varnish purge --tag=home
		 */
		public function purge( $args, $assoc_args ) {

			$wp_version  = get_bloginfo( 'version' );
			$cli_version = WP_CLI_VERSION;

			// Handle tag-based purging.
			if ( isset( $assoc_args['tag'] ) ) {
				$tag = sanitize_text_field( $assoc_args['tag'] );

				if ( empty( $tag ) ) {
					WP_CLI::error( __( 'You must provide a tag value with --tag=<tag>.', 'varnish-http-purge' ) );
				}

				// Check if Cache Tags mode is enabled.
				if ( ! get_site_option( 'vhp_varnish_use_tags' ) ) {
					WP_CLI::warning( __( 'Cache Tags mode is not enabled. The purge request will be sent, but may not work unless your cache supports tag-based purging.', 'varnish-http-purge' ) );
				}

				$this->varnish_purge->purge_tags( array( $tag ) );

				// translators: %s is the cache tag being purged.
				WP_CLI::success( sprintf( __( 'Proxy Cache Purge has flushed cache for tag: %s', 'varnish-http-purge' ), $tag ) );
				return;
			}

			// Set the URL/path.
			$url = '';
			if ( ! empty( $args ) ) {
				list( $url ) = $args;
			}

			// Determine purge behavior:
			// --all: full site purge (explicit)
			// --url-only: purge only the exact URL, no wildcard
			// --wildcard: purge URL with wildcard (default for URLs)
			// No URL and no --all: full site purge (implicit)
			$pregex = '';
			$wild   = '';

			$is_all_flag = isset( $assoc_args['all'] );
			$is_url_only = isset( $assoc_args['url-only'] );
			$is_wildcard = isset( $assoc_args['wildcard'] );

			if ( $is_all_flag ) {
				// Explicit full purge.
				$url    = $this->varnish_purge->the_home_url();
				$pregex = '/?vhp-regex';
				$wild   = '.*';
			} elseif ( empty( $url ) ) {
				// No URL provided = full site purge.
				$url    = $this->varnish_purge->the_home_url();
				$pregex = '/?vhp-regex';
				$wild   = '.*';
			} elseif ( $is_url_only ) {
				// Purge only the exact URL, no regex.
				$url    = esc_url( $url );
				$pregex = '';
				$wild   = '';
			} else {
				// Default behavior for URL: wildcard purge.
				// This is the existing behavior when a URL is provided.
				$url    = esc_url( $url );
				$url    = rtrim( $url, '/' );
				$pregex = '/?vhp-regex';
				$wild   = '.*';
			}

			if ( version_compare( $wp_version, '4.6', '>=' ) && ( version_compare( $cli_version, '0.25.0', '<' ) || version_compare( $cli_version, '0.25.0-alpha', 'eq' ) ) ) {

				// translators: %1$s is the version of WP-CLI.
				// translators: %2$s is the version of WordPress.
				WP_CLI::log( sprintf( __( 'This plugin does not work on WP 4.6 and up, unless WP-CLI is version 0.25.0 or greater. You\'re using WP-CLI %1$s and WordPress %2$s.', 'varnish-http-purge' ), $cli_version, $wp_version ) );
				WP_CLI::log( __( 'To flush your cache, please run the following command:', 'varnish-http-purge' ) );
				WP_CLI::log( sprintf( '$ curl -X PURGE "%s"', $url . $wild ) );
				WP_CLI::error( __( 'Your cache must be purged manually.', 'varnish-http-purge' ) );
			}

			$this->varnish_purge->purge_url( $url . $pregex );

			if ( WP_DEBUG === true ) {
				// translators: %1$s is the URL being flushed.
				// translators: %2$s are the params being flushed.
				WP_CLI::log( sprintf( __( 'Proxy Cache Purge is flushing the URL %1$s with params %2$s.', 'varnish-http-purge' ), $url, $pregex ) );
			}

			// Provide appropriate success message.
			if ( ! empty( $pregex ) && ( $is_all_flag || empty( $args ) ) ) {
				WP_CLI::success( __( 'Proxy Cache Purge has flushed the entire site cache.', 'varnish-http-purge' ) );
			} elseif ( ! empty( $pregex ) ) {
				// translators: %s is the URL being flushed.
				WP_CLI::success( sprintf( __( 'Proxy Cache Purge has flushed cache for %s and all content below it.', 'varnish-http-purge' ), $url ) );
			} else {
				// translators: %s is the URL being flushed.
				WP_CLI::success( sprintf( __( 'Proxy Cache Purge has flushed cache for: %s', 'varnish-http-purge' ), $url ) );
			}
		}

		/**
		 * Activate, deactivate, or toggle Development Mode.
		 *
		 * ## OPTIONS
		 *
		 * [<state>]
		 * : Change the state of Development Mode
		 * ---
		 * options:
		 *   - activate
		 *   - deactivate
		 *   - toggle
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *      wp varnish devmode activate
		 *      wp varnish devmode deactivate
		 *      wp varnish devmode toggle
		 */
		public function devmode( $args, $assoc_args ) {

			$valid_modes = array( 'activate', 'deactivate', 'toggle' );
			$devmode     = get_site_option( 'vhp_varnish_devmode', VarnishPurger::$devmode );

			// Check for valid arguments.
			if ( empty( $args[0] ) ) {
				// No params, echo state.
				$state = ( $devmode['active'] ) ? __( 'activated', 'varnish-http-purge' ) : __( 'deactivated', 'varnish-http-purge' );
				// translators: %s is the state of dev mode.
				WP_CLI::log( sprintf( __( 'Proxy Cache Purge development mode is currently %s.', 'varnish-http-purge' ), $state ) );
			} elseif ( ! in_array( $args[0], $valid_modes, true ) ) {
				// Invalid Params, warn.
				// translators: %s is the bad command.
				WP_CLI::error( sprintf( __( '%s is not a valid subcommand for development mode.', 'varnish-http-purge' ), sanitize_text_field( $args[0] ) ) );
			} else {
				// Run the toggle!
				$result = VarnishDebug::devmode_toggle( sanitize_text_field( $args[0] ) );
				$state  = ( $result ) ? __( 'activated', 'varnish-http-purge' ) : __( 'deactivated', 'varnish-http-purge' );
				// translators: %s is the state of dev mode.
				WP_CLI::success( sprintf( __( 'Proxy Cache Purge development mode has been %s.', 'varnish-http-purge' ), $state ) );
			}
		} // End devmode.

		/**
		 * Runs a debug check of the site to see if there are any known issues.
		 *
		 * ## OPTIONS
		 *
		 * [<url>]
		 * : Specify a URL for testing against. Default is the home URL.
		 *
		 * [--include-headers]
		 * : Include headers in debug check output.
		 *
		 * [--include-grep]
		 * : Also grep active theme and plugin directories for common issues.
		 *
		 * [--format=<format>]
		 * : Render output in a particular format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - csv
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *      wp varnish debug
		 *
		 *      wp varnish debug http://example.com/wp-content/themes/twentyeleventy/style.css
		 */
		public function debug( $args, $assoc_args ) {

			// Set the URL/path.
			if ( ! empty( $args ) ) {
				list( $url ) = $args;
			}

			if ( empty( $url ) ) {
				$url = esc_url( $this->varnish_purge->the_home_url() );
			}

			WP_CLI::log( __( 'Robots are scanning your site for possible issues with caching... ', 'varnish-http-purge' ) );

			if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'include-grep' ) ) {
				$pattern = '(PHPSESSID|session_start|start_session|$cookie|setCookie)';
				// translators: %s is the pattern string.
				WP_CLI::log( sprintf( __( 'Grepping for: %s.', 'varnish-http-purge' ), $pattern ) );
				WP_CLI::log( '' );
				$paths = array(
					get_template_directory(),
					get_stylesheet_directory(),
				);
				foreach ( wp_get_active_and_valid_plugins() as $plugin_path ) {
					// We don't care about our own plugin.
					if ( false !== stripos( $plugin_path, 'varnish-http-purge/varnish-http-purge.php' ) ) {
						continue;
					}
					$paths[] = dirname( $plugin_path );
				}
				$paths = array_unique( $paths );
				foreach ( $paths as $path ) {
					$cmd = sprintf(
						// Greps for matches and removes ABSPATH from filepath.
						"grep --include=*.php -RE '%s' %s | cut -d '/' -f %d-",
						$pattern,
						escapeshellarg( $path ),
						substr_count( ABSPATH, '/' ) + 1
					);
					// @codingStandardsIgnoreStart
					system( $cmd );
					// @codingStandardsIgnoreEnd
				}
				WP_CLI::log( '' );
				WP_CLI::log( __( 'Grep complete. If no data was output, you\'re good!', 'varnish-http-purge' ) );
			}

			// Include the debug code.
			if ( ! class_exists( 'VarnishDebug' ) ) {
				include 'debug.php';
			}

			// Validate the URL.
			$valid_url = VarnishDebug::is_url_valid( $url );

			if ( 'valid' !== $valid_url ) {
				switch ( $valid_url ) {
					case 'empty':
					case 'domain':
						WP_CLI::error( __( 'You must provide a URL on your own domain to scan.', 'varnish-http-purge' ) );
						break;
					case 'invalid':
						WP_CLI::error( __( 'You have entered an invalid URL address.', 'varnish-http-purge' ) );
						break;
					default:
						WP_CLI::error( __( 'An unknown error has occurred.', 'varnish-http-purge' ) );
						break;
				}
			}
			$varnishurl = get_site_option( 'vhp_varnish_url', $url );

			// Get the response and headers.
			$remote_get = VarnishDebug::remote_get( $varnishurl );

			if ( is_wp_error( $remote_get ) || 'fail' === $remote_get ) {
				WP_CLI::error( __( 'Unable to retrieve data. Debug cannot be run at this time. Please run "curl -I [URL]" manually on your personal computer.', 'varnish-http-purge' ) );
			}

			$headers = wp_remote_retrieve_headers( $remote_get );

			if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'include-headers' ) ) {
				WP_CLI::log( 'Headers:' );
				foreach ( $headers as $key => $value ) {
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					WP_CLI::log( " - {$key}: {$value}" );
				}
			}

			// Preflight checklist.
			$preflight = VarnishDebug::preflight( $remote_get );

			// Check for Remote IP.
			$remote_ip = VarnishDebug::remote_ip( $headers );

			// Get the IP.
			if ( false !== VHP_VARNISH_IP ) {
				$varniship = VHP_VARNISH_IP;
			} else {
				$varniship = get_site_option( 'vhp_varnish_ip' );
			}

			if ( false === $preflight['preflight'] ) {
				WP_CLI::error( $preflight['message'] );
			} else {
				$results = VarnishDebug::get_all_the_results( $headers, $remote_ip, $varniship );

				// Generate array.
				foreach ( $results as $type => $content ) {
					$items[] = array(
						'name'    => $type,
						'status'  => ucwords( $content['icon'] ),
						'message' => $content['message'],
					);
				}

				$format = ( isset( $assoc_args['format'] ) ) ? $assoc_args['format'] : 'table';

				// Output the data.
				WP_CLI\Utils\format_items( $format, $items, array( 'name', 'status', 'message' ) );
			}
		} // End Debug.

		/**
		 * Inspect or manage the async purge queue used when cron-mode is enabled.
		 *
		 * This command is primarily intended for operational use and mirrors the
		 * behaviour of the background WP-Cron processor.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : The queue action to perform.
		 * ---
		 * options:
		 *   - status
		 *   - process
		 *   - clear
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     # Show queue status
		 *     wp varnish queue status
		 *
		 *     # Process any pending items immediately
		 *     wp varnish queue process
		 *
		 *     # Clear the queue without processing
		 *     wp varnish queue clear
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function queue( $args, $assoc_args ) {
			$action = isset( $args[0] ) ? sanitize_key( $args[0] ) : 'status';

			if ( ! class_exists( 'VarnishPurger' ) ) {
				WP_CLI::error( __( 'VarnishPurger class is not available. Is the plugin active?', 'varnish-http-purge' ) );
			}

			$queue = get_site_option( VarnishPurger::PURGE_QUEUE_OPTION, array() );
			if ( ! is_array( $queue ) ) {
				$queue = array();
			}

			$full            = ( isset( $queue['full'] ) && $queue['full'] );
			$urls            = ( isset( $queue['urls'] ) && is_array( $queue['urls'] ) ) ? $queue['urls'] : array();
			$tags            = ( isset( $queue['tags'] ) && is_array( $queue['tags'] ) ) ? $queue['tags'] : array();
			$created_at      = isset( $queue['created_at'] ) ? (int) $queue['created_at'] : 0;
			$last_updated_at = isset( $queue['last_updated_at'] ) ? (int) $queue['last_updated_at'] : 0;
			$last_run        = (int) get_site_option( 'vhp_varnish_last_queue_run', 0 );

			switch ( $action ) {
				case 'clear':
					delete_site_option( VarnishPurger::PURGE_QUEUE_OPTION );
					WP_CLI::success( __( 'Proxy Cache Purge queue cleared.', 'varnish-http-purge' ) );
					break;

				case 'process':
					// Run the same processor that WP-Cron uses.
					$this->varnish_purge->process_purge_queue();
					WP_CLI::success( __( 'Proxy Cache Purge queue processed.', 'varnish-http-purge' ) );
					break;

				case 'status':
				default:
					$data = array(
						array(
							'field' => 'cron_mode_enabled',
							'value' => VarnishPurger::is_cron_purging_enabled_static() ? 'yes' : 'no',
						),
						array(
							'field' => 'full_purge_queued',
							'value' => $full ? 'yes' : 'no',
						),
						array(
							'field' => 'queued_urls',
							'value' => count( $urls ),
						),
						array(
							'field' => 'queued_tags',
							'value' => count( $tags ),
						),
						array(
							'field' => 'queue_created_at',
							'value' => $created_at ? date_i18n( 'Y-m-d H:i:s', $created_at ) : '',
						),
						array(
							'field' => 'queue_last_updated_at',
							'value' => $last_updated_at ? date_i18n( 'Y-m-d H:i:s', $last_updated_at ) : '',
						),
						array(
							'field' => 'last_queue_run',
							'value' => $last_run ? date_i18n( 'Y-m-d H:i:s', $last_run ) : '',
						),
					);

					WP_CLI\Utils\format_items( 'table', $data, array( 'field', 'value' ) );
					break;
			}
		}
	}
}

WP_CLI::add_command( 'varnish', 'WP_CLI_Varnish_Command' );
