<?php
/**
 * Analytics system: click tracking, impression data, CTR, and reporting.
 *
 * Tracks clicks on auto-generated interlinks via a lightweight JS beacon.
 * Tracks impressions via the replacer's shutdown hook.
 * Provides aggregated statistics, CTR, comparisons, and CSV export.
 *
 * @since   3.0.0
 * @package FPP_Interlinking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FPP_Interlinking_Analytics {

	/**
	 * Register AJAX handlers for click tracking (both logged-in and guest).
	 *
	 * @since 3.0.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_fpp_interlinking_track_click', array( $this, 'ajax_track_click' ) );
		add_action( 'wp_ajax_nopriv_fpp_interlinking_track_click', array( $this, 'ajax_track_click' ) );
	}

	/**
	 * Enqueue the lightweight frontend click tracker script.
	 *
	 * Only loads on non-admin pages when tracking is enabled.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public static function enqueue_tracker() {
		if ( is_admin() || is_feed() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		wp_enqueue_script(
			'fpp-interlinking-tracker',
			FPP_INTERLINKING_PLUGIN_URL . 'assets/js/fpp-interlinking-tracker.js',
			array(),
			FPP_INTERLINKING_VERSION,
			true
		);

		wp_localize_script( 'fpp-interlinking-tracker', 'fppTracker', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fpp_interlinking_tracker_nonce' ),
		) );
	}

	/**
	 * AJAX handler: log a click event from the frontend tracker.
	 *
	 * Rate-limited to 10 clicks per IP per minute via transient.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and dies.
	 */
	public function ajax_track_click() {
		// Verify tracker nonce.
		if ( ! check_ajax_referer( 'fpp_interlinking_tracker_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
		}

		$keyword_id = isset( $_POST['keyword_id'] ) ? absint( $_POST['keyword_id'] ) : 0;
		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$target_url = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';

		if ( ! $keyword_id || empty( $target_url ) ) {
			wp_send_json_error( array( 'message' => 'Missing data.' ), 400 );
		}

		// Rate limit: max 10 clicks per IP per minute.
		$ip_hash    = md5( self::get_client_ip() );
		$rate_key   = 'fpp_click_rate_' . $ip_hash;
		$rate_count = (int) get_transient( $rate_key );

		if ( $rate_count >= 10 ) {
			wp_send_json_success( array( 'throttled' => true ) );
		}

		set_transient( $rate_key, $rate_count + 1, 60 );

		// Insert click record.
		global $wpdb;
		$table = self::get_clicks_table();

		$wpdb->insert(
			$table,
			array(
				'keyword_id' => $keyword_id,
				'post_id'    => $post_id,
				'target_url' => $target_url,
				'clicked_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		wp_send_json_success( array( 'tracked' => true ) );
	}

	/* ── Analytics Data Retrieval ────────────────────────────────────── */

	/**
	 * Get summary statistics for a given period.
	 *
	 * @since 3.0.0
	 *
	 * @param string $period     Period key ('today', '7days', '30days', 'all', 'custom').
	 * @param string $start_date Start date for custom period (Y-m-d).
	 * @param string $end_date   End date for custom period (Y-m-d).
	 * @return array Summary stats.
	 */
	public static function get_summary_stats( $period = '30days', $start_date = '', $end_date = '' ) {
		global $wpdb;
		$table = self::get_clicks_table();
		$where = self::period_where( $period, 'clicked_at', $start_date, $end_date );

		$total_clicks = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE 1=1 {$where}"
		);

		$unique_keywords = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT keyword_id) FROM {$table} WHERE 1=1 {$where}"
		);

		$unique_posts = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$table} WHERE post_id > 0 {$where}"
		);

		$avg_clicks = $unique_keywords > 0 ? round( $total_clicks / $unique_keywords, 1 ) : 0;

		// Top keyword.
		$top = $wpdb->get_row(
			"SELECT c.keyword_id, k.keyword, COUNT(*) as clicks
			 FROM {$table} c
			 LEFT JOIN {$wpdb->prefix}fpp_interlinking_keywords k ON k.id = c.keyword_id
			 WHERE 1=1 {$where}
			 GROUP BY c.keyword_id
			 ORDER BY clicks DESC
			 LIMIT 1"
		);

		// CTR: total impressions for the period.
		$imp_where         = self::period_where( $period, 'impression_date', $start_date, $end_date );
		$total_impressions = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(impression_count), 0) FROM {$wpdb->prefix}fpp_interlinking_impressions WHERE 1=1 {$imp_where}"
		);
		$ctr = $total_impressions > 0 ? round( ( $total_clicks / $total_impressions ) * 100, 2 ) : 0;

		return array(
			'total_clicks'             => $total_clicks,
			'unique_keywords_clicked'  => $unique_keywords,
			'unique_posts_with_clicks' => $unique_posts,
			'avg_clicks_per_keyword'   => $avg_clicks,
			'top_keyword'              => $top ? $top->keyword : null,
			'top_keyword_clicks'       => $top ? (int) $top->clicks : 0,
			'total_impressions'        => $total_impressions,
			'ctr'                      => $ctr,
		);
	}

	/**
	 * Get comparison statistics (current period vs previous period).
	 *
	 * @since 4.0.0
	 *
	 * @param string $period Period key.
	 * @return array Comparison data with change percentages.
	 */
	public static function get_comparison_stats( $period = '30d', $start_date = '', $end_date = '' ) {
		$current = self::get_summary_stats( $period, $start_date, $end_date );

		// Calculate previous period dates.
		switch ( $period ) {
			case 'today':
				$prev_start = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
				$prev_end   = $prev_start;
				break;
			case '7d':
				$prev_start = gmdate( 'Y-m-d', strtotime( '-14 days' ) );
				$prev_end   = gmdate( 'Y-m-d', strtotime( '-8 days' ) );
				break;
			case '30d':
				$prev_start = gmdate( 'Y-m-d', strtotime( '-60 days' ) );
				$prev_end   = gmdate( 'Y-m-d', strtotime( '-31 days' ) );
				break;
			case 'custom':
				if ( $start_date && $end_date ) {
					$diff       = abs( strtotime( $end_date ) - strtotime( $start_date ) );
					$days       = (int) ceil( $diff / DAY_IN_SECONDS ) + 1;
					$prev_end   = gmdate( 'Y-m-d', strtotime( $start_date . ' -1 day' ) );
					$prev_start = gmdate( 'Y-m-d', strtotime( $prev_end . ' -' . $days . ' days' ) );
					break;
				}
				// Fall through if no dates.
			default:
				return array(
					'current'        => $current,
					'clicks_change'  => 0,
					'impr_change'    => 0,
					'ctr_change'     => 0,
				);
		}

		$previous = self::get_summary_stats( 'custom', $prev_start, $prev_end );

		$clicks_change = $previous['total_clicks'] > 0
			? round( ( ( $current['total_clicks'] - $previous['total_clicks'] ) / $previous['total_clicks'] ) * 100, 1 )
			: ( $current['total_clicks'] > 0 ? 100 : 0 );

		$impr_change = $previous['total_impressions'] > 0
			? round( ( ( $current['total_impressions'] - $previous['total_impressions'] ) / $previous['total_impressions'] ) * 100, 1 )
			: ( $current['total_impressions'] > 0 ? 100 : 0 );

		$ctr_change = $previous['ctr'] > 0
			? round( $current['ctr'] - $previous['ctr'], 2 )
			: $current['ctr'];

		return array(
			'current'        => $current,
			'previous'       => $previous,
			'clicks_change'  => $clicks_change,
			'impr_change'    => $impr_change,
			'ctr_change'     => $ctr_change,
		);
	}

	/**
	 * Get top performing keywords by click count with CTR data.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $limit      Number of results.
	 * @param string $period     Time period filter.
	 * @param string $start_date Start date for custom period.
	 * @param string $end_date   End date for custom period.
	 * @return array Array of keyword performance data.
	 */
	public static function get_top_keywords( $limit = 20, $period = '30days', $start_date = '', $end_date = '' ) {
		global $wpdb;
		$table     = self::get_clicks_table();
		$imp_table = $wpdb->prefix . 'fpp_interlinking_impressions';
		$where     = self::period_where( $period, 'c.clicked_at', $start_date, $end_date );
		$imp_where = self::period_where( $period, 'i.impression_date', $start_date, $end_date );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.keyword_id, k.keyword, k.target_url, COUNT(*) as click_count,
			        COUNT(DISTINCT c.post_id) as unique_posts,
			        COALESCE(imp.impressions, 0) as impressions
			 FROM {$table} c
			 LEFT JOIN {$wpdb->prefix}fpp_interlinking_keywords k ON k.id = c.keyword_id
			 LEFT JOIN (
			     SELECT keyword_id, SUM(impression_count) as impressions
			     FROM {$imp_table} i
			     WHERE 1=1 {$imp_where}
			     GROUP BY keyword_id
			 ) imp ON imp.keyword_id = c.keyword_id
			 WHERE 1=1 {$where}
			 GROUP BY c.keyword_id
			 ORDER BY click_count DESC
			 LIMIT %d",
			$limit
		), ARRAY_A );

		if ( $results ) {
			foreach ( $results as &$row ) {
				$row['ctr'] = $row['impressions'] > 0
					? round( ( $row['click_count'] / $row['impressions'] ) * 100, 2 )
					: 0;
			}
			unset( $row );
		}

		return $results ? $results : array();
	}

	/**
	 * Get top performing links (keyword + URL pairs).
	 *
	 * @since 3.0.0
	 *
	 * @param int    $limit      Number of results.
	 * @param string $period     Time period filter.
	 * @param string $start_date Start date for custom period.
	 * @param string $end_date   End date for custom period.
	 * @return array Array of link performance data.
	 */
	public static function get_top_links( $limit = 20, $period = '30days', $start_date = '', $end_date = '' ) {
		global $wpdb;
		$table = self::get_clicks_table();
		$where = self::period_where( $period, 'c.clicked_at', $start_date, $end_date );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT k.keyword, c.target_url, COUNT(*) as click_count
			 FROM {$table} c
			 LEFT JOIN {$wpdb->prefix}fpp_interlinking_keywords k ON k.id = c.keyword_id
			 WHERE 1=1 {$where}
			 GROUP BY c.keyword_id, c.target_url
			 ORDER BY click_count DESC
			 LIMIT %d",
			$limit
		), ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Get click data grouped by post (which posts generate the most clicks).
	 *
	 * @since 3.0.0
	 *
	 * @param int    $limit      Number of results.
	 * @param string $period     Time period filter.
	 * @param string $start_date Start date for custom period.
	 * @param string $end_date   End date for custom period.
	 * @return array Array of post click data.
	 */
	public static function get_clicks_by_post( $limit = 20, $period = '30days', $start_date = '', $end_date = '' ) {
		global $wpdb;
		$table = self::get_clicks_table();
		$where = self::period_where( $period, 'c.clicked_at', $start_date, $end_date );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.post_id, p.post_title, p.post_type, COUNT(*) as click_count,
			        COUNT(DISTINCT c.keyword_id) as unique_keywords
			 FROM {$table} c
			 LEFT JOIN {$wpdb->posts} p ON p.ID = c.post_id
			 WHERE c.post_id > 0 {$where}
			 GROUP BY c.post_id
			 ORDER BY click_count DESC
			 LIMIT %d",
			$limit
		), ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Get daily click trend data for charting.
	 *
	 * @since 3.0.0
	 *
	 * @param int $days Number of days to look back.
	 * @return array Array of ['date' => 'Y-m-d', 'clicks' => int].
	 */
	public static function get_daily_trend( $days = 30, $start_date = '' ) {
		global $wpdb;
		$table = self::get_clicks_table();

		if ( ! empty( $start_date ) ) {
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(clicked_at) as date, COUNT(*) as clicks
				 FROM {$table}
				 WHERE clicked_at >= %s
				 GROUP BY DATE(clicked_at)
				 ORDER BY date ASC",
				$start_date . ' 00:00:00'
			), ARRAY_A );
		} else {
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(clicked_at) as date, COUNT(*) as clicks
				 FROM {$table}
				 WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY DATE(clicked_at)
				 ORDER BY date ASC",
				$days
			), ARRAY_A );
		}

		// Fill in missing days with 0 clicks.
		$trend = array();
		if ( ! empty( $start_date ) ) {
			$date = new DateTime( $start_date );
		} else {
			$date = new DateTime( '-' . $days . ' days' );
		}
		$end = new DateTime( 'now' );
		$data  = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				$data[ $row['date'] ] = (int) $row['clicks'];
			}
		}

		while ( $date <= $end ) {
			$d = $date->format( 'Y-m-d' );
			$trend[] = array(
				'date'   => $d,
				'clicks' => isset( $data[ $d ] ) ? $data[ $d ] : 0,
			);
			$date->modify( '+1 day' );
		}

		return $trend;
	}

	/**
	 * Get analytics data broken down by post type.
	 *
	 * @since 3.0.0
	 *
	 * @param string $period     Time period filter.
	 * @param string $start_date Start date for custom period.
	 * @param string $end_date   End date for custom period.
	 * @return array Array of post type stats.
	 */
	public static function get_stats_by_post_type( $period = '30days', $start_date = '', $end_date = '' ) {
		global $wpdb;
		$table = self::get_clicks_table();
		$where = self::period_where( $period, 'c.clicked_at', $start_date, $end_date );

		$results = $wpdb->get_results(
			"SELECT p.post_type, COUNT(*) as click_count,
			        COUNT(DISTINCT c.keyword_id) as keyword_count
			 FROM {$table} c
			 LEFT JOIN {$wpdb->posts} p ON p.ID = c.post_id
			 WHERE c.post_id > 0 {$where}
			 GROUP BY p.post_type
			 ORDER BY click_count DESC",
			ARRAY_A
		);

		// Add labels.
		if ( $results ) {
			foreach ( $results as &$row ) {
				$pt_obj = get_post_type_object( $row['post_type'] );
				$row['post_type_label'] = $pt_obj ? $pt_obj->labels->singular_name : $row['post_type'];
			}
			unset( $row );
		}

		return $results ? $results : array();
	}

	/**
	 * Get link coverage statistics.
	 *
	 * @since 3.0.0
	 *
	 * @return array Coverage stats.
	 */
	public static function get_coverage_stats() {
		global $wpdb;

		$active_keywords = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}fpp_interlinking_keywords WHERE is_active = 1"
		);

		$total_keywords = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}fpp_interlinking_keywords"
		);

		$post_types = FPP_Interlinking_DB::get_configured_post_types();
		$pt_in      = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

		$total_posts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$pt_in})"
		);

		$coverage = $total_posts > 0 && $active_keywords > 0
			? min( round( ( $active_keywords / $total_posts ) * 100, 1 ), 100 )
			: 0;

		return array(
			'total_active_keywords' => $active_keywords,
			'total_keywords'        => $total_keywords,
			'total_published_posts' => $total_posts,
			'coverage_percentage'   => $coverage,
		);
	}

	/**
	 * Purge analytics data older than retention period.
	 *
	 * @since 3.0.0
	 *
	 * @param int $days_to_keep Number of days to retain (default from option).
	 * @return int Rows deleted.
	 */
	public static function purge_old_data( $days_to_keep = 0 ) {
		if ( $days_to_keep <= 0 ) {
			$days_to_keep = (int) get_option( 'fpp_interlinking_tracking_retention_days', 90 );
		}
		if ( $days_to_keep <= 0 ) {
			$days_to_keep = 90;
		}

		global $wpdb;

		// Purge clicks.
		$deleted = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}fpp_interlinking_clicks WHERE clicked_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days_to_keep
		) );

		// Purge impressions.
		$deleted += (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}fpp_interlinking_impressions WHERE impression_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
			$days_to_keep
		) );

		return $deleted;
	}

	/**
	 * Get recent click events for the dashboard.
	 *
	 * @since 3.0.0
	 *
	 * @param int $limit Number of recent events.
	 * @return array Array of recent click events.
	 */
	public static function get_recent_clicks( $limit = 10 ) {
		global $wpdb;
		$table = self::get_clicks_table();

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.keyword_id, c.post_id, c.target_url, c.clicked_at,
			        k.keyword, p.post_title
			 FROM {$table} c
			 LEFT JOIN {$wpdb->prefix}fpp_interlinking_keywords k ON k.id = c.keyword_id
			 LEFT JOIN {$wpdb->posts} p ON p.ID = c.post_id
			 ORDER BY c.clicked_at DESC
			 LIMIT %d",
			$limit
		), ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Build a CSV string for analytics data export.
	 *
	 * @since 4.0.0
	 *
	 * @param string $type       Data type: 'top_keywords', 'clicks_by_post', 'daily_trend', 'top_links'.
	 * @param string $period     Period key.
	 * @param string $start_date Start date for custom period.
	 * @param string $end_date   End date for custom period.
	 * @return string CSV content.
	 */
	public static function build_csv( $type, $period = '30days', $start_date = '', $end_date = '' ) {
		$output = fopen( 'php://temp', 'r+' );

		switch ( $type ) {
			case 'top_keywords':
				fputcsv( $output, array( 'Keyword', 'Target URL', 'Clicks', 'Impressions', 'CTR %', 'Unique Posts' ) );
				$data = self::get_top_keywords( 100, $period, $start_date, $end_date );
				foreach ( $data as $row ) {
					fputcsv( $output, array(
						$row['keyword'],
						$row['target_url'],
						$row['click_count'],
						$row['impressions'],
						$row['ctr'],
						$row['unique_posts'],
					) );
				}
				break;

			case 'clicks_by_post':
				fputcsv( $output, array( 'Post Title', 'Post Type', 'Clicks', 'Unique Keywords' ) );
				$data = self::get_clicks_by_post( 100, $period, $start_date, $end_date );
				foreach ( $data as $row ) {
					fputcsv( $output, array(
						$row['post_title'],
						$row['post_type'],
						$row['click_count'],
						$row['unique_keywords'],
					) );
				}
				break;

			case 'daily_trend':
				fputcsv( $output, array( 'Date', 'Clicks' ) );
				$days = 'today' === $period ? 1 : ( '7days' === $period ? 7 : 30 );
				$data = self::get_daily_trend( $days );
				foreach ( $data as $row ) {
					fputcsv( $output, array( $row['date'], $row['clicks'] ) );
				}
				break;

			case 'top_links':
				fputcsv( $output, array( 'Keyword', 'Target URL', 'Clicks' ) );
				$data = self::get_top_links( 100, $period, $start_date, $end_date );
				foreach ( $data as $row ) {
					fputcsv( $output, array( $row['keyword'], $row['target_url'], $row['click_count'] ) );
				}
				break;
		}

		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output );

		return $csv;
	}

	/* ── Private Helpers ──────────────────────────────────────────────── */

	/**
	 * Build a WHERE clause for period filtering.
	 *
	 * @since 3.0.0
	 *
	 * @param string $period     Period key ('today', '7days', '30days', 'all', 'custom').
	 * @param string $column     Date column name.
	 * @param string $start_date Start date for custom period (Y-m-d).
	 * @param string $end_date   End date for custom period (Y-m-d).
	 * @return string SQL WHERE fragment (includes AND prefix).
	 */
	private static function period_where( $period, $column = 'clicked_at', $start_date = '', $end_date = '' ) {
		switch ( $period ) {
			case 'today':
				return " AND {$column} >= CURDATE()";
			case '7d':
			case '7days':
				return " AND {$column} >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
			case '30d':
			case '30days':
				return " AND {$column} >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
			case 'custom':
				if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
					global $wpdb;
					return $wpdb->prepare(
						" AND {$column} >= %s AND {$column} <= %s",
						$start_date . ' 00:00:00',
						$end_date . ' 23:59:59'
					);
				}
				return '';
			case 'all':
			default:
				return '';
		}
	}

	/**
	 * Get the clicks table name.
	 *
	 * @since 3.0.0
	 *
	 * @return string Full table name with prefix.
	 */
	private static function get_clicks_table() {
		global $wpdb;
		return $wpdb->prefix . 'fpp_interlinking_clicks';
	}

	/**
	 * Get the client IP address (anonymised for privacy).
	 *
	 * @since 3.0.0
	 *
	 * @return string IP address or empty string.
	 */
	private static function get_client_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $ips[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ip;
	}
}
