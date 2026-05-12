<?php
/**
 * Plugin test case.
 *
 * Tests core plugin initialization and WordPress integration.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Router;

/**
 * Plugin test class.
 *
 * Tests that the plugin integrates correctly with WordPress.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Test that CPT is registered with correct configuration.
	 *
	 * Verifies the custom sitemap post type is registered in WordPress
	 * with the expected settings for admin visibility.
	 *
	 * @return void
	 */
	public function test_cpt_is_registered_with_correct_settings(): void {
		$this->assertTrue(
			post_type_exists( Sitemap_CPT::POST_TYPE ),
			'Custom sitemap post type should be registered'
		);

		$post_type_obj = get_post_type_object( Sitemap_CPT::POST_TYPE );

		$this->assertFalse( $post_type_obj->public, 'CPT should not be public' );
		$this->assertTrue( $post_type_obj->show_ui, 'CPT should show in admin UI' );
		$this->assertTrue( $post_type_obj->show_in_rest, 'CPT should be available in REST API' );
	}

	/**
	 * Test that sitemap query vars are registered via the filter.
	 *
	 * Verifies the plugin registers its custom query variables.
	 *
	 * @return void
	 */
	public function test_sitemap_query_vars_are_registered(): void {
		$router = new Sitemap_Router();

		// Simulate the filter being applied.
		$vars = $router->register_query_vars( [] );

		$this->assertContains( Sitemap_Router::QUERY_VAR_SITEMAP, $vars );
		$this->assertContains( Sitemap_Router::QUERY_VAR_YEAR, $vars );
		$this->assertContains( Sitemap_Router::QUERY_VAR_MONTH, $vars );
		$this->assertContains( Sitemap_Router::QUERY_VAR_DAY, $vars );
		$this->assertContains( Sitemap_Router::QUERY_VAR_XSL, $vars );
	}

	/**
	 * Test that existing query vars are preserved when adding sitemap vars.
	 *
	 * @return void
	 */
	public function test_query_vars_filter_preserves_existing_vars(): void {
		$router        = new Sitemap_Router();
		$existing_vars = [ 'foo', 'bar', 'baz' ];

		$result = $router->register_query_vars( $existing_vars );

		// Existing vars should still be present.
		$this->assertContains( 'foo', $result );
		$this->assertContains( 'bar', $result );
		$this->assertContains( 'baz', $result );

		// New vars should also be present.
		$this->assertContains( Sitemap_Router::QUERY_VAR_SITEMAP, $result );
	}
}
