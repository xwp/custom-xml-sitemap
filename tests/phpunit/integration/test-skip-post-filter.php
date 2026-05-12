<?php
/**
 * Integration tests for the cxs_sitemap_skip_post filter.
 *
 * Generates a real urlset against a wp-phpunit fixture, hooks the filter to
 * skip a known post, and asserts the resulting XML omits its permalink while
 * still emitting other posts.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Generator;

/**
 * Integration tests for the cxs_sitemap_skip_post filter chokepoint.
 */
class Test_Skip_Post_Filter extends WP_UnitTestCase {

	/**
	 * Sitemap post ID for tests.
	 *
	 * @var int
	 */
	private int $sitemap_id;

	/**
	 * Set up the sitemap post and force year granularity for a single urlset.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->sitemap_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Skip Filter Sitemap',
				'post_name'   => 'skip-filter-sitemap',
			]
		);

		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'year' );
	}

	/**
	 * Tear down the sitemap post and remove any registered filters.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'cxs_sitemap_skip_post' );
		wp_delete_post( $this->sitemap_id, true );

		parent::tear_down();
	}

	/**
	 * Filter handlers that skip a specific post ID drop it from the urlset
	 * while leaving other posts intact.
	 *
	 * @return void
	 */
	public function test_filter_omits_skipped_post_from_urlset(): void {
		$kept_id    = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Kept Post',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);
		$skipped_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Skipped Post',
				'post_date'   => '2024-06-20 10:00:00',
			]
		);

		add_filter(
			'cxs_sitemap_skip_post',
			static function ( bool $skip, int $post_id ) use ( $skipped_id ): bool {
				return $post_id === $skipped_id ? true : $skip;
			},
			10,
			2
		);

		$generator = new Sitemap_Generator( get_post( $this->sitemap_id ) );
		$xml       = $generator->get_year_sitemap( 2024, true );

		$this->assertStringContainsString( get_permalink( $kept_id ), $xml );
		$this->assertStringNotContainsString( get_permalink( $skipped_id ), $xml );
	}

	/**
	 * Without any filter handlers, every published post still appears in
	 * the urlset (filter defaults to false).
	 *
	 * @return void
	 */
	public function test_filter_default_includes_all_posts(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Default Behaviour Post',
				'post_date'   => '2024-07-01 10:00:00',
			]
		);

		$generator = new Sitemap_Generator( get_post( $this->sitemap_id ) );
		$xml       = $generator->get_year_sitemap( 2024, true );

		$this->assertStringContainsString( get_permalink( $post_id ), $xml );
	}
}
