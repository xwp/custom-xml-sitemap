<?php
/**
 * Integration tests for the paginated terms sitemap rewrite rule.
 *
 * Pins /sitemaps/{slug}/page-{n}.xml so that the rewrite rule resolves to the
 * correct query vars and the QUERY_VAR_PAGE is registered alongside the rest
 * of the routing surface.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Router;

/**
 * Verifies routing for paginated terms sitemap URLs.
 */
class Test_Page_Rewrite extends WP_UnitTestCase {

	/**
	 * Sitemap post ID for tests.
	 *
	 * @var int
	 */
	private int $sitemap_id;

	/**
	 * Set up a published sitemap post and refresh rewrite rules so the
	 * /sitemaps/{slug}/page-{n}.xml rule is matchable in this test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// The WP test suite defaults to plain permalinks, which disables
		// rewrite rule resolution. Switch to a pretty structure and refresh
		// rules so the plugin's /sitemaps/... rules are matchable.
		$this->set_permalink_structure( '/%postname%/' );

		$this->sitemap_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Page Rewrite Sitemap',
				'post_name'   => 'page-rewrite-sitemap',
			]
		);

		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_SITEMAP_MODE, Sitemap_CPT::SITEMAP_MODE_TERMS );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY, 'category' );

		( new Sitemap_Router() )->register_rewrite_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Tear down the sitemap post.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_delete_post( $this->sitemap_id, true );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The page query var is registered so WP_Query can read it.
	 *
	 * @return void
	 */
	public function test_page_query_var_is_registered(): void {
		$router = new Sitemap_Router();
		$vars   = $router->register_query_vars( [] );

		$this->assertContains( Sitemap_Router::QUERY_VAR_PAGE, $vars );
	}

	/**
	 * /sitemaps/{slug}/page-{n}.xml resolves into the correct query vars.
	 *
	 * @return void
	 */
	public function test_page_url_resolves_to_query_vars(): void {
		global $wp_rewrite;
		$rules = $wp_rewrite->wp_rewrite_rules();
		$this->assertArrayHasKey( '^sitemaps/([a-z0-9-]+)/page-([0-9]+)\.xml$', $rules );

		$this->go_to( home_url( '/sitemaps/page-rewrite-sitemap/page-2.xml' ) );

		$this->assertSame( 'page-rewrite-sitemap', get_query_var( Sitemap_Router::QUERY_VAR_SITEMAP ) );
		$this->assertSame( '2', (string) get_query_var( Sitemap_Router::QUERY_VAR_PAGE ) );
		$this->assertEmpty( get_query_var( Sitemap_Router::QUERY_VAR_YEAR ) );
		$this->assertEmpty( get_query_var( Sitemap_Router::QUERY_VAR_MONTH ) );
		$this->assertEmpty( get_query_var( Sitemap_Router::QUERY_VAR_DAY ) );
	}

	/**
	 * /sitemaps/{slug}/index.xml does not set the page query var.
	 *
	 * @return void
	 */
	public function test_index_url_does_not_set_page_var(): void {
		$this->go_to( home_url( '/sitemaps/page-rewrite-sitemap/index.xml' ) );

		$this->assertSame( 'page-rewrite-sitemap', get_query_var( Sitemap_Router::QUERY_VAR_SITEMAP ) );
		$this->assertEmpty( get_query_var( Sitemap_Router::QUERY_VAR_PAGE ) );
	}
}
