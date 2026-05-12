<?php
/**
 * Sitemap Generator test case.
 *
 * Tests XML generation, caching behavior, and taxonomy filtering
 * for the Sitemap_Generator class.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Generator;

/**
 * Sitemap Generator test class.
 *
 * Integration tests for XML sitemap generation including content querying,
 * XML structure validation, caching, and taxonomy-based filtering.
 */
class Test_Sitemap_Generator extends WP_UnitTestCase {

	/**
	 * Sitemap post ID for tests.
	 *
	 * @var int
	 */
	private int $sitemap_id;

	/**
	 * Test category term ID.
	 *
	 * @var int
	 */
	private int $category_id;

	/**
	 * Set up test fixtures.
	 *
	 * Creates a sitemap CPT and test category for use in tests.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Create a sitemap post.
		$this->sitemap_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Sitemap',
				'post_name'   => 'test-sitemap',
			]
		);

		// Create a test category.
		$this->category_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Test Category',
			]
		);
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_delete_post( $this->sitemap_id, true );
		wp_delete_term( $this->category_id, 'category' );

		parent::tear_down();
	}

	/**
	 * Test generator returns correct post type from config.
	 *
	 * @return void
	 */
	public function test_get_post_type_returns_configured_value(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_POST_TYPE, 'page' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$this->assertSame( 'page', $generator->get_post_type() );
	}

	/**
	 * Test generator returns default post type when not configured.
	 *
	 * @return void
	 */
	public function test_get_post_type_returns_default_when_empty(): void {
		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$this->assertSame( 'post', $generator->get_post_type() );
	}

	/**
	 * Test generator returns correct granularity from config.
	 *
	 * @return void
	 */
	public function test_get_granularity_returns_configured_value(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'day' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$this->assertSame( 'day', $generator->get_granularity() );
	}

	/**
	 * Test generator returns default granularity when not configured.
	 *
	 * @return void
	 */
	public function test_get_granularity_returns_default_when_empty(): void {
		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$this->assertSame( 'month', $generator->get_granularity() );
	}

	/**
	 * Test index XML is valid and contains sitemapindex element.
	 *
	 * @return void
	 */
	public function test_get_index_returns_valid_xml(): void {
		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_index();

		$this->assertStringContainsString( '<?xml version="1.0"', $xml );
		$this->assertStringContainsString( '<sitemapindex', $xml );
		$this->assertStringContainsString( '</sitemapindex>', $xml );
		$this->assertStringContainsString( 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml );
	}

	/**
	 * Test index XML includes XSL stylesheet reference.
	 *
	 * @return void
	 */
	public function test_get_index_includes_xsl_stylesheet(): void {
		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_index();

		$this->assertStringContainsString( '<?xml-stylesheet', $xml );
		$this->assertStringContainsString( 'cxs-sitemap-index.xsl', $xml );
	}

	/**
	 * Test index XML is cached in post meta.
	 *
	 * @return void
	 */
	public function test_get_index_caches_result(): void {
		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		// Generate index.
		$generator->get_index();

		// Verify cached in meta.
		$cached = get_post_meta( $this->sitemap_id, Sitemap_Generator::META_KEY_INDEX_XML, true );

		$this->assertNotEmpty( $cached );
		$this->assertStringContainsString( '<sitemapindex', $cached );
	}

	/**
	 * Test force regenerate bypasses cache.
	 *
	 * @return void
	 */
	public function test_get_index_force_regenerate_bypasses_cache(): void {
		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		// Set fake cached value.
		update_post_meta( $this->sitemap_id, Sitemap_Generator::META_KEY_INDEX_XML, 'cached-xml' );

		// Without force, returns cached.
		$cached_result = $generator->get_index( false );
		$this->assertSame( 'cached-xml', $cached_result );

		// With force, regenerates.
		$fresh_result = $generator->get_index( true );
		$this->assertStringContainsString( '<sitemapindex', $fresh_result );
		$this->assertNotSame( 'cached-xml', $fresh_result );
	}

	/**
	 * Test index contains year sitemaps when posts exist.
	 *
	 * @return void
	 */
	public function test_get_index_includes_years_with_content(): void {
		// Create posts in different years.
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2023-03-10 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_index( true );

		$this->assertStringContainsString( '/sitemaps/test-sitemap/2024.xml', $xml );
		$this->assertStringContainsString( '/sitemaps/test-sitemap/2023.xml', $xml );
	}

	/**
	 * Test year sitemap with month granularity returns sitemap index.
	 *
	 * @return void
	 */
	public function test_year_sitemap_with_month_granularity_returns_index(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'month' );

		// Create posts in different months.
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2024-03-10 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_year_sitemap( 2024, true );

