<?php
/**
 * News Extension test case.
 *
 * Tests the News_Extension class for generating Google News sitemap XML.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Extensions\News_Extension;

/**
 * News Extension test class.
 *
 * Tests for the News_Extension class functionality including XML generation,
 * publication metadata, language codes, and keywords extraction.
 */
class Test_News_Extension extends WP_UnitTestCase {

	/**
	 * News Extension instance.
	 *
	 * @var News_Extension
	 */
	private News_Extension $extension;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->extension = new News_Extension();
	}

	/**
	 * Test build_xml returns valid XML structure.
	 *
	 * @return void
	 */
	public function test_build_xml_returns_valid_structure(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test Article',
				'post_date'   => '2024-01-15 10:30:00',
			]
		);
		$post    = get_post( $post_id );

		$xml = $this->extension->build_xml( $post );

		$this->assertStringContainsString( '<news:news>', $xml );
		$this->assertStringContainsString( '</news:news>', $xml );
		$this->assertStringContainsString( '<news:publication>', $xml );
		$this->assertStringContainsString( '</news:publication>', $xml );
		$this->assertStringContainsString( '<news:name>', $xml );
		$this->assertStringContainsString( '<news:language>', $xml );
		$this->assertStringContainsString( '<news:publication_date>', $xml );
		$this->assertStringContainsString( '<news:title>Test Article</news:title>', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test publication name uses site name.
	 *
	 * @return void
	 */
	public function test_publication_name_uses_site_name(): void {
		update_option( 'blogname', 'My Test Site' );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test',
			]
		);
		$post    = get_post( $post_id );

		$xml = $this->extension->build_xml( $post );

		$this->assertStringContainsString( '<news:name>My Test Site</news:name>', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test publication name strips trailing parentheticals.
	 *
	 * Per Google spec, parentheticals like "(subscription)" should be removed.
	 *
	 * @return void
	 */
	public function test_publication_name_strips_parentheticals(): void {
		update_option( 'blogname', 'The Example Times (subscription)' );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test',
			]
		);
		$post    = get_post( $post_id );

		$xml = $this->extension->build_xml( $post );

		$this->assertStringContainsString( '<news:name>The Example Times</news:name>', $xml );
		$this->assertStringNotContainsString( '(subscription)', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test publication date is in ISO 8601 format.
	 *
	 * @return void
	 */
	public function test_publication_date_format(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Test',
				'post_date_gmt' => '2024-03-15 14:30:00',
			]
		);
		$post    = get_post( $post_id );

		$xml = $this->extension->build_xml( $post );

		// Should match ISO 8601 format (YYYY-MM-DDTHH:MM:SS+00:00 or similar).
		$this->assertMatchesRegularExpression(
			'/<news:publication_date>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}<\/news:publication_date>/',
			$xml
		);

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test title is properly escaped.
	 *
	 * @return void
	 */
	public function test_title_escapes_special_characters(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Breaking: "Test" & More News',
			]
		);
		$post    = get_post( $post_id );

		$xml = $this->extension->build_xml( $post );

		// Ampersand should be escaped to &amp; for valid XML.
		$this->assertStringContainsString( '&amp;', $xml );
		// The title text should be present (WordPress may convert quotes to curly quotes).
		$this->assertStringContainsString( 'Breaking:', $xml );
		$this->assertStringContainsString( 'More News', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test keywords include categories.
	 *
	 * @return void
	 */
	public function test_keywords_include_categories(): void {
		$category_id = self::factory()->category->create( [ 'name' => 'Technology' ] );

		$post_id = self::factory()->post->create(
			[
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Test',
				'post_category' => [ $category_id ],
			]
		);
		$post    = get_post( $post_id );

		$xml = $this->extension->build_xml( $post );

		$this->assertStringContainsString( '<news:keywords>Technology</news:keywords>', $xml );

		wp_delete_post( $post_id, true );
		wp_delete_term( $category_id, 'category' );
	}

	/**
	 * Test keywords include tags.
	 *
	 * @return void
	 */
	public function test_keywords_include_tags(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test',
				'tags_input'  => [ 'breaking', 'exclusive' ],
			]
		);
		$post    = get_post( $post_id );

		$xml = $this->extension->build_xml( $post );

		$this->assertStringContainsString( 'breaking', $xml );
		$this->assertStringContainsString( 'exclusive', $xml );
		$this->assertStringContainsString( '<news:keywords>', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test keywords excludes Uncategorized category.
	 *
	 * @return void
	 */
	public function test_keywords_excludes_uncategorized(): void {
		// Create a post with only the default Uncategorized category.
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test',
			]
		);
		$post    = get_post( $post_id );

		$xml = $this->extension->build_xml( $post );

		// Should not have keywords element when only Uncategorized.
		$this->assertStringNotContainsString( '<news:keywords>Uncategorized</news:keywords>', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test keywords element is omitted when no valid keywords exist.
	 *
	 * @return void
	 */
	public function test_keywords_omitted_when_none(): void {
		// Create a post with only the default Uncategorized category and no tags.
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test',
			]
		);

		// Remove any existing terms (ensure only Uncategorized).
		wp_set_object_terms( $post_id, [], 'post_tag' );

		$post = get_post( $post_id );
		$xml  = $this->extension->build_xml( $post );

		// Keywords element should not be present.
		$this->assertStringNotContainsString( '<news:keywords>', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test keywords combines categories and tags.
	 *
	 * @return void
	 */
	public function test_keywords_combines_categories_and_tags(): void {
		$category_id = self::factory()->category->create( [ 'name' => 'Sports' ] );

		$post_id = self::factory()->post->create(
			[
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Test',
				'post_category' => [ $category_id ],
				'tags_input'    => [ 'football' ],
			]
		);
		$post    = get_post( $post_id );

		$xml = $this->extension->build_xml( $post );

		// Should contain comma-separated keywords.
		$this->assertStringContainsString( '<news:keywords>Sports, football</news:keywords>', $xml );

		wp_delete_post( $post_id, true );
		wp_delete_term( $category_id, 'category' );
	}
}
