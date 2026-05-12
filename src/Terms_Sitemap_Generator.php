<?php
/**
 * Terms Sitemap Generator.
 *
 * Generates and caches XML sitemaps listing taxonomy term archive URLs.
 * Uses the same caching pattern as Sitemap_Generator, storing
 * generated XML in post meta for fast retrieval.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap;

use WP_Error;
use WP_Post;
use WP_Term;

/**
 * Terms Sitemap Generator.
 *
 * Creates XML sitemaps listing taxonomy term archive URLs (e.g., /topics/gaming/).
 * Supports pagination for large taxonomies (1000 terms per page).
 *
 * Unlike post-based sitemaps, terms sitemaps do not include <lastmod> elements
 * since taxonomy terms don't have inherent modification timestamps.
 *
 * URL structure:
 * - /sitemaps/{slug}/index.xml - Index (contains all URLs if <= 1000 terms, otherwise lists page sitemaps)
 * - /sitemaps/{slug}/page-1.xml - First 1000 terms (only when paginated)
 * - /sitemaps/{slug}/page-2.xml - Next 1000 terms, etc.
 */
class Terms_Sitemap_Generator {

	/**
	 * Maximum terms per sitemap file.
	 *
	 * @var int
	 */
	public const MAX_TERMS_PER_SITEMAP = 1000;

	/**
	 * Meta key for index XML cache.
	 *
	 * @var string
	 */
	public const META_KEY_INDEX_XML = 'cxs_terms_sitemap_index_xml';

	/**
	 * Meta key prefix for page XML cache.
	 *
	 * @var string
	 */
	public const META_KEY_PAGE_XML_PREFIX = 'cxs_terms_sitemap_page_';

	/**
	 * Meta key for cached term count.
	 *
	 * @var string
	 */
	public const META_KEY_TERM_COUNT = 'cxs_terms_sitemap_term_count';

	/**
	 * Sitemap post object.
	 *
	 * @var WP_Post
	 */
	private WP_Post $sitemap_post;

	/**
	 * Sitemap configuration.
	 *
	 * @var array{mode: string, post_type: string, granularity: string, taxonomy: string, terms: array<int>, include_images: string, include_news: bool, terms_hide_empty: bool}
	 */
	private array $config;

	/**
	 * Constructor.
	 *
	 * @param WP_Post $sitemap_post Custom sitemap post object.
	 */
	public function __construct( WP_Post $sitemap_post ) {
		$this->sitemap_post = $sitemap_post;
		$this->config       = Sitemap_CPT::get_sitemap_config( $sitemap_post->ID );
	}

	/**
	 * Get the sitemap post object.
	 *
	 * @return WP_Post The sitemap post object.
	 */
	public function get_sitemap_post(): WP_Post {
		return $this->sitemap_post;
	}

	/**
	 * Get the configured taxonomy for this sitemap.
	 *
	 * @return string Taxonomy slug, or empty string if not configured.
	 */
	public function get_taxonomy(): string {
		return ! empty( $this->config['taxonomy'] ) ? $this->config['taxonomy'] : '';
	}

	/**
	 * Get whether to hide empty terms.
	 *
	 * @return bool True to hide empty terms, false to include all terms.
	 */
	public function get_hide_empty(): bool {
		return $this->config['terms_hide_empty'] ?? true;
	}

	/**
	 * Get sitemap index XML (from cache or generate).
	 *
	 * If the taxonomy has 1000 or fewer terms, the index contains all term URLs directly.
	 * If more than 1000 terms, the index lists page sitemaps (page-1.xml, page-2.xml, etc.).
	 *
	 * @param bool $force_regenerate Force regeneration even if cached.
	 * @return string XML content.
	 */
	public function get_index( bool $force_regenerate = false ): string {
		if ( ! $force_regenerate ) {
			$cached = Sitemap_CPT::get_meta_direct( $this->sitemap_post->ID, self::META_KEY_INDEX_XML );
			if ( ! empty( $cached ) ) {
				return $cached;
			}
		}

		$xml = $this->generate_index();
		Sitemap_CPT::set_meta_direct( $this->sitemap_post->ID, self::META_KEY_INDEX_XML, $xml );

		return $xml;
	}

