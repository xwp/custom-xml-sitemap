<?php
/**
 * Integration tests for filter_mode (include/exclude).
 *
 * Verifies that posts are correctly included or excluded based on the
 * configured term filter mode.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Generator;

/**
 * Integration tests for the filter_mode setting.
 */
class Test_Filter_Mode extends WP_UnitTestCase {

	/**
	 * Sitemap CPT post ID.
	 *
	 * @var int
	 */
	private int $sitemap_id;

	/**
	 * IDs of categories created in setUp().
	 *
	 * @var array<string, int>
	 */
	private array $categories = [];

	/**
	 * IDs of posts created in setUp().
	 *
	 * @var array<string, int>
	 */
	private array $posts = [];

	/**
	 * Build a fixture: two categories ('news' and 'sponsored') and three posts:
	 * - news_post: only in 'news'
	 * - sponsored_post: only in 'sponsored'
	 * - both_post: in both
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->categories['news']      = self::factory()->category->create( [ 'name' => 'News' ] );
		$this->categories['sponsored'] = self::factory()->category->create( [ 'name' => 'Sponsored' ] );

		$this->posts['news_post'] = self::factory()->post->create(
			[
				'post_title'    => 'News Post',
				'post_status'   => 'publish',
				'post_category' => [ $this->categories['news'] ],
				'post_date'     => '2024-03-15 10:00:00',
			]
		);

		$this->posts['sponsored_post'] = self::factory()->post->create(
			[
				'post_title'    => 'Sponsored Post',
				'post_status'   => 'publish',
				'post_category' => [ $this->categories['sponsored'] ],
				'post_date'     => '2024-03-16 10:00:00',
			]
		);

		$this->posts['both_post'] = self::factory()->post->create(
			[
				'post_title'    => 'Both Categories Post',
				'post_status'   => 'publish',
				'post_category' => [
					$this->categories['news'],
					$this->categories['sponsored'],
				],
				'post_date'     => '2024-03-17 10:00:00',
			]
		);

		$this->sitemap_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Filter Mode Test',
				'post_name'   => 'filter-mode-test',
			]
		);

		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_POST_TYPE, 'post' );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, Sitemap_CPT::GRANULARITY_MONTH );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY, 'category' );
		update_post_meta(
			$this->sitemap_id,
			Sitemap_CPT::META_KEY_TAXONOMY_TERMS,
			[ $this->categories['sponsored'] ]
		);
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->categories as $id ) {
			wp_delete_term( $id, 'category' );
		}
		wp_delete_post( $this->sitemap_id, true );

		parent::tear_down();
	}

	/**
	 * Include mode lists only the posts that have the selected term.
	 *
	 * @return void
	 */
	public function test_include_mode_lists_only_posts_with_selected_terms(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_FILTER_MODE, Sitemap_CPT::FILTER_MODE_INCLUDE );

		$xml = $this->generate_month_xml();

		$this->assertStringContainsString( '?p=' . $this->posts['sponsored_post'], $this->normalise_links( $xml ) );
		$this->assertStringContainsString( '?p=' . $this->posts['both_post'], $this->normalise_links( $xml ) );
		$this->assertStringNotContainsString( '?p=' . $this->posts['news_post'], $this->normalise_links( $xml ) );
	}

	/**
	 * Exclude mode lists posts that do NOT have the selected term, including
	 * posts with no terms in the taxonomy. Posts that have ANY excluded term
	 * are dropped even if they also have other terms.
	 *
	 * @return void
	 */
	public function test_exclude_mode_omits_posts_with_selected_terms(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_FILTER_MODE, Sitemap_CPT::FILTER_MODE_EXCLUDE );

		$xml = $this->generate_month_xml();

		$this->assertStringContainsString( '?p=' . $this->posts['news_post'], $this->normalise_links( $xml ) );
		$this->assertStringNotContainsString( '?p=' . $this->posts['sponsored_post'], $this->normalise_links( $xml ) );
		$this->assertStringNotContainsString( '?p=' . $this->posts['both_post'], $this->normalise_links( $xml ) );
	}

	/**
	 * Generate the month sitemap covering the fixture posts.
	 *
	 * @return string XML.
	 */
	private function generate_month_xml(): string {
		$post = get_post( $this->sitemap_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$generator = new Sitemap_Generator( $post );

		return $generator->get_month_sitemap( 2024, 3, true );
	}

	/**
	 * The post permalinks in the sitemap may be plain or pretty depending on
	 * permalink settings. For assertions, normalise to '?p=ID'.
	 *
	 * @param string $xml Generated XML.
	 * @return string Normalised XML where each <loc> contains '?p=ID'.
	 */
	private function normalise_links( string $xml ): string {
		// Replace pretty permalinks with the equivalent ?p=ID form.
		return preg_replace_callback(
			'#<loc>([^<]+)</loc>#',
			static function ( $match ): string {
				$post_id = url_to_postid( $match[1] );
				if ( $post_id > 0 ) {
					return '<loc>?p=' . $post_id . '</loc>';
				}
				return $match[0];
			},
			$xml
		) ?? $xml;
	}
}
