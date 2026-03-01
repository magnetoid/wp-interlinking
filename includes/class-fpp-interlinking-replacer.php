<?php
/**
 * Front-end content filter that replaces configured keywords with links.
 *
 * Hooks into `the_content` at priority 999 so that other plugins can finish
 * processing the content first. Content is split into segments ONCE, then all
 * keywords are matched against plain-text segments only.
 *
 * Protected elements (not replaced):
 *  - Existing <a> links.
 *  - <h1>–<h6> headings, <button>, <label>, <figcaption>.
 *  - <script>, <style>, <code>, <pre>, <textarea>, <select>, <option>.
 *  - HTML comments.
 *
 * Security: output is escaped late using esc_url() and esc_html().
 *
 * @since   1.0.0
 * @package FPP_Interlinking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FPP_Interlinking_Replacer {

	/**
	 * Pending impression data to flush on shutdown.
	 *
	 * @since 4.0.0
	 * @var array
	 */
	private static $pending_impressions = array();

	/**
	 * Whether the shutdown hook has been registered.
	 *
	 * @since 4.0.0
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Register the content filter.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'the_content', array( $this, 'replace_keywords' ), 999 );
	}

	/**
	 * Main filter callback.
	 *
	 * Splits content once into protected/plain segments, then iterates all
	 * keywords against the plain-text segments. This is O(segments × keywords)
	 * with a single regex split instead of the old O(keywords × split).
	 *
	 * @since 1.0.0
	 *
	 * @param  string $content Post content.
	 * @return string Modified content.
	 */
	public function replace_keywords( $content ) {
		// Bail early in contexts where replacement is not desired.
		if ( is_admin() || is_feed() || empty( $content ) ) {
			return $content;
		}

		// Skip REST API and AJAX requests.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $content;
		}
		if ( wp_doing_ajax() ) {
			return $content;
		}

		// Skip excluded posts.
		$excluded = $this->get_excluded_post_ids();
		$post_id  = get_the_ID();
		if ( $post_id && in_array( $post_id, $excluded, true ) ) {
			return $content;
		}

		// Skip disallowed post types.
		if ( $post_id ) {
			$allowed_types = $this->get_allowed_post_types();
			$current_type  = get_post_type( $post_id );
			if ( ! empty( $allowed_types ) && ! in_array( $current_type, $allowed_types, true ) ) {
				return $content;
			}
		}

		$keywords = $this->get_cached_keywords();
		if ( empty( $keywords ) ) {
			return $content;
		}

		// Resolve the current post's permalink once for self-link prevention.
		$current_url = ( $post_id ) ? get_permalink( $post_id ) : '';

		$global_max      = (int) get_option( 'fpp_interlinking_max_replacements', 1 );
		$global_nofollow = (int) get_option( 'fpp_interlinking_nofollow', 0 );
		$global_new_tab  = (int) get_option( 'fpp_interlinking_new_tab', 1 );
		$case_sensitive  = (int) get_option( 'fpp_interlinking_case_sensitive', 0 );
		$max_per_post    = (int) get_option( 'fpp_interlinking_max_links_per_post', 0 );

		// Sort by keyword length descending to prevent partial matches.
		// e.g. "WordPress SEO" is processed before "WordPress".
		usort( $keywords, function ( $a, $b ) {
			return strlen( $b['keyword'] ) - strlen( $a['keyword'] );
		} );

		/*
		 * Split content ONCE into protected and plain-text segments.
		 *
		 * Protected segments (captured by the regex) include:
		 *  - Existing <a> … </a> blocks (DOTALL so nested tags are handled).
		 *  - Heading <h1>–<h6> blocks — we don't replace inside headings.
		 *  - <button>, <label>, <figcaption> blocks.
		 *  - <script>, <style>, <code>, <pre>, <textarea>, <select>, <option>.
		 *  - HTML comments <!-- … -->.
		 *  - Any remaining HTML tag (<…>).
		 */
		$protected_pattern = '/'
			. '(<a\b[^>]*>.*?<\/a>'                    // Existing anchor links.
			. '|<h[1-6]\b[^>]*>.*?<\/h[1-6]>'          // Headings h1–h6.
			. '|<button\b[^>]*>.*?<\/button>'           // Button elements.
			. '|<label\b[^>]*>.*?<\/label>'             // Label elements.
			. '|<figcaption\b[^>]*>.*?<\/figcaption>'   // Figcaption elements.
			. '|<script\b[^>]*>.*?<\/script>'           // Script blocks.
			. '|<style\b[^>]*>.*?<\/style>'             // Style blocks.
			. '|<code\b[^>]*>.*?<\/code>'               // Code blocks.
			. '|<pre\b[^>]*>.*?<\/pre>'                 // Preformatted blocks.
			. '|<textarea\b[^>]*>.*?<\/textarea>'       // Textarea blocks.
			. '|<select\b[^>]*>.*?<\/select>'           // Select blocks.
			. '|<option\b[^>]*>.*?<\/option>'           // Option elements.
			. '|<!--.*?-->'                              // HTML comments.
			. '|<[^>]+>'                                 // Any HTML tag.
			. ')/is';

		$parts = preg_split( $protected_pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return $content;
		}

		// Track total links inserted for the per-post cap.
		$total_links = 0;

		// Process each keyword against the pre-split segments.
		foreach ( $keywords as $mapping ) {
			// Self-link prevention: skip when the target URL matches the current post.
			if ( $current_url && $this->urls_match( $current_url, $mapping['target_url'] ) ) {
				continue;
			}

			// Check global per-post cap.
			if ( $max_per_post > 0 && $total_links >= $max_per_post ) {
				break;
			}

			$keyword  = preg_quote( $mapping['keyword'], '/' );
			$max      = ( (int) $mapping['max_replacements'] > 0 ) ? (int) $mapping['max_replacements'] : $global_max;
			$nofollow = (int) $mapping['nofollow'] ? true : (bool) $global_nofollow;
			$new_tab  = (int) $mapping['new_tab'] ? true : (bool) $global_new_tab;

			// Escape URL late, at the point of output.
			$url       = esc_url( $mapping['target_url'] );
			$rel_parts = array();
			if ( $nofollow ) {
				$rel_parts[] = 'nofollow';
			}
			if ( $new_tab ) {
				$rel_parts[] = 'noopener';
				$rel_parts[] = 'noreferrer';
			}
			$rel_attr    = ! empty( $rel_parts ) ? ' rel="' . implode( ' ', $rel_parts ) . '"' : '';
			$target_attr = $new_tab ? ' target="_blank"' : '';

			$flags           = $case_sensitive ? '' : 'i';
			$keyword_pattern = '/\b(' . $keyword . ')\b/' . $flags;
			$kw_count        = 0;

			foreach ( $parts as &$part ) {
				// Skip protected segments (anything starting with <).
				if ( isset( $part[0] ) && '<' === $part[0] ) {
					continue;
				}

				// Per-keyword cap reached.
				if ( $max > 0 && $kw_count >= $max ) {
					break;
				}

				// Per-post cap reached.
				if ( $max_per_post > 0 && $total_links >= $max_per_post ) {
					break;
				}

				// v3.0.0: Build tracking data attributes for click analytics.
				$tracking_attr = '';
				if ( (int) get_option( 'fpp_interlinking_enable_tracking', 1 ) ) {
					$tracking_attr = ' data-fpp-keyword-id="' . esc_attr( $mapping['id'] ) . '"'
						. ' data-fpp-post-id="' . esc_attr( $post_id ? $post_id : 0 ) . '"';
				}

				$part = preg_replace_callback(
					$keyword_pattern,
					function ( $matches ) use ( $url, $rel_attr, $target_attr, $tracking_attr, &$kw_count, $max, &$total_links, $max_per_post ) {
						if ( $max > 0 && $kw_count >= $max ) {
							return $matches[0];
						}
						if ( $max_per_post > 0 && $total_links >= $max_per_post ) {
							return $matches[0];
						}
						$kw_count++;
						$total_links++;
						return '<a href="' . $url . '"' . $rel_attr . $target_attr . $tracking_attr . '>' . esc_html( $matches[1] ) . '</a>';
					},
					$part
				);
			}
			unset( $part );
		}

		// v4.0.0: Collect keyword IDs that were linked for impression tracking.
		if ( $total_links > 0 && $post_id && (int) get_option( 'fpp_interlinking_enable_tracking', 1 ) ) {
			$linked_ids = array();
			foreach ( $keywords as $mapping ) {
				// Check if this keyword was actually linked by looking for its tracking attribute in the output.
				if ( strpos( implode( '', $parts ), 'data-fpp-keyword-id="' . esc_attr( $mapping['id'] ) . '"' ) !== false ) {
					$linked_ids[] = (int) $mapping['id'];
				}
			}
			if ( ! empty( $linked_ids ) ) {
				self::$pending_impressions[] = array(
					'keyword_ids' => $linked_ids,
					'post_id'     => (int) $post_id,
				);
				if ( ! self::$shutdown_registered ) {
					add_action( 'shutdown', array( __CLASS__, 'flush_impressions' ) );
					self::$shutdown_registered = true;
				}
			}
		}

		return implode( '', $parts );
	}

	/**
	 * Batch-insert impression data on shutdown.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE to aggregate daily counts
	 * without creating duplicate rows.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public static function flush_impressions() {
		if ( empty( self::$pending_impressions ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'fpp_interlinking_impressions';
		$today = current_time( 'Y-m-d' );

		foreach ( self::$pending_impressions as $entry ) {
			foreach ( $entry['keyword_ids'] as $keyword_id ) {
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$table} (keyword_id, post_id, impression_date, impression_count)
					 VALUES (%d, %d, %s, 1)
					 ON DUPLICATE KEY UPDATE impression_count = impression_count + 1",
					$keyword_id,
					$entry['post_id'],
					$today
				) );
			}
		}

		self::$pending_impressions = array();
	}

	/**
	 * Retrieve active keywords, with transient caching (1 hour).
	 *
	 * Cache is busted whenever keywords or settings change via the admin.
	 *
	 * @since  1.0.0
	 *
	 * @return array<array<string,mixed>>
	 */
	private function get_cached_keywords() {
		$keywords = get_transient( 'fpp_interlinking_keywords_cache' );
		if ( false === $keywords ) {
			$keywords = FPP_Interlinking_DB::get_all_keywords( true );
			set_transient( 'fpp_interlinking_keywords_cache', $keywords, HOUR_IN_SECONDS );
		}
		return $keywords;
	}

	/**
	 * Parse the excluded-posts option into an array of integer IDs.
	 *
	 * @since  1.0.0
	 *
	 * @return int[]
	 */
	private function get_excluded_post_ids() {
		$excluded_raw = get_option( 'fpp_interlinking_excluded_posts', '' );
		if ( empty( $excluded_raw ) ) {
			return array();
		}

		$items = array_map( 'trim', explode( ',', $excluded_raw ) );
		$ids   = array();

		foreach ( $items as $item ) {
			if ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
			}
		}

		return $ids;
	}

	/**
	 * Retrieve the allowed post types for keyword replacement.
	 *
	 * @since  2.1.0
	 *
	 * @return string[] Array of post type slugs.
	 */
	private function get_allowed_post_types() {
		$setting = get_option( 'fpp_interlinking_post_types', 'post,page' );
		if ( empty( $setting ) ) {
			return array();
		}

		return array_filter( array_map( 'trim', explode( ',', $setting ) ) );
	}

	/**
	 * Compare two URLs ignoring trailing slashes and scheme differences.
	 *
	 * Used for self-link prevention: we don't want to link a post to itself.
	 *
	 * @since  1.1.0
	 *
	 * @param  string $url_a First URL.
	 * @param  string $url_b Second URL.
	 * @return bool   True if the URLs effectively point to the same resource.
	 */
	private function urls_match( $url_a, $url_b ) {
		$normalize = function ( $url ) {
			$url = strtolower( trim( $url ) );
			$url = preg_replace( '#^https?://#', '', $url );
			$url = untrailingslashit( $url );
			return $url;
		};

		return $normalize( $url_a ) === $normalize( $url_b );
	}
}
