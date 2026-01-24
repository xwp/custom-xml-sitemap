<?php
/**
 * Custom XML Sitemap
 *
 * Custom taxonomy-based XML sitemap generator for WordPress. Creates hierarchical
 * sitemaps filtered by taxonomy terms with configurable granularity (year/month/day).
 *
 * @package   XWP\CustomXmlSitemap
 * @author    XWP
 * @license   GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Custom XML Sitemap
 * Plugin URI:  https://github.com/xwp/custom-xml-sitemap
 * Description: Custom taxonomy-based XML sitemap generator with configurable granularity.
 * Version:     1.0.0
 * Author:      XWP
 * Author URI:  https://xwp.co
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: custom-xml-sitemap
 * Requires PHP: 8.4
 */

namespace XWP\CustomXmlSitemap;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'CXS_PLUGIN_FILE', __FILE__ );
define( 'CXS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CXS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CXS_VERSION', '1.0.0' );

// Load Composer autoloader if present.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Load Action Scheduler if not already loaded.
if ( ! class_exists( 'ActionScheduler_Store' ) ) {
	if ( file_exists( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
		require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
	}
}

/**
 * Initialize the plugin on plugins_loaded hook.
 *
 * Uses plugins_loaded to ensure all dependencies are available.
 *
 * @return void
 */
function init(): void {
	( new Plugin() )->init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Flush rewrite rules on plugin activation.
 *
 * @return void
 */
function activate(): void {
	// Register CPT and rewrite rules first.
	( new Sitemap_CPT() )->register();
	( new Sitemap_Router() )->register_rewrite_rules();

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Required for plugin activation to register new rewrite rules.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Clean up on plugin deactivation.
 *
 * @return void
 */
function deactivate(): void {
	// Unschedule all Action Scheduler jobs.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', [], Sitemap_Scheduler::AS_GROUP );
	}

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Required for plugin deactivation to clean up rewrite rules.
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
