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
	 * @since 5.4.0
	 * @access protected
	 * @return array
	 */
	protected function get_tags() {
		$tags = array();

		// Global tags.
		$tags[] = 'site-' . get_current_blog_id();

		if ( is_front_page() ) {
			$tags[] = 'home';
		}

		if ( is_home() ) {
			$tags[] = 'blog';
			$tags[] = 'archive';
		}

		// Single Post/Page/Custom Post Type.
		if ( is_singular() ) {
			global $post;
			if ( isset( $post->ID ) ) {
				$tags[] = 'p-' . $post->ID;
				$tags[] = 'pt-' . $post->post_type;

				// Author.
				$tags[] = 'a-' . $post->post_author;

				// Terms.
				$taxonomies = get_object_taxonomies( $post->post_type );
				foreach ( $taxonomies as $tax ) {
					$terms = get_the_terms( $post->ID, $tax );
					if ( $terms && ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$tags[] = 't-' . $term->term_id;
						}
					}
				}
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
		}

		// Feeds.
		if ( is_feed() ) {
			$tags[] = 'feed';
		}

		// 404.
		if ( is_404() ) {
			$tags[] = '404';
		}

		return array_unique( $tags );
	}

	/**
	 * Get Tags to Purge for a Post
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

		// Global site tag.
		$tags[] = 'site-' . get_current_blog_id();

		// Post specific tags.
		$tags[] = 'p-' . $post->ID;
		$tags[] = 'pt-' . $post->post_type;

		// Author.
		$tags[] = 'a-' . $post->post_author;

		// Terms.
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $tax ) {
			$terms = get_the_terms( $post->ID, $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$tags[] = 't-' . $term->term_id;
				}
			}
		}

		// General Context Tags that should be purged on post update.
		$tags[] = 'archive';
		$tags[] = 'feed';
		$tags[] = 'blog';

		// If it's a page on front, purge home.
		// Actually, any post update might change home (recent posts).
		$tags[] = 'home';

		// 404s might be resolved?
		$tags[] = '404';

		return array_unique( $tags );
	}
}
