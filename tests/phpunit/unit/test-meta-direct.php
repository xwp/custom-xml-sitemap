<?php
/**
 * Unit tests for Sitemap_CPT meta-direct helpers.
 *
 * Verifies that get_meta_direct, set_meta_direct, and prime_config_meta_cache
 * bypass the object cache for XML blobs while still invalidating it after writes.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;

/**
 * Test the meta-direct helpers on Sitemap_CPT.
 */
class Test_Meta_Direct extends TestCase {

	/**
	 * Set up Brain\Monkey and a mock $wpdb global.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['wpdb'] = new Mock_Wpdb();
	}

	/**
	 * Tear down Brain\Monkey and clear the mock $wpdb.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	/**
	 * get_meta_direct returns the stored value when one exists.
	 *
	 * @return void
	 */
	public function test_get_meta_direct_returns_stored_value(): void {
		$GLOBALS['wpdb']->var_to_return = '<urlset>cached</urlset>';

		$value = Sitemap_CPT::get_meta_direct( 42, 'cxs_sitemap_index_xml' );

		$this->assertSame( '<urlset>cached</urlset>', $value );
		$this->assertSame(
			'SELECT meta_value FROM `wp_postmeta` WHERE post_id = 42 AND meta_key = \'cxs_sitemap_index_xml\' ORDER BY meta_id DESC LIMIT 1',
			$GLOBALS['wpdb']->last_query
		);
	}

	/**
	 * get_meta_direct returns empty string when no row exists (NULL from DB).
	 *
	 * @return void
	 */
	public function test_get_meta_direct_returns_empty_string_when_missing(): void {
		$GLOBALS['wpdb']->var_to_return = null;

		$value = Sitemap_CPT::get_meta_direct( 42, 'cxs_sitemap_index_xml' );

		$this->assertSame( '', $value );
	}

	/**
	 * set_meta_direct inserts a new row when none exists and clears object cache.
	 *
	 * @return void
	 */
	public function test_set_meta_direct_inserts_when_missing(): void {
		$GLOBALS['wpdb']->var_to_return = null; // exists check returns null.
		$cache_deleted                  = false;

		Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key, $group ) use ( &$cache_deleted ): bool {
				if ( 42 === $key && 'post_meta' === $group ) {
					$cache_deleted = true;
				}
				return true;
			}
		);

		Sitemap_CPT::set_meta_direct( 42, 'cxs_sitemap_index_xml', '<x/>' );

		$this->assertSame( 'insert', $GLOBALS['wpdb']->last_op );
		$this->assertSame(
			[
				'post_id'    => 42,
				'meta_key'   => 'cxs_sitemap_index_xml',
				'meta_value' => '<x/>',
			],
			$GLOBALS['wpdb']->last_data
		);
		$this->assertTrue( $cache_deleted, 'wp_cache_delete should be called for post meta.' );
	}

	/**
	 * set_meta_direct updates the existing row when one already exists.
	 *
	 * @return void
	 */
	public function test_set_meta_direct_updates_when_exists(): void {
		$GLOBALS['wpdb']->var_to_return = 1; // exists check returns truthy.

		Functions\when( 'wp_cache_delete' )->justReturn( true );

		Sitemap_CPT::set_meta_direct( 42, 'cxs_sitemap_index_xml', '<y/>' );

		$this->assertSame( 'update', $GLOBALS['wpdb']->last_op );
		$this->assertSame( [ 'meta_value' => '<y/>' ], $GLOBALS['wpdb']->last_data );
		$this->assertSame(
			[
				'post_id'  => 42,
				'meta_key' => 'cxs_sitemap_index_xml',
			],
			$GLOBALS['wpdb']->last_where
		);
	}

	/**
	 * prime_config_meta_cache short-circuits when all IDs are already cached.
	 *
	 * @return void
	 */
	public function test_prime_config_meta_cache_skips_when_already_cached(): void {
		Functions\when( 'wp_cache_get' )->justReturn( [ 'cxs_post_type' => [ 'post' ] ] );

		$add_called = false;
		Functions\when( 'wp_cache_add' )->alias(
			static function () use ( &$add_called ): bool {
				$add_called = true;
				return true;
			}
		);

		Sitemap_CPT::prime_config_meta_cache( [ 1, 2, 3 ] );

		$this->assertNull( $GLOBALS['wpdb']->last_query );
		$this->assertFalse( $add_called );
	}

	/**
	 * prime_config_meta_cache loads non-XML meta and primes the object cache,
	 * grouping rows by post ID.
	 *
	 * @return void
	 */
	public function test_prime_config_meta_cache_primes_only_non_cached_ids(): void {
		Functions\when( 'wp_cache_get' )->alias(
			static fn( $key, $group ) => 99 === $key ? [ 'cxs_post_type' => [ 'post' ] ] : false
		);

		$GLOBALS['wpdb']->results_to_return = [
			[
				'post_id'    => 100,
				'meta_key'   => 'cxs_post_type',
				'meta_value' => 'post',
			],
			[
				'post_id'    => 100,
				'meta_key'   => 'cxs_taxonomy',
				'meta_value' => 'category',
			],
			[
				'post_id'    => 101,
				'meta_key'   => 'cxs_post_type',
				'meta_value' => 'page',
			],
		];

		$added = [];
		Functions\when( 'wp_cache_add' )->alias(
			static function ( $key, $value, $group ) use ( &$added ): bool {
				if ( 'post_meta' === $group ) {
					$added[ $key ] = $value;
				}
				return true;
			}
		);

		Sitemap_CPT::prime_config_meta_cache( [ 99, 100, 101 ] );

		$this->assertNotNull( $GLOBALS['wpdb']->last_query );
		$this->assertArrayNotHasKey( 99, $added, 'Already-cached IDs should not be re-primed.' );
		$this->assertSame(
			[
				'cxs_post_type' => [ 'post' ],
				'cxs_taxonomy'  => [ 'category' ],
			],
			$added[100]
		);
		$this->assertSame(
			[ 'cxs_post_type' => [ 'page' ] ],
			$added[101]
		);
	}
}
