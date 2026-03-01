<?php
/**
 * Plugin Name:       WP Interlinking
 * Plugin URI:        https://developer.wordpress.org/plugins/
 * Description:       AI-powered SEO internal linking with deep analytics. Map keywords to URLs with automatic replacement, keyword extraction, relevance scoring, content gap analysis, CTR tracking, and Chart.js visualisations. Supports OpenAI, Anthropic, Ollama, Google Gemini, Mistral AI, and DeepSeek.
 * Version:           4.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            FPP
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fpp-interlinking
 * Domain Path:       /languages
 *
 * @package FPP_Interlinking
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
define( 'FPP_INTERLINKING_VERSION', '4.0.0' );
define( 'FPP_INTERLINKING_DB_VERSION', '3.0.0' );
define( 'FPP_INTERLINKING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FPP_INTERLINKING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FPP_INTERLINKING_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'FPP_INTERLINKING_MAX_REPLACEMENTS_LIMIT', 100 );

/**
 * Autoload plugin classes.
 */
require_once FPP_INTERLINKING_PLUGIN_DIR . 'includes/class-fpp-interlinking-activator.php';
require_once FPP_INTERLINKING_PLUGIN_DIR . 'includes/class-fpp-interlinking-deactivator.php';
require_once FPP_INTERLINKING_PLUGIN_DIR . 'includes/class-fpp-interlinking-db.php';
require_once FPP_INTERLINKING_PLUGIN_DIR . 'includes/class-fpp-interlinking-ai.php';
require_once FPP_INTERLINKING_PLUGIN_DIR . 'includes/class-fpp-interlinking-analyzer.php';
require_once FPP_INTERLINKING_PLUGIN_DIR . 'includes/class-fpp-interlinking-analytics.php';
require_once FPP_INTERLINKING_PLUGIN_DIR . 'includes/class-fpp-interlinking-admin.php';
require_once FPP_INTERLINKING_PLUGIN_DIR . 'includes/class-fpp-interlinking-replacer.php';

/**
 * Lifecycle hooks.
 */
register_activation_hook( __FILE__, array( 'FPP_Interlinking_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FPP_Interlinking_Deactivator', 'deactivate' ) );

/**
 * Check DB schema version on every load and run migrations when needed.
 *
 * Uses dbDelta() which is safe to re-run – it only applies incremental changes.
 *
 * @since 1.0.0
 *
 * @return void
 */
function fpp_interlinking_check_db_version() {
	$installed_version = get_option( 'fpp_interlinking_db_version', '0' );
	if ( version_compare( $installed_version, FPP_INTERLINKING_DB_VERSION, '<' ) ) {
		FPP_Interlinking_Activator::activate();
		update_option( 'fpp_interlinking_db_version', FPP_INTERLINKING_DB_VERSION, false );
	}
}
add_action( 'plugins_loaded', 'fpp_interlinking_check_db_version' );

/**
 * Add a "Settings" link on the Plugins list page.
 *
 * @since 1.1.0
 *
 * @param string[] $links Existing plugin action links.
 * @return string[] Modified plugin action links.
 */
function fpp_interlinking_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=fpp-interlinking' ) ),
		esc_html__( 'Settings', 'fpp-interlinking' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . FPP_INTERLINKING_PLUGIN_BASENAME, 'fpp_interlinking_plugin_action_links' );

/**
 * Initialize admin functionality.
 */
if ( is_admin() ) {
	new FPP_Interlinking_Admin();
}

/**
 * Initialize front-end content replacement and analytics tracking.
 */
if ( ! is_admin() ) {
	new FPP_Interlinking_Replacer();

	// v3.0.0: Click tracking.
	if ( get_option( 'fpp_interlinking_enable_tracking', 1 ) ) {
		new FPP_Interlinking_Analytics();
		add_action( 'wp_enqueue_scripts', array( 'FPP_Interlinking_Analytics', 'enqueue_tracker' ) );
	}
}

/**
 * v3.0.0: Daily analytics data purge via WP-Cron.
 */
add_action( 'fpp_interlinking_purge_analytics', array( 'FPP_Interlinking_Analytics', 'purge_old_data' ) );
