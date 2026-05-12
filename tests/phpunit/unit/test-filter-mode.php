<?php
/**
 * Unit tests for filter_mode handling.
 *
 * Covers Sitemap_CPT::sanitize_filter_mode() and the include/exclude branches
 * of Sitemap_Generator::build_taxonomy_where_clause() / add_taxonomy_query().
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
 * Tests for include/exclude filter mode handling.
 */
class Test_Filter_Mode extends TestCase {

	/**
	 * Set up Brain\Monkey and a mock $wpdb global.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['wpdb'] = new Mock_Wpdb();

		// Default WP function mocks used by the generator.
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_parse_id_list' )->alias(
			static fn( $ids ) => array_values( array_filter( array_map( 'intval', (array) $ids ) ) )
		);
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
	 * sanitize_filter_mode returns INCLUDE for empty / unknown input.
	 *
	 * @dataProvider provide_invalid_filter_mode_values
	 *
	 * @param mixed $input Raw stored or submitted value.
	 * @return void
	 */
	public function test_sanitize_filter_mode_defaults_to_include( mixed $input ): void {
		$this->assertSame(
			Sitemap_CPT::FILTER_MODE_INCLUDE,
			Sitemap_CPT::sanitize_filter_mode( $input )
		);
	}

	/**
	 * Provide values that should normalise to INCLUDE.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_invalid_filter_mode_values(): array {
		return [
			'empty string' => [ '' ],
			'null'         => [ null ],
			'integer'      => [ 1 ],
			'array'        => [ [ 'exclude' ] ],
			'unknown enum' => [ 'foo' ],
			'whitespace'   => [ ' include ' ],
		];
	}

	/**
	 * sanitize_filter_mode returns EXCLUDE only for the exact 'exclude' string.
	 *
	 * @return void
	 */
	public function test_sanitize_filter_mode_accepts_exclude(): void {
		$this->assertSame(
			Sitemap_CPT::FILTER_MODE_EXCLUDE,
			Sitemap_CPT::sanitize_filter_mode( 'exclude' )
		);
		$this->assertSame(
			Sitemap_CPT::FILTER_MODE_INCLUDE,
			Sitemap_CPT::sanitize_filter_mode( 'include' )
		);
	}

	/**
	 * Include mode produces an INNER JOIN clause with term_id IN (…).
	 *
	 * @return void
	 */
	public function test_include_mode_builds_inner_join_clause(): void {
		$generator = $this->build_generator(
			[
				'taxonomy'    => 'category',
				'terms'       => [ 5, 9 ],
				'filter_mode' => Sitemap_CPT::FILTER_MODE_INCLUDE,
			]
		);

		$clause = $this->invoke( $generator, 'build_taxonomy_where_clause' );

		$this->assertStringContainsString( 'INNER JOIN %i tr', $clause['join'] );
		$this->assertStringContainsString( 'AND tt.taxonomy = %s', $clause['where'] );
		$this->assertStringContainsString( 'AND tt.term_id IN (%d, %d)', $clause['where'] );
		$this->assertStringNotContainsString( 'NOT EXISTS', $clause['where'] );
		$this->assertSame( [ 'wp_term_relationships', 'wp_term_taxonomy' ], $clause['tables'] );
		$this->assertSame( [ 'category', 5, 9 ], $clause['values'] );
	}

	/**
	 * Exclude mode produces a NOT EXISTS subquery and no top-level JOIN.
	 *
	 * @return void
	 */
	public function test_exclude_mode_builds_not_exists_subquery(): void {
		$generator = $this->build_generator(
			[
				'taxonomy'    => 'category',
				'terms'       => [ 5, 9 ],
				'filter_mode' => Sitemap_CPT::FILTER_MODE_EXCLUDE,
			]
		);

		$clause = $this->invoke( $generator, 'build_taxonomy_where_clause' );

		$this->assertSame( '', $clause['join'] );
		$this->assertSame( [], $clause['tables'] );
		$this->assertStringContainsString( 'NOT EXISTS', $clause['where'] );
		$this->assertStringContainsString( 'ett.term_id IN (%d, %d)', $clause['where'] );

		// Table names are placed at the start of values for the in-where %i placeholders.
		$this->assertSame(
			[ 'wp_term_relationships', 'wp_term_taxonomy', 'category', 5, 9 ],
			$clause['values']
		);
	}

