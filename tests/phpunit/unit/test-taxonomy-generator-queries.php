<?php
/**
 * Unit tests for Taxonomy generator helper queries.
 *
 * Verifies the SQL shape produced by get_dates_with_modified_posts() at each
 * granularity, the URL-limit query, and the batch-invalidation DELETE query.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Generator;

/**
 * Tests for SQL shape of generator hardening helpers.
 */
class Test_Taxonomy_Generator_Queries extends TestCase {

	/**
	 * Set up Brain\Monkey + Mock_Wpdb.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['wpdb'] = new Mock_Wpdb();

		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_parse_id_list' )->alias(
			static fn( $ids ) => array_values( array_filter( array_map( 'intval', (array) $ids ) ) )
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	/**
	 * Tear down Brain\Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	/**
	 * Year-granularity modified-posts query selects only YEAR().
	 *
	 * @return void
	 */
	public function test_modified_posts_query_year_granularity(): void {
		$generator = $this->build_generator( Sitemap_CPT::GRANULARITY_YEAR );

		$generator->get_dates_with_modified_posts( 1_700_000_000 );

		$sql = $GLOBALS['wpdb']->last_query;
		$this->assertStringContainsString( 'YEAR(p.post_date) as year', $sql );
		$this->assertStringNotContainsString( 'MONTH(p.post_date)', $sql );
		$this->assertStringNotContainsString( 'DAY(p.post_date)', $sql );
		$this->assertStringContainsString( 'p.post_modified_gmt >=', $sql );
	}

	/**
	 * Month-granularity modified-posts query adds MONTH() but not DAY().
	 *
	 * @return void
	 */
	public function test_modified_posts_query_month_granularity(): void {
		$generator = $this->build_generator( Sitemap_CPT::GRANULARITY_MONTH );

		$generator->get_dates_with_modified_posts( 1_700_000_000 );

		$sql = $GLOBALS['wpdb']->last_query;
		$this->assertStringContainsString( 'YEAR(p.post_date) as year', $sql );
		$this->assertStringContainsString( 'MONTH(p.post_date) as month', $sql );
		$this->assertStringNotContainsString( 'DAY(p.post_date)', $sql );
	}

	/**
	 * Day-granularity modified-posts query adds DAY() to SELECT and GROUP BY.
	 *
	 * @return void
	 */
	public function test_modified_posts_query_day_granularity(): void {
		$generator = $this->build_generator( Sitemap_CPT::GRANULARITY_DAY );

		$generator->get_dates_with_modified_posts( 1_700_000_000 );

		$sql = $GLOBALS['wpdb']->last_query;
		$this->assertStringContainsString( 'DAY(p.post_date) as day', $sql );
		$this->assertStringContainsString( 'GROUP BY YEAR(p.post_date), MONTH(p.post_date), DAY(p.post_date)', $sql );
	}

	/**
	 * Modified-posts result rows are normalised to year/month/day keys per granularity.
	 *
	 * @return void
	 */
	public function test_modified_posts_returns_normalised_rows(): void {
		$generator                          = $this->build_generator( Sitemap_CPT::GRANULARITY_DAY );
		$GLOBALS['wpdb']->results_to_return = [
			[
				'year'  => '2024',
				'month' => '3',
				'day'   => '15',
			],
		];

		$dates = $generator->get_dates_with_modified_posts( 1_700_000_000 );

		$this->assertSame(
			[
				[
					'year'  => 2024,
					'month' => 3,
					'day'   => 15,
				],
			],
			$dates
		);
	}

	/**
	 * has_exceeded_url_limit() builds a COUNT(*) query against url_count meta.
	 *
	 * @return void
	 */
	public function test_url_limit_query_targets_url_count_meta(): void {
		// Need sitemap_post for this method - assign it via reflection with WP_Post stub.
		$generator                       = $this->build_generator_with_post();
		$GLOBALS['wpdb']->var_to_return = '0';

		$generator->has_exceeded_url_limit();

		$sql = $GLOBALS['wpdb']->last_query;
		$this->assertStringContainsString( 'SELECT COUNT(*)', $sql );
		$this->assertStringContainsString( "meta_key LIKE 'cxs_sitemap_url_count_%'", $sql );
		$this->assertStringContainsString( 'CAST(meta_value AS UNSIGNED) >= 1000', $sql );
	}

