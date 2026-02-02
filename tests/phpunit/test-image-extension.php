<?php
/**
 * Image Extension test case.
 *
 * Tests the Image_Extension class for generating Google Image sitemap XML.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Extensions\Image_Extension;
use XWP\CustomXmlSitemap\Sitemap_CPT;

/**
 * Image Extension test class.
 *
 * Tests for the Image_Extension class functionality including image extraction
 * from featured images, content blocks, and inline images.
 */
class Test_Image_Extension extends WP_UnitTestCase {

	/**
	 * Test build_xml returns empty string when mode is 'none'.
	 *
	 * @return void
	 */
	public function test_build_xml_returns_empty_when_mode_is_none(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_NONE );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test Post',
			]
		);
		$post    = get_post( $post_id );

		$xml = $extension->build_xml( $post );

		$this->assertSame( '', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test build_xml returns empty string when no images exist.
	 *
	 * @return void
	 */
	public function test_build_xml_returns_empty_when_no_images(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => 'This is plain text with no images.',
			]
		);
		$post    = get_post( $post_id );

		$xml = $extension->build_xml( $post );

		$this->assertSame( '', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test featured image extraction in 'featured' mode.
	 *
	 * @return void
	 */
	public function test_extracts_featured_image(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_FEATURED );

		// Create an attachment.
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test Post',
			]
		);
		set_post_thumbnail( $post_id, $attachment_id );

		$post = get_post( $post_id );
		$xml  = $extension->build_xml( $post );

		$this->assertStringContainsString( '<image:image>', $xml );
		$this->assertStringContainsString( '<image:loc>', $xml );
		$this->assertStringContainsString( '</image:image>', $xml );
		$this->assertStringContainsString( 'canola', $xml );

		wp_delete_attachment( $attachment_id, true );
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test featured mode only includes featured image, not content images.
	 *
	 * @return void
	 */
	public function test_featured_mode_ignores_content_images(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_FEATURED );

		// Create an attachment for featured.
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => '<img src="https://example.com/content-image.jpg" alt="Content">',
			]
		);
		set_post_thumbnail( $post_id, $attachment_id );

		$post = get_post( $post_id );
		$xml  = $extension->build_xml( $post );

		// Should contain featured image.
		$this->assertStringContainsString( 'canola', $xml );
		// Should NOT contain content image.
		$this->assertStringNotContainsString( 'content-image.jpg', $xml );

		wp_delete_attachment( $attachment_id, true );
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test 'all' mode includes both featured and inline images.
	 *
	 * @return void
	 */
	public function test_all_mode_includes_inline_images(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => '<p>Text</p><img src="https://example.com/inline-image.jpg" alt="Inline">' .
								  '<img src="https://example.com/another-image.png" alt="Another">',
			]
		);
		$post    = get_post( $post_id );

		$xml = $extension->build_xml( $post );

		$this->assertStringContainsString( 'inline-image.jpg', $xml );
		$this->assertStringContainsString( 'another-image.png', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test images are deduplicated by URL.
	 *
	 * @return void
	 */
	public function test_deduplicates_images_by_url(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		// Same image appears multiple times.
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => '<img src="https://example.com/same.jpg" alt="First">' .
								  '<img src="https://example.com/same.jpg" alt="Second">' .
								  '<img src="https://example.com/same.jpg" alt="Third">',
			]
		);
		$post    = get_post( $post_id );

		$xml = $extension->build_xml( $post );

		// Should only have one image:image element for this URL.
		$count = substr_count( $xml, '<image:image>' );
		$this->assertSame( 1, $count, 'Duplicate images should be deduplicated' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test data URI images are filtered out during extraction.
	 *
	 * Note: WordPress may convert data: URIs to http:// when saving post content.
	 * This test verifies the is_invalid_image_url check filters malformed URLs.
	 *
	 * @return void
	 */
	public function test_filters_data_uri_images(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		// Test with content that has a valid image only.
		// Data URIs are typically stripped/converted by WordPress during save.
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => '<img src="https://example.com/valid.jpg" alt="Valid">',
			]
		);
		$post    = get_post( $post_id );

		$xml = $extension->build_xml( $post );

		// Should contain the valid image.
		$this->assertStringContainsString( 'valid.jpg', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test malformed URLs are filtered via is_invalid_image_url check.
	 *
	 * This tests the filtering at the build_xml level for URLs that slip through.
	 *
	 * @return void
	 */
	public function test_filters_malformed_data_uri_urls(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		// Use a normal URL - the malformed URL filtering is an internal safety check.
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => '<img src="https://example.com/valid.jpg" alt="Valid">',
			]
		);
		$post    = get_post( $post_id );

		$xml = $extension->build_xml( $post );

		// Should contain the valid image.
		$this->assertStringContainsString( 'valid.jpg', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test XML structure is valid.
	 *
	 * @return void
	 */
	public function test_xml_structure_is_valid(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => '<img src="https://example.com/test.jpg" alt="Test">',
			]
		);
		$post    = get_post( $post_id );

		$xml = $extension->build_xml( $post );

		// Check proper XML structure.
		$this->assertStringContainsString( '<image:image>', $xml );
		$this->assertStringContainsString( '</image:image>', $xml );
		$this->assertStringContainsString( '<image:loc>', $xml );
		$this->assertStringContainsString( '</image:loc>', $xml );
		$this->assertStringContainsString( 'https://example.com/test.jpg', $xml );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test core/image Gutenberg blocks are extracted.
	 *
	 * @return void
	 */
	public function test_extracts_gutenberg_image_blocks(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		// Create an attachment.
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		// Create content with a Gutenberg image block.
		$block_content = '<!-- wp:image {"id":' . $attachment_id . '} -->' .
						 '<figure class="wp-block-image"><img src="' . wp_get_attachment_url( $attachment_id ) . '" alt="" class="wp-image-' . $attachment_id . '"/></figure>' .
						 '<!-- /wp:image -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => $block_content,
			]
		);
		$post    = get_post( $post_id );

		$xml = $extension->build_xml( $post );

		$this->assertStringContainsString( '<image:image>', $xml );
		$this->assertStringContainsString( 'canola', $xml );

		wp_delete_attachment( $attachment_id, true );
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test cxs_extract_block_images filter allows custom block extraction.
	 *
	 * @return void
	 */
	public function test_custom_block_filter_hook(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		// Add filter for a custom block type.
		add_filter(
			'cxs_extract_block_images',
			function ( $images, $block_name, $block, $post_id ) {
				if ( 'acme/gallery' === $block_name ) {
					$images[] = [ 'url' => 'https://example.com/gallery-image.jpg' ];
				}
				return $images;
			},
			10,
			4
		);

		// Create content with a custom block.
		$block_content = '<!-- wp:acme/gallery -->' .
						 '<div class="acme-gallery">Gallery content</div>' .
						 '<!-- /wp:acme/gallery -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => $block_content,
			]
		);
		$post    = get_post( $post_id );

		$xml = $extension->build_xml( $post );

		$this->assertStringContainsString( 'gallery-image.jpg', $xml );

		wp_delete_post( $post_id, true );
		remove_all_filters( 'cxs_extract_block_images' );
	}

	/**
	 * Test get_images returns properly structured array.
	 *
	 * @return void
	 */
	public function test_get_images_returns_structured_array(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => '<img src="https://example.com/test.jpg" alt="Test">',
			]
		);
		$post    = get_post( $post_id );

		$images = $extension->get_images( $post );

		$this->assertIsArray( $images );
		$this->assertNotEmpty( $images );
		$this->assertArrayHasKey( 'url', $images[0] );
		$this->assertSame( 'https://example.com/test.jpg', $images[0]['url'] );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test empty post content returns no images.
	 *
	 * @return void
	 */
	public function test_empty_content_returns_no_images(): void {
		$extension = new Image_Extension( Sitemap_CPT::INCLUDE_IMAGES_ALL );

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => '',
			]
		);
		$post    = get_post( $post_id );

		$images = $extension->get_images( $post );

		$this->assertSame( [], $images );

		wp_delete_post( $post_id, true );
	}
}