	/**
	 * Get page sitemap XML (from cache or generate).
	 *
	 * Returns urlset with term archive URLs for the specified page.
	 * Returns empty urlset if page number is out of valid range.
	 *
	 * @param int  $page             Page number (1-based).
	 * @param bool $force_regenerate Force regeneration even if cached.
	 * @return string XML content.
	 */
	public function get_page( int $page, bool $force_regenerate = false ): string {
		// Validate page number is within valid range.
		if ( $page < 1 || $page > $this->get_page_count() ) {
			return $this->generate_empty_urlset();
		}

		$meta_key = self::META_KEY_PAGE_XML_PREFIX . $page;

		if ( ! $force_regenerate ) {
			$cached = Sitemap_CPT::get_meta_direct( $this->sitemap_post->ID, $meta_key );
			if ( ! empty( $cached ) ) {
				return $cached;
			}
		}

		$xml = $this->generate_page( $page );
		Sitemap_CPT::set_meta_direct( $this->sitemap_post->ID, $meta_key, $xml );

		return $xml;
	}

	/**
	 * Regenerate all cached sitemaps for this terms sitemap.
	 *
	 * Called when sitemap configuration changes or terms are modified.
	 *
	 * @return array{index: bool, pages: array<int>} Summary of regeneration (index and pages processed).
	 */
	public function regenerate_all(): array {
		$summary = [
			'index' => false,
			'pages' => [],
		];

		// Clear all cached XML.
		$this->clear_all_cached_xml();

		// Regenerate index.
		$this->get_index( true );
		$summary['index'] = true;

		// Regenerate page sitemaps if paginated.
		$page_count = $this->get_page_count();
		if ( $page_count > 1 ) {
			for ( $page = 1; $page <= $page_count; $page++ ) {
				$this->get_page( $page, true );
				$summary['pages'][] = $page;
			}
		}

		return $summary;
	}

