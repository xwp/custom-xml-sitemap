<?php
/**
 * Sitemap Generator.
 *
 * Generates and caches hierarchical XML sitemaps filtered by taxonomy terms.
 * Stores generated XML in post meta for fast retrieval.
 * Supports configurable granularity (year, month, or day).
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap;

use WP_Post;
use WP_Query;
use XWP\CustomXmlSitemap\Extensions\Image_Extension;
use XWP\CustomXmlSitemap\Extensions\News_Extension;

/**
 * Sitemap Generator.
 *
 * Creates XML sitemaps with hierarchical structure based on configured granularity:
 * - Year granularity: Index -> Year (URLs)
 * - Month granularity: Index -> Year -> Month (URLs)
 * - Day granularity: Index -> Year -> Month -> Day (URLs)
 *
 * Generated XML is cached in post meta on the cxs_sitemap CPT for fast retrieval.
 */
class Sitemap_Generator {

	/**
	 * Maximum URLs per sitemap file.
	 *
	 * @var int
	 */
	public const MAX_URLS_PER_SITEMAP = 1000;

	/**
	 * Meta key prefix for cached sitemap XML.
	 *
	 * @var string
	 */
	public const META_KEY_XML_PREFIX = 'cxs_sitemap_xml_';

	/**
	 * Meta key prefix for sitemap URL count.
	 *
	 * @var string
	 */
	public const META_KEY_URL_COUNT = 'cxs_sitemap_url_count_';

	/**
	 * Meta key for index XML.
	 *
	 * @var string
	 */
	public const META_KEY_INDEX_XML = 'cxs_sitemap_index_xml';

	/**
	 * XML namespace for image sitemap extension.
	 *
	 * @var string
	 */
	public const XMLNS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';

	/**
	 * XML namespace for news sitemap extension.
	 *
	 * @var string
	 */
	public const XMLNS_NEWS = 'http://www.google.com/schemas/sitemap-news/0.9';

	/**
	 * Sitemap post object.
	 *
	 * @var WP_Post
	 */
	private WP_Post $sitemap_post;

	/**
	 * Sitemap configuration.
	 *
	 * @var array{post_type: string, granularity: string, taxonomy: string, terms: array<int>, include_images: string, include_news: bool}
	 */
	private array $config;

	/**
	 * Image extension instance.
	 *
	 * @var Image_Extension|null
	 */
	private ?Image_Extension $image_extension = null;

	/**
	 * News extension instance.
	 *
	 * @var News_Extension|null
	 */
	private ?News_Extension $news_extension = null;

	/**
	 * Constructor.
	 *
	 * @param WP_Post $sitemap_post Custom sitemap post object.
	 */
	public function __construct( WP_Post $sitemap_post ) {
		$this->sitemap_post = $sitemap_post;
		$this->config       = Sitemap_CPT::get_sitemap_config( $sitemap_post->ID );
		$this->initialize_extensions();
	}

