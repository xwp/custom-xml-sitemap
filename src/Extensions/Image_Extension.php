<?php
/**
 * Image Sitemap Extension.
 *
 * Extracts images from posts and generates XML for the image sitemap extension.
 * Supports featured images, Gutenberg image blocks, and classic editor images.
 *
 * @package XWP\CustomXmlSitemap\Extensions
 */

namespace XWP\CustomXmlSitemap\Extensions;

use WP_Post;
use XWP\CustomXmlSitemap\Sitemap_CPT;

/**
 * Image Extension class.
 *
 * Generates <image:image> elements for sitemap URL entries.
 *
 * Note: <image:title>, <image:caption>, <image:geo_location>, and <image:license>
 * were deprecated by Google. Only <image:loc> is now supported.
 *
 * @see https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps
 */
class Image_Extension {

	/**
	 * Image inclusion mode.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Constructor.
	 *
	 * @param string $mode Image inclusion mode ('none', 'featured', 'all').
	 */
	public function __construct( string $mode = 'none' ) {
		$this->mode = $mode;
	}

	/**
	 * Build XML for image extension.
	 *
	 * @param WP_Post $post Post object.
	 * @return string XML string with image:image elements, or empty string if no images.
	 */
	public function build_xml( WP_Post $post ): string {
		if ( Sitemap_CPT::INCLUDE_IMAGES_NONE === $this->mode ) {
			return '';
		}

		$images = $this->get_images( $post );

		if ( empty( $images ) ) {
			return '';
		}

		$xml = '';
		foreach ( $images as $image ) {
			// Skip data URIs and malformed URLs (WordPress may convert data: to http://).
			if ( $this->is_invalid_image_url( $image['url'] ) ) {
				continue;
			}
			$xml .= $this->build_image_element( $image['url'] );
		}

		return $xml;
	}

	/**
	 * Get images for a post based on the configured mode.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<array{url: string}> Array of images with 'url' key.
	 */
	public function get_images( WP_Post $post ): array {
		$images = [];

		// Always include featured image if available.
		$featured = $this->get_featured_image( $post );
		if ( ! empty( $featured ) ) {
			$images[] = $featured;
		}

		// For 'all' mode, also include content images.
		if ( Sitemap_CPT::INCLUDE_IMAGES_ALL === $this->mode ) {
			$content_images = $this->get_content_images( $post );
			$images         = array_merge( $images, $content_images );
		}

		// Deduplicate by URL.
		return $this->deduplicate_images( $images );
	}

	/**
	 * Get the featured image for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array{url: string}|array{} Image data with 'url' key, or empty array if no featured image.
	 */
	private function get_featured_image( WP_Post $post ): array {
		$thumbnail_id = get_post_thumbnail_id( $post->ID );

		if ( empty( $thumbnail_id ) ) {
			return [];
		}

		$image_src = wp_get_attachment_image_src( (int) $thumbnail_id, 'full' );

		if ( empty( $image_src ) || empty( $image_src[0] ) ) {
			return [];
		}

		return [
			'url' => $image_src[0],
		];
	}

	/**
	 * Get images from post content.
	 *
	 * Uses WP_Block_Processor for efficient streaming extraction from:
	 * - core/image Gutenberg blocks
	 * - Custom blocks via the 'cxs_extract_block_images' filter
	 * - Classic editor inline <img> tags (non-block content)
	 *
	 * @param WP_Post $post Post object.
	 * @return array<array{url: string}> Array of images with 'url' key.
	 */
	private function get_content_images( WP_Post $post ): array {
		$images = [];

		if ( empty( $post->post_content ) ) {
			return $images;
		}

		// Use streaming block processor for efficient extraction.
		if ( has_blocks( $post->post_content ) ) {
			$block_images = $this->extract_images_with_block_processor( $post->post_content, $post->ID );
			$images       = array_merge( $images, $block_images );
		}

		// Also extract classic editor inline images (handles non-block content).
		$inline_images = $this->extract_inline_images( $post->post_content );
		$images        = array_merge( $images, $inline_images );

		return $images;
	}

