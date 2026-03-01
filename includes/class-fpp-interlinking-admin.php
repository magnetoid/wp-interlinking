<?php
/**
 * Admin-side functionality: tabbed settings page, AJAX handlers, asset loading.
 *
 * Security model:
 *  - Every AJAX handler verifies a nonce (CSRF) AND the `manage_options` capability.
 *  - Input is sanitised early; output is escaped late.
 *
 * @since   1.0.0
 * @package FPP_Interlinking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FPP_Interlinking_Admin {

	/**
	 * Wire up hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Keyword CRUD.
		add_action( 'wp_ajax_fpp_interlinking_add_keyword', array( $this, 'ajax_add_keyword' ) );
		add_action( 'wp_ajax_fpp_interlinking_update_keyword', array( $this, 'ajax_update_keyword' ) );
		add_action( 'wp_ajax_fpp_interlinking_delete_keyword', array( $this, 'ajax_delete_keyword' ) );
		add_action( 'wp_ajax_fpp_interlinking_toggle_keyword', array( $this, 'ajax_toggle_keyword' ) );
		add_action( 'wp_ajax_fpp_interlinking_save_settings', array( $this, 'ajax_save_settings' ) );

		// Search, scan, suggest.
		add_action( 'wp_ajax_fpp_interlinking_search_posts', array( $this, 'ajax_search_posts' ) );
		add_action( 'wp_ajax_fpp_interlinking_scan_keyword', array( $this, 'ajax_scan_keyword' ) );
		add_action( 'wp_ajax_fpp_interlinking_suggest_keywords', array( $this, 'ajax_suggest_keywords' ) );

		// AI-specific endpoints (kept for backward compatibility).
		add_action( 'wp_ajax_fpp_interlinking_save_ai_settings', array( $this, 'ajax_save_ai_settings' ) );
		add_action( 'wp_ajax_fpp_interlinking_test_ai_connection', array( $this, 'ajax_test_ai_connection' ) );
		add_action( 'wp_ajax_fpp_interlinking_ai_extract_keywords', array( $this, 'ajax_ai_extract_keywords' ) );
		add_action( 'wp_ajax_fpp_interlinking_ai_score_relevance', array( $this, 'ajax_ai_score_relevance' ) );
		add_action( 'wp_ajax_fpp_interlinking_ai_content_gaps', array( $this, 'ajax_ai_content_gaps' ) );
		add_action( 'wp_ajax_fpp_interlinking_ai_auto_generate', array( $this, 'ajax_ai_auto_generate' ) );
		add_action( 'wp_ajax_fpp_interlinking_ai_add_mapping', array( $this, 'ajax_ai_add_mapping' ) );

		// Paginated table, bulk ops, import/export.
		add_action( 'wp_ajax_fpp_interlinking_load_keywords', array( $this, 'ajax_load_keywords' ) );
		add_action( 'wp_ajax_fpp_interlinking_bulk_action', array( $this, 'ajax_bulk_action' ) );
		add_action( 'wp_ajax_fpp_interlinking_export_csv', array( $this, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_fpp_interlinking_import_csv', array( $this, 'ajax_import_csv' ) );

		// v3.0.0: Analysis dispatcher endpoints.
		add_action( 'wp_ajax_fpp_interlinking_analyze_extract', array( $this, 'ajax_analyze_extract' ) );
		add_action( 'wp_ajax_fpp_interlinking_analyze_score', array( $this, 'ajax_analyze_score' ) );
		add_action( 'wp_ajax_fpp_interlinking_analyze_gaps', array( $this, 'ajax_analyze_gaps' ) );
		add_action( 'wp_ajax_fpp_interlinking_analyze_generate', array( $this, 'ajax_analyze_generate' ) );

		// v3.0.0: Dashboard and analytics data.
		add_action( 'wp_ajax_fpp_interlinking_load_dashboard', array( $this, 'ajax_load_dashboard' ) );
		add_action( 'wp_ajax_fpp_interlinking_load_analytics', array( $this, 'ajax_load_analytics' ) );

		// v4.0.0: AJAX tab loading and analytics CSV export.
		add_action( 'wp_ajax_fpp_interlinking_load_tab', array( $this, 'ajax_load_tab' ) );
		add_action( 'wp_ajax_fpp_interlinking_export_analytics_csv', array( $this, 'ajax_export_analytics_csv' ) );
	}

	/**
	 * Register the settings page under Settings menu.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'WP Interlinking', 'fpp-interlinking' ),
			__( 'WP Interlinking', 'fpp-interlinking' ),
			'manage_options',
			'fpp-interlinking',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS only on the plugin's own settings page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_fpp-interlinking' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'fpp-interlinking-admin',
			FPP_INTERLINKING_PLUGIN_URL . 'assets/css/fpp-interlinking-admin.css',
			array(),
			FPP_INTERLINKING_VERSION
		);

		wp_enqueue_script(
			'fpp-chartjs',
			FPP_INTERLINKING_PLUGIN_URL . 'assets/js/chart.min.js',
			array(),
			'4.4.7',
			true
		);

		wp_enqueue_script(
			'fpp-interlinking-admin',
			FPP_INTERLINKING_PLUGIN_URL . 'assets/js/fpp-interlinking-admin.js',
			array( 'jquery', 'fpp-chartjs' ),
			FPP_INTERLINKING_VERSION,
			true
		);

		wp_localize_script( 'fpp-interlinking-admin', 'fppInterlinking', array(
			'ajax_url'             => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'fpp_interlinking_nonce' ),
			'max_replacements_cap' => FPP_INTERLINKING_MAX_REPLACEMENTS_LIMIT,
			'analysis_engine'      => get_option( 'fpp_interlinking_analysis_engine', 'internal' ),
			'enable_tracking'      => (int) get_option( 'fpp_interlinking_enable_tracking', 1 ),
			'i18n'                 => array(
				'confirm_delete'   => esc_html__( 'Are you sure you want to delete this keyword mapping?', 'fpp-interlinking' ),
				'required'         => esc_html__( 'Keyword and Target URL are required.', 'fpp-interlinking' ),
				'request_failed'   => esc_html__( 'Request failed. Please try again.', 'fpp-interlinking' ),
				'scan_found'       => esc_html__( 'Found %d matching posts/pages:', 'fpp-interlinking' ),
				'scan_no_results'  => esc_html__( 'No posts or pages found matching this keyword.', 'fpp-interlinking' ),
				'use_this_url'     => esc_html__( 'Use this URL', 'fpp-interlinking' ),
				'updating'         => esc_html__( 'Updating...', 'fpp-interlinking' ),
				'close'            => esc_html__( 'Close', 'fpp-interlinking' ),
				'scanning'         => esc_html__( 'Scanning...', 'fpp-interlinking' ),
				'scan_post_titles' => esc_html__( 'Scan Post Titles', 'fpp-interlinking' ),
				'no_suggestions'   => esc_html__( 'No published posts or pages found.', 'fpp-interlinking' ),
				'already_mapped'   => esc_html__( 'Already mapped', 'fpp-interlinking' ),
				'available'        => esc_html__( 'Available', 'fpp-interlinking' ),
				'add_as_keyword'   => esc_html__( 'Add as Keyword', 'fpp-interlinking' ),
				'page_info'        => esc_html__( 'Page %1$d of %2$d (%3$d total)', 'fpp-interlinking' ),
				'no_posts_found'   => esc_html__( 'No posts found.', 'fpp-interlinking' ),
				'ai_processing'          => esc_html__( 'Processing...', 'fpp-interlinking' ),
				'ai_extract_btn'         => esc_html__( 'Extract Keywords', 'fpp-interlinking' ),
				'ai_score_btn'           => esc_html__( 'Score Relevance', 'fpp-interlinking' ),
				'ai_gaps_btn'            => esc_html__( 'Analyse Gaps', 'fpp-interlinking' ),
				'ai_generate_btn'        => esc_html__( 'Auto-Generate', 'fpp-interlinking' ),
				'ai_no_results'          => esc_html__( 'No results found. Try again with different content.', 'fpp-interlinking' ),
				'ai_add_mapping'         => esc_html__( 'Add Mapping', 'fpp-interlinking' ),
				'ai_add_all'             => esc_html__( 'Add All', 'fpp-interlinking' ),
				'ai_added'               => esc_html__( 'Added!', 'fpp-interlinking' ),
				'ai_connection_ok'       => esc_html__( 'Connection successful!', 'fpp-interlinking' ),
				'ai_select_post'         => esc_html__( 'Select a post to analyse', 'fpp-interlinking' ),
				'ai_enter_keyword'       => esc_html__( 'Enter a keyword first', 'fpp-interlinking' ),
				'ai_analysed_info'       => esc_html__( 'Analysed %1$d of %2$d posts', 'fpp-interlinking' ),
				'ai_confidence'          => esc_html__( 'Confidence', 'fpp-interlinking' ),
				'ai_relevance'           => esc_html__( 'Relevance', 'fpp-interlinking' ),
				'ai_no_gaps'             => esc_html__( 'No content gaps found — your interlinking looks good!', 'fpp-interlinking' ),
				'ai_added_count'         => esc_html__( 'Added %d keyword mappings.', 'fpp-interlinking' ),
				'saving'                 => esc_html__( 'Saving...', 'fpp-interlinking' ),
				'adding'                 => esc_html__( 'Adding...', 'fpp-interlinking' ),
				'adding_all'             => esc_html__( 'Adding all...', 'fpp-interlinking' ),
				'add_keyword'            => esc_html__( 'Add Keyword', 'fpp-interlinking' ),
				'update_keyword'         => esc_html__( 'Update Keyword', 'fpp-interlinking' ),
				'save_settings'          => esc_html__( 'Save Settings', 'fpp-interlinking' ),
				'save_ai_settings'       => esc_html__( 'Save AI Settings', 'fpp-interlinking' ),
				'invalid_url'            => esc_html__( 'Please enter a valid URL starting with http:// or https://.', 'fpp-interlinking' ),
				'add_new_mapping'        => esc_html__( 'Add New Keyword Mapping', 'fpp-interlinking' ),
				'edit_mapping'           => esc_html__( 'Edit Keyword Mapping', 'fpp-interlinking' ),
				'active'                 => esc_html__( 'Active', 'fpp-interlinking' ),
				'inactive'               => esc_html__( 'Inactive', 'fpp-interlinking' ),
				'disable'                => esc_html__( 'Disable', 'fpp-interlinking' ),
				'enable'                 => esc_html__( 'Enable', 'fpp-interlinking' ),
				'bulk_select_action'     => esc_html__( 'Please select a bulk action.', 'fpp-interlinking' ),
				'bulk_select_items'      => esc_html__( 'Please select at least one keyword.', 'fpp-interlinking' ),
				'bulk_confirm_delete'    => esc_html__( 'Are you sure you want to delete the selected keywords?', 'fpp-interlinking' ),
				'bulk_success'           => esc_html__( 'Bulk action completed successfully.', 'fpp-interlinking' ),
				'export_empty'           => esc_html__( 'No keywords to export.', 'fpp-interlinking' ),
				'import_select_file'     => esc_html__( 'Please select a CSV file to import.', 'fpp-interlinking' ),
				'import_success'         => esc_html__( 'Import complete: %1$d imported, %2$d skipped, %3$d errors.', 'fpp-interlinking' ),
				'importing'              => esc_html__( 'Importing...', 'fpp-interlinking' ),
				'loading'                => esc_html__( 'Loading...', 'fpp-interlinking' ),
				'no_keywords'            => esc_html__( 'No keyword mappings found.', 'fpp-interlinking' ),
				'keyword_page_info'      => esc_html__( 'Showing %1$d–%2$d of %3$d', 'fpp-interlinking' ),
				// v3.0.0 additions.
				'no_data'                => esc_html__( 'No data available yet.', 'fpp-interlinking' ),
				'total_clicks'           => esc_html__( 'Total Clicks', 'fpp-interlinking' ),
				'unique_keywords'        => esc_html__( 'Unique Keywords Clicked', 'fpp-interlinking' ),
				'avg_clicks'             => esc_html__( 'Avg Clicks/Keyword', 'fpp-interlinking' ),
				'top_keyword'            => esc_html__( 'Top Keyword', 'fpp-interlinking' ),
				'coverage'               => esc_html__( 'Coverage', 'fpp-interlinking' ),
				'internal_engine'        => esc_html__( 'Internal Engine', 'fpp-interlinking' ),
				'ai_engine'              => esc_html__( 'AI Engine', 'fpp-interlinking' ),
				'no_clicks_yet'          => esc_html__( 'No click data yet. Links will be tracked once visitors start clicking.', 'fpp-interlinking' ),
				'clicks'                 => esc_html__( 'Clicks', 'fpp-interlinking' ),
				'keyword'                => esc_html__( 'Keyword', 'fpp-interlinking' ),
				'post'                   => esc_html__( 'Post', 'fpp-interlinking' ),
				'url'                    => esc_html__( 'URL', 'fpp-interlinking' ),
				'date'                   => esc_html__( 'Date', 'fpp-interlinking' ),
				'save_all_settings'      => esc_html__( 'Save All Settings', 'fpp-interlinking' ),
				// v4.0.0 additions.
				'impressions'            => esc_html__( 'Impressions', 'fpp-interlinking' ),
				'ctr'                    => esc_html__( 'CTR', 'fpp-interlinking' ),
				'vs_previous'            => esc_html__( 'vs previous period', 'fpp-interlinking' ),
				'no_impressions_yet'     => esc_html__( 'No impression data yet. Impressions are tracked when pages with interlinks are viewed.', 'fpp-interlinking' ),
				'export_analytics'       => esc_html__( 'Export CSV', 'fpp-interlinking' ),
				'top_links'              => esc_html__( 'Top Links', 'fpp-interlinking' ),
				'post_type'              => esc_html__( 'Post Type', 'fpp-interlinking' ),
				'no_keywords_empty'      => esc_html__( 'No keywords yet. Add your first keyword mapping to start building interlinks.', 'fpp-interlinking' ),
				'no_analytics_empty'     => esc_html__( 'No click data yet. Links will be tracked automatically once visitors start clicking.', 'fpp-interlinking' ),
				'no_recent_empty'        => esc_html__( 'No recent clicks. Activity will appear here as visitors interact with your interlinks.', 'fpp-interlinking' ),
				'run_analysis_empty'     => esc_html__( 'Run an analysis to discover keyword opportunities and interlinking gaps.', 'fpp-interlinking' ),
			),
		) );
	}

	/* ── Tab Navigation & Rendering ────────────────────────────────── */

	/**
	 * Render the admin settings page with tabbed navigation.
	 *
	 * @since 3.0.0
	 */
	public function render_admin_page() {
		$tabs = array(
			'dashboard' => __( 'Dashboard', 'fpp-interlinking' ),
			'keywords'  => __( 'Keywords', 'fpp-interlinking' ),
			'analysis'  => __( 'Analysis Tools', 'fpp-interlinking' ),
			'analytics' => __( 'Analytics', 'fpp-interlinking' ),
			'settings'  => __( 'Settings', 'fpp-interlinking' ),
		);

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! array_key_exists( $current_tab, $tabs ) ) {
			$current_tab = 'dashboard';
		}

		$base_url = admin_url( 'options-general.php?page=fpp-interlinking' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<div id="fpp-notices" role="alert" aria-live="polite"></div>

			<nav class="nav-tab-wrapper fpp-nav-tabs">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_key, $base_url ) ); ?>"
					   class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>"
					   data-tab="<?php echo esc_attr( $tab_key ); ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="fpp-tab-content">
				<?php
				$method = 'render_tab_' . $current_tab;
				if ( method_exists( $this, $method ) ) {
					$this->$method();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Dashboard tab.
	 *
	 * @since 3.0.0
	 */
	private function render_tab_dashboard() {
		$base_url = admin_url( 'options-general.php?page=fpp-interlinking' );
		?>
		<div id="fpp-dashboard">
			<div class="fpp-stat-cards" id="fpp-dashboard-cards">
				<div class="fpp-stat-card fpp-card-blue">
					<span class="fpp-stat-icon dashicons dashicons-tag" aria-hidden="true"></span>
					<span class="fpp-stat-value" id="fpp-dash-total-keywords">—</span>
					<span class="fpp-stat-label"><?php esc_html_e( 'Total Keywords', 'fpp-interlinking' ); ?></span>
					<canvas class="fpp-sparkline" id="fpp-spark-keywords" width="60" height="24"></canvas>
				</div>
				<div class="fpp-stat-card fpp-card-green">
					<span class="fpp-stat-icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<span class="fpp-stat-value" id="fpp-dash-active-keywords">—</span>
					<span class="fpp-stat-label"><?php esc_html_e( 'Active Keywords', 'fpp-interlinking' ); ?></span>
				</div>
				<div class="fpp-stat-card fpp-card-orange">
					<span class="fpp-stat-icon dashicons dashicons-chart-line" aria-hidden="true"></span>
					<span class="fpp-stat-value" id="fpp-dash-clicks-30d">—</span>
					<span class="fpp-stat-label"><?php esc_html_e( 'Clicks (30 days)', 'fpp-interlinking' ); ?></span>
					<canvas class="fpp-sparkline" id="fpp-spark-clicks" width="60" height="24"></canvas>
				</div>
				<div class="fpp-stat-card fpp-card-purple">
					<span class="fpp-stat-icon dashicons dashicons-networking" aria-hidden="true"></span>
					<span class="fpp-stat-value" id="fpp-dash-coverage">—</span>
					<span class="fpp-stat-label"><?php esc_html_e( 'Coverage', 'fpp-interlinking' ); ?></span>
				</div>
			</div>

			<div class="fpp-dashboard-actions">
				<h3><?php esc_html_e( 'Quick Actions', 'fpp-interlinking' ); ?></h3>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'keywords', $base_url ) ); ?>" class="button button-primary">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Add Keyword', 'fpp-interlinking' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'analysis', $base_url ) ); ?>" class="button">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<?php esc_html_e( 'Run Analysis', 'fpp-interlinking' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'analytics', $base_url ) ); ?>" class="button">
					<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
					<?php esc_html_e( 'View Analytics', 'fpp-interlinking' ); ?>
				</a>
			</div>

			<div class="fpp-dashboard-recent">
				<h3><?php esc_html_e( 'Recent Click Activity', 'fpp-interlinking' ); ?></h3>
				<div id="fpp-dashboard-recent-table">
					<p><span class="spinner is-active" style="float:none;"></span> <?php esc_html_e( 'Loading...', 'fpp-interlinking' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Keywords tab.
	 *
	 * @since 3.0.0
	 */
	private function render_tab_keywords() {
		$max_cap = FPP_INTERLINKING_MAX_REPLACEMENTS_LIMIT;
		?>
		<!-- Quick-Add from Post Search -->
		<div class="fpp-section fpp-quick-add-section">
			<h2><?php esc_html_e( 'Quick Add from Post Search', 'fpp-interlinking' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Search for an existing post or page to auto-fill the keyword and URL fields below.', 'fpp-interlinking' ); ?></p>
			<div class="fpp-search-wrapper">
				<input type="text" id="fpp-post-search" class="regular-text"
					placeholder="<?php esc_attr_e( 'Type to search posts and pages...', 'fpp-interlinking' ); ?>"
					autocomplete="off" />
				<div id="fpp-post-search-results" class="fpp-search-dropdown" style="display:none;"></div>
			</div>
		</div>

		<hr />

		<!-- Add / Edit Keyword Form -->
		<div class="fpp-section fpp-add-keyword-section">
			<h2 id="fpp-form-title"><?php esc_html_e( 'Add New Keyword Mapping', 'fpp-interlinking' ); ?></h2>
			<input type="hidden" id="fpp-edit-id" value="" />
			<table class="form-table">
				<tr>
					<th><label for="fpp-keyword"><?php esc_html_e( 'Keyword', 'fpp-interlinking' ); ?></label></th>
					<td><input type="text" id="fpp-keyword" class="regular-text" placeholder="<?php esc_attr_e( 'Enter keyword or phrase', 'fpp-interlinking' ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="fpp-target-url"><?php esc_html_e( 'Target URL', 'fpp-interlinking' ); ?></label></th>
					<td><input type="url" id="fpp-target-url" class="regular-text" placeholder="https://example.com/page" /></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Per-mapping overrides', 'fpp-interlinking' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" id="fpp-per-nofollow" value="1" />
								<?php esc_html_e( 'Nofollow', 'fpp-interlinking' ); ?>
							</label>
							<br />
							<label>
								<input type="checkbox" id="fpp-per-new-tab" value="1" checked />
								<?php esc_html_e( 'Open in new tab', 'fpp-interlinking' ); ?>
							</label>
							<br />
							<label>
								<?php esc_html_e( 'Max replacements:', 'fpp-interlinking' ); ?>
								<input type="number" id="fpp-per-max-replacements" min="0" max="<?php echo esc_attr( $max_cap ); ?>" value="0" class="small-text" />
							</label>
							<p class="description"><?php esc_html_e( 'Set to 0 to use the global setting.', 'fpp-interlinking' ); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" id="fpp-add-keyword" class="button button-primary"><?php esc_html_e( 'Add Keyword', 'fpp-interlinking' ); ?></button>
				<button type="button" id="fpp-update-keyword" class="button button-primary" style="display:none;"><?php esc_html_e( 'Update Keyword', 'fpp-interlinking' ); ?></button>
				<button type="button" id="fpp-cancel-edit" class="button" style="display:none;"><?php esc_html_e( 'Cancel', 'fpp-interlinking' ); ?></button>
			</p>
		</div>

		<hr />

		<!-- Keywords Table -->
		<div class="fpp-section">
			<h2><?php esc_html_e( 'Keyword Mappings', 'fpp-interlinking' ); ?></h2>

			<div class="fpp-table-toolbar">
				<div class="fpp-toolbar-left">
					<select id="fpp-bulk-action">
						<option value=""><?php esc_html_e( 'Bulk Actions', 'fpp-interlinking' ); ?></option>
						<option value="enable"><?php esc_html_e( 'Enable', 'fpp-interlinking' ); ?></option>
						<option value="disable"><?php esc_html_e( 'Disable', 'fpp-interlinking' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'fpp-interlinking' ); ?></option>
					</select>
					<button type="button" id="fpp-bulk-apply" class="button"><?php esc_html_e( 'Apply', 'fpp-interlinking' ); ?></button>
				</div>
				<div class="fpp-toolbar-center">
					<button type="button" id="fpp-export-csv" class="button">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Export CSV', 'fpp-interlinking' ); ?>
					</button>
					<label class="button fpp-import-label" for="fpp-import-csv-file">
						<span class="dashicons dashicons-upload" aria-hidden="true"></span>
						<?php esc_html_e( 'Import CSV', 'fpp-interlinking' ); ?>
					</label>
					<input type="file" id="fpp-import-csv-file" accept=".csv" style="display:none;" />
				</div>
				<div class="fpp-toolbar-right">
					<input type="search" id="fpp-keyword-search" class="regular-text"
						placeholder="<?php esc_attr_e( 'Search keywords...', 'fpp-interlinking' ); ?>" />
				</div>
			</div>

			<p id="fpp-no-keywords" style="display:none;"><?php esc_html_e( 'No keyword mappings found. Add your first one above.', 'fpp-interlinking' ); ?></p>
			<table class="wp-list-table widefat fixed striped" id="fpp-keywords-table">
				<thead>
					<tr>
						<th class="column-cb check-column"><input type="checkbox" id="fpp-select-all" /></th>
						<th class="column-keyword"><?php esc_html_e( 'Keyword', 'fpp-interlinking' ); ?></th>
						<th class="column-url"><?php esc_html_e( 'Target URL', 'fpp-interlinking' ); ?></th>
						<th class="column-nofollow"><?php esc_html_e( 'Nofollow', 'fpp-interlinking' ); ?></th>
						<th class="column-newtab"><?php esc_html_e( 'New Tab', 'fpp-interlinking' ); ?></th>
						<th class="column-max"><?php esc_html_e( 'Max', 'fpp-interlinking' ); ?></th>
						<th class="column-active"><?php esc_html_e( 'Active', 'fpp-interlinking' ); ?></th>
						<th class="column-actions"><?php esc_html_e( 'Actions', 'fpp-interlinking' ); ?></th>
					</tr>
				</thead>
				<tbody id="fpp-keywords-tbody">
					<tr><td colspan="8"><span class="spinner is-active" style="float:none;"></span> <?php esc_html_e( 'Loading...', 'fpp-interlinking' ); ?></td></tr>
				</tbody>
			</table>
			<div id="fpp-keywords-pagination" class="tablenav bottom" style="display:none;">
				<div class="tablenav-pages">
					<span class="fpp-keywords-info"></span>
					<button type="button" id="fpp-keywords-prev" class="button button-small" disabled>&laquo; <?php esc_html_e( 'Previous', 'fpp-interlinking' ); ?></button>
					<button type="button" id="fpp-keywords-next" class="button button-small"><?php esc_html_e( 'Next', 'fpp-interlinking' ); ?> &raquo;</button>
				</div>
			</div>
		</div>

		<hr />

		<!-- Suggest Keywords from Content -->
		<div class="fpp-section fpp-suggest-section">
			<h2 class="fpp-section-toggle" id="fpp-toggle-suggestions" role="button" tabindex="0" aria-expanded="false" aria-controls="fpp-suggestions-content">
				<?php esc_html_e( 'Suggest Keywords from Content', 'fpp-interlinking' ); ?>
				<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
			</h2>
			<div class="fpp-section-content" id="fpp-suggestions-content" role="region" aria-labelledby="fpp-toggle-suggestions" style="display:none;">
				<p class="description">
					<?php esc_html_e( 'Scan your published posts and pages to discover potential keyword mappings based on their titles.', 'fpp-interlinking' ); ?>
				</p>
				<p>
					<button type="button" id="fpp-scan-titles" class="button button-secondary">
						<?php esc_html_e( 'Scan Post Titles', 'fpp-interlinking' ); ?>
					</button>
				</p>
				<div id="fpp-suggestions-results" style="display:none;">
					<table class="wp-list-table widefat fixed striped" id="fpp-suggestions-table">
						<thead>
							<tr>
								<th class="column-sg-title"><?php esc_html_e( 'Post Title (Keyword)', 'fpp-interlinking' ); ?></th>
								<th class="column-sg-type"><?php esc_html_e( 'Type', 'fpp-interlinking' ); ?></th>
								<th class="column-sg-url"><?php esc_html_e( 'URL', 'fpp-interlinking' ); ?></th>
								<th class="column-sg-status"><?php esc_html_e( 'Status', 'fpp-interlinking' ); ?></th>
								<th class="column-sg-actions"><?php esc_html_e( 'Actions', 'fpp-interlinking' ); ?></th>
							</tr>
						</thead>
						<tbody id="fpp-suggestions-tbody"></tbody>
					</table>
					<div id="fpp-suggestions-pagination" class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="fpp-suggestions-info"></span>
							<button type="button" id="fpp-suggestions-prev" class="button button-small" disabled>&laquo; <?php esc_html_e( 'Previous', 'fpp-interlinking' ); ?></button>
							<button type="button" id="fpp-suggestions-next" class="button button-small"><?php esc_html_e( 'Next', 'fpp-interlinking' ); ?> &raquo;</button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Analysis Tools tab.
	 *
	 * @since 3.0.0
	 */
	private function render_tab_analysis() {
		$engine = get_option( 'fpp_interlinking_analysis_engine', 'internal' );
		$engine_label = 'ai' === $engine ? __( 'AI Engine', 'fpp-interlinking' ) : __( 'Internal Engine', 'fpp-interlinking' );
		$engine_class = 'ai' === $engine ? 'fpp-engine-ai' : 'fpp-engine-internal';
		?>
		<div class="fpp-analysis-header">
			<p class="description">
				<?php esc_html_e( 'Analyse your content to discover keyword opportunities and interlinking gaps.', 'fpp-interlinking' ); ?>
			</p>
			<span class="fpp-engine-badge <?php echo esc_attr( $engine_class ); ?>" id="fpp-engine-badge">
				<?php echo esc_html( $engine_label ); ?>
			</span>
		</div>

		<!-- Keyword Extraction -->
		<div class="fpp-section fpp-ai-section fpp-ai-extract-section">
			<h2 class="fpp-section-toggle" id="fpp-toggle-ai-extract" role="button" tabindex="0" aria-expanded="false" aria-controls="fpp-ai-extract-content">
				<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
				<?php esc_html_e( 'Extract Keywords', 'fpp-interlinking' ); ?>
				<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
			</h2>
			<div class="fpp-section-content" id="fpp-ai-extract-content" role="region" aria-labelledby="fpp-toggle-ai-extract" style="display:none;">
				<p class="description"><?php esc_html_e( 'Select a post or page to analyse its content and extract SEO keywords for interlinking.', 'fpp-interlinking' ); ?></p>
				<div class="fpp-ai-controls">
					<div class="fpp-search-wrapper">
						<input type="text" id="fpp-ai-extract-search" class="regular-text"
							placeholder="<?php esc_attr_e( 'Search for a post to analyse...', 'fpp-interlinking' ); ?>"
							autocomplete="off" />
						<div id="fpp-ai-extract-search-results" class="fpp-search-dropdown" style="display:none;"></div>
					</div>
					<input type="hidden" id="fpp-ai-extract-post-id" value="" />
					<span id="fpp-ai-extract-selected" class="fpp-ai-selected-post"></span>
					<button type="button" id="fpp-ai-extract-btn" class="button button-primary" disabled>
						<span class="dashicons dashicons-lightbulb"></span>
						<?php esc_html_e( 'Extract Keywords', 'fpp-interlinking' ); ?>
					</button>
				</div>
				<div id="fpp-ai-extract-results" class="fpp-ai-results" style="display:none;">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th class="column-ai-keyword"><?php esc_html_e( 'Keyword', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-relevance"><?php esc_html_e( 'Relevance', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-actions"><?php esc_html_e( 'Actions', 'fpp-interlinking' ); ?></th>
							</tr>
						</thead>
						<tbody id="fpp-ai-extract-tbody"></tbody>
					</table>
				</div>
			</div>
		</div>

		<hr />

		<!-- Relevance Scoring -->
		<div class="fpp-section fpp-ai-section fpp-ai-score-section">
			<h2 class="fpp-section-toggle" id="fpp-toggle-ai-score" role="button" tabindex="0" aria-expanded="false" aria-controls="fpp-ai-score-content">
				<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
				<?php esc_html_e( 'Relevance Scoring', 'fpp-interlinking' ); ?>
				<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
			</h2>
			<div class="fpp-section-content" id="fpp-ai-score-content" role="region" aria-labelledby="fpp-toggle-ai-score" style="display:none;">
				<p class="description"><?php esc_html_e( 'Enter a keyword to find and score the most relevant pages to link to.', 'fpp-interlinking' ); ?></p>
				<div class="fpp-ai-controls">
					<input type="text" id="fpp-ai-score-keyword" class="regular-text"
						placeholder="<?php esc_attr_e( 'Enter keyword to score...', 'fpp-interlinking' ); ?>" />
					<button type="button" id="fpp-ai-score-btn" class="button button-primary">
						<span class="dashicons dashicons-chart-bar"></span>
						<?php esc_html_e( 'Score Relevance', 'fpp-interlinking' ); ?>
					</button>
				</div>
				<div id="fpp-ai-score-results" class="fpp-ai-results" style="display:none;">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th class="column-ai-title"><?php esc_html_e( 'Page', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-url"><?php esc_html_e( 'URL', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-score"><?php esc_html_e( 'Score', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-reason"><?php esc_html_e( 'Reason', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-actions"><?php esc_html_e( 'Actions', 'fpp-interlinking' ); ?></th>
							</tr>
						</thead>
						<tbody id="fpp-ai-score-tbody"></tbody>
					</table>
				</div>
			</div>
		</div>

		<hr />

		<!-- Content Gap Analysis -->
		<div class="fpp-section fpp-ai-section fpp-ai-gaps-section">
			<h2 class="fpp-section-toggle" id="fpp-toggle-ai-gaps" role="button" tabindex="0" aria-expanded="false" aria-controls="fpp-ai-gaps-content">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<?php esc_html_e( 'Content Gap Analysis', 'fpp-interlinking' ); ?>
				<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
			</h2>
			<div class="fpp-section-content" id="fpp-ai-gaps-content" role="region" aria-labelledby="fpp-toggle-ai-gaps" style="display:none;">
				<p class="description"><?php esc_html_e( 'Analyse your published content to discover posts that should link to each other but currently don\'t.', 'fpp-interlinking' ); ?></p>
				<div class="fpp-ai-controls">
					<button type="button" id="fpp-ai-gaps-btn" class="button button-primary">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Analyse Content Gaps', 'fpp-interlinking' ); ?>
					</button>
					<span id="fpp-ai-gaps-status" class="fpp-ai-status"></span>
				</div>
				<div id="fpp-ai-gaps-results" class="fpp-ai-results" style="display:none;">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th class="column-ai-keyword"><?php esc_html_e( 'Keyword', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-source"><?php esc_html_e( 'Source Post', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-target"><?php esc_html_e( 'Target Post', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-confidence"><?php esc_html_e( 'Confidence', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-reason"><?php esc_html_e( 'Reason', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-actions"><?php esc_html_e( 'Actions', 'fpp-interlinking' ); ?></th>
							</tr>
						</thead>
						<tbody id="fpp-ai-gaps-tbody"></tbody>
					</table>
				</div>
			</div>
		</div>

		<hr />

		<!-- Auto-Generate Mappings -->
		<div class="fpp-section fpp-ai-section fpp-ai-generate-section">
			<h2 class="fpp-section-toggle" id="fpp-toggle-ai-generate" role="button" tabindex="0" aria-expanded="false" aria-controls="fpp-ai-generate-content">
				<span class="dashicons dashicons-update" aria-hidden="true"></span>
				<?php esc_html_e( 'Auto-Generate Mappings', 'fpp-interlinking' ); ?>
				<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
			</h2>
			<div class="fpp-section-content" id="fpp-ai-generate-content" role="region" aria-labelledby="fpp-toggle-ai-generate" style="display:none;">
				<p class="description"><?php esc_html_e( 'Scan your content and automatically propose keyword-to-URL mappings for a complete interlinking strategy.', 'fpp-interlinking' ); ?></p>
				<div class="fpp-ai-controls">
					<button type="button" id="fpp-ai-generate-btn" class="button button-primary">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Auto-Generate Mappings', 'fpp-interlinking' ); ?>
					</button>
					<button type="button" id="fpp-ai-add-all-btn" class="button" style="display:none;">
						<?php esc_html_e( 'Add All Mappings', 'fpp-interlinking' ); ?>
					</button>
					<span id="fpp-ai-generate-status" class="fpp-ai-status"></span>
				</div>
				<div id="fpp-ai-generate-results" class="fpp-ai-results" style="display:none;">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th class="column-ai-keyword"><?php esc_html_e( 'Keyword', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-url"><?php esc_html_e( 'Target URL', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-target"><?php esc_html_e( 'Target Page', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-confidence"><?php esc_html_e( 'Confidence', 'fpp-interlinking' ); ?></th>
								<th class="column-ai-actions"><?php esc_html_e( 'Actions', 'fpp-interlinking' ); ?></th>
							</tr>
						</thead>
						<tbody id="fpp-ai-generate-tbody"></tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Analytics tab.
	 *
	 * @since 3.0.0
	 */
	private function render_tab_analytics() {
		?>
		<div id="fpp-analytics">
			<div class="fpp-analytics-toolbar">
				<div class="fpp-period-selector">
					<button type="button" class="button fpp-period-btn" data-period="today"><?php esc_html_e( 'Today', 'fpp-interlinking' ); ?></button>
					<button type="button" class="button fpp-period-btn" data-period="7d"><?php esc_html_e( '7 Days', 'fpp-interlinking' ); ?></button>
					<button type="button" class="button fpp-period-btn active" data-period="30d"><?php esc_html_e( '30 Days', 'fpp-interlinking' ); ?></button>
					<button type="button" class="button fpp-period-btn" data-period="all"><?php esc_html_e( 'All Time', 'fpp-interlinking' ); ?></button>
					<button type="button" class="button fpp-period-btn" data-period="custom"><?php esc_html_e( 'Custom', 'fpp-interlinking' ); ?></button>
				</div>
				<div class="fpp-custom-date-range" id="fpp-custom-date-range" style="display:none;">
					<input type="date" id="fpp-date-start" class="fpp-date-input" />
					<span class="fpp-date-sep">&ndash;</span>
					<input type="date" id="fpp-date-end" class="fpp-date-input" />
					<button type="button" id="fpp-apply-custom-date" class="button button-small"><?php esc_html_e( 'Apply', 'fpp-interlinking' ); ?></button>
				</div>
				<div class="fpp-analytics-actions">
					<button type="button" id="fpp-export-analytics-csv" class="button">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Export CSV', 'fpp-interlinking' ); ?>
					</button>
				</div>
			</div>

			<div class="fpp-stat-cards" id="fpp-analytics-cards">
				<div class="fpp-stat-card fpp-card-blue">
					<span class="fpp-stat-icon dashicons dashicons-admin-links" aria-hidden="true"></span>
					<span class="fpp-stat-value" id="fpp-analytics-total-clicks">—</span>
					<span class="fpp-stat-label"><?php esc_html_e( 'Total Clicks', 'fpp-interlinking' ); ?></span>
					<span class="fpp-comparison-badge" id="fpp-cmp-clicks"></span>
				</div>
				<div class="fpp-stat-card fpp-card-green">
					<span class="fpp-stat-icon dashicons dashicons-visibility" aria-hidden="true"></span>
					<span class="fpp-stat-value" id="fpp-analytics-impressions">—</span>
					<span class="fpp-stat-label"><?php esc_html_e( 'Impressions', 'fpp-interlinking' ); ?></span>
					<span class="fpp-comparison-badge" id="fpp-cmp-impressions"></span>
				</div>
				<div class="fpp-stat-card fpp-card-orange">
					<span class="fpp-stat-icon dashicons dashicons-performance" aria-hidden="true"></span>
					<span class="fpp-stat-value" id="fpp-analytics-ctr">—</span>
					<span class="fpp-stat-label"><?php esc_html_e( 'CTR', 'fpp-interlinking' ); ?></span>
					<span class="fpp-comparison-badge" id="fpp-cmp-ctr"></span>
				</div>
				<div class="fpp-stat-card fpp-card-purple">
					<span class="fpp-stat-icon dashicons dashicons-star-filled" aria-hidden="true"></span>
					<span class="fpp-stat-value" id="fpp-analytics-top-keyword">—</span>
					<span class="fpp-stat-label"><?php esc_html_e( 'Top Keyword', 'fpp-interlinking' ); ?></span>
				</div>
			</div>

			<div id="fpp-analytics-trend" class="fpp-analytics-section">
				<h3><?php esc_html_e( 'Click Trend', 'fpp-interlinking' ); ?></h3>
				<div class="fpp-chart-container">
					<canvas id="fpp-trend-chart"></canvas>
				</div>
			</div>

			<div id="fpp-analytics-tables" class="fpp-analytics-section">
				<div class="fpp-analytics-grid">
					<div>
						<h3><?php esc_html_e( 'Top Keywords', 'fpp-interlinking' ); ?></h3>
						<div id="fpp-analytics-top-keywords">
							<div class="fpp-skeleton fpp-skeleton-table"></div>
						</div>
					</div>
					<div>
						<h3><?php esc_html_e( 'Clicks by Post', 'fpp-interlinking' ); ?></h3>
						<div id="fpp-analytics-clicks-by-post">
							<div class="fpp-skeleton fpp-skeleton-table"></div>
						</div>
					</div>
				</div>
			</div>

			<div id="fpp-analytics-extra" class="fpp-analytics-section">
				<div class="fpp-analytics-grid">
					<div>
						<h3><?php esc_html_e( 'Top Links', 'fpp-interlinking' ); ?></h3>
						<div id="fpp-analytics-top-links">
							<div class="fpp-skeleton fpp-skeleton-table"></div>
						</div>
					</div>
					<div>
						<h3><?php esc_html_e( 'Clicks by Post Type', 'fpp-interlinking' ); ?></h3>
						<div class="fpp-chart-container fpp-chart-small">
							<canvas id="fpp-post-type-chart"></canvas>
						</div>
					</div>
				</div>
			</div>

			<div id="fpp-analytics-empty" class="fpp-empty-state" style="display:none;">
				<span class="dashicons dashicons-chart-area fpp-empty-icon" aria-hidden="true"></span>
				<h3><?php esc_html_e( 'No analytics data yet', 'fpp-interlinking' ); ?></h3>
				<p><?php esc_html_e( 'Click data will appear here once visitors start clicking your interlinks.', 'fpp-interlinking' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Settings tab.
	 *
	 * @since 3.0.0
	 */
	private function render_tab_settings() {
		$max_replacements    = get_option( 'fpp_interlinking_max_replacements', 1 );
		$nofollow            = get_option( 'fpp_interlinking_nofollow', 0 );
		$new_tab             = get_option( 'fpp_interlinking_new_tab', 1 );
		$case_sensitive      = get_option( 'fpp_interlinking_case_sensitive', 0 );
		$excluded_posts      = get_option( 'fpp_interlinking_excluded_posts', '' );
		$max_links_per_post  = get_option( 'fpp_interlinking_max_links_per_post', 0 );
		$post_types_setting  = get_option( 'fpp_interlinking_post_types', 'post,page' );
		$active_post_types   = array_map( 'trim', explode( ',', $post_types_setting ) );
		$max_cap             = FPP_INTERLINKING_MAX_REPLACEMENTS_LIMIT;
		$engine              = get_option( 'fpp_interlinking_analysis_engine', 'internal' );
		$enable_tracking     = get_option( 'fpp_interlinking_enable_tracking', 1 );
		$retention_days      = get_option( 'fpp_interlinking_tracking_retention_days', 90 );
		?>

		<!-- General Settings -->
		<div class="fpp-section">
			<h2><?php esc_html_e( 'General Settings', 'fpp-interlinking' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="fpp-global-max-replacements"><?php esc_html_e( 'Max replacements per keyword', 'fpp-interlinking' ); ?></label></th>
					<td>
						<input type="number" id="fpp-global-max-replacements" min="1" max="<?php echo esc_attr( $max_cap ); ?>" value="<?php echo esc_attr( $max_replacements ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Maximum number of times each keyword gets linked per post. Set to 1 to only link the first occurrence.', 'fpp-interlinking' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="fpp-global-nofollow"><?php esc_html_e( 'Add rel="nofollow"', 'fpp-interlinking' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="fpp-global-nofollow" value="1" <?php checked( $nofollow, 1 ); ?> />
							<?php esc_html_e( 'Add nofollow attribute to generated links', 'fpp-interlinking' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="fpp-global-new-tab"><?php esc_html_e( 'Open in new tab', 'fpp-interlinking' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="fpp-global-new-tab" value="1" <?php checked( $new_tab, 1 ); ?> />
							<?php esc_html_e( 'Open links in a new browser tab', 'fpp-interlinking' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="fpp-global-case-sensitive"><?php esc_html_e( 'Case sensitive', 'fpp-interlinking' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="fpp-global-case-sensitive" value="1" <?php checked( $case_sensitive, 1 ); ?> />
							<?php esc_html_e( 'Match keywords with exact case', 'fpp-interlinking' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="fpp-global-max-links-per-post"><?php esc_html_e( 'Max links per post', 'fpp-interlinking' ); ?></label></th>
					<td>
						<input type="number" id="fpp-global-max-links-per-post" min="0" max="500" value="<?php echo esc_attr( $max_links_per_post ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Maximum total auto-generated links per post/page. Set to 0 for unlimited.', 'fpp-interlinking' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="fpp-global-excluded-posts"><?php esc_html_e( 'Excluded posts/pages', 'fpp-interlinking' ); ?></label></th>
					<td>
						<textarea id="fpp-global-excluded-posts" rows="3" class="large-text"><?php echo esc_textarea( $excluded_posts ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Comma-separated list of post/page IDs to exclude from keyword replacement.', 'fpp-interlinking' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<hr />

		<!-- Post Types -->
		<div class="fpp-section">
			<h2><?php esc_html_e( 'Post Types', 'fpp-interlinking' ); ?></h2>
			<fieldset id="fpp-post-types-fieldset">
				<?php
				$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
				foreach ( $all_post_types as $pt ) :
					if ( 'attachment' === $pt->name ) {
						continue;
					}
				?>
					<label>
						<input type="checkbox" class="fpp-post-type-checkbox" value="<?php echo esc_attr( $pt->name ); ?>"
							<?php checked( in_array( $pt->name, $active_post_types, true ) ); ?> />
						<?php echo esc_html( $pt->labels->singular_name ); ?>
						<code>(<?php echo esc_html( $pt->name ); ?>)</code>
					</label><br />
				<?php endforeach; ?>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Select which post types should have keyword replacement applied.', 'fpp-interlinking' ); ?></p>
		</div>

		<hr />

		<!-- Analysis Engine -->
		<div class="fpp-section">
			<h2><?php esc_html_e( 'Analysis Engine', 'fpp-interlinking' ); ?></h2>
			<fieldset>
				<label>
					<input type="radio" name="fpp_analysis_engine" value="internal" <?php checked( $engine, 'internal' ); ?> />
					<strong><?php esc_html_e( 'Internal Engine', 'fpp-interlinking' ); ?></strong>
					— <?php esc_html_e( 'Fast, free, uses PHP-based TF-IDF analysis. Recommended for most sites.', 'fpp-interlinking' ); ?>
				</label><br /><br />
				<label>
					<input type="radio" name="fpp_analysis_engine" value="ai" <?php checked( $engine, 'ai' ); ?> />
					<strong><?php esc_html_e( 'AI-Powered', 'fpp-interlinking' ); ?></strong>
					— <?php esc_html_e( 'Uses your AI provider (OpenAI, Anthropic, Gemini, Mistral, DeepSeek, or Ollama). Better accuracy but may require API key.', 'fpp-interlinking' ); ?>
				</label>
			</fieldset>
		</div>

		<hr />

		<!-- AI Configuration (visible when AI engine selected) -->
		<div class="fpp-section fpp-ai-config-section" id="fpp-ai-config" style="<?php echo 'ai' !== $engine ? 'display:none;' : ''; ?>">
			<h2><?php esc_html_e( 'AI Configuration', 'fpp-interlinking' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="fpp-ai-provider"><?php esc_html_e( 'AI Provider', 'fpp-interlinking' ); ?></label></th>
					<td>
						<select id="fpp-ai-provider">
							<?php
							$current_provider = FPP_Interlinking_AI::get_provider();
							foreach ( FPP_Interlinking_AI::PROVIDER_INFO as $key => $info ) :
							?>
								<option value="<?php echo esc_attr( $key ); ?>"
									data-requires-key="<?php echo $info['requires_key'] ? '1' : '0'; ?>"
									<?php selected( $current_provider, $key ); ?>>
									<?php echo esc_html( $info['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr id="fpp-ai-api-key-row" style="<?php echo 'ollama' === $current_provider ? 'display:none;' : ''; ?>">
					<th><label for="fpp-ai-api-key"><?php esc_html_e( 'API Key', 'fpp-interlinking' ); ?></label></th>
					<td>
						<?php $masked = FPP_Interlinking_AI::get_masked_key(); ?>
						<input type="password" id="fpp-ai-api-key" class="regular-text"
							placeholder="<?php echo $masked ? esc_attr( $masked ) : esc_attr__( 'Enter your API key', 'fpp-interlinking' ); ?>"
							autocomplete="off" />
						<?php if ( $masked ) : ?>
							<p class="description">
								<?php printf( esc_html__( 'Current key: %s — Leave blank to keep existing key.', 'fpp-interlinking' ), '<code>' . esc_html( $masked ) . '</code>' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr id="fpp-ai-base-url-row" style="<?php echo 'ollama' !== $current_provider ? 'display:none;' : ''; ?>">
					<th><label for="fpp-ai-base-url"><?php esc_html_e( 'Base URL', 'fpp-interlinking' ); ?></label></th>
					<td>
						<input type="url" id="fpp-ai-base-url" class="regular-text"
							value="<?php echo esc_attr( FPP_Interlinking_AI::get_base_url() ); ?>"
							placeholder="http://localhost:11434" />
						<p class="description"><?php esc_html_e( 'Ollama server URL. Default: http://localhost:11434', 'fpp-interlinking' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="fpp-ai-model"><?php esc_html_e( 'Model', 'fpp-interlinking' ); ?></label></th>
					<td>
						<input type="text" id="fpp-ai-model" class="regular-text"
							value="<?php echo esc_attr( FPP_Interlinking_AI::get_model() ); ?>"
							placeholder="gpt-4o-mini" />
						<p class="description" id="fpp-ai-model-desc">
							<?php
							$model_hints = array();
							foreach ( FPP_Interlinking_AI::PROVIDER_INFO as $key => $info ) {
								$models = implode( ', ', $info['models'] );
								$model_hints[] = $info['label'] . ': ' . $models;
							}
							echo esc_html( implode( '. ', $model_hints ) . '.' );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="fpp-ai-max-tokens"><?php esc_html_e( 'Max Tokens', 'fpp-interlinking' ); ?></label></th>
					<td>
						<input type="number" id="fpp-ai-max-tokens" class="small-text" min="500" max="8000"
							value="<?php echo esc_attr( FPP_Interlinking_AI::get_max_tokens() ); ?>" />
					</td>
				</tr>
			</table>
			<p>
				<button type="button" id="fpp-test-ai-connection" class="button"><?php esc_html_e( 'Test Connection', 'fpp-interlinking' ); ?></button>
				<span id="fpp-ai-connection-status"></span>
			</p>
		</div>

		<hr />

		<!-- Analytics Settings -->
		<div class="fpp-section">
			<h2><?php esc_html_e( 'Analytics Settings', 'fpp-interlinking' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="fpp-enable-tracking"><?php esc_html_e( 'Click Tracking', 'fpp-interlinking' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="fpp-enable-tracking" value="1" <?php checked( $enable_tracking, 1 ); ?> />
							<?php esc_html_e( 'Track clicks on auto-generated interlinks', 'fpp-interlinking' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="fpp-retention-days"><?php esc_html_e( 'Data Retention', 'fpp-interlinking' ); ?></label></th>
					<td>
						<select id="fpp-retention-days">
							<option value="30" <?php selected( $retention_days, 30 ); ?>><?php esc_html_e( '30 days', 'fpp-interlinking' ); ?></option>
							<option value="60" <?php selected( $retention_days, 60 ); ?>><?php esc_html_e( '60 days', 'fpp-interlinking' ); ?></option>
							<option value="90" <?php selected( $retention_days, 90 ); ?>><?php esc_html_e( '90 days', 'fpp-interlinking' ); ?></option>
							<option value="180" <?php selected( $retention_days, 180 ); ?>><?php esc_html_e( '180 days', 'fpp-interlinking' ); ?></option>
							<option value="365" <?php selected( $retention_days, 365 ); ?>><?php esc_html_e( '365 days', 'fpp-interlinking' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Automatically purge click data older than this.', 'fpp-interlinking' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<hr />

		<p>
			<button type="button" id="fpp-save-all-settings" class="button button-primary button-hero">
				<?php esc_html_e( 'Save All Settings', 'fpp-interlinking' ); ?>
			</button>
		</p>
		<?php
	}

	/* ── AJAX Handlers ─────────────────────────────────────────────────────
	 *
	 * Security: each handler checks nonce + capability before proceeding.
	 * Input:    sanitised early with sanitize_text_field / esc_url_raw / absint.
	 * Output:   wp_send_json_* handles JSON encoding and Content-Type header.
	 * ──────────────────────────────────────────────────────────────────── */

	/**
	 * AJAX: Add a new keyword mapping.
	 *
	 * @since 1.0.0
	 */
	public function ajax_add_keyword() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$keyword    = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$target_url = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';

		if ( empty( $keyword ) || empty( $target_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Keyword and URL are required.', 'fpp-interlinking' ) ) );
		}

		if ( FPP_Interlinking_DB::keyword_exists( $keyword ) ) {
			wp_send_json_error( array(
				'message' => __( 'This keyword already exists. Please use a different keyword or edit the existing one.', 'fpp-interlinking' ),
			) );
		}

		$nofollow         = isset( $_POST['nofollow'] ) ? absint( $_POST['nofollow'] ) : 0;
		$new_tab          = isset( $_POST['new_tab'] ) ? absint( $_POST['new_tab'] ) : 1;
		$max_replacements = isset( $_POST['max_replacements'] ) ? absint( $_POST['max_replacements'] ) : 0;

		$id = FPP_Interlinking_DB::insert_keyword( array(
			'keyword'          => $keyword,
			'target_url'       => $target_url,
			'nofollow'         => $nofollow,
			'new_tab'          => $new_tab,
			'max_replacements' => $max_replacements,
		) );

		if ( $id ) {
			delete_transient( 'fpp_interlinking_keywords_cache' );
			wp_send_json_success( array(
				'message' => __( 'Keyword added successfully.', 'fpp-interlinking' ),
				'keyword' => array(
					'id'               => $id,
					'keyword'          => $keyword,
					'target_url'       => $target_url,
					'nofollow'         => $nofollow,
					'new_tab'          => $new_tab,
					'max_replacements' => min( $max_replacements, FPP_INTERLINKING_MAX_REPLACEMENTS_LIMIT ),
					'is_active'        => 1,
				),
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to add keyword.', 'fpp-interlinking' ) ) );
		}
	}

	/**
	 * AJAX: Update an existing keyword mapping.
	 *
	 * @since 1.0.0
	 */
	public function ajax_update_keyword() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$keyword    = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$target_url = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';

		if ( ! $id || empty( $keyword ) || empty( $target_url ) ) {
			wp_send_json_error( array( 'message' => __( 'ID, keyword, and URL are required.', 'fpp-interlinking' ) ) );
		}

		if ( FPP_Interlinking_DB::keyword_exists( $keyword, $id ) ) {
			wp_send_json_error( array(
				'message' => __( 'Another keyword with this text already exists.', 'fpp-interlinking' ),
			) );
		}

		$nofollow         = isset( $_POST['nofollow'] ) ? absint( $_POST['nofollow'] ) : 0;
		$new_tab          = isset( $_POST['new_tab'] ) ? absint( $_POST['new_tab'] ) : 1;
		$max_replacements = isset( $_POST['max_replacements'] ) ? absint( $_POST['max_replacements'] ) : 0;

		$result = FPP_Interlinking_DB::update_keyword( $id, array(
			'keyword'          => $keyword,
			'target_url'       => $target_url,
			'nofollow'         => $nofollow,
			'new_tab'          => $new_tab,
			'max_replacements' => $max_replacements,
		) );

		if ( false !== $result ) {
			delete_transient( 'fpp_interlinking_keywords_cache' );
			wp_send_json_success( array(
				'message' => __( 'Keyword updated successfully.', 'fpp-interlinking' ),
				'keyword' => array(
					'id'               => $id,
					'keyword'          => $keyword,
					'target_url'       => $target_url,
					'nofollow'         => $nofollow,
					'new_tab'          => $new_tab,
					'max_replacements' => min( $max_replacements, FPP_INTERLINKING_MAX_REPLACEMENTS_LIMIT ),
					'is_active'        => 1,
				),
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to update keyword.', 'fpp-interlinking' ) ) );
		}
	}

	/**
	 * AJAX: Delete a keyword mapping.
	 *
	 * @since 1.0.0
	 */
	public function ajax_delete_keyword() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid keyword ID.', 'fpp-interlinking' ) ) );
		}

		$result = FPP_Interlinking_DB::delete_keyword( $id );

		if ( $result ) {
			delete_transient( 'fpp_interlinking_keywords_cache' );
			wp_send_json_success( array( 'message' => __( 'Keyword deleted successfully.', 'fpp-interlinking' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to delete keyword.', 'fpp-interlinking' ) ) );
		}
	}

	/**
	 * AJAX: Toggle a keyword's active state.
	 *
	 * @since 1.0.0
	 */
	public function ajax_toggle_keyword() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$id        = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$is_active = isset( $_POST['is_active'] ) ? absint( $_POST['is_active'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid keyword ID.', 'fpp-interlinking' ) ) );
		}

		$result = FPP_Interlinking_DB::toggle_keyword( $id, $is_active );

		if ( false !== $result ) {
			delete_transient( 'fpp_interlinking_keywords_cache' );
			wp_send_json_success( array(
				'message'   => $is_active
					? __( 'Keyword enabled.', 'fpp-interlinking' )
					: __( 'Keyword disabled.', 'fpp-interlinking' ),
				'is_active' => $is_active,
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to toggle keyword.', 'fpp-interlinking' ) ) );
		}
	}

	/**
	 * AJAX: Save global settings.
	 *
	 * @since 1.0.0
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$max_replacements = isset( $_POST['max_replacements'] ) ? absint( $_POST['max_replacements'] ) : 1;
		$max_replacements = max( 1, min( $max_replacements, FPP_INTERLINKING_MAX_REPLACEMENTS_LIMIT ) );

		update_option( 'fpp_interlinking_max_replacements', $max_replacements );
		update_option( 'fpp_interlinking_nofollow', isset( $_POST['nofollow'] ) ? absint( $_POST['nofollow'] ) : 0 );
		update_option( 'fpp_interlinking_new_tab', isset( $_POST['new_tab'] ) ? absint( $_POST['new_tab'] ) : 0 );
		update_option( 'fpp_interlinking_case_sensitive', isset( $_POST['case_sensitive'] ) ? absint( $_POST['case_sensitive'] ) : 0 );
		update_option( 'fpp_interlinking_excluded_posts', isset( $_POST['excluded_posts'] ) ? sanitize_text_field( wp_unslash( $_POST['excluded_posts'] ) ) : '' );

		$max_links = isset( $_POST['max_links_per_post'] ) ? absint( $_POST['max_links_per_post'] ) : 0;
		if ( $max_links > 500 ) {
			$max_links = 500;
		}
		update_option( 'fpp_interlinking_max_links_per_post', $max_links );

		$post_types = isset( $_POST['post_types'] ) ? sanitize_text_field( wp_unslash( $_POST['post_types'] ) ) : 'post,page';
		update_option( 'fpp_interlinking_post_types', $post_types );

		// v3.0.0: Analysis engine + analytics settings.
		if ( isset( $_POST['analysis_engine'] ) ) {
			$eng = sanitize_text_field( wp_unslash( $_POST['analysis_engine'] ) );
			if ( in_array( $eng, array( 'internal', 'ai' ), true ) ) {
				update_option( 'fpp_interlinking_analysis_engine', $eng );
			}
		}

		if ( isset( $_POST['enable_tracking'] ) ) {
			update_option( 'fpp_interlinking_enable_tracking', absint( $_POST['enable_tracking'] ) );
		}

		if ( isset( $_POST['retention_days'] ) ) {
			$days = absint( $_POST['retention_days'] );
			if ( in_array( $days, array( 30, 60, 90, 180, 365 ), true ) ) {
				update_option( 'fpp_interlinking_tracking_retention_days', $days );
			}
		}

		// AI settings (if provided).
		if ( isset( $_POST['ai_provider'] ) ) {
			$provider = sanitize_text_field( wp_unslash( $_POST['ai_provider'] ) );
			if ( array_key_exists( $provider, FPP_Interlinking_AI::PROVIDER_INFO ) ) {
				update_option( FPP_Interlinking_AI::OPTION_PROVIDER, $provider, false );
			}
		}

		if ( ! empty( $_POST['ai_model'] ) ) {
			update_option( FPP_Interlinking_AI::OPTION_MODEL, sanitize_text_field( wp_unslash( $_POST['ai_model'] ) ), false );
		}

		if ( isset( $_POST['ai_max_tokens'] ) ) {
			$tokens = max( 500, min( absint( $_POST['ai_max_tokens'] ), 8000 ) );
			update_option( FPP_Interlinking_AI::OPTION_MAX_TOKENS, $tokens, false );
		}

		if ( ! empty( $_POST['ai_api_key'] ) ) {
			FPP_Interlinking_AI::save_api_key( sanitize_text_field( wp_unslash( $_POST['ai_api_key'] ) ) );
		}

		// v4.0.0: Ollama base URL.
		if ( isset( $_POST['ai_base_url'] ) ) {
			$base_url = esc_url_raw( wp_unslash( $_POST['ai_base_url'] ) );
			if ( ! empty( $base_url ) ) {
				update_option( FPP_Interlinking_AI::OPTION_BASE_URL, $base_url, false );
			}
		}

		delete_transient( 'fpp_interlinking_keywords_cache' );

		wp_send_json_success( array( 'message' => __( 'Settings saved successfully.', 'fpp-interlinking' ) ) );
	}

	/* ── v1.2.0: Scan, Suggest & Search Handlers ──────────────────────── */

	/**
	 * AJAX: Search posts/pages by title for autocomplete.
	 *
	 * @since 1.2.0
	 */
	public function ajax_search_posts() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$query = new WP_Query( array(
			's'                => $search,
			'post_type'        => FPP_Interlinking_DB::get_configured_post_types(),
			'post_status'      => 'publish',
			'posts_per_page'   => 10,
			'orderby'          => 'relevance',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		) );

		$results = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$type_obj  = get_post_type_object( get_post_type() );
				$results[] = array(
					'id'        => get_the_ID(),
					'title'     => get_the_title(),
					'permalink' => get_permalink(),
					'post_type' => $type_obj ? $type_obj->labels->singular_name : get_post_type(),
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX: Scan for posts whose title matches a keyword.
	 *
	 * @since 1.2.0
	 */
	public function ajax_scan_keyword() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';

		if ( empty( $keyword ) ) {
			wp_send_json_error( array( 'message' => __( 'Keyword is required.', 'fpp-interlinking' ) ) );
		}

		$query = new WP_Query( array(
			's'                => $keyword,
			'post_type'        => FPP_Interlinking_DB::get_configured_post_types(),
			'post_status'      => 'publish',
			'posts_per_page'   => 20,
			'orderby'          => 'relevance',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		) );

		$results = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$type_obj  = get_post_type_object( get_post_type() );
				$results[] = array(
					'id'        => get_the_ID(),
					'title'     => get_the_title(),
					'permalink' => get_permalink(),
					'post_type' => $type_obj ? $type_obj->labels->singular_name : get_post_type(),
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array(
			'results' => $results,
			'keyword' => $keyword,
		) );
	}

	/**
	 * AJAX: Suggest keywords from published post titles.
	 *
	 * @since 1.2.0
	 */
	public function ajax_suggest_keywords() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page = 30;

		$query = new WP_Query( array(
			'post_type'      => FPP_Interlinking_DB::get_configured_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, $page ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$existing_keywords = FPP_Interlinking_DB::get_all_keywords();
		$existing_map      = array();
		foreach ( $existing_keywords as $ek ) {
			$existing_map[ strtolower( $ek['keyword'] ) ] = true;
		}

		$results = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$title    = get_the_title();
				$type_obj = get_post_type_object( get_post_type() );
				$results[] = array(
					'id'            => get_the_ID(),
					'title'         => $title,
					'permalink'     => get_permalink(),
					'post_type'     => $type_obj ? $type_obj->labels->singular_name : get_post_type(),
					'already_added' => isset( $existing_map[ strtolower( $title ) ] ),
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array(
			'results'     => $results,
			'page'        => max( 1, $page ),
			'total_pages' => $query->max_num_pages,
			'total_posts' => $query->found_posts,
		) );
	}

	/* ── v2.0.0: AI-Powered AJAX Handlers ────────────────────────────── */

	/**
	 * AJAX: Save AI settings.
	 *
	 * @since 2.0.0
	 */
	public function ajax_save_ai_settings() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$provider   = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'openai';
		$model      = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$api_key    = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$max_tokens = isset( $_POST['max_tokens'] ) ? absint( $_POST['max_tokens'] ) : 2000;

		if ( ! array_key_exists( $provider, FPP_Interlinking_AI::PROVIDER_INFO ) ) {
			$provider = 'openai';
		}

		$max_tokens = max( 500, min( $max_tokens, 8000 ) );

		update_option( FPP_Interlinking_AI::OPTION_PROVIDER, $provider, false );
		update_option( FPP_Interlinking_AI::OPTION_MAX_TOKENS, $max_tokens, false );

		if ( ! empty( $model ) ) {
			update_option( FPP_Interlinking_AI::OPTION_MODEL, $model, false );
		}

		if ( ! empty( $api_key ) ) {
			FPP_Interlinking_AI::save_api_key( $api_key );
		}

		// v4.0.0: Ollama base URL.
		if ( isset( $_POST['base_url'] ) ) {
			$base_url = esc_url_raw( wp_unslash( $_POST['base_url'] ) );
			if ( ! empty( $base_url ) ) {
				update_option( FPP_Interlinking_AI::OPTION_BASE_URL, $base_url, false );
			}
		}

		wp_send_json_success( array(
			'message'    => __( 'AI settings saved.', 'fpp-interlinking' ),
			'masked_key' => FPP_Interlinking_AI::get_masked_key(),
		) );
	}

	/**
	 * AJAX: Test the AI API connection.
	 *
	 * @since 2.0.0
	 */
	public function ajax_test_ai_connection() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$result = FPP_Interlinking_AI::test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connection successful! Your AI provider is working.', 'fpp-interlinking' ) ) );
	}

	/**
	 * AJAX: Extract keywords from a post using AI.
	 *
	 * @since 2.0.0
	 */
	public function ajax_ai_extract_keywords() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$rate_check = FPP_Interlinking_AI::check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a post to analyse.', 'fpp-interlinking' ) ) );
		}

		$result = FPP_Interlinking_AI::extract_keywords( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$existing = FPP_Interlinking_DB::get_all_keywords();
		$existing_map = array();
		foreach ( $existing as $ek ) {
			$existing_map[ strtolower( $ek['keyword'] ) ] = true;
		}

		foreach ( $result as &$kw ) {
			$kw['already_exists'] = isset( $existing_map[ strtolower( $kw['keyword'] ?? '' ) ] );
		}
		unset( $kw );

		$post = get_post( $post_id );
		wp_send_json_success( array(
			'keywords'   => $result,
			'post_title' => $post ? $post->post_title : '',
			'post_url'   => get_permalink( $post_id ),
		) );
	}

	/**
	 * AJAX: Score relevance of pages for a keyword using AI.
	 *
	 * @since 2.0.0
	 */
	public function ajax_ai_score_relevance() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$rate_check = FPP_Interlinking_AI::check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
		}

		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';

		if ( empty( $keyword ) ) {
			wp_send_json_error( array( 'message' => __( 'Keyword is required.', 'fpp-interlinking' ) ) );
		}

		$query = new WP_Query( array(
			's'                => $keyword,
			'post_type'        => FPP_Interlinking_DB::get_configured_post_types(),
			'post_status'      => 'publish',
			'posts_per_page'   => 15,
			'orderby'          => 'relevance',
			'order'            => 'DESC',
			'no_found_rows'    => true,
		) );

		$candidates = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$candidates[] = array(
					'id'      => get_the_ID(),
					'title'   => get_the_title(),
					'url'     => get_permalink(),
					'excerpt' => wp_strip_all_tags( get_the_excerpt() ),
				);
			}
			wp_reset_postdata();
		}

		if ( empty( $candidates ) ) {
			wp_send_json_error( array( 'message' => __( 'No matching posts found for this keyword.', 'fpp-interlinking' ) ) );
		}

		$result = FPP_Interlinking_AI::score_relevance( $keyword, $candidates );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'keyword' => $keyword,
			'scores'  => $result,
		) );
	}

	/**
	 * AJAX: Analyse content gaps using AI.
	 *
	 * @since 2.0.0
	 */
	public function ajax_ai_content_gaps() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$rate_check = FPP_Interlinking_AI::check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$result = FPP_Interlinking_AI::analyse_content_gaps( 20, $offset );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Auto-generate keyword mappings using AI.
	 *
	 * @since 2.0.0
	 */
	public function ajax_ai_auto_generate() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$rate_check = FPP_Interlinking_AI::check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$result = FPP_Interlinking_AI::auto_generate_mappings( 20, $offset );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Add a single suggested mapping to the keywords table.
	 *
	 * @since 2.0.0
	 */
	public function ajax_ai_add_mapping() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$keyword    = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$target_url = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';

		if ( empty( $keyword ) || empty( $target_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Keyword and URL are required.', 'fpp-interlinking' ) ) );
		}

		if ( FPP_Interlinking_DB::keyword_exists( $keyword ) ) {
			wp_send_json_error( array( 'message' => __( 'This keyword already exists.', 'fpp-interlinking' ) ) );
		}

		$id = FPP_Interlinking_DB::insert_keyword( array(
			'keyword'          => $keyword,
			'target_url'       => $target_url,
			'nofollow'         => 0,
			'new_tab'          => 1,
			'max_replacements' => 0,
		) );

		if ( $id ) {
			delete_transient( 'fpp_interlinking_keywords_cache' );
			wp_send_json_success( array(
				'message' => sprintf( __( 'Keyword "%s" added successfully.', 'fpp-interlinking' ), $keyword ),
				'keyword' => array(
					'id'               => $id,
					'keyword'          => $keyword,
					'target_url'       => $target_url,
					'nofollow'         => 0,
					'new_tab'          => 1,
					'max_replacements' => 0,
					'is_active'        => 1,
				),
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to add keyword.', 'fpp-interlinking' ) ) );
		}
	}

	/* ── v2.1.0: Paginated Table, Bulk Ops, Import/Export ────────────── */

	/**
	 * AJAX: Load keywords with pagination and search.
	 *
	 * @since 2.1.0
	 */
	public function ajax_load_keywords() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page = 20;
		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$orderby  = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'keyword';
		$order    = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : 'ASC';

		$result = FPP_Interlinking_DB::get_keywords_paginated( $page, $per_page, $search, $orderby, $order );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Bulk action on selected keywords.
	 *
	 * @since 2.1.0
	 */
	public function ajax_bulk_action() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$ids    = array_filter( $ids );

		if ( empty( $action ) || empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid bulk action or no items selected.', 'fpp-interlinking' ) ) );
		}

		$count = 0;

		switch ( $action ) {
			case 'delete':
				$count = FPP_Interlinking_DB::bulk_delete( $ids );
				break;

			case 'enable':
				$count = FPP_Interlinking_DB::bulk_toggle( $ids, 1 );
				break;

			case 'disable':
				$count = FPP_Interlinking_DB::bulk_toggle( $ids, 0 );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown bulk action.', 'fpp-interlinking' ) ) );
				return;
		}

		delete_transient( 'fpp_interlinking_keywords_cache' );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of keywords affected. */
				__( '%d keyword(s) updated.', 'fpp-interlinking' ),
				$count
			),
			'affected' => $count,
		) );
	}

	/**
	 * AJAX: Export all keywords as CSV.
	 *
	 * @since 2.1.0
	 */
	public function ajax_export_csv() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$keywords = FPP_Interlinking_DB::export_all();

		if ( empty( $keywords ) ) {
			wp_send_json_error( array( 'message' => __( 'No keywords to export.', 'fpp-interlinking' ) ) );
		}

		$csv = "keyword,target_url,nofollow,new_tab,max_replacements,is_active\n";
		foreach ( $keywords as $kw ) {
			$csv .= sprintf(
				'"%s","%s",%d,%d,%d,%d' . "\n",
				str_replace( '"', '""', $kw['keyword'] ),
				str_replace( '"', '""', $kw['target_url'] ),
				(int) $kw['nofollow'],
				(int) $kw['new_tab'],
				(int) $kw['max_replacements'],
				(int) $kw['is_active']
			);
		}

		wp_send_json_success( array(
			'csv'      => $csv,
			'filename' => 'wp-interlinking-keywords-' . gmdate( 'Y-m-d' ) . '.csv',
			'count'    => count( $keywords ),
		) );
	}

	/**
	 * AJAX: Import keywords from uploaded CSV data.
	 *
	 * @since 2.1.0
	 */
	public function ajax_import_csv() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$csv_data = isset( $_POST['csv_data'] ) ? wp_unslash( $_POST['csv_data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $csv_data ) ) {
			wp_send_json_error( array( 'message' => __( 'No CSV data provided.', 'fpp-interlinking' ) ) );
		}

		$lines = explode( "\n", trim( $csv_data ) );
		if ( count( $lines ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'CSV file is empty or has no data rows.', 'fpp-interlinking' ) ) );
		}

		$header = str_getcsv( array_shift( $lines ) );
		$header = array_map( 'trim', $header );
		$header = array_map( 'strtolower', $header );

		if ( ! in_array( 'keyword', $header, true ) || ! in_array( 'target_url', $header, true ) ) {
			wp_send_json_error( array( 'message' => __( 'CSV must have "keyword" and "target_url" columns.', 'fpp-interlinking' ) ) );
		}

		$rows = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}
			$values = str_getcsv( $line );
			$row    = array();
			foreach ( $header as $i => $key ) {
				$row[ $key ] = isset( $values[ $i ] ) ? $values[ $i ] : '';
			}
			$rows[] = $row;
		}

		$result = FPP_Interlinking_DB::import_keywords( $rows );
		delete_transient( 'fpp_interlinking_keywords_cache' );

		wp_send_json_success( array(
			'message'  => sprintf(
				/* translators: 1: imported count, 2: skipped count, 3: error count. */
				__( 'Import complete: %1$d imported, %2$d skipped (duplicates), %3$d errors.', 'fpp-interlinking' ),
				$result['imported'],
				$result['skipped'],
				$result['errors']
			),
			'imported' => $result['imported'],
			'skipped'  => $result['skipped'],
			'errors'   => $result['errors'],
		) );
	}

	/* ── v3.0.0: Analysis Dispatcher Handlers ────────────────────────── */

	/**
	 * Get the current analysis engine setting.
	 *
	 * @since 3.0.0
	 *
	 * @return string 'internal' or 'ai'.
	 */
	private function get_analysis_engine() {
		$engine = get_option( 'fpp_interlinking_analysis_engine', 'internal' );
		return in_array( $engine, array( 'internal', 'ai' ), true ) ? $engine : 'internal';
	}

	/**
	 * AJAX: Extract keywords — dispatches to internal or AI engine.
	 *
	 * @since 3.0.0
	 */
	public function ajax_analyze_extract() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a post to analyse.', 'fpp-interlinking' ) ) );
		}

		$engine = $this->get_analysis_engine();

		if ( 'ai' === $engine ) {
			$rate_check = FPP_Interlinking_AI::check_rate_limit();
			if ( is_wp_error( $rate_check ) ) {
				wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
			}
			$result = FPP_Interlinking_AI::extract_keywords( $post_id );
		} else {
			$analyzer = new FPP_Interlinking_Analyzer();
			$result   = $analyzer->extract_keywords( $post_id );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Add already_exists flag.
		$existing = FPP_Interlinking_DB::get_all_keywords();
		$existing_map = array();
		foreach ( $existing as $ek ) {
			$existing_map[ strtolower( $ek['keyword'] ) ] = true;
		}
		foreach ( $result as &$kw ) {
			$kw['already_exists'] = isset( $existing_map[ strtolower( $kw['keyword'] ?? '' ) ] );
		}
		unset( $kw );

		$post = get_post( $post_id );
		wp_send_json_success( array(
			'keywords'   => $result,
			'post_title' => $post ? $post->post_title : '',
			'post_url'   => get_permalink( $post_id ),
			'engine'     => $engine,
		) );
	}

	/**
	 * AJAX: Score relevance — dispatches to internal or AI engine.
	 *
	 * @since 3.0.0
	 */
	public function ajax_analyze_score() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';

		if ( empty( $keyword ) ) {
			wp_send_json_error( array( 'message' => __( 'Keyword is required.', 'fpp-interlinking' ) ) );
		}

		$post_types = FPP_Interlinking_DB::get_configured_post_types();

		$query = new WP_Query( array(
			's'                => $keyword,
			'post_type'        => $post_types,
			'post_status'      => 'publish',
			'posts_per_page'   => 15,
			'orderby'          => 'relevance',
			'order'            => 'DESC',
			'no_found_rows'    => true,
		) );

		$candidates = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$candidates[] = array(
					'id'      => get_the_ID(),
					'title'   => get_the_title(),
					'url'     => get_permalink(),
					'excerpt' => wp_strip_all_tags( get_the_excerpt() ),
				);
			}
			wp_reset_postdata();
		}

		if ( empty( $candidates ) ) {
			wp_send_json_error( array( 'message' => __( 'No matching posts found for this keyword.', 'fpp-interlinking' ) ) );
		}

		$engine = $this->get_analysis_engine();

		if ( 'ai' === $engine ) {
			$rate_check = FPP_Interlinking_AI::check_rate_limit();
			if ( is_wp_error( $rate_check ) ) {
				wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
			}
			$result = FPP_Interlinking_AI::score_relevance( $keyword, $candidates );
		} else {
			$analyzer = new FPP_Interlinking_Analyzer();
			$result   = $analyzer->score_relevance( $keyword, $candidates, $post_types );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'keyword' => $keyword,
			'scores'  => $result,
			'engine'  => $engine,
		) );
	}

	/**
	 * AJAX: Content gap analysis — dispatches to internal or AI engine.
	 *
	 * @since 3.0.0
	 */
	public function ajax_analyze_gaps() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$engine = $this->get_analysis_engine();

		if ( 'ai' === $engine ) {
			$rate_check = FPP_Interlinking_AI::check_rate_limit();
			if ( is_wp_error( $rate_check ) ) {
				wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
			}
			$result = FPP_Interlinking_AI::analyse_content_gaps( 20, $offset );
		} else {
			$analyzer = new FPP_Interlinking_Analyzer();
			$result   = $analyzer->analyse_content_gaps( 20, $offset );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$result['engine'] = $engine;
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Auto-generate mappings — dispatches to internal or AI engine.
	 *
	 * @since 3.0.0
	 */
	public function ajax_analyze_generate() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$engine = $this->get_analysis_engine();

		if ( 'ai' === $engine ) {
			$rate_check = FPP_Interlinking_AI::check_rate_limit();
			if ( is_wp_error( $rate_check ) ) {
				wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
			}
			$result = FPP_Interlinking_AI::auto_generate_mappings( 20, $offset );
		} else {
			$analyzer = new FPP_Interlinking_Analyzer();
			$result   = $analyzer->auto_generate_mappings( 20, $offset );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$result['engine'] = $engine;
		wp_send_json_success( $result );
	}

	/* ── v3.0.0: Dashboard & Analytics Data Handlers ─────────────────── */

	/**
	 * AJAX: Load dashboard data.
	 *
	 * @since 3.0.0
	 */
	public function ajax_load_dashboard() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$analytics = new FPP_Interlinking_Analytics();
		$coverage  = $analytics->get_coverage_stats();
		$summary   = $analytics->get_summary_stats( '30d' );
		$recent    = $analytics->get_recent_clicks( 10 );
		$trend_7d  = $analytics->get_daily_trend( 7 );

		wp_send_json_success( array(
			'coverage'  => $coverage,
			'summary'   => $summary,
			'recent'    => $recent,
			'trend_7d'  => $trend_7d,
		) );
	}

	/**
	 * AJAX: Load analytics data for a given period.
	 *
	 * @since 3.0.0
	 */
	public function ajax_load_analytics() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '30d';
		if ( ! in_array( $period, array( 'today', '7d', '30d', 'all', 'custom' ), true ) ) {
			$period = '30d';
		}

		// v4.0.0: Custom date range support.
		$start_date = '';
		$end_date   = '';
		if ( 'custom' === $period ) {
			$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
			$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
			if ( empty( $start_date ) || empty( $end_date ) ) {
				$period = '30d';
			}
		}

		// Determine trend days from period.
		$trend_map = array(
			'today' => 1,
			'7d'    => 7,
			'30d'   => 30,
			'all'   => 90,
		);
		if ( 'custom' === $period && $start_date && $end_date ) {
			$diff       = abs( strtotime( $end_date ) - strtotime( $start_date ) );
			$trend_days = max( 1, (int) ceil( $diff / DAY_IN_SECONDS ) + 1 );
		} else {
			$trend_days = isset( $trend_map[ $period ] ) ? $trend_map[ $period ] : 30;
		}

		$analytics = new FPP_Interlinking_Analytics();

		wp_send_json_success( array(
			'summary'         => $analytics->get_summary_stats( $period, $start_date, $end_date ),
			'comparison'      => $analytics->get_comparison_stats( $period, $start_date, $end_date ),
			'top_keywords'    => $analytics->get_top_keywords( 10, $period, $start_date, $end_date ),
			'clicks_by_post'  => $analytics->get_clicks_by_post( 10, $period, $start_date, $end_date ),
			'top_links'       => $analytics->get_top_links( 10, $period, $start_date, $end_date ),
			'post_type_stats' => $analytics->get_stats_by_post_type( $period, $start_date, $end_date ),
			'daily_trend'     => $analytics->get_daily_trend( $trend_days, $start_date ),
			'period'          => $period,
		) );
	}

	/* ── v4.0.0: AJAX Tab Loading & Analytics CSV Export ────────────── */

	/**
	 * AJAX: Load a tab's HTML via AJAX for seamless tab switching.
	 *
	 * @since 4.0.0
	 */
	public function ajax_load_tab() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$tab    = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : '';
		$method = 'render_tab_' . $tab;

		if ( empty( $tab ) || ! method_exists( $this, $method ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid tab.', 'fpp-interlinking' ) ) );
		}

		ob_start();
		$this->$method();
		$html = ob_get_clean();

		wp_send_json_success( array(
			'html' => $html,
			'tab'  => $tab,
		) );
	}

	/**
	 * AJAX: Export analytics data as CSV.
	 *
	 * @since 4.0.0
	 */
	public function ajax_export_analytics_csv() {
		check_ajax_referer( 'fpp_interlinking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fpp-interlinking' ) ) );
		}

		$type   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'top_keywords';
		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '30d';

		$start_date = '';
		$end_date   = '';
		if ( 'custom' === $period ) {
			$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
			$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		}

		$analytics = new FPP_Interlinking_Analytics();
		$csv       = $analytics->build_csv( $type, $period, $start_date, $end_date );

		if ( empty( $csv ) ) {
			wp_send_json_error( array( 'message' => __( 'No data to export.', 'fpp-interlinking' ) ) );
		}

		wp_send_json_success( array(
			'csv'      => $csv,
			'filename' => 'wp-interlinking-' . $type . '-' . gmdate( 'Y-m-d' ) . '.csv',
		) );
	}
}