	/**
	 * has_exceeded_url_limit() returns true when the count is > 0.
	 *
	 * @return void
	 */
	public function test_url_limit_returns_true_when_any_bucket_exceeds(): void {
		$generator                       = $this->build_generator_with_post();
		$GLOBALS['wpdb']->var_to_return = '2';

		$this->assertTrue( $generator->has_exceeded_url_limit() );
	}

	/**
	 * has_exceeded_url_limit() returns false when all buckets are below the limit.
	 *
	 * @return void
	 */
	public function test_url_limit_returns_false_when_under_threshold(): void {
		$generator                       = $this->build_generator_with_post();
		$GLOBALS['wpdb']->var_to_return = '0';

		$this->assertFalse( $generator->has_exceeded_url_limit() );
	}

	/**
	 * batch_invalidate_cache() short-circuits on empty input.
	 *
	 * @return void
	 */
	public function test_batch_invalidate_cache_short_circuits_on_empty_input(): void {
		Sitemap_Generator::batch_invalidate_cache( [], 2024 );

		$this->assertNull( $GLOBALS['wpdb']->last_query );
	}

	/**
	 * batch_invalidate_cache() builds a single DELETE for index + year XML when
	 * no month is supplied.
	 *
	 * @return void
	 */
	public function test_batch_invalidate_cache_year_only_deletes_index_and_year_xml(): void {
		Sitemap_Generator::batch_invalidate_cache( [ 10, 11 ], 2024 );

		$sql = $GLOBALS['wpdb']->last_query;
		$this->assertNotNull( $sql );
		$this->assertStringContainsString( 'DELETE FROM `wp_postmeta`', $sql );
		$this->assertStringContainsString( 'post_id IN (10, 11)', $sql );
		$this->assertStringContainsString( "'cxs_sitemap_index_xml'", $sql );
		$this->assertStringContainsString( "'cxs_sitemap_xml_2024'", $sql );
		$this->assertStringNotContainsString( "'cxs_sitemap_xml_2024_", $sql );
	}

	/**
	 * batch_invalidate_cache() includes month-specific keys when a month is given.
	 *
	 * @return void
	 */
	public function test_batch_invalidate_cache_includes_month_keys(): void {
		Sitemap_Generator::batch_invalidate_cache( [ 10 ], 2024, 3 );

		$sql = $GLOBALS['wpdb']->last_query;
		$this->assertStringContainsString( "'cxs_sitemap_xml_2024_03'", $sql );
		$this->assertStringContainsString( "'cxs_sitemap_url_count_2024_03'", $sql );
	}

	/**
	 * Build a generator with config but no sitemap_post (for non-post methods).
	 *
	 * @param string $granularity Granularity to test.
	 * @return Sitemap_Generator
	 */
	private function build_generator( string $granularity ): Sitemap_Generator {
		$ref       = new ReflectionClass( Sitemap_Generator::class );
		$generator = $ref->newInstanceWithoutConstructor();

		$config_prop = $ref->getProperty( 'config' );
		$config_prop->setAccessible( true );
		$config_prop->setValue(
			$generator,
			[
				'post_type'   => 'post',
				'granularity' => $granularity,
				'taxonomy'    => '',
				'terms'       => [],
				'filter_mode' => Sitemap_CPT::FILTER_MODE_INCLUDE,
			]
		);

		return $generator;
	}

	/**
	 * Build a generator with a stub sitemap_post for methods that need it.
	 *
	 * @return Sitemap_Generator
	 */
	private function build_generator_with_post(): Sitemap_Generator {
		// Use eval to build an anonymous WP_Post-shaped instance — Brain\Monkey
		// doesn't load WP_Post, so we use a class_alias to satisfy the type hint.
		if ( ! class_exists( 'WP_Post' ) ) {
			eval( 'class WP_Post { public int $ID; }' );
		}

		$generator = $this->build_generator( Sitemap_CPT::GRANULARITY_MONTH );

		$ref       = new ReflectionClass( Sitemap_Generator::class );
		$post_prop = $ref->getProperty( 'sitemap_post' );
		$post_prop->setAccessible( true );

		$post     = new \WP_Post();
		$post->ID = 99;

		$post_prop->setValue( $generator, $post );

		return $generator;
	}
}