		// Should be sitemap index with month references.
		$this->assertStringContainsString( '<sitemapindex', $xml );
		$this->assertStringContainsString( '/2024-06.xml', $xml );
		$this->assertStringContainsString( '/2024-03.xml', $xml );
	}

	/**
	 * Test year sitemap with year granularity returns urlset.
	 *
	 * @return void
	 */
	public function test_year_sitemap_with_year_granularity_returns_urlset(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'year' );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test Post 2024',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_year_sitemap( 2024, true );

		// Should be urlset with post URLs.
		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringContainsString( '<url>', $xml );
		$this->assertStringContainsString( '<loc>', $xml );
		$this->assertStringContainsString( get_permalink( $post_id ), $xml );
	}

	/**
	 * Test month sitemap returns urlset with post URLs.
	 *
	 * @return void
	 */
	public function test_month_sitemap_returns_urlset_with_posts(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'month' );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'June Post',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_month_sitemap( 2024, 6, true );

		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringContainsString( get_permalink( $post_id ), $xml );
	}

	/**
	 * Test month sitemap with day granularity returns sitemap index.
	 *
	 * @return void
	 */
	public function test_month_sitemap_with_day_granularity_returns_index(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'day' );

		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2024-06-20 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_month_sitemap( 2024, 6, true );

		// Should be sitemap index with day references.
		$this->assertStringContainsString( '<sitemapindex', $xml );
		$this->assertStringContainsString( '/2024-06-15.xml', $xml );
		$this->assertStringContainsString( '/2024-06-20.xml', $xml );
	}

	/**
	 * Test day sitemap returns urlset with post URLs.
	 *
	 * @return void
	 */
	public function test_day_sitemap_returns_urlset_with_posts(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'day' );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'June 15 Post',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_day_sitemap( 2024, 6, 15, true );

		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringContainsString( get_permalink( $post_id ), $xml );
	}

	/**
	 * Test sitemap only includes posts of configured post type.
	 *
	 * @return void
	 */
	public function test_sitemap_filters_by_post_type(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_POST_TYPE, 'page' );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'year' );

		// Create a post (should be excluded).
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Blog Post',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		// Create a page (should be included).
		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About Page',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_year_sitemap( 2024, true );

		$this->assertStringContainsString( get_permalink( $page_id ), $xml );
		$this->assertStringNotContainsString( 'blog-post', $xml );
	}

	/**
	 * Test sitemap filters by taxonomy terms when configured.
	 *
	 * @return void
	 */
	public function test_sitemap_filters_by_taxonomy_terms(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'year' );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY, 'category' );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY_TERMS, [ $this->category_id ] );

		// Create post in test category (should be included).
		$included_post = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Categorized Post',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);
		wp_set_post_terms( $included_post, [ $this->category_id ], 'category' );

		// Create post without category (should be excluded).
		$excluded_post = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Uncategorized Post',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_year_sitemap( 2024, true );

		$this->assertStringContainsString( get_permalink( $included_post ), $xml );
		$this->assertStringNotContainsString( get_permalink( $excluded_post ), $xml );
	}

	/**
	 * Test sitemap excludes draft posts.
	 *
	 * @return void
	 */
	public function test_sitemap_excludes_non_published_posts(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'year' );

		// Create published post.
		$published = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Published Post',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		// Create draft post.
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Draft Post',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_year_sitemap( 2024, true );

		$this->assertStringContainsString( get_permalink( $published ), $xml );
		$this->assertStringNotContainsString( 'draft-post', $xml );
	}

	/**
	 * Test clear_all_cached_xml removes all cached data.
	 *
	 * @return void
	 */
	public function test_clear_all_cached_xml_removes_cache(): void {
		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		// Set some cached values.
		update_post_meta( $this->sitemap_id, Sitemap_Generator::META_KEY_INDEX_XML, 'index-xml' );
		update_post_meta( $this->sitemap_id, Sitemap_Generator::META_KEY_XML_PREFIX . '2024', 'year-xml' );
		update_post_meta( $this->sitemap_id, Sitemap_Generator::META_KEY_URL_COUNT . '2024_06', 50 );

		// Clear cache.
		$generator->clear_all_cached_xml();

		// Verify all cleared.
		$this->assertEmpty( get_post_meta( $this->sitemap_id, Sitemap_Generator::META_KEY_INDEX_XML, true ) );
		$this->assertEmpty( get_post_meta( $this->sitemap_id, Sitemap_Generator::META_KEY_XML_PREFIX . '2024', true ) );
	}

	/**
	 * Test regenerate_all regenerates index and all child sitemaps.
	 *
	 * @return void
	 */
	public function test_regenerate_all_returns_summary(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'month' );

		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$summary = $generator->regenerate_all();

		$this->assertTrue( $summary['index'] );
		$this->assertContains( 2024, $summary['years'] );
		$this->assertContains( '2024-6', $summary['months'] );
	}

	/**
	 * Test URL entries include lastmod element.
	 *
	 * @return void
	 */
	public function test_url_entries_include_lastmod(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'year' );

		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2024-06-15 10:00:00',
			]
		);

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		$xml = $generator->get_year_sitemap( 2024, true );

		$this->assertStringContainsString( '<lastmod>', $xml );
		$this->assertStringContainsString( '</lastmod>', $xml );
	}

	/**
	 * Test empty sitemap returns valid empty XML.
	 *
	 * @return void
	 */
	public function test_empty_sitemap_returns_valid_xml(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_GRANULARITY, 'year' );

		$sitemap   = get_post( $this->sitemap_id );
		$generator = new Sitemap_Generator( $sitemap );

		// Request year with no content.
		$xml = $generator->get_year_sitemap( 1990, true );

		$this->assertStringContainsString( '<?xml version="1.0"', $xml );
		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringContainsString( '</urlset>', $xml );
		$this->assertStringNotContainsString( '<url>', $xml );
	}
}