	/**
	 * Clear all cached XML for this sitemap.
	 *
	 * Deletes all terms sitemap meta keys including index, pages, and term count.
	 *
	 * @return void
	 */
	public function clear_all_cached_xml(): void {
		global $wpdb;

		// Delete all meta keys for this terms sitemap.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE post_id = %d AND (meta_key LIKE %s OR meta_key = %s OR meta_key = %s)',
				$wpdb->postmeta,
				$this->sitemap_post->ID,
				$wpdb->esc_like( self::META_KEY_PAGE_XML_PREFIX ) . '%',
				self::META_KEY_INDEX_XML,
				self::META_KEY_TERM_COUNT
			)
		);

		// Clear object cache for this post's meta.
		wp_cache_delete( $this->sitemap_post->ID, 'post_meta' );
	}

	/**
	 * Get the total number of terms matching the sitemap configuration.
	 *
	 * @return int Term count.
	 */
	public function get_terms_count(): int {
		$taxonomy = $this->get_taxonomy();

		if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$count = wp_count_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => $this->get_hide_empty(),
			]
		);

		if ( $count instanceof WP_Error ) {
			return 0;
		}

		return (int) $count;
	}

	/**
	 * Get the number of pages needed for this sitemap.
	 *
	 * @return int Page count (minimum 1).
	 */
	public function get_page_count(): int {
		$term_count = $this->get_terms_count();

		if ( 0 === $term_count ) {
			return 1;
		}

		return (int) ceil( $term_count / self::MAX_TERMS_PER_SITEMAP );
	}

	/**
	 * Get terms for a specific page.
	 *
	 * @param int $page Page number (1-based).
	 * @return array<WP_Term> Array of WP_Term objects.
	 */
	public function get_terms_for_page( int $page ): array {
		$taxonomy = $this->get_taxonomy();

		if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$offset = ( $page - 1 ) * self::MAX_TERMS_PER_SITEMAP;

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => $this->get_hide_empty(),
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => self::MAX_TERMS_PER_SITEMAP,
				'offset'     => $offset,
			]
		);

		if ( $terms instanceof WP_Error ) {
			return [];
		}

		return $terms;
	}

	/**
	 * Generate sitemap index XML.
	 *
	 * If <= 1000 terms, returns a urlset with all term URLs.
	 * If > 1000 terms, returns a sitemap index listing page sitemaps.
	 *
	 * @return string XML content.
	 */
	private function generate_index(): string {
		$term_count = $this->get_terms_count();

		// Cache the term count for stats/debugging.
		Sitemap_CPT::set_meta_direct( $this->sitemap_post->ID, self::META_KEY_TERM_COUNT, (string) $term_count );

		// If no terms, return empty urlset.
		if ( 0 === $term_count ) {
			return $this->generate_empty_urlset();
		}

		// If 1000 or fewer terms, include all URLs directly in index.
		if ( $term_count <= self::MAX_TERMS_PER_SITEMAP ) {
			return $this->generate_index_as_urlset();
		}

		// More than 1000 terms: return sitemap index listing page sitemaps.
		return $this->generate_index_as_sitemap_index();
	}

	/**
	 * Generate index as urlset (when <= 1000 terms).
	 *
	 * Returns a urlset containing all term archive URLs.
	 *
	 * @return string XML content.
	 */
	private function generate_index_as_urlset(): string {
		$terms = $this->get_terms_for_page( 1 );

		if ( empty( $terms ) ) {
			return $this->generate_empty_urlset();
		}

		$xml = $this->get_urlset_header();

		foreach ( $terms as $term ) {
			$xml .= $this->build_url_entry( $term );
		}

		$xml .= $this->get_urlset_footer();

		return $xml;
	}

	/**
	 * Generate index as sitemap index (when > 1000 terms).
	 *
	 * Returns a sitemap index listing page sitemaps.
	 *
	 * @return string XML content.
	 */
	private function generate_index_as_sitemap_index(): string {
		$page_count = $this->get_page_count();

		$xml = $this->get_sitemap_index_header();

		for ( $page = 1; $page <= $page_count; $page++ ) {
			$sitemap_url = home_url( "/sitemaps/{$this->sitemap_post->post_name}/page-{$page}.xml" );
			$xml        .= $this->build_sitemap_entry( $sitemap_url );
		}

		$xml .= $this->get_sitemap_index_footer();

		return $xml;
	}

	/**
	 * Generate page sitemap XML.
	 *
	 * Returns urlset with term archive URLs for the specified page.
	 *
	 * @param int $page Page number (1-based).
	 * @return string XML content.
	 */
	private function generate_page( int $page ): string {
		$terms = $this->get_terms_for_page( $page );

		if ( empty( $terms ) ) {
			return $this->generate_empty_urlset();
		}

		$xml = $this->get_urlset_header();

		foreach ( $terms as $term ) {
			$xml .= $this->build_url_entry( $term );
		}

		$xml .= $this->get_urlset_footer();

		return $xml;
	}

	/**
	 * Get sitemap index XML header.
	 *
	 * Includes XSL stylesheet reference for browser display.
	 *
	 * @return string XML header.
	 */
	private function get_sitemap_index_header(): string {
		$xsl_url = home_url( '/cxs-sitemap-index.xsl' );

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>' . "\n" .
			'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	}

	/**
	 * Get sitemap index XML footer.
	 *
	 * @return string XML footer.
	 */
	private function get_sitemap_index_footer(): string {
		return '</sitemapindex>' . "\n";
	}

	/**
	 * Get urlset XML header.
	 *
	 * Includes XSL stylesheet reference for browser display.
	 *
	 * @return string XML header.
	 */
	private function get_urlset_header(): string {
		$xsl_url = home_url( '/cxs-sitemap.xsl' );

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>' . "\n" .
			'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	}

	/**
	 * Get urlset XML footer.
	 *
	 * @return string XML footer.
	 */
	private function get_urlset_footer(): string {
		return '</urlset>' . "\n";
	}

	/**
	 * Generate empty urlset.
	 *
	 * @return string XML content.
	 */
	private function generate_empty_urlset(): string {
		return $this->get_urlset_header() . $this->get_urlset_footer();
	}

	/**
	 * Build sitemap entry for sitemap index.
	 *
	 * Note: No <lastmod> element since term sitemaps don't track modification dates.
	 *
	 * @param string $url Sitemap URL.
	 * @return string XML entry.
	 */
	private function build_sitemap_entry( string $url ): string {
		$xml  = "\t<sitemap>\n";
		$xml .= "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
		$xml .= "\t</sitemap>\n";

		return $xml;
	}

	/**
	 * Build URL entry for urlset.
	 *
	 * Note: No <lastmod> element since taxonomy terms don't have
	 * inherent modification timestamps.
	 *
	 * @param WP_Term $term Term object.
	 * @return string XML entry.
	 */
	private function build_url_entry( WP_Term $term ): string {
		$url = get_term_link( $term );

		// Skip if get_term_link returns an error.
		if ( $url instanceof WP_Error ) {
			return '';
		}

		$xml  = "\t<url>\n";
		$xml .= "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
		$xml .= "\t</url>\n";

		return $xml;
	}
}
