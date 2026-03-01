<?php
/**
 * Fired during plugin activation.
 *
 * Creates the custom database table and sets default option values.
 * Uses dbDelta() so the method is safe to call on every upgrade as well.
 *
 * @since   1.0.0
 * @package FPP_Interlinking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FPP_Interlinking_Activator {

	/**
	 * Run activation tasks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_table();
		self::set_default_options();
	}

	/**
	 * Create or update the keywords table using dbDelta().
	 *
	 * dbDelta() compares the desired schema against the existing table and
	 * only applies incremental changes, making this safe for upgrades.
	 *
	 * @since 1.0.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @return void
	 */
	private static function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// NOTE: dbDelta() requires:
		//  - Two spaces between PRIMARY KEY and opening parenthesis.
		//  - Each column on its own line.
		//  - KEY statements must use a name.

		// Keywords table.
		$keywords_table = $wpdb->prefix . 'fpp_interlinking_keywords';
		$sql_keywords = "CREATE TABLE {$keywords_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword varchar(255) NOT NULL,
			target_url varchar(2083) NOT NULL,
			nofollow tinyint(1) NOT NULL DEFAULT 0,
			new_tab tinyint(1) NOT NULL DEFAULT 1,
			max_replacements int(11) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY keyword_idx (keyword)
		) {$charset_collate};";

		// v3.0.0: Analytics clicks table.
		$clicks_table = $wpdb->prefix . 'fpp_interlinking_clicks';
		$sql_clicks = "CREATE TABLE {$clicks_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_url varchar(2083) NOT NULL,
			clicked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY keyword_id_idx (keyword_id),
			KEY post_id_idx (post_id),
			KEY clicked_at_idx (clicked_at)
		) {$charset_collate};";

		// v4.0.0: Impressions table for CTR tracking.
		$impressions_table = $wpdb->prefix . 'fpp_interlinking_impressions';
		$sql_impressions = "CREATE TABLE {$impressions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			impression_date date NOT NULL,
			impression_count int(11) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY keyword_post_date (keyword_id, post_id, impression_date),
			KEY impression_date_idx (impression_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_keywords );
		dbDelta( $sql_clicks );
		dbDelta( $sql_impressions );
	}

	/**
	 * Set default option values.
	 *
	 * Uses add_option() which only writes if the option does not exist yet,
	 * preserving user-configured values across re-activations.
	 *
	 * Third argument = deprecated, fourth = autoload.
	 * Settings that are only needed on specific pages use autoload = false
	 * to avoid loading them into the alloptions cache on every request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function set_default_options() {
		// Core replacement settings – loaded on frontend, autoload = true.
		add_option( 'fpp_interlinking_max_replacements', 1, '', true );
		add_option( 'fpp_interlinking_nofollow', 0, '', true );
		add_option( 'fpp_interlinking_new_tab', 1, '', true );
		add_option( 'fpp_interlinking_case_sensitive', 0, '', true );

		// Max total links per post – loaded on frontend, autoload = true.
		add_option( 'fpp_interlinking_max_links_per_post', 0, '', true );

		// Excluded posts – loaded on frontend, autoload = true.
		add_option( 'fpp_interlinking_excluded_posts', '', '', true );

		// Post types for replacement – loaded on frontend, autoload = true.
		add_option( 'fpp_interlinking_post_types', 'post,page', '', true );

		// DB version tracking – admin-only, autoload = false.
		add_option( 'fpp_interlinking_db_version', FPP_INTERLINKING_VERSION, '', false );

		// AI settings – admin-only, autoload = false.
		add_option( 'fpp_interlinking_ai_provider', 'openai', '', false );
		add_option( 'fpp_interlinking_ai_model', 'gpt-4o-mini', '', false );
		add_option( 'fpp_interlinking_ai_max_tokens', 2000, '', false );

		// v3.0.0: Analysis engine – 'internal' (default) or 'ai'.
		add_option( 'fpp_interlinking_analysis_engine', 'internal', '', false );

		// v4.0.0: Ollama base URL.
		add_option( 'fpp_interlinking_ai_base_url', 'http://localhost:11434', '', false );

		// v3.0.0: Analytics settings.
		add_option( 'fpp_interlinking_enable_tracking', 1, '', true );
		add_option( 'fpp_interlinking_tracking_retention_days', 90, '', false );

		// v3.0.0: Schedule daily analytics purge cron.
		if ( ! wp_next_scheduled( 'fpp_interlinking_purge_analytics' ) ) {
			wp_schedule_event( time(), 'daily', 'fpp_interlinking_purge_analytics' );
		}
	}
}
