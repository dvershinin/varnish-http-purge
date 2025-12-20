<?php
/**
 * Varnish Tags Class
 *
 * @package varnish-http-purge
 * @since 5.4.0
 */

class VarnishTags {

	/**
	 * Init
	 *
	 * @since 5.4.0
	 * @access public
	 */
	public function __construct() {
		add_action( 'send_headers', array( $this, 'add_headers' ) );
	}

	/**
	 * Add Headers
	 *
	 * @since 5.4.0
	 * @access public
	 */
	public function add_headers() {
		if ( headers_sent() ) {
			return;
		}

		$tags = $this->get_tags();

		if ( empty( $tags ) ) {
			return;
		}

		// Allow filtering of the header name.
		$header_name = apply_filters( 'vhp_cache_tags_header', 'X-Cache-Tags' );

		// Allow filtering of the tags.
		$tags = apply_filters( 'vhp_cache_tags', $tags );

		// Output the header.
		header( sprintf( '%s: %s', $header_name, implode( ',', $tags ) ) );
	}

	/**
	 * Get Tags
	 *
	 * Generates cache tags for the current request. Listing pages (blog, home,
	 * archives) include individual post tags for all displayed posts, enabling
	 * precise cache invalidation when any of those posts is updated.
	 *
	 * @since 5.4.0
	 * @access protected
	 * @return array
	 */
	protected function get_tags() {
		$tags = array();

		// Global tags.
		$tags[] = 'site-' . get_current_blog_id();

		// Front page (static or posts).
		if ( is_front_page() ) {
			$tags[] = 'home';

			// If front page shows posts (not a static page), include displayed post tags.
			if ( 'posts' === get_option( 'show_on_front' ) ) {
				$tags = array_merge( $tags, $this->get_queried_post_tags() );
			}
		}

		// Blog posts page (either front page or separate posts page).
		if ( is_home() ) {
			$tags[] = 'blog';
			$tags[] = 'archive';

			// Include tags for all posts displayed on this page.
			$tags = array_merge( $tags, $this->get_queried_post_tags() );
		}

		// Single Post/Page/Custom Post Type.
		// Only tag with p-{id} to avoid cross-contamination when purging.
		// Listing pages (archives) carry context tags (pt-{type}, a-{author},
		// t-{term}) plus individual post tags, so purging p-{id} is sufficient
		// to invalidate both the single post and any listing showing it.
		if ( is_singular() ) {
			global $post;
			if ( isset( $post->ID ) ) {
				$tags[] = 'p-' . $post->ID;
			}
		}

		// Archives.
		if ( is_archive() ) {
			$tags[] = 'archive';

			if ( is_category() || is_tag() || is_tax() ) {
				$term = get_queried_object();
				if ( $term && isset( $term->term_id ) ) {
					$tags[] = 't-' . $term->term_id;
				}
			} elseif ( is_author() ) {
				$author = get_queried_object();
				if ( $author && isset( $author->ID ) ) {
					$tags[] = 'a-' . $author->ID;
				}
			} elseif ( is_post_type_archive() ) {
				$pt = get_queried_object();
				if ( $pt && isset( $pt->name ) ) {
					$tags[] = 'pt-' . $pt->name;
				}
			}

			// Include tags for all posts displayed on this archive page.
			$tags = array_merge( $tags, $this->get_queried_post_tags() );
		}

		// Feeds.
		if ( is_feed() ) {
			$tags[] = 'feed';

			// Include tags for posts in this feed.
			$tags = array_merge( $tags, $this->get_queried_post_tags() );
		}

		// 404.
		if ( is_404() ) {
			$tags[] = '404';
		}

		return array_unique( $tags );
	}

	/**
	 * Get Post Tags for Current Query
	 *
	 * Returns an array of post ID tags (p-{id}) for all posts in the current
	 * WP_Query. Used by listing pages to enable precise cache invalidation.
	 *
	 * @since 5.6.0
	 * @access protected
	 * @return array
	 */
	protected function get_queried_post_tags() {
		$tags = array();

		global $wp_query;
		if ( ! isset( $wp_query ) || ! is_object( $wp_query ) || empty( $wp_query->posts ) ) {
			return $tags;
		}

		foreach ( $wp_query->posts as $queried_post ) {
			if ( isset( $queried_post->ID ) ) {
				$tags[] = 'p-' . $queried_post->ID;
			}
		}

		return $tags;
	}

	/**
	 * Get Tags to Purge for a Post
	 *
	 * Returns an array of cache tags that should be purged when a post is
	 * updated. This method now uses precise tagging instead of blanket context
	 * tags (home, blog, archive, feed, 404).
	 *
	 * Listing pages (blog, home, archives) now include individual post tags
	 * (p-{id}) when rendered, so purging the post's own tag is sufficient to
	 * invalidate any listing page that displays it.
	 *
	 * @since 5.4.0
	 * @access public
	 * @static
	 * @param int $post_id The post ID.
	 * @return array
	 */
	public static function get_purge_tags_for_post( $post_id ) {
		$tags = array();

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $tags;
		}

		// Post specific tag - this is the PRIMARY tag for cache invalidation.
		// Listing pages (blog, home, archives) now include individual post tags
		// (p-{id}) for each displayed post, so purging p-{id} is sufficient to
		// invalidate both the single post AND any listing page showing it.
		$tags[] = 'p-' . $post->ID;

		// Only purge feed tag for post type 'post' (standard blog posts).
		// Pages and custom post types typically don't appear in the main feed.
		if ( 'post' === $post->post_type ) {
			$tags[] = 'feed';
		}

		/**
		 * Filter the tags to purge for a post.
		 *
		 * Allows adding additional tags to purge when a specific post is updated.
		 * You can add author tags (a-{id}), term tags (t-{id}), or post type
		 * tags (pt-{type}) if you need to invalidate archives that don't yet
		 * carry the post's p-{id} tag (e.g., for newly published posts).
		 *
		 * @since 5.6.0
		 *
		 * @param array   $tags    Array of cache tags to purge.
		 * @param int     $post_id The post ID being purged.
		 * @param WP_Post $post    The post object.
		 */
		$tags = apply_filters( 'vhp_purge_tags_for_post', $tags, $post_id, $post );

		return array_unique( $tags );
	}
}
