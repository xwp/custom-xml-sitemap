<?php
/**
 * Sitemap CPT test case.
 *
 * Tests the custom sitemap post type configuration methods and query behavior.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;

/**
 * Sitemap CPT test class.
 *
 * Tests for the Sitemap_CPT class functionality including configuration
 * retrieval and query methods.
 */
class Test_Sitemap_CPT extends WP_UnitTestCase {

	/**
	 * Test get_sitemap_config returns sensible defaults when no meta exists.
	 *
	 * Ensures new sitemaps have working defaults without requiring explicit configuration.
	 *
	 * @return void
	 */
	public function test_get_sitemap_config_returns_defaults_for_empty_meta(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Sitemap',
			]
		);

		$config = Sitemap_CPT::get_sitemap_config( $post_id );

		$this->assertSame( 'post', $config['post_type'], 'Default post type should be "post"' );
		$this->assertSame( 'month', $config['granularity'], 'Default granularity should be "month"' );
		$this->assertSame( '', $config['taxonomy'], 'Default taxonomy should be empty' );
		$this->assertSame( [], $config['terms'], 'Default terms should be empty array' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test get_sitemap_config returns saved meta values correctly.
	 *
	 * Verifies configuration is properly retrieved from post meta.
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

		update_post_meta( $post_id, Sitemap_CPT::META_KEY_POST_TYPE, 'page' );
		update_post_meta( $post_id, Sitemap_CPT::META_KEY_GRANULARITY, 'day' );
		update_post_meta( $post_id, Sitemap_CPT::META_KEY_TAXONOMY, 'category' );
		update_post_meta( $post_id, Sitemap_CPT::META_KEY_TAXONOMY_TERMS, [ 5, 10, 15 ] );

		$config = Sitemap_CPT::get_sitemap_config( $post_id );

		$this->assertSame( 'page', $config['post_type'] );
		$this->assertSame( 'day', $config['granularity'] );
		$this->assertSame( 'category', $config['taxonomy'] );
		$this->assertSame( [ 5, 10, 15 ], $config['terms'] );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test get_sitemap_by_slug returns the correct published post.
	 *
	 * @return void
	 */
	public function test_get_sitemap_by_slug_returns_published_post(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'News Sitemap',
				'post_name'   => 'news-sitemap',
			]
		);

		$result = Sitemap_CPT::get_sitemap_by_slug( 'news-sitemap' );

		$this->assertInstanceOf( 'WP_Post', $result );
		$this->assertSame( $post_id, $result->ID );
		$this->assertSame( 'news-sitemap', $result->post_name );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test get_sitemap_by_slug returns null for non-existent slug.
	 *
	 * @return void
	 */
	public function test_get_sitemap_by_slug_returns_null_for_nonexistent(): void {
		$result = Sitemap_CPT::get_sitemap_by_slug( 'nonexistent-sitemap' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_sitemap_by_slug only returns published sitemaps.
	 *
	 * Ensures draft, pending, and private sitemaps are not accessible.
	 *
	 * @dataProvider non_published_statuses_provider
	 *
	 * @param string $status Post status to test.
	 * @return void
	 */
	public function test_get_sitemap_by_slug_ignores_non_published_statuses( string $status ): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => $status,
				'post_title'  => 'Non-Published Sitemap',
				'post_name'   => 'non-published-sitemap',
			]
		);

		$result = Sitemap_CPT::get_sitemap_by_slug( 'non-published-sitemap' );

		$this->assertNull( $result, "Sitemap with status '{$status}' should not be returned" );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Data provider for non-published post statuses.
	 *
	 * @return array<string, array{status: string}> Test data.
	 */
	public static function non_published_statuses_provider(): array {
		return [
			'draft'   => [ 'status' => 'draft' ],
			'pending' => [ 'status' => 'pending' ],
			'private' => [ 'status' => 'private' ],
			'trash'   => [ 'status' => 'trash' ],
		];
	}

	/**
	 * Test get_all_sitemap_configs returns only published sitemaps.
	 *
	 * @return void
	 */
	public function test_get_all_sitemap_configs_excludes_drafts(): void {
		// Clear any existing cache.
		Sitemap_CPT::clear_sitemap_configs_cache();

		$published_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Published Sitemap',
			]
		);

		$draft_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Draft Sitemap',
			]
		);

		// Clear cache to ensure fresh query.
		Sitemap_CPT::clear_sitemap_configs_cache();

		$configs = Sitemap_CPT::get_all_sitemap_configs();

		$returned_ids = array_map(
			fn( $item ) => $item['post']->ID,
			$configs
		);

		$this->assertContains( $published_id, $returned_ids, 'Published sitemap should be returned' );
		$this->assertNotContains( $draft_id, $returned_ids, 'Draft sitemap should not be returned' );

		wp_delete_post( $published_id, true );
		wp_delete_post( $draft_id, true );
	}

	/**
	 * Test get_configs_for_post_type filters sitemaps correctly.
	 *
	 * @return void
	 */
	public function test_get_configs_for_post_type_filters_by_post_type(): void {
		Sitemap_CPT::clear_sitemap_configs_cache();

		$post_sitemap_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Posts Sitemap',
			]
		);
		update_post_meta( $post_sitemap_id, Sitemap_CPT::META_KEY_POST_TYPE, 'post' );

		$page_sitemap_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Pages Sitemap',
			]
		);
		update_post_meta( $page_sitemap_id, Sitemap_CPT::META_KEY_POST_TYPE, 'page' );

		// Clear cache to ensure fresh data with meta.
		Sitemap_CPT::clear_sitemap_configs_cache();

		$post_configs = Sitemap_CPT::get_configs_for_post_type( 'post' );
		$page_configs = Sitemap_CPT::get_configs_for_post_type( 'page' );

		$this->assertCount( 1, $post_configs );
		$this->assertSame( $post_sitemap_id, $post_configs[0]['post']->ID );

		$this->assertCount( 1, $page_configs );
		$this->assertSame( $page_sitemap_id, $page_configs[0]['post']->ID );

		wp_delete_post( $post_sitemap_id, true );
		wp_delete_post( $page_sitemap_id, true );
	}

	/**
	 * Test get_configs_for_post_type returns empty array when no matches.
	 *
	 * @return void
	 */
	public function test_get_configs_for_post_type_returns_empty_for_no_matches(): void {
		Sitemap_CPT::clear_sitemap_configs_cache();

		$post_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Posts Sitemap',
			]
		);
		update_post_meta( $post_id, Sitemap_CPT::META_KEY_POST_TYPE, 'post' );

		Sitemap_CPT::clear_sitemap_configs_cache();

		$result = Sitemap_CPT::get_configs_for_post_type( 'product' );

		$this->assertSame( [], $result );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test config includes correct structure with post and config keys.
	 *
	 * @return void
	 */
	public function test_get_all_sitemap_configs_returns_correct_structure(): void {
		Sitemap_CPT::clear_sitemap_configs_cache();

		$post_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Structure Test Sitemap',
			]
		);

		Sitemap_CPT::clear_sitemap_configs_cache();

		$configs = Sitemap_CPT::get_all_sitemap_configs();

		$this->assertNotEmpty( $configs );

		$first_config = $configs[0];

		$this->assertArrayHasKey( 'post', $first_config );
		$this->assertArrayHasKey( 'config', $first_config );
		$this->assertInstanceOf( 'WP_Post', $first_config['post'] );
		$this->assertIsArray( $first_config['config'] );
		$this->assertArrayHasKey( 'post_type', $first_config['config'] );
		$this->assertArrayHasKey( 'granularity', $first_config['config'] );
		$this->assertArrayHasKey( 'taxonomy', $first_config['config'] );
		$this->assertArrayHasKey( 'terms', $first_config['config'] );

		wp_delete_post( $post_id, true );
	}
}
