<?php
/**
 * Main Plugin Class.
 *
 * Orchestrates plugin initialization and coordinates all components including
 * CPT registration, routing, scheduling, and admin functionality.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap;

use XWP\CustomXmlSitemap\Admin\Settings_Panel;
use XWP\CustomXmlSitemap\CLI\Sitemap_Command;

/**
 * Main Plugin Class.
 *
 * Initializes all plugin components and registers WordPress hooks.
 */
class Plugin {

	/**
	 * Maximum number of custom sitemaps allowed.
	 *
	 * @var int
	 */
	public const MAX_SITEMAPS = 100;

	/**
	 * Maximum number of terms per sitemap configuration.
	 *
	 * @var int
	 */
	public const MAX_TERMS_PER_SITEMAP = 1000;

	/**
	 * Sitemap CPT instance.
	 *
	 * @var Sitemap_CPT
	 */
	private Sitemap_CPT $cpt;

	/**
	 * Sitemap Router instance.
	 *
	 * @var Sitemap_Router
	 */
	private Sitemap_Router $router;

	/**
	 * Sitemap Scheduler instance.
	 *
	 * @var Sitemap_Scheduler
	 */
	private Sitemap_Scheduler $scheduler;

	/**
	 * Settings Panel instance.
	 *
	 * @var Settings_Panel
	 */
	private Settings_Panel $settings_panel;

	/**
	 * Initialize the plugin.
	 *
	 * Sets up all plugin components and registers WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Initialize components.
		$this->cpt            = new Sitemap_CPT();
		$this->router         = new Sitemap_Router();
		$this->scheduler      = new Sitemap_Scheduler();
		$this->settings_panel = new Settings_Panel();

		// Register CPT.
		add_action( 'init', [ $this->cpt, 'register' ] );

		// Initialize router (rewrite rules, query vars, template handling).
		$this->router->init();

		// Initialize scheduler (Action Scheduler hooks).
		$this->scheduler->init();

		// Initialize admin settings panel.
		$this->settings_panel->init();

		// Add sitemaps to robots.txt.
		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.robots_txt -- Intentionally modifying robots.txt to include sitemap references.
		add_filter( 'robots_txt', [ $this, 'add_sitemaps_to_robots' ], PHP_INT_MAX, 2 );

		// Register WP-CLI commands when available.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli_command = new Sitemap_Command();
			$cli_command->register();
		}
	}

	/**
	 * Add custom sitemaps to robots.txt.
	 *
	 * Appends sitemap index URLs for all published custom sitemaps.
	 *
	 * @param string $output    Current robots.txt content.
	 * @param bool   $is_public Whether the site is public.
	 * @return string Modified robots.txt content.
	 */
	public function add_sitemaps_to_robots( string $output, bool $is_public ): string {
		if ( ! $is_public ) {
			return $output;
		}

		$sitemaps = Sitemap_CPT::get_all_sitemap_configs();

		if ( empty( $sitemaps ) ) {
			return $output;
		}

		$sitemap_lines = [];
		foreach ( $sitemaps as $sitemap_data ) {
			$sitemap     = $sitemap_data['post'];
			$sitemap_url = home_url( "/sitemaps/{$sitemap->post_name}/index.xml" );

			$sitemap_lines[] = "Sitemap: {$sitemap_url}";
		}

		return $output . "\n" . implode( "\n", $sitemap_lines ) . "\n";
	}
}
