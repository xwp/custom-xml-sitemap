<?php
/**
 * Sitemap CPT test case.
 *
 * Tests the custom sitemap post type registration and configuration methods.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;

/**
 * Sitemap CPT test class.
 *
 * Tests for the Sitemap_CPT class functionality.
 */
class Test_Sitemap_CPT extends WP_UnitTestCase {

	/**
	 * Test CPT constants are defined.
	 *
	 * Verifies all expected class constants are available.
	 *
	 * @return void
	 */
	public function test_cpt_constants_are_defined(): void {
		$this->assertSame( 'cxs_sitemap', Sitemap_CPT::POST_TYPE );
		$this->assertSame( 'cxs_post_type', Sitemap_CPT::META_KEY_POST_TYPE );
		$this->assertSame( 'cxs_granularity', Sitemap_CPT::META_KEY_GRANULARITY );
		$this->assertSame( 'cxs_taxonomy', Sitemap_CPT::META_KEY_TAXONOMY );
		$this->assertSame( 'cxs_taxonomy_terms', Sitemap_CPT::META_KEY_TAXONOMY_TERMS );
	}

	/**
	 * Test granularity constants.
	 *
	 * Verifies granularity options are defined correctly.
	 *
	 * @return void
	 */
	public function test_granularity_constants(): void {
		$this->assertSame( 'year', Sitemap_CPT::GRANULARITY_YEAR );
		$this->assertSame( 'month', Sitemap_CPT::GRANULARITY_MONTH );
		$this->assertSame( 'day', Sitemap_CPT::GRANULARITY_DAY );
	}

	/**
	 * Test get_sitemap_config returns default values.
	 *
	 * When no meta exists, should return sensible defaults.
	 *
	 * @return void
	 */
	public function test_get_sitemap_config_returns_defaults(): void {
		// Create a sitemap post without any meta.
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Sitemap',
			]
		);

		$config = Sitemap_CPT::get_sitemap_config( $post_id );

		$this->assertSame( 'post', $config['post_type'] );
		$this->assertSame( 'month', $config['granularity'] );
		$this->assertSame( '', $config['taxonomy'] );
		$this->assertSame( [], $config['terms'] );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test get_sitemap_config returns saved values.
	 *
	 * @return void
	 */
	public function test_get_sitemap_config_returns_saved_values(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Sitemap',
			]
		);

		// Set meta values.
		update_post_meta( $post_id, Sitemap_CPT::META_KEY_POST_TYPE, 'page' );
		update_post_meta( $post_id, Sitemap_CPT::META_KEY_GRANULARITY, 'year' );
		update_post_meta( $post_id, Sitemap_CPT::META_KEY_TAXONOMY, 'category' );
		update_post_meta( $post_id, Sitemap_CPT::META_KEY_TAXONOMY_TERMS, [ 1, 2, 3 ] );

		$config = Sitemap_CPT::get_sitemap_config( $post_id );

		$this->assertSame( 'page', $config['post_type'] );
		$this->assertSame( 'year', $config['granularity'] );
		$this->assertSame( 'category', $config['taxonomy'] );
		$this->assertSame( [ 1, 2, 3 ], $config['terms'] );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test get_sitemap_by_slug returns post.
	 *
	 * @return void
	 */
	public function test_get_sitemap_by_slug_returns_post(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Sitemap',
				'post_name'   => 'test-sitemap',
			]
		);

		$result = Sitemap_CPT::get_sitemap_by_slug( 'test-sitemap' );

		$this->assertInstanceOf( 'WP_Post', $result );
		$this->assertSame( $post_id, $result->ID );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test get_sitemap_by_slug returns null for non-existent slug.
	 *
	 * @return void
	 */
	public function test_get_sitemap_by_slug_returns_null_for_nonexistent(): void {
		$result = Sitemap_CPT::get_sitemap_by_slug( 'nonexistent-slug' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_sitemap_by_slug ignores draft posts.
	 *
	 * @return void
	 */
	public function test_get_sitemap_by_slug_ignores_drafts(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Draft Sitemap',
				'post_name'   => 'draft-sitemap',
			]
		);

		$result = Sitemap_CPT::get_sitemap_by_slug( 'draft-sitemap' );

		$this->assertNull( $result );

		// Clean up.
		wp_delete_post( $post_id, true );
	}
}