	/**
	 * Initialize sitemap extensions based on configuration.
	 *
	 * @return void
	 */
	private function initialize_extensions(): void {
		$include_images = $this->config['include_images'] ?? Sitemap_CPT::INCLUDE_IMAGES_NONE;

		if ( Sitemap_CPT::INCLUDE_IMAGES_NONE !== $include_images ) {
			$this->image_extension = new Image_Extension( $include_images );
		}

		if ( ! empty( $this->config['include_news'] ) ) {
			$this->news_extension = new News_Extension();
		}
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
	 * Get the configured post type for this sitemap.
	 *
	 * @return string Post type slug, defaults to 'post'.
	 */
	public function get_post_type(): string {
		return ! empty( $this->config['post_type'] ) ? $this->config['post_type'] : 'post';
	}

	/**
	 * Get the configured granularity for this sitemap.
	 *
	 * @return string Granularity setting (year, month, or day), defaults to 'month'.
	 */
	public function get_granularity(): string {
		return ! empty( $this->config['granularity'] ) ? $this->config['granularity'] : Sitemap_CPT::GRANULARITY_MONTH;
	}

	/**
	 * Get unique dates that have posts modified since a given timestamp.
	 *
	 * Queries posts by post_modified_gmt to find which sitemap dates need
	 * regeneration. This is the core of the deferred batch detection pattern.
	 *
	 * Returns dates at the appropriate granularity level for this sitemap.
	 *
	 * @param int $since_timestamp Unix timestamp of last check.
	 * @return array<array{year: int, month: int, day: int}> Array of date arrays.
	 */
	public function get_dates_with_modified_posts( int $since_timestamp ): array {
		global $wpdb;

		$since_datetime = gmdate( 'Y-m-d H:i:s', $since_timestamp );
		$post_type      = $this->get_post_type();
		$granularity    = $this->get_granularity();
		$where_clause   = $this->build_taxonomy_where_clause();

		// Build SELECT clause based on granularity.
		$select_fields = 'YEAR(p.post_date) as year';
		$group_by      = 'YEAR(p.post_date)';

		if ( Sitemap_CPT::GRANULARITY_MONTH === $granularity || Sitemap_CPT::GRANULARITY_DAY === $granularity ) {
			$select_fields .= ', MONTH(p.post_date) as month';
			$group_by      .= ', MONTH(p.post_date)';
		}

		if ( Sitemap_CPT::GRANULARITY_DAY === $granularity ) {
			$select_fields .= ', DAY(p.post_date) as day';
			$group_by      .= ', DAY(p.post_date)';
		}

		// Build prepare values: posts table, join tables, post_type, status, since_datetime, then taxonomy clause values.
		$prepare_values = array_merge(
			[ $wpdb->posts ],
			$where_clause['tables'],
			[ $post_type, 'publish', $since_datetime ],
			$where_clause['values']
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT {$select_fields}
				FROM %i p
				{$where_clause['join']}
				WHERE p.post_type = %s
				AND p.post_status = %s
				AND p.post_modified_gmt >= %s
				{$where_clause['where']}
				GROUP BY {$group_by}
				ORDER BY {$group_by} DESC",
				...$prepare_values
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( empty( $results ) ) {
			return [];
		}

		// Normalize results to always have year, month, day keys.
		$dates = [];
		foreach ( $results as $row ) {
			$dates[] = [
				'year'  => (int) $row['year'],
				'month' => ( Sitemap_CPT::GRANULARITY_YEAR === $granularity ) ? 0 : (int) $row['month'],
				'day'   => ( Sitemap_CPT::GRANULARITY_DAY === $granularity ) ? (int) $row['day'] : 0,
			];
		}

		return $dates;
	}

	/**
	 * Get sitemap index XML (from cache or generate).
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
	 * Get year sitemap XML (from cache or generate).
	 *
	 * @param int  $year             Year (4-digit).
	 * @param bool $force_regenerate Force regeneration even if cached.
	 * @return string XML content.
	 */
	public function get_year_sitemap( int $year, bool $force_regenerate = false ): string {
		$meta_key = self::META_KEY_XML_PREFIX . $year;

		if ( ! $force_regenerate ) {
			$cached = Sitemap_CPT::get_meta_direct( $this->sitemap_post->ID, $meta_key );
			if ( ! empty( $cached ) ) {
				return $cached;
			}
		}

		$xml = $this->generate_year_sitemap( $year );
		Sitemap_CPT::set_meta_direct( $this->sitemap_post->ID, $meta_key, $xml );

		return $xml;
	}

	/**
	 * Get month sitemap XML (from cache or generate).
	 *
	 * Behavior depends on granularity setting:
	 * - Year granularity: Not used (year sitemap contains URLs directly)
	 * - Month granularity: Returns urlset with post URLs for the month
	 * - Day granularity: Returns sitemap index listing day sitemaps
	 *
	 * @param int  $year             Year (4-digit).
	 * @param int  $month            Month (1-12).
	 * @param bool $force_regenerate Force regeneration even if cached.
	 * @return string XML content.
	 */
	public function get_month_sitemap( int $year, int $month, bool $force_regenerate = false ): string {
		$month_padded = str_pad( (string) $month, 2, '0', STR_PAD_LEFT );
		$meta_key     = self::META_KEY_XML_PREFIX . $year . '_' . $month_padded;

		if ( ! $force_regenerate ) {
			$cached = Sitemap_CPT::get_meta_direct( $this->sitemap_post->ID, $meta_key );
			if ( ! empty( $cached ) ) {
				return $cached;
			}
		}

		$xml       = $this->generate_month_sitemap( $year, $month );
		$url_count = substr_count( $xml, '<url>' );

		Sitemap_CPT::set_meta_direct( $this->sitemap_post->ID, $meta_key, $xml );
		update_post_meta( $this->sitemap_post->ID, self::META_KEY_URL_COUNT . $year . '_' . $month_padded, $url_count );

		return $xml;
	}

	/**
	 * Get day sitemap XML (from cache or generate).
	 *
	 * Only used when granularity is set to 'day'. Returns urlset with post URLs for the day.
	 *
	 * @param int  $year             Year (4-digit).
	 * @param int  $month            Month (1-12).
	 * @param int  $day              Day (1-31).
	 * @param bool $force_regenerate Force regeneration even if cached.
	 * @return string XML content.
	 */
	public function get_day_sitemap( int $year, int $month, int $day, bool $force_regenerate = false ): string {
		$month_padded = str_pad( (string) $month, 2, '0', STR_PAD_LEFT );
		$day_padded   = str_pad( (string) $day, 2, '0', STR_PAD_LEFT );
		$meta_key     = self::META_KEY_XML_PREFIX . $year . '_' . $month_padded . '_' . $day_padded;

		if ( ! $force_regenerate ) {
			$cached = Sitemap_CPT::get_meta_direct( $this->sitemap_post->ID, $meta_key );
			if ( ! empty( $cached ) ) {
				return $cached;
			}
		}

		$xml       = $this->generate_day_sitemap( $year, $month, $day );
		$url_count = substr_count( $xml, '<url>' );

		Sitemap_CPT::set_meta_direct( $this->sitemap_post->ID, $meta_key, $xml );
		update_post_meta( $this->sitemap_post->ID, self::META_KEY_URL_COUNT . $year . '_' . $month_padded . '_' . $day_padded, $url_count );

		return $xml;
	}

	/**
	 * Regenerate all cached sitemaps for this custom sitemap.
	 *
	 * Called when sitemap configuration changes or content is updated.
	 * Regenerates sitemaps based on the configured granularity level.
	 *
	 * @return array{index: bool, years: array<int>, months: array<string>, days: array<string>} Summary of regeneration.
	 */
	public function regenerate_all(): array {
		$summary = [
			'index'  => false,
			'years'  => [],
			'months' => [],
			'days'   => [],
		];

		// Clear all cached XML.
		$this->clear_all_cached_xml();

		// Regenerate index.
		$this->get_index( true );
		$summary['index'] = true;

		$granularity = $this->get_granularity();
		$years       = $this->get_years_with_content();

		foreach ( $years as $year ) {
			$this->get_year_sitemap( $year, true );
			$summary['years'][] = $year;

			// Year granularity: no month sitemaps needed.
			if ( Sitemap_CPT::GRANULARITY_YEAR === $granularity ) {
				continue;
			}

			// Month/Day granularity: regenerate month sitemaps.
			$months = $this->get_months_with_content( $year );
			foreach ( $months as $month ) {
				$this->get_month_sitemap( $year, $month, true );
				$summary['months'][] = "{$year}-{$month}";

				// Day granularity: regenerate day sitemaps.
				if ( Sitemap_CPT::GRANULARITY_DAY === $granularity ) {
					$days = $this->get_days_with_content( $year, $month );
					foreach ( $days as $day ) {
						$this->get_day_sitemap( $year, $month, $day, true );
						$summary['days'][] = "{$year}-{$month}-{$day}";
					}
				}
			}
		}

		return $summary;
	}

	/**
	 * Invalidate cached sitemap for a specific year/month.
	 *
	 * @param int      $year  Year.
	 * @param int|null $month Month (null to invalidate entire year).
	 * @return void
	 */
	public function invalidate_cache( int $year, ?int $month = null ): void {
		self::batch_invalidate_cache( [ $this->sitemap_post->ID ], $year, $month );
	}

	/**
	 * Batch invalidate cached sitemaps for multiple sitemap post IDs.
	 *
	 * Uses a single database query to delete meta keys for all specified
	 * sitemap posts, improving performance when many sitemaps need invalidation.
	 *
	 * @param array<int> $sitemap_post_ids Array of sitemap post IDs.
	 * @param int        $year             Year.
	 * @param int|null   $month            Month (null to invalidate entire year).
	 * @return void
	 */
	public static function batch_invalidate_cache( array $sitemap_post_ids, int $year, ?int $month = null ): void {
		if ( empty( $sitemap_post_ids ) ) {
			return;
		}

		global $wpdb;

		// Sanitize post IDs.
		$sitemap_post_ids = wp_parse_id_list( $sitemap_post_ids );

		if ( empty( $sitemap_post_ids ) ) {
			return;
		}

		// Build meta keys to delete.
		$meta_keys = [
			self::META_KEY_INDEX_XML,
			self::META_KEY_XML_PREFIX . $year,
		];

		if ( null !== $month ) {
			$month_padded = str_pad( (string) $month, 2, '0', STR_PAD_LEFT );
			$meta_keys[]  = self::META_KEY_XML_PREFIX . $year . '_' . $month_padded;
			$meta_keys[]  = self::META_KEY_URL_COUNT . $year . '_' . $month_padded;
		}

		// Build placeholders for the query.
		$post_id_placeholders  = implode( ', ', array_fill( 0, count( $sitemap_post_ids ), '%d' ) );
		$meta_key_placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

		// Combine values for prepare: post IDs first, then meta keys.
		$prepare_values = array_merge( $sitemap_post_ids, $meta_keys );

		// Delete all matching meta in a single query.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE post_id IN ({$post_id_placeholders}) AND meta_key IN ({$meta_key_placeholders})",
				$wpdb->postmeta,
				...$prepare_values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// Clear object cache for affected posts.
		foreach ( $sitemap_post_ids as $post_id ) {
			wp_cache_delete( $post_id, 'post_meta' );
		}
	}

	/**
	 * Check if any sitemap period has reached the URL limit.
	 *
	 * Queries cached URL counts from post meta to determine if any
	 * sitemap file has hit the maximum URL limit.
	 *
	 * @return bool True if any sitemap has reached the limit.
	 */
	public function has_exceeded_url_limit(): bool {
		global $wpdb;

		// Query for any URL count that has reached the limit.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE post_id = %d AND meta_key LIKE %s AND CAST(meta_value AS UNSIGNED) >= %d',
				$wpdb->postmeta,
				$this->sitemap_post->ID,
				$wpdb->esc_like( self::META_KEY_URL_COUNT ) . '%',
				self::MAX_URLS_PER_SITEMAP
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Clear all cached XML for this sitemap.
	 *
	 * @return void
	 */
	public function clear_all_cached_xml(): void {
		global $wpdb;

		// Delete all meta keys starting with our prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE post_id = %d AND (meta_key LIKE %s OR meta_key = %s)',
				$wpdb->postmeta,
				$this->sitemap_post->ID,
				$wpdb->esc_like( self::META_KEY_XML_PREFIX ) . '%',
				self::META_KEY_INDEX_XML
			)
		);

		// Clear object cache for this post's meta.
		wp_cache_delete( $this->sitemap_post->ID, 'post_meta' );
	}

	/**
	 * Generate sitemap index XML.
	 *
	 * @return string XML content.
	 */
	private function generate_index(): string {
		$years = $this->get_years_with_content();

		if ( empty( $years ) ) {
			return $this->generate_empty_sitemap_index();
		}

		$xml = $this->get_sitemap_index_header();

		foreach ( $years as $year ) {
			$sitemap_url   = home_url( "/sitemaps/{$this->sitemap_post->post_name}/{$year}.xml" );
			$last_modified = $this->get_last_modified_for_date( $year );

			$xml .= $this->build_sitemap_entry( $sitemap_url, $last_modified );
		}

		$xml .= $this->get_sitemap_index_footer();

		return $xml;
	}

	/**
	 * Generate year sitemap XML.
	 *
	 * Behavior depends on granularity setting:
	 * - Year granularity: Returns urlset with post URLs for the entire year
	 * - Month/Day granularity: Returns sitemap index listing month sitemaps
	 *
	 * @param int $year Year (4-digit).
	 * @return string XML content.
	 */
	private function generate_year_sitemap( int $year ): string {
		$granularity = $this->get_granularity();

		// Year granularity: return URLs directly.
		if ( Sitemap_CPT::GRANULARITY_YEAR === $granularity ) {
			return $this->generate_year_sitemap_as_urlset( $year );
		}

		// Month/Day granularity: return sitemap index listing months.
		$months = $this->get_months_with_content( $year );

		if ( empty( $months ) ) {
			return $this->generate_empty_sitemap_index();
		}

		$xml = $this->get_sitemap_index_header();

		foreach ( $months as $month ) {
			$month_padded  = str_pad( (string) $month, 2, '0', STR_PAD_LEFT );
			$sitemap_url   = home_url( "/sitemaps/{$this->sitemap_post->post_name}/{$year}-{$month_padded}.xml" );
			$last_modified = $this->get_last_modified_for_date( $year, $month );

			$xml .= $this->build_sitemap_entry( $sitemap_url, $last_modified );
		}

		$xml .= $this->get_sitemap_index_footer();

		return $xml;
	}

	/**
	 * Generate year sitemap as urlset (for year granularity).
	 *
	 * Returns a urlset containing all post URLs for the entire year.
	 *
	 * @param int $year Year (4-digit).
	 * @return string XML content.
	 */
	private function generate_year_sitemap_as_urlset( int $year ): string {
		$posts = $this->get_posts_for_date( $year );

		if ( empty( $posts ) ) {
			return $this->generate_empty_urlset();
		}

		$xml = $this->get_urlset_header();

		foreach ( $posts as $post ) {
			$xml .= $this->build_url_entry( $post );
		}

		$xml .= $this->get_urlset_footer();

		return $xml;
	}

	/**
	 * Generate month sitemap XML.
	 *
	 * Behavior depends on granularity setting:
	 * - Year granularity: Not typically called (year sitemap has URLs)
	 * - Month granularity: Returns urlset with post URLs for the month
	 * - Day granularity: Returns sitemap index listing day sitemaps
	 *
	 * @param int $year  Year (4-digit).
	 * @param int $month Month (1-12).
	 * @return string XML content.
	 */
	private function generate_month_sitemap( int $year, int $month ): string {
		$granularity = $this->get_granularity();

		// Day granularity: return sitemap index listing days.
		if ( Sitemap_CPT::GRANULARITY_DAY === $granularity ) {
			return $this->generate_month_sitemap_as_index( $year, $month );
		}

		// Month/Year granularity: return URLs directly.
		$posts = $this->get_posts_for_date( $year, $month );

		if ( empty( $posts ) ) {
			return $this->generate_empty_urlset();
		}

		$xml = $this->get_urlset_header();

		foreach ( $posts as $post ) {
			$xml .= $this->build_url_entry( $post );
		}

		$xml .= $this->get_urlset_footer();

		return $xml;
	}

	/**
	 * Generate month sitemap as index (for day granularity).
	 *
	 * Returns a sitemap index listing all days with content in the month.
	 *
	 * @param int $year  Year (4-digit).
	 * @param int $month Month (1-12).
	 * @return string XML content.
	 */
	private function generate_month_sitemap_as_index( int $year, int $month ): string {
		$days = $this->get_days_with_content( $year, $month );

		if ( empty( $days ) ) {
			return $this->generate_empty_sitemap_index();
		}

		$month_padded = str_pad( (string) $month, 2, '0', STR_PAD_LEFT );
		$xml          = $this->get_sitemap_index_header();

		foreach ( $days as $day ) {
			$day_padded    = str_pad( (string) $day, 2, '0', STR_PAD_LEFT );
			$sitemap_url   = home_url( "/sitemaps/{$this->sitemap_post->post_name}/{$year}-{$month_padded}-{$day_padded}.xml" );
			$last_modified = $this->get_last_modified_for_date( $year, $month, $day );

			$xml .= $this->build_sitemap_entry( $sitemap_url, $last_modified );
		}

		$xml .= $this->get_sitemap_index_footer();

		return $xml;
	}

	/**
	 * Generate day sitemap XML.
	 *
	 * Returns urlset with post URLs for a specific day.
	 * Only used when granularity is set to 'day'.
	 *
	 * @param int $year  Year (4-digit).
	 * @param int $month Month (1-12).
	 * @param int $day   Day (1-31).
	 * @return string XML content.
	 */
	private function generate_day_sitemap( int $year, int $month, int $day ): string {
		$posts = $this->get_posts_for_date( $year, $month, $day );

		if ( empty( $posts ) ) {
			return $this->generate_empty_urlset();
		}

		$xml = $this->get_urlset_header();

		foreach ( $posts as $post ) {
			$xml .= $this->build_url_entry( $post );
		}

		$xml .= $this->get_urlset_footer();

		return $xml;
	}

	/**
	 * Get years that have content matching the sitemap filters.
	 *
	 * @return array<int> Array of years.
	 */
	private function get_years_with_content(): array {
		global $wpdb;

		$where_clause = $this->build_taxonomy_where_clause();

		// Build prepare values array: posts table, join tables, post_type, status, then taxonomy clause values.
		$prepare_values = array_merge(
			[ $wpdb->posts ],
			$where_clause['tables'],
			[ $this->get_post_type(), 'publish' ],
			$where_clause['values']
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT YEAR(p.post_date) as year
				FROM %i p
				{$where_clause['join']}
				WHERE p.post_type = %s
				AND p.post_status = %s
				{$where_clause['where']}
				ORDER BY year DESC",
				...$prepare_values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', $results );
	}

	/**
	 * Get months that have content for a given year.
	 *
	 * @param int $year Year (4-digit).
	 * @return array<int> Array of months (1-12).
	 */
	private function get_months_with_content( int $year ): array {
		global $wpdb;

		$where_clause = $this->build_taxonomy_where_clause();

		// Build prepare values array: posts table, join tables, post_type, status, year, then taxonomy clause values.
		$prepare_values = array_merge(
			[ $wpdb->posts ],
			$where_clause['tables'],
			[ $this->get_post_type(), 'publish', $year ],
			$where_clause['values']
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT MONTH(p.post_date) as month
				FROM %i p
				{$where_clause['join']}
				WHERE p.post_type = %s
				AND p.post_status = %s
				AND YEAR(p.post_date) = %d
				{$where_clause['where']}
				ORDER BY month DESC",
				...$prepare_values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', $results );
	}

	/**
	 * Get posts for a specific date period.
	 *
	 * @param int $year  Year (4-digit).
	 * @param int $month Month (1-12), 0 to ignore.
	 * @param int $day   Day (1-31), 0 to ignore.
	 * @return array<WP_Post> Array of post objects.
	 */
	private function get_posts_for_date( int $year, int $month = 0, int $day = 0 ): array {
		$date_query = [ 'year' => $year ];

		if ( $month > 0 ) {
			$date_query['month'] = $month;
		}

		if ( $day > 0 ) {
			$date_query['day'] = $day;
		}

		$args = [
			'post_type'              => $this->get_post_type(),
			'post_status'            => 'publish',
			'posts_per_page'         => self::MAX_URLS_PER_SITEMAP,
			'date_query'             => [ $date_query ],
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'suppress_filters'       => false,
		];

		$args  = $this->add_taxonomy_query( $args );
		$query = new WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Get days that have content for a given year/month (for day granularity).
	 *
	 * @param int $year  Year (4-digit).
	 * @param int $month Month (1-12).
	 * @return array<int> Array of days (1-31).
	 */
	private function get_days_with_content( int $year, int $month ): array {
		global $wpdb;

		$where_clause = $this->build_taxonomy_where_clause();

		// Build prepare values array: posts table, join tables, post_type, status, year, month, then taxonomy clause values.
		$prepare_values = array_merge(
			[ $wpdb->posts ],
			$where_clause['tables'],
			[ $this->get_post_type(), 'publish', $year, $month ],
			$where_clause['values']
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT DAY(p.post_date) as day
				FROM %i p
				{$where_clause['join']}
				WHERE p.post_type = %s
				AND p.post_status = %s
				AND YEAR(p.post_date) = %d
				AND MONTH(p.post_date) = %d
				{$where_clause['where']}
				ORDER BY day DESC",
				...$prepare_values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', $results );
	}

	/**
	 * Build taxonomy WHERE clause for direct SQL queries.
	 *
	 * Uses wp_parse_id_list for term IDs and wpdb->prepare for the taxonomy
	 * to ensure proper sanitization of all values. Table names are returned
	 * separately in 'tables' array for use with %i placeholder.
	 *
	 * @return array{join: string, where: string, values: array<mixed>, tables: array<string>} SQL clause parts.
	 */
	private function build_taxonomy_where_clause(): array {
		$result = [
			'join'   => '',
			'where'  => '',
			'values' => [],
			'tables' => [],
		];

		if ( empty( $this->config['taxonomy'] ) || ! taxonomy_exists( $this->config['taxonomy'] ) ) {
			return $result;
		}

		global $wpdb;

		// Default JOIN-based clause covers both "no terms selected" and include-mode cases.
		$result['join']   = 'INNER JOIN %i tr ON p.ID = tr.object_id
			INNER JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id';
		$result['tables'] = [ $wpdb->term_relationships, $wpdb->term_taxonomy ];

		$result['where']    = 'AND tt.taxonomy = %s';
		$result['values'][] = $this->config['taxonomy'];

		if ( empty( $this->config['terms'] ) || ! is_array( $this->config['terms'] ) ) {
			return $result;
		}

		$term_ids = wp_parse_id_list( $this->config['terms'] );
		if ( empty( $term_ids ) ) {
			return $result;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );
		$is_exclude   = Sitemap_CPT::FILTER_MODE_EXCLUDE === ( $this->config['filter_mode'] ?? Sitemap_CPT::FILTER_MODE_INCLUDE );

		if ( $is_exclude ) {
			// Exclude mode: NOT EXISTS subquery. Includes posts with no terms in
			// this taxonomy and excludes posts that have ANY of the selected terms,
			// even if they also have other terms. Table names move into 'values'
			// because the %i placeholders live in the WHERE clause.
			$result['join']   = '';
			$result['tables'] = [];
			$result['where']  = "AND NOT EXISTS (
				SELECT 1 FROM %i etr
				INNER JOIN %i ett ON etr.term_taxonomy_id = ett.term_taxonomy_id
				WHERE etr.object_id = p.ID
				AND ett.taxonomy = %s
				AND ett.term_id IN ({$placeholders})
			)";
			$result['values'] = array_merge(
				[ $wpdb->term_relationships, $wpdb->term_taxonomy, $this->config['taxonomy'] ],
				$term_ids
			);

			return $result;
		}

		// Include mode: filter to only posts with the specified terms.
		$result['where'] .= " AND tt.term_id IN ({$placeholders})";
		$result['values'] = array_merge( $result['values'], $term_ids );

		return $result;
	}

	/**
	 * Add taxonomy query to WP_Query args.
	 *
	 * @param array<string, mixed> $args WP_Query arguments.
	 * @return array<string, mixed> Modified arguments.
	 */
	private function add_taxonomy_query( array $args ): array {
		if ( empty( $this->config['taxonomy'] ) || ! taxonomy_exists( $this->config['taxonomy'] ) ) {
			return $args;
		}

		if ( ! empty( $this->config['terms'] ) && is_array( $this->config['terms'] ) ) {
			$is_exclude = Sitemap_CPT::FILTER_MODE_EXCLUDE === ( $this->config['filter_mode'] ?? Sitemap_CPT::FILTER_MODE_INCLUDE );

			$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => $this->config['taxonomy'],
					'field'    => 'term_id',
					'terms'    => array_map( 'absint', $this->config['terms'] ),
					'operator' => $is_exclude ? 'NOT IN' : 'IN',
				],
			];
		}

		return $args;
	}

	/**
	 * Get last modified date for a date period.
	 *
	 * @param int $year  Year (4-digit).
	 * @param int $month Month (1-12), 0 to ignore.
	 * @param int $day   Day (1-31), 0 to ignore.
	 * @return string|null W3C formatted date or null.
	 */
	private function get_last_modified_for_date( int $year, int $month = 0, int $day = 0 ): ?string {
		$date_query = [ 'year' => $year ];

		if ( $month > 0 ) {
			$date_query['month'] = $month;
		}

		if ( $day > 0 ) {
			$date_query['day'] = $day;
		}

		$args = [
			'post_type'              => $this->get_post_type(),
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'date_query'             => [ $date_query ],
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'suppress_filters'       => false,
		];

		$args  = $this->add_taxonomy_query( $args );
		$query = new WP_Query( $args );

		if ( empty( $query->posts ) ) {
			return null;
		}

		$post = get_post( $query->posts[0] );
		if ( ! $post ) {
			return null;
		}

		$modified = mysql2date( 'c', $post->post_modified_gmt );

		return is_string( $modified ) ? $modified : null;
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
	 * Dynamically includes image and news namespaces when extensions are enabled.
	 *
	 * @return string XML header.
	 */
	private function get_urlset_header(): string {
		$xsl_url    = home_url( '/cxs-sitemap.xsl' );
		$namespaces = $this->build_namespace_attributes();

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>' . "\n" .
			'<urlset ' . $namespaces . '>' . "\n";
	}

	/**
	 * Build XML namespace attributes for urlset element.
	 *
	 * @return string Space-separated namespace attribute declarations.
	 */
	private function build_namespace_attributes(): string {
		$namespaces = [ 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' ];

		if ( null !== $this->image_extension ) {
			$namespaces[] = 'xmlns:image="' . self::XMLNS_IMAGE . '"';
		}

		if ( null !== $this->news_extension ) {
			$namespaces[] = 'xmlns:news="' . self::XMLNS_NEWS . '"';
		}

		return implode( ' ', $namespaces );
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
	 * Generate empty sitemap index.
	 *
	 * @return string XML content.
	 */
	private function generate_empty_sitemap_index(): string {
		return $this->get_sitemap_index_header() . $this->get_sitemap_index_footer();
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
	 * Build sitemap entry for index.
	 *
	 * @param string      $url           Sitemap URL.
	 * @param string|null $last_modified Last modified date.
	 * @return string XML entry.
	 */
	private function build_sitemap_entry( string $url, ?string $last_modified ): string {
		$xml  = "\t<sitemap>\n";
		$xml .= "\t\t<loc>" . esc_url( $url ) . "</loc>\n";

		if ( $last_modified ) {
			$xml .= "\t\t<lastmod>" . esc_html( $last_modified ) . "</lastmod>\n";
		}

		$xml .= "\t</sitemap>\n";

		return $xml;
	}

	/**
	 * Build URL entry for urlset.
	 *
	 * Includes image and news extension elements when enabled.
	 *
	 * @param WP_Post $post Post object.
	 * @return string XML entry.
	 */
	private function build_url_entry( WP_Post $post ): string {
		$url           = get_permalink( $post );
		$last_modified = mysql2date( 'c', $post->post_modified_gmt );

		// Ensure last_modified is a string for esc_html().
		if ( ! is_string( $last_modified ) ) {
			$timestamp     = strtotime( $post->post_modified_gmt );
			$last_modified = gmdate( 'c', false !== $timestamp ? $timestamp : time() );
		}

		$xml  = "\t<url>\n";
		$xml .= "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
		$xml .= "\t\t<lastmod>" . esc_html( $last_modified ) . "</lastmod>\n";
		$xml .= $this->build_image_extension_xml( $post );
		$xml .= $this->build_news_extension_xml( $post );
		$xml .= "\t</url>\n";

		return $xml;
	}

	/**
	 * Build image extension XML for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string XML string with image:image elements, or empty string.
	 */
	private function build_image_extension_xml( WP_Post $post ): string {
		if ( null === $this->image_extension ) {
			return '';
		}

		return $this->image_extension->build_xml( $post );
	}

	/**
	 * Build news extension XML for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string XML string with news:news element, or empty string.
	 */
	private function build_news_extension_xml( WP_Post $post ): string {
		if ( null === $this->news_extension ) {
			return '';
		}

		return $this->news_extension->build_xml( $post );
	}
}
