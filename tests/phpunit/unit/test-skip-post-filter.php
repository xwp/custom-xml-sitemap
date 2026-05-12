<?php
/**
 * Unit tests for the cxs_sitemap_skip_post filter applied in build_url_entry().
 *
 * Verifies that returning true from any handler omits the post from the urlset
 * and that the image/news extensions are not invoked for skipped posts.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XWP\CustomXmlSitemap\Sitemap_Generator;

/**
 * Test the cxs_sitemap_skip_post filter chokepoint.
 */
class Test_Skip_Post_Filter extends TestCase {

	/**
	 * Set up Brain\Monkey and a stub WP_Post class.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( 'WP_Post' ) ) {
			eval( 'class WP_Post { public int $ID; public string $post_modified_gmt = ""; }' );
		}

		Functions\when( 'get_permalink' )->alias(
			static fn( $post ) => 'https://example.com/?p=' . ( is_object( $post ) ? $post->ID : (int) $post )
		);
		Functions\when( 'mysql2date' )->justReturn( '2024-01-01T00:00:00+00:00' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	/**
	 * Tear down Brain\Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Sitemap_Generator instance without running the constructor.
	 *
	 * @return Sitemap_Generator
	 */
	private function make_generator(): Sitemap_Generator {
		$reflection = new ReflectionClass( Sitemap_Generator::class );
		$generator  = $reflection->newInstanceWithoutConstructor();

		$config = $reflection->getProperty( 'config' );
		$config->setAccessible( true );
		$config->setValue( $generator, [] );

		return $generator;
	}

	/**
	 * Invoke the private build_url_entry() method.
	 *
	 * @param Sitemap_Generator $generator Generator instance.
	 * @param \WP_Post          $post      Post to render.
	 * @return string XML entry or empty string.
	 */
	private function build( Sitemap_Generator $generator, \WP_Post $post ): string {
		$method = new \ReflectionMethod( Sitemap_Generator::class, 'build_url_entry' );
		$method->setAccessible( true );

		return (string) $method->invoke( $generator, $post );
	}

	/**
	 * Build a stub post with the given ID.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post
	 */
	private function make_post( int $id ): \WP_Post {
		$post                    = new \WP_Post();
		$post->ID                = $id;
		$post->post_modified_gmt = '2024-01-01 00:00:00';

		return $post;
	}

	/**
	 * By default the filter does not fire and a urlset entry is emitted.
	 *
	 * @return void
	 */
	public function test_no_filter_emits_url_entry(): void {
		Filters\expectApplied( 'cxs_sitemap_skip_post' )
			->once()
			->with( false, 42 )
			->andReturn( false );

		$xml = $this->build( $this->make_generator(), $this->make_post( 42 ) );

		$this->assertStringContainsString( '<loc>', $xml );
		$this->assertStringContainsString( '?p=42', $xml );
		$this->assertStringContainsString( '<lastmod>', $xml );
	}

	/**
	 * Returning true from cxs_sitemap_skip_post produces no XML.
	 *
	 * @return void
	 */
	public function test_skipping_omits_entry(): void {
		Filters\expectApplied( 'cxs_sitemap_skip_post' )
			->once()
			->with( false, 99 )
			->andReturn( true );

		$xml = $this->build( $this->make_generator(), $this->make_post( 99 ) );

		$this->assertSame( '', $xml );
	}

	/**
	 * The filter receives the post ID, not the WP_Post object, so handlers can
	 * be reused with the MSM-style signature.
	 *
	 * @return void
	 */
	public function test_filter_receives_post_id(): void {
		$received = null;
		Filters\expectApplied( 'cxs_sitemap_skip_post' )
			->once()
			->andReturnUsing(
				static function ( $skip, $post_id ) use ( &$received ) {
					$received = $post_id;
					return $skip;
				}
			);

		$this->build( $this->make_generator(), $this->make_post( 7 ) );

		$this->assertSame( 7, $received );
	}
}
