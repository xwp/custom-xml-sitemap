<?php
/**
 * Terms Sitemap Generator test case.
 *
 * Tests XML generation, caching behavior, and pagination
 * for the Terms_Sitemap_Generator class.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Terms_Sitemap_Generator;

/**
 * Terms Sitemap Generator test class.
 *
 * Integration tests for taxonomy term archive URL sitemap generation.
 */
class Test_Terms_Sitemap_Generator extends WP_UnitTestCase {

	/**
	 * Sitemap post ID for tests.
	 *
	 * @var int
	 */
	private int $sitemap_id;

	/**
	 * Set up test fixtures.
	 *
	 * Creates a sitemap CPT configured for terms mode.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Create a sitemap post with terms mode.
		$this->sitemap_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Terms Sitemap',
				'post_name'   => 'test-terms-sitemap',
			]
		);

		// Set default configuration for terms mode.
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_SITEMAP_MODE, Sitemap_CPT::SITEMAP_MODE_TERMS );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY, 'category' );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TERMS_HIDE_EMPTY, '1' );
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_delete_post( $this->sitemap_id, true );
		parent::tear_down();
	}

	/**
	 * Helper: Create a category term with an associated published post.
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy slug (default: category).
	 * @return int Term ID.
	 */
	private function create_term_with_post( string $name, string $taxonomy = 'category' ): int {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => $taxonomy,
				'name'     => $name,
			]
		);
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_post_terms( $post_id, [ $term_id ], $taxonomy );

		return $term_id;
	}

	/**
	 * Test get_index returns valid XML structure with XSL stylesheet.
	 *
	 * @return void
	 */
	public function test_get_index_returns_valid_xml(): void {
		$this->create_term_with_post( 'Test Category' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$xml       = $generator->get_index();

		$this->assertStringContainsString( '<?xml version="1.0" encoding="UTF-8"?>', $xml );
		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringContainsString( '</urlset>', $xml );
		$this->assertStringContainsString( 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml );
		$this->assertStringContainsString( 'xml-stylesheet', $xml );
		$this->assertStringContainsString( 'cxs-stylesheet.xsl', $xml );
	}

	/**
	 * Test get_index includes term archive URLs.
	 *
	 * @return void
	 */
	public function test_get_index_includes_term_urls(): void {
		$term_1_id = $this->create_term_with_post( 'Gaming' );
		$term_2_id = $this->create_term_with_post( 'Tech' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$xml       = $generator->get_index( true );

		$this->assertStringContainsString( '<url>', $xml );
		$this->assertStringContainsString( '<loc>', $xml );
		$this->assertStringContainsString( get_term_link( $term_1_id, 'category' ), $xml );
		$this->assertStringContainsString( get_term_link( $term_2_id, 'category' ), $xml );
		// Terms sitemaps should not include lastmod.
		$this->assertStringNotContainsString( '<lastmod>', $xml );
	}

	/**
	 * Test empty sitemap returns valid XML structure.
	 *
	 * @return void
	 */
	public function test_empty_sitemap_returns_valid_xml(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY, 'post_tag' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$xml       = $generator->get_index( true );

		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringContainsString( '</urlset>', $xml );
		$this->assertStringNotContainsString( '<url>', $xml );
	}

	/**
	 * Test hide_empty setting filters terms correctly.
	 *
	 * @return void
	 */
	public function test_hide_empty_filters_terms(): void {
		$term_with_posts = $this->create_term_with_post( 'Has Posts' );
		$term_without    = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'No Posts',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$xml       = $generator->get_index( true );

		// hide_empty = true (default): only term with posts.
		$this->assertStringContainsString( get_term_link( $term_with_posts, 'category' ), $xml );
		$this->assertStringNotContainsString( get_term_link( $term_without, 'category' ), $xml );

		// hide_empty = false: both terms.
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TERMS_HIDE_EMPTY, '0' );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$xml       = $generator->get_index( true );

		$this->assertStringContainsString( get_term_link( $term_with_posts, 'category' ), $xml );
		$this->assertStringContainsString( get_term_link( $term_without, 'category' ), $xml );
	}

	/**
	 * Data provider for invalid taxonomy tests.
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public function data_invalid_taxonomy(): array {
		return [
			'nonexistent_taxonomy' => [ 'nonexistent_taxonomy' ],
			'empty_string'         => [ '' ],
		];
	}

	/**
	 * Test methods return zero/empty for invalid taxonomy.
	 *
	 * @dataProvider data_invalid_taxonomy
	 *
	 * @param string $taxonomy Invalid taxonomy slug.
	 * @return void
	 */
	public function test_invalid_taxonomy_returns_empty( string $taxonomy ): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY, $taxonomy );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );

		$this->assertSame( 0, $generator->get_terms_count() );
		$this->assertSame( [], $generator->get_terms_for_page( 1 ) );
		$this->assertSame( 1, $generator->get_page_count() ); // Minimum 1 page.
	}

	/**
	 * Test XML caching stores and retrieves from post meta.
	 *
	 * @return void
	 */
	public function test_xml_caching(): void {
		$this->create_term_with_post( 'Test Category' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );

		// First call generates and caches.
		$xml1 = $generator->get_index();
		$this->assertSame( $xml1, get_post_meta( $this->sitemap_id, Terms_Sitemap_Generator::META_KEY_INDEX_XML, true ) );

		// Second call returns cached version.
		$this->assertSame( $xml1, $generator->get_index() );

		// Modify cache directly.
		update_post_meta( $this->sitemap_id, Terms_Sitemap_Generator::META_KEY_INDEX_XML, 'modified' );
		$this->assertSame( 'modified', $generator->get_index() );

		// force_regenerate bypasses cache.
		$this->assertSame( $xml1, $generator->get_index( true ) );
	}

	/**
	 * Test regenerate_all clears and rebuilds cache.
	 *
	 * @return void
	 */
	public function test_regenerate_all(): void {
		$this->create_term_with_post( 'Test Category' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$summary   = $generator->regenerate_all();

		$this->assertTrue( $summary['index'] );
		$this->assertIsArray( $summary['pages'] );
	}

	/**
	 * Test clear_all_cached_xml removes all cache entries.
	 *
	 * @return void
	 */
	public function test_clear_all_cached_xml(): void {
		$this->create_term_with_post( 'Test Category' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$generator->get_index();

		$this->assertNotEmpty( get_post_meta( $this->sitemap_id, Terms_Sitemap_Generator::META_KEY_INDEX_XML, true ) );

		$generator->clear_all_cached_xml();

		$this->assertEmpty( get_post_meta( $this->sitemap_id, Terms_Sitemap_Generator::META_KEY_INDEX_XML, true ) );
	}

	/**
	 * Test terms are ordered by name ascending.
	 *
	 * @return void
	 */
	public function test_terms_ordered_by_name(): void {
		$zebra = $this->create_term_with_post( 'Zebra' );
		$apple = $this->create_term_with_post( 'Apple' );
		$mango = $this->create_term_with_post( 'Mango' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$xml       = $generator->get_index( true );

		$apple_pos = strpos( $xml, get_term_link( $apple, 'category' ) );
		$mango_pos = strpos( $xml, get_term_link( $mango, 'category' ) );
		$zebra_pos = strpos( $xml, get_term_link( $zebra, 'category' ) );

		$this->assertLessThan( $mango_pos, $apple_pos );
		$this->assertLessThan( $zebra_pos, $mango_pos );
	}

	/**
	 * Test get_page returns valid XML and caches correctly.
	 *
	 * @return void
	 */
	public function test_get_page(): void {
		$this->create_term_with_post( 'Test Category' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$xml       = $generator->get_page( 1 );

		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringContainsString( '<url>', $xml );

		// Verify caching.
		$meta_key = Terms_Sitemap_Generator::META_KEY_PAGE_XML_PREFIX . '1';
		$this->assertSame( $xml, get_post_meta( $this->sitemap_id, $meta_key, true ) );
	}

	/**
	 * Test get_page returns empty urlset for invalid page numbers.
	 *
	 * @return void
	 */
	public function test_get_page_returns_empty_for_invalid_page(): void {
		$this->create_term_with_post( 'Test Category' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );

		// Page 0 is invalid (pages are 1-based).
		$xml = $generator->get_page( 0 );
		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringNotContainsString( '<url>', $xml );

		// Page 999 is beyond the actual page count.
		$xml = $generator->get_page( 999 );
		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringNotContainsString( '<url>', $xml );

		// Negative page is invalid.
		$xml = $generator->get_page( -1 );
		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringNotContainsString( '<url>', $xml );
	}

	/**
	 * Test sitemap stores term count in meta during generation.
	 *
	 * @return void
	 */
	public function test_sitemap_stores_term_count_in_meta(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TERMS_HIDE_EMPTY, '0' );
		self::factory()->term->create_many( 2, [ 'taxonomy' => 'category' ] );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$generator->get_index( true );

		$term_count = get_post_meta( $this->sitemap_id, Terms_Sitemap_Generator::META_KEY_TERM_COUNT, true );
		$this->assertGreaterThanOrEqual( 2, (int) $term_count );
	}

	/**
	 * Data provider for taxonomy configuration tests.
	 *
	 * @return array<string, array{string}> Test cases for different taxonomies.
	 */
	public function data_taxonomy_configurations(): array {
		return [
			'category_taxonomy' => [ 'category' ],
			'post_tag_taxonomy' => [ 'post_tag' ],
		];
	}

	/**
	 * Test sitemap works with different taxonomies.
	 *
	 * @dataProvider data_taxonomy_configurations
	 *
	 * @param string $taxonomy Taxonomy to test.
	 * @return void
	 */
	public function test_sitemap_with_different_taxonomies( string $taxonomy ): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY, $taxonomy );

		$term_id = $this->create_term_with_post( 'Test Term', $taxonomy );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Terms_Sitemap_Generator( $sitemap );
		$xml       = $generator->get_index( true );

		$this->assertSame( $taxonomy, $generator->get_taxonomy() );
		$this->assertStringContainsString( '<url>', $xml );
		$this->assertStringContainsString( esc_url( get_term_link( $term_id, $taxonomy ) ), $xml );
	}

}