	/**
	 * Empty terms collapses to the bare taxonomy clause regardless of mode.
	 *
	 * @return void
	 */
	public function test_empty_terms_falls_back_to_taxonomy_only_clause(): void {
		$include = $this->invoke(
			$this->build_generator(
				[
					'taxonomy'    => 'category',
					'terms'       => [],
					'filter_mode' => Sitemap_CPT::FILTER_MODE_INCLUDE,
				]
			),
			'build_taxonomy_where_clause'
		);

		$exclude = $this->invoke(
			$this->build_generator(
				[
					'taxonomy'    => 'category',
					'terms'       => [],
					'filter_mode' => Sitemap_CPT::FILTER_MODE_EXCLUDE,
				]
			),
			'build_taxonomy_where_clause'
		);

		foreach ( [ $include, $exclude ] as $clause ) {
			$this->assertStringContainsString( 'INNER JOIN %i tr', $clause['join'] );
			$this->assertSame( 'AND tt.taxonomy = %s', $clause['where'] );
			$this->assertStringNotContainsString( 'NOT EXISTS', $clause['where'] );
			$this->assertSame( [ 'category' ], $clause['values'] );
		}
	}

	/**
	 * add_taxonomy_query() picks the right tax_query operator for each mode.
	 *
	 * @return void
	 */
	public function test_add_taxonomy_query_operator_matches_filter_mode(): void {
		$include_args = $this->invoke(
			$this->build_generator(
				[
					'taxonomy'    => 'category',
					'terms'       => [ 5 ],
					'filter_mode' => Sitemap_CPT::FILTER_MODE_INCLUDE,
				]
			),
			'add_taxonomy_query',
			[ 'post_type' => 'post' ]
		);

		$exclude_args = $this->invoke(
			$this->build_generator(
				[
					'taxonomy'    => 'category',
					'terms'       => [ 5 ],
					'filter_mode' => Sitemap_CPT::FILTER_MODE_EXCLUDE,
				]
			),
			'add_taxonomy_query',
			[ 'post_type' => 'post' ]
		);

		$this->assertSame( 'IN', $include_args['tax_query'][0]['operator'] );
		$this->assertSame( 'NOT IN', $exclude_args['tax_query'][0]['operator'] );
		$this->assertSame( [ 5 ], $exclude_args['tax_query'][0]['terms'] );
	}

	/**
	 * Build a Sitemap_Generator with a config without booting WordPress.
	 *
	 * Bypasses the real constructor (which expects a sitemap WP_Post) by
	 * setting properties via reflection.
	 *
	 * @param array<string, mixed> $config Sitemap config to inject.
	 * @return Sitemap_Generator
	 */
	private function build_generator( array $config ): Sitemap_Generator {
		$ref       = new ReflectionClass( Sitemap_Generator::class );
		$generator = $ref->newInstanceWithoutConstructor();

		$config_prop = $ref->getProperty( 'config' );
		$config_prop->setAccessible( true );
		$config_prop->setValue( $generator, $config );

		// sitemap_post is typed WP_Post and not accessed by the tested methods;
		// leave it uninitialised. Any accidental access raises a clear error.

		return $generator;
	}

	/**
	 * Invoke a private/protected method on the generator.
	 *
	 * @param Sitemap_Generator $generator Target instance.
	 * @param string            $method    Method name.
	 * @param mixed             ...$args   Method arguments.
	 * @return mixed Return value.
	 */
	private function invoke( Sitemap_Generator $generator, string $method, mixed ...$args ): mixed {
		$method_ref = new \ReflectionMethod( $generator, $method );
		$method_ref->setAccessible( true );

		return $method_ref->invokeArgs( $generator, $args );
	}
}
