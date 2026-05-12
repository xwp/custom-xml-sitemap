<?php
/**
 * Integration tests for Sitemap_CPT meta-direct helpers.
 *
 * Verifies that the direct helpers persist data via the database and do not
 * pollute the post-meta object cache with large XML blobs.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Generator;
use XWP\CustomXmlSitemap\Terms_Sitemap_Generator;

/**
 * Memcached-safe meta-direct integration tests.
 */
class Test_Meta_Direct extends WP_UnitTestCase {

	/**
	 * Sitemap CPT post ID.
	 *
	 * @var int
	 */
	private int $sitemap_id;

	/**
	 * Set up a sitemap CPT post for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->sitemap_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Direct Meta Test',
				'post_name'   => 'direct-meta-test',
			]
		);
	}

	/**
	 * Clean up post and object cache after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_delete_post( $this->sitemap_id, true );
		wp_cache_flush();

		parent::tear_down();
	}

	/**
	 * set_meta_direct followed by get_meta_direct round-trips the value
	 * via the database.
	 *
	 * @return void
	 */
	public function test_set_then_get_roundtrips_value(): void {
		Sitemap_CPT::set_meta_direct( $this->sitemap_id, 'cxs_sitemap_index_xml', '<urlset></urlset>' );

		$this->assertSame(
			'<urlset></urlset>',
			Sitemap_CPT::get_meta_direct( $this->sitemap_id, 'cxs_sitemap_index_xml' )
		);
	}

	/**
	 * set_meta_direct overwrites an existing value rather than appending.
	 *
	 * @return void
	 */
	public function test_set_meta_direct_overwrites_existing(): void {
		Sitemap_CPT::set_meta_direct( $this->sitemap_id, 'cxs_sitemap_index_xml', '<a/>' );
		Sitemap_CPT::set_meta_direct( $this->sitemap_id, 'cxs_sitemap_index_xml', '<b/>' );

		$this->assertSame( '<b/>', Sitemap_CPT::get_meta_direct( $this->sitemap_id, 'cxs_sitemap_index_xml' ) );
	}

	/**
	 * Calling set_meta_direct invalidates the object-cache entry for the post
	 * so subsequent get_post_meta() reads return the new value via the DB.
	 *
	 * @return void
	 */
	public function test_set_meta_direct_invalidates_object_cache(): void {
		// Prime the cache with the initial value via update_post_meta.
		update_post_meta( $this->sitemap_id, 'cxs_sitemap_index_xml', '<old/>' );
		$this->assertSame( '<old/>', get_post_meta( $this->sitemap_id, 'cxs_sitemap_index_xml', true ) );

		// Direct write replaces the row; object cache for the post must be cleared.
		Sitemap_CPT::set_meta_direct( $this->sitemap_id, 'cxs_sitemap_index_xml', '<new/>' );

		$this->assertSame( '<new/>', get_post_meta( $this->sitemap_id, 'cxs_sitemap_index_xml', true ) );
	}

	/**
	 * get_meta_direct returns empty string when the key has never been written.
	 *
	 * @return void
	 */
	public function test_get_meta_direct_returns_empty_for_missing(): void {
		$this->assertSame( '', Sitemap_CPT::get_meta_direct( $this->sitemap_id, 'cxs_sitemap_index_xml' ) );
	}

	/**
	 * prime_config_meta_cache loads small config meta but excludes XML blobs,
	 * so the resulting post-meta cache entry contains the config keys without
	 * loading multi-megabyte XML payloads into the object cache.
	 *
	 * @return void
	 */
	public function test_prime_config_meta_cache_excludes_xml_blobs(): void {
		// Small config meta — should be primed.
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_POST_TYPE, 'post' );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY, 'category' );

		// Large XML blobs — must be excluded from priming. Write them with the
		// direct helpers so they land in the DB without polluting the cache.
		Sitemap_CPT::set_meta_direct(
			$this->sitemap_id,
			Sitemap_Generator::META_KEY_INDEX_XML,
			str_repeat( 'a', 1024 )
		);
		Sitemap_CPT::set_meta_direct(
			$this->sitemap_id,
			Sitemap_Generator::META_KEY_XML_PREFIX . '2024_03',
			str_repeat( 'b', 1024 )
		);
		Sitemap_CPT::set_meta_direct(
			$this->sitemap_id,
			Terms_Sitemap_Generator::META_KEY_INDEX_XML,
			str_repeat( 'c', 1024 )
		);
		Sitemap_CPT::set_meta_direct(
			$this->sitemap_id,
			Terms_Sitemap_Generator::META_KEY_PAGE_XML_PREFIX . '1',
			str_repeat( 'd', 1024 )
		);

		// Flush cache so the next prime call has nothing in the cache.
		wp_cache_delete( $this->sitemap_id, 'post_meta' );

		Sitemap_CPT::prime_config_meta_cache( [ $this->sitemap_id ] );

		$cached = wp_cache_get( $this->sitemap_id, 'post_meta' );

		$this->assertIsArray( $cached, 'Object cache should be primed after prime_config_meta_cache().' );

		// Config meta is present.
		$this->assertArrayHasKey( Sitemap_CPT::META_KEY_POST_TYPE, $cached );
		$this->assertArrayHasKey( Sitemap_CPT::META_KEY_TAXONOMY, $cached );

		// XML blobs are NOT present.
		$this->assertArrayNotHasKey( Sitemap_Generator::META_KEY_INDEX_XML, $cached );
		$this->assertArrayNotHasKey( Sitemap_Generator::META_KEY_XML_PREFIX . '2024_03', $cached );
		$this->assertArrayNotHasKey( Terms_Sitemap_Generator::META_KEY_INDEX_XML, $cached );
		$this->assertArrayNotHasKey( Terms_Sitemap_Generator::META_KEY_PAGE_XML_PREFIX . '1', $cached );
	}

	/**
	 * prime_config_meta_cache is idempotent: a second call is a no-op when the
	 * cache is already populated.
	 *
	 * @return void
	 */
	public function test_prime_config_meta_cache_skips_when_already_cached(): void {
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_POST_TYPE, 'post' );

		// First call primes the cache.
		Sitemap_CPT::prime_config_meta_cache( [ $this->sitemap_id ] );
		$first = wp_cache_get( $this->sitemap_id, 'post_meta' );

		// Mutate the row directly to detect whether prime_config_meta_cache reloads.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->postmeta,
			[ 'meta_value' => 'page' ],
			[
				'post_id'  => $this->sitemap_id,
				'meta_key' => Sitemap_CPT::META_KEY_POST_TYPE,
			]
		);

		// Second prime should be a no-op (cache already populated).
		Sitemap_CPT::prime_config_meta_cache( [ $this->sitemap_id ] );
		$second = wp_cache_get( $this->sitemap_id, 'post_meta' );

		$this->assertSame( $first, $second, 'Second prime call should not refresh the cache.' );
	}
}
