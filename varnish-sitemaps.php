<?php
/**
 * Varnish Sitemaps Class
 *
 * Purges XML sitemaps when the content they list changes, so that sitemaps
 * can be cached for a long time without going stale.
 *
 * @package varnish-http-purge
 * @since 5.13.0
 */

defined( 'ABSPATH' ) || exit;

class VarnishSitemaps {

	/**
	 * Above this many changed posts in one request, their terms are no longer
	 * looked up one by one.
	 *
	 * @since 5.13.0
	 * @var int
	 */
	const MAX_POSTS_TO_INSPECT = 20;

	/**
	 * The purger that sends (or queues) the PURGE requests.
	 *
	 * @since 5.13.0
	 * @var VarnishPurger|null
	 */
	protected $purger;

	/**
	 * Sitemap changes recorded during this request, per blog ID.
	 *
	 * Each entry holds 'post_ids' (posts whose taxonomies are resolved at
	 * flush time, because the REST API assigns terms only after the post
	 * itself is saved), 'post_types' and 'taxonomies' (names as keys),
	 * 'author' (the author sitemap changed) and 'all' (every sitemap changed).
	 *
	 * @since 5.13.0
	 * @var array
	 */
	protected $pending = array();

	/**
	 * Init
	 *
	 * @since 5.13.0
	 * @access public
	 * @param VarnishPurger|null $purger The purger that sends the PURGE requests.
	 */
	public function __construct( $purger = null ) {
		$this->purger = $purger;

		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ) );
		add_action( 'add_attachment', array( $this, 'on_attachment_change' ) );
		add_action( 'edit_attachment', array( $this, 'on_attachment_change' ) );
		add_action( 'delete_attachment', array( $this, 'on_attachment_change' ) );
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 10, 6 );
		// A new term has no posts yet; set_object_terms records it once it gets one.
		add_action( 'edited_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'profile_update', array( $this, 'on_user_change' ) );
		add_action( 'user_register', array( $this, 'on_user_change' ) );
		add_action( 'deleted_user', array( $this, 'on_user_change' ) );
		add_action( 'update_option_wpseo', array( $this, 'on_settings_change' ), 10, 2 );
		add_action( 'update_option_wpseo_titles', array( $this, 'on_settings_change' ), 10, 2 );

		// Late on purpose: sitemap plugins drop their own sitemap cache on
		// shutdown at the default priority. Purging the proxy before that
		// would let a crawler re-cache the previous sitemap.
		add_action( 'shutdown', array( $this, 'flush' ), 999 );
	}

	/**
	 * Whether sitemap purging is active on this site.
	 *
	 * On by default when Yoast SEO serves XML sitemaps. Other sitemap plugins
	 * that use the same URL scheme can opt in with the vhp_purge_sitemaps filter.
	 *
	 * @since 5.13.0
	 * @access public
	 * @static
	 * @return bool
	 */
	public static function is_enabled() {
		if ( defined( 'VHP_DISABLE_SITEMAP_PURGE' ) && VHP_DISABLE_SITEMAP_PURGE ) {
			return false;
		}

		/**
		 * Filters whether XML sitemaps are purged when content changes.
		 *
		 * @since 5.13.0
		 *
		 * @param bool $enabled True when a supported sitemap plugin is active.
		 */
		return (bool) apply_filters( 'vhp_purge_sitemaps', self::is_yoast_active() );
	}

	/**
	 * Whether Yoast SEO is active with XML sitemaps turned on.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @return bool
	 */
	protected static function is_yoast_active() {
		if ( ! class_exists( 'WPSEO_Sitemaps_Router' ) ) {
			return false;
		}

		return (bool) self::yoast_option( 'enable_xml_sitemap', true );
	}

	/**
	 * Read a Yoast SEO option without depending on Yoast being installed.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @param string $key           Option key.
	 * @param mixed  $default_value Value to return when Yoast SEO is not active.
	 * @return mixed
	 */
	protected static function yoast_option( $key, $default_value ) {
		$getter = array( 'WPSEO_Options', 'get' );

		if ( ! is_callable( $getter ) ) {
			return $default_value;
		}

		return call_user_func( $getter, $key, $default_value );
	}

	/**
	 * Record a post entering, leaving or changing inside its sitemap.
	 *
	 * Only transitions that involve the "publish" status matter: a sitemap
	 * lists published posts, so saving a draft never changes it.
	 *
	 * @since 5.13.0
	 * @access public
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		if ( ! is_object( $post ) || empty( $post->ID ) || ! self::is_enabled() ) {
			return;
		}

		$this->record_post( $post, $new_status !== $old_status );
	}

	/**
	 * Record a published post that is deleted without going through the trash.
	 *
	 * @since 5.13.0
	 * @access public
	 * @param int $post_id Post ID.
	 */
	public function on_before_delete_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! is_object( $post ) || 'publish' !== $post->post_status || ! self::is_enabled() ) {
			return;
		}

		if ( ! self::is_post_type_in_sitemap( $post->post_type ) ) {
			return;
		}

		// The post is gone by the time we flush, so resolve its terms now.
		$this->record_post( $post, true, true );
	}

	/**
	 * Record an uploaded, edited or deleted attachment.
	 *
	 * Attachments never hold the "publish" status, so the post status hook
	 * does not see them. They have a sitemap only when attachment pages are on.
	 *
	 * @since 5.13.0
	 * @access public
	 */
	public function on_attachment_change() {
		if ( self::is_enabled() && self::is_post_type_in_sitemap( 'attachment' ) ) {
			$this->mark( 'post_types', 'attachment' );
		}
	}

	/**
	 * Record a published post whose terms changed.
	 *
	 * @since 5.13.0
	 * @access public
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Terms (unused).
	 * @param array  $tt_ids     New term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether terms were appended (unused).
	 * @param array  $old_tt_ids Old term taxonomy IDs.
	 */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$new_ids = array_map( 'intval', (array) $tt_ids );
		$old_ids = array_map( 'intval', (array) $old_tt_ids );
		sort( $new_ids );
		sort( $old_ids );

		if ( $new_ids === $old_ids || 'publish' !== get_post_status( $object_id ) || ! self::is_enabled() ) {
			return;
		}

		if ( self::is_taxonomy_in_sitemap( $taxonomy ) ) {
			$this->mark( 'taxonomies', $taxonomy );
		}
	}

	/**
	 * Record an edited or deleted term.
	 *
	 * @since 5.13.0
	 * @access public
	 * @param int    $term_id  Term ID (unused).
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function on_term_change( $term_id, $tt_id = 0, $taxonomy = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! is_string( $taxonomy ) || '' === $taxonomy || ! self::is_enabled() ) {
			return;
		}

		if ( self::is_taxonomy_in_sitemap( $taxonomy ) ) {
			$this->mark( 'taxonomies', $taxonomy );
		}
	}

	/**
	 * Record a registered, updated or deleted user.
	 *
	 * @since 5.13.0
	 * @access public
	 */
	public function on_user_change() {
		if ( self::is_enabled() && self::is_author_in_sitemap() ) {
			$this->mark( 'author' );
		}
	}

	/**
	 * Record a change of the SEO settings that decide what a sitemap lists.
	 *
	 * Yoast SEO also keeps internal state (indexing progress, notices) in the
	 * same options and saves it often; those saves change no sitemap.
	 *
	 * @since 5.13.0
	 * @access public
	 * @param mixed $old_value Option value before the update.
	 * @param mixed $value     Option value after the update.
	 */
	public function on_settings_change( $old_value = null, $value = null ) {
		if ( self::settings_change_affects_sitemaps( $old_value, $value ) && self::is_enabled() ) {
			$this->mark( 'all' );
		}
	}

	/**
	 * Whether two versions of a Yoast SEO option differ in a sitemap setting:
	 * the sitemap switch itself, or any "noindex-..." / "disable-..." key.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @param mixed $old_value Option value before the update.
	 * @param mixed $value     Option value after the update.
	 * @return bool
	 */
	protected static function settings_change_affects_sitemaps( $old_value, $value ) {
		if ( ! is_array( $old_value ) || ! is_array( $value ) ) {
			return true;
		}

		foreach ( array_keys( $old_value + $value ) as $key ) {
			if ( ! is_string( $key ) || ! preg_match( '/^(noindex-|disable-|enable_xml_sitemap$)/', $key ) ) {
				continue;
			}

			$before = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
			$after  = isset( $value[ $key ] ) ? $value[ $key ] : null;

			if ( $before !== $after ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record one changed post.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @param WP_Post $post           Post object.
	 * @param bool    $status_changed Whether the post entered or left the published set.
	 * @param bool    $resolve_now    Resolve the post's taxonomies now instead of at flush time.
	 */
	protected function record_post( $post, $status_changed, $resolve_now = false ) {
		if ( ! self::is_post_type_in_sitemap( $post->post_type ) ) {
			return;
		}

		$this->mark( 'post_types', $post->post_type );

		if ( $resolve_now ) {
			foreach ( self::get_post_taxonomies( $post ) as $taxonomy ) {
				$this->mark( 'taxonomies', $taxonomy );
			}
		} else {
			$this->mark( 'post_ids', $post->ID );
		}

		// An author is listed only while they have published posts.
		if ( $status_changed && post_type_supports( $post->post_type, 'author' ) && self::is_author_in_sitemap() ) {
			$this->mark( 'author' );
		}
	}

	/**
	 * Note a sitemap change for the current blog.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @param string          $group 'post_ids', 'post_types', 'taxonomies', 'author' or 'all'.
	 * @param string|int|null $name  Entry to add to a list group; omit for the 'author' and 'all' flags.
	 */
	protected function mark( $group, $name = null ) {
		$blog_id = get_current_blog_id();

		if ( ! isset( $this->pending[ $blog_id ] ) ) {
			$this->pending[ $blog_id ] = array(
				'post_ids'   => array(),
				'post_types' => array(),
				'taxonomies' => array(),
				'author'     => false,
				'all'        => false,
			);
		}

		if ( null === $name ) {
			$this->pending[ $blog_id ][ $group ] = true;
		} else {
			$this->pending[ $blog_id ][ $group ][ $name ] = true;
		}
	}

	/**
	 * Purge (or queue) the sitemaps recorded during this request.
	 *
	 * @since 5.13.0
	 * @access public
	 */
	public function flush() {
		// Nearly every request changes nothing: leave before doing any work.
		if ( empty( $this->pending ) ) {
			return;
		}

		$pending       = $this->pending;
		$this->pending = array();
		$urls          = array();

		foreach ( $pending as $blog_id => $changes ) {
			// On multisite a change may have been made inside switch_to_blog().
			$switched = is_multisite() && get_current_blog_id() !== $blog_id;

			if ( $switched ) {
				switch_to_blog( $blog_id );
			}

			$urls = array_merge( $urls, self::urls_for_changes( $changes ) );

			if ( $switched ) {
				restore_current_blog();
			}
		}

		if ( empty( $urls ) ) {
			return;
		}

		if ( is_object( $this->purger ) && method_exists( $this->purger, 'purge_or_enqueue_urls' ) ) {
			$this->purger->purge_or_enqueue_urls( $urls );
			return;
		}

		foreach ( array_unique( $urls ) as $url ) {
			VarnishPurger::purge_url( $url );
		}
	}

	/**
	 * Sitemap URLs to purge for the changes recorded on one blog.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @param array $changes One entry of $pending.
	 * @return array
	 */
	protected static function urls_for_changes( $changes ) {
		if ( $changes['all'] ) {
			return self::all_urls();
		}

		$taxonomies = $changes['taxonomies'];

		if ( count( $changes['post_ids'] ) > self::MAX_POSTS_TO_INSPECT ) {
			// A bulk edit or an import: looking up the terms of every post would
			// be slow, so purge every taxonomy sitemap of the affected post types.
			foreach ( array_keys( $changes['post_types'] ) as $post_type ) {
				foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
					if ( self::is_taxonomy_in_sitemap( $taxonomy ) ) {
						$taxonomies[ $taxonomy ] = true;
					}
				}
			}
		} else {
			foreach ( array_keys( $changes['post_ids'] ) as $post_id ) {
				$post = get_post( $post_id );
				if ( ! is_object( $post ) ) {
					continue;
				}
				foreach ( self::get_post_taxonomies( $post ) as $taxonomy ) {
					$taxonomies[ $taxonomy ] = true;
				}
			}
		}

		return self::build_urls(
			array(
				'post_types' => array_keys( $changes['post_types'] ),
				'taxonomies' => array_keys( $taxonomies ),
				'author'     => $changes['author'],
				'all'        => false,
			)
		);
	}

	/**
	 * Sitemap URLs that list a given post: its post type sitemap, the
	 * sitemaps of the taxonomies it has terms in, and the sitemap index.
	 *
	 * @since 5.13.0
	 * @access public
	 * @static
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function urls_for_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! is_object( $post ) || ! self::is_enabled() || ! self::is_post_type_in_sitemap( $post->post_type ) ) {
			return array();
		}

		return self::build_urls(
			array(
				'post_types' => array( $post->post_type ),
				'taxonomies' => self::get_post_taxonomies( $post ),
				'author'     => false,
				'all'        => false,
			)
		);
	}

	/**
	 * Every sitemap URL of the site.
	 *
	 * @since 5.13.0
	 * @access public
	 * @static
	 * @return array
	 */
	public static function all_urls() {
		if ( ! self::is_enabled() ) {
			return array();
		}

		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( self::is_post_type_in_sitemap( $post_type ) ) {
				$post_types[] = $post_type;
			}
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			if ( self::is_taxonomy_in_sitemap( $taxonomy ) ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return self::build_urls(
			array(
				'post_types' => $post_types,
				'taxonomies' => $taxonomies,
				'author'     => self::is_author_in_sitemap(),
				'all'        => true,
			)
		);
	}

	/**
	 * Turn a set of changed sitemaps into purge URLs.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @param array $context Changed sitemaps: 'post_types', 'taxonomies', 'author', 'all'.
	 * @return array
	 */
	protected static function build_urls( $context ) {
		$urls = array();

		foreach ( $context['post_types'] as $post_type ) {
			// Attachments are stored with the "inherit" status, never "publish".
			$status = ( 'attachment' === $post_type ) ? 'inherit' : 'publish';
			$counts = wp_count_posts( $post_type );
			$count  = isset( $counts->$status ) ? (int) $counts->$status : 0;
			$urls[] = self::sitemap_url( $post_type, $count );
		}

		foreach ( $context['taxonomies'] as $taxonomy ) {
			$count = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'fields'     => 'count',
				)
			);
			// An uncountable taxonomy is purged across all of its pages.
			$urls[] = self::sitemap_url( $taxonomy, is_numeric( $count ) ? (int) $count : PHP_INT_MAX - 1 );
		}

		if ( $context['author'] ) {
			$urls[] = self::sitemap_url( 'author', function_exists( 'get_user_count' ) ? (int) get_user_count() : PHP_INT_MAX - 1 );
		}

		// The index carries a last-modified date per sitemap, so it changes with any of them.
		if ( ! empty( $urls ) || $context['all'] ) {
			array_unshift( $urls, self::base_url( 'sitemap_index.xml' ) );
		}

		/**
		 * Filters the sitemap URLs that are about to be purged.
		 *
		 * Append "?vhp-regex" to a URL to purge everything that starts with it.
		 *
		 * @since 5.13.0
		 *
		 * @param array $urls    Sitemap URLs to purge.
		 * @param array $context Changed sitemaps: 'post_types' (array), 'taxonomies' (array),
		 *                       'author' (bool), 'all' (bool).
		 */
		$urls = apply_filters( 'vhp_sitemap_purge_urls', $urls, $context );

		return array_values( array_unique( array_filter( (array) $urls, 'is_string' ) ) );
	}

	/**
	 * Purge URL for one sitemap type.
	 *
	 * A type that fits on one sitemap page is purged by its exact URL. A
	 * paginated type ("post-sitemap.xml", "post-sitemap2.xml", ...) is purged
	 * with one wildcard request, because an edit moves a post between pages.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @param string $type  Sitemap type: a post type, a taxonomy, or "author".
	 * @param int    $count Number of entries of that type.
	 * @return string
	 */
	protected static function sitemap_url( $type, $count ) {
		// One spare entry, so a type shrinking back to a single page still clears page 2.
		if ( $count + 1 > self::entries_per_page() ) {
			return self::base_url( $type . '-sitemap' ) . '?vhp-regex';
		}

		return self::base_url( $type . '-sitemap.xml' );
	}

	/**
	 * Number of entries on one sitemap page.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @return int
	 */
	protected static function entries_per_page() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own filter.
		$per_page = (int) apply_filters( 'wpseo_sitemap_entries_per_page', 1000 );

		/**
		 * Filters the number of entries on one sitemap page.
		 *
		 * @since 5.13.0
		 *
		 * @param int $per_page Entries per sitemap page.
		 */
		$per_page = (int) apply_filters( 'vhp_sitemap_entries_per_page', $per_page );

		return max( 1, $per_page );
	}

	/**
	 * Absolute URL of a sitemap file.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @param string $page Sitemap file name, e.g. "post-sitemap.xml".
	 * @return string
	 */
	protected static function base_url( $page ) {
		$builder = array( 'WPSEO_Sitemaps_Router', 'get_base_url' );

		if ( is_callable( $builder ) ) {
			return (string) call_user_func( $builder, $page );
		}

		return home_url( '/' . $page );
	}

	/**
	 * Taxonomies a post has terms in and that have a sitemap.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	protected static function get_post_taxonomies( $post ) {
		$taxonomies = array();

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( ! self::is_taxonomy_in_sitemap( $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}

	/**
	 * Whether a post type has a sitemap.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	protected static function is_post_type_in_sitemap( $post_type ) {
		$object = get_post_type_object( $post_type );

		if ( ! is_object( $object ) || ! is_post_type_viewable( $object ) ) {
			return false;
		}

		// Yoast SEO redirects attachment pages to the file itself by default.
		if ( 'attachment' === $post_type && self::yoast_option( 'disable-attachment', false ) ) {
			return false;
		}

		return ! self::yoast_option( 'noindex-' . $post_type, false );
	}

	/**
	 * Whether a taxonomy has a sitemap.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	protected static function is_taxonomy_in_sitemap( $taxonomy ) {
		$object = get_taxonomy( $taxonomy );

		if ( ! is_object( $object ) || empty( $object->public ) ) {
			return false;
		}

		return ! self::yoast_option( 'noindex-tax-' . $taxonomy, false );
	}

	/**
	 * Whether authors have a sitemap.
	 *
	 * @since 5.13.0
	 * @access protected
	 * @static
	 * @return bool
	 */
	protected static function is_author_in_sitemap() {
		return ! self::yoast_option( 'disable-author', false ) && ! self::yoast_option( 'noindex-author-wpseo', false );
	}
}