	/**
	 * Extract images from parsed blocks using parse_blocks().
	 *
	 * Uses WordPress core parse_blocks() function to extract block attributes
	 * including image IDs for core/image blocks.
	 *
	 * @param string $content Post content HTML.
	 * @param int    $post_id Post ID for filter context.
	 * @return array<array{url: string}> Array of images with 'url' key.
	 */
	private function extract_images_with_block_processor( string $content, int $post_id ): array {
		$images = [];
		$blocks = parse_blocks( $content );

		$this->extract_images_from_blocks( $blocks, $images, $post_id );

		return $images;
	}

	/**
	 * Recursively extract images from blocks array.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks  Array of parsed blocks.
	 * @param array<array{url: string}>               $images  Images array to append to (passed by reference).
	 * @param int                                     $post_id Post ID for filter context.
	 * @return void
	 */
	private function extract_images_from_blocks( array $blocks, array &$images, int $post_id ): void {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? null;

			if ( null === $block_name ) {
				continue;
			}

			// Handle core/image blocks.
			if ( 'core/image' === $block_name ) {
				$image = $this->extract_image_from_block( $block );
				if ( ! empty( $image ) ) {
					$images[] = $image;
				}
			}

			/**
			 * Filter to extract images from custom blocks.
			 *
			 * Allows themes/plugins to add image extraction for custom block types.
			 *
			 * @param array<array{url: string}> $images     Current images array with 'url' keys.
			 * @param string                    $block_name Block name (e.g., 'acme/gallery').
			 * @param array                     $block      Parsed block array.
			 * @param int                       $post_id    Post ID being processed.
			 */
			$images = apply_filters( 'cxs_extract_block_images', $images, $block_name, $block, $post_id );

			// Process inner blocks recursively.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->extract_images_from_blocks( $block['innerBlocks'], $images, $post_id );
			}
		}
	}

	/**
	 * Extract image URL from a parsed block.
	 *
	 * @param array<string, mixed> $block Parsed block array.
	 * @return array{url: string}|array{} Image data with 'url' key, or empty array.
	 */
	private function extract_image_from_block( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		if ( empty( $attrs['id'] ) ) {
			return [];
		}

		$attachment_id = absint( $attrs['id'] );
		$image_url     = wp_get_attachment_image_url( $attachment_id, 'full' );

		if ( empty( $image_url ) ) {
			return [];
		}

		return [ 'url' => $image_url ];
	}

	/**
	 * Extract images from inline <img> tags in content.
	 *
	 * Handles classic editor content and any inline images not in blocks.
	 *
	 * @param string $content Post content HTML.
	 * @return array<array{url: string}> Array of images with 'url' key.
	 */
	private function extract_inline_images( string $content ): array {
		$images = [];

		// Match all img tags with src attribute.
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				// Skip data URIs and empty URLs.
				if ( empty( $url ) || str_starts_with( $url, 'data:' ) ) {
					continue;
				}

				$images[] = [ 'url' => $url ];
			}
		}

		return $images;
	}

	/**
	 * Deduplicate images by URL.
	 *
	 * @param array<array{url: string}> $images Array of images with 'url' key.
	 * @return array<array{url: string}> Deduplicated array of images.
	 */
	private function deduplicate_images( array $images ): array {
		$seen   = [];
		$result = [];

		foreach ( $images as $image ) {
			if ( empty( $image['url'] ) ) {
				continue;
			}

			$url = $image['url'];

			if ( isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ] = true;
			$result[]     = $image;
		}

		return $result;
	}

	/**
	 * Check if a URL is invalid for sitemap inclusion.
	 *
	 * Filters out data URIs and malformed URLs. WordPress's kses filters
	 * may convert data: URIs to http:// URLs, creating invalid entries.
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL should be skipped.
	 */
	private function is_invalid_image_url( string $url ): bool {
		// Skip data URIs.
		if ( str_starts_with( $url, 'data:' ) ) {
			return true;
		}

		// Skip URLs that look like malformed data URIs (data: converted to http://).
		// These have patterns like "http://image/png;base64,..." or "http://image/jpeg;...".
		if ( preg_match( '#^https?://image/[^/]+;#i', $url ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Build a single image:image XML element.
	 *
	 * @param string $url Image URL.
	 * @return string XML element string.
	 */
	private function build_image_element( string $url ): string {
		return "\t\t<image:image>\n" .
			"\t\t\t<image:loc>" . esc_url( $url ) . "</image:loc>\n" .
			"\t\t</image:image>\n";
	}
}
