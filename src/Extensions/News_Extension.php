<?php
/**
 * News Sitemap Extension.
 *
 * Generates XML for the Google News sitemap extension including publication
 * metadata, title, and keywords from categories and tags.
 *
 * @package XWP\CustomXmlSitemap\Extensions
 */

namespace XWP\CustomXmlSitemap\Extensions;

use WP_Post;

/**
 * News Extension class.
 *
 * Generates <news:news> elements for sitemap URL entries.
 *
 * @see https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap
 */
class News_Extension {

	/**
	 * Build XML for news extension.
	 *
	 * @param WP_Post $post Post object.
	 * @return string XML string with news:news element.
	 */
	public function build_xml( WP_Post $post ): string {
		$publication_name = $this->get_publication_name();
		$language_code    = $this->get_language_code();
		$publication_date = $this->get_publication_date( $post );
		$title            = $this->get_title( $post );
		$keywords         = $this->get_keywords( $post );

		$xml  = "\t\t<news:news>\n";
		$xml .= "\t\t\t<news:publication>\n";
		$xml .= "\t\t\t\t<news:name>" . esc_html( $publication_name ) . "</news:name>\n";
		$xml .= "\t\t\t\t<news:language>" . esc_html( $language_code ) . "</news:language>\n";
		$xml .= "\t\t\t</news:publication>\n";
		$xml .= "\t\t\t<news:publication_date>" . esc_html( $publication_date ) . "</news:publication_date>\n";
		$xml .= "\t\t\t<news:title>" . esc_html( $title ) . "</news:title>\n";

		// Only include keywords if we have any.
		if ( null !== $keywords ) {
			$xml .= "\t\t\t<news:keywords>" . esc_html( $keywords ) . "</news:keywords>\n";
		}

		$xml .= "\t\t</news:news>\n";

		return $xml;
	}

	/**
	 * Get the publication name.
	 *
	 * Uses the site name and strips any trailing parentheticals per Google spec.
	 *
	 * @return string Publication name.
	 */
	private function get_publication_name(): string {
		$name = get_bloginfo( 'name' );

		// Strip trailing parentheticals per Google spec.
		// e.g., "The Example Times (subscription)" becomes "The Example Times".
		$name = preg_replace( '/\s*\([^)]*\)\s*$/', '', $name ) ?? $name;

		return trim( $name );
	}

	/**
	 * Get the language code in ISO 639 format.
	 *
	 * Extracts 2-letter language code from WordPress locale.
	 * Handles Chinese exception per Google spec.
	 *
	 * @return string ISO 639 language code (e.g., 'en', 'zh-cn', 'zh-tw').
	 */
	private function get_language_code(): string {
		$locale = get_locale();

		// Handle Chinese locales per Google spec.
		if ( str_starts_with( $locale, 'zh_CN' ) || 'zh_Hans' === $locale ) {
			return 'zh-cn';
		}

		if ( str_starts_with( $locale, 'zh_TW' ) || str_starts_with( $locale, 'zh_HK' ) || 'zh_Hant' === $locale ) {
			return 'zh-tw';
		}

		// Extract 2-letter language code.
		$parts = explode( '_', $locale );

		return strtolower( $parts[0] );
	}

	/**
	 * Get the publication date in ISO 8601 format.
	 *
	 * @param WP_Post $post Post object.
	 * @return string ISO 8601 formatted date.
	 */
	private function get_publication_date( WP_Post $post ): string {
		return mysql2date( 'c', $post->post_date_gmt );
	}

	/**
	 * Get the post title.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Post title.
	 */
	private function get_title( WP_Post $post ): string {
		return get_the_title( $post );
	}

	/**
	 * Get keywords from categories and tags.
	 *
	 * Returns categories first, then tags, as a comma-separated string.
	 * Excludes the "Uncategorized" category.
	 *
	 * @param WP_Post $post Post object.
	 * @return string|null Comma-separated keywords, or null if none.
	 */
	private function get_keywords( WP_Post $post ): ?string {
		$keywords = [];

		// Get categories (excluding "Uncategorized").
		$categories = get_the_category( $post->ID );
		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			foreach ( $categories as $category ) {
				// Skip "Uncategorized" category.
				if ( 'uncategorized' === $category->slug ) {
					continue;
				}
				$keywords[] = $category->name;
			}
		}

		// Get tags.
		$tags = get_the_tags( $post->ID );
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			foreach ( $tags as $tag ) {
				$keywords[] = $tag->name;
			}
		}

		if ( empty( $keywords ) ) {
			return null;
		}

		return implode( ', ', $keywords );
	}
}
