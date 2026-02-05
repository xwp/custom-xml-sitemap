<?php
/**
 * Sitemap CLI Command.
 *
 * Provides WP-CLI commands for managing custom sitemaps including listing,
 * regenerating, validating, and displaying statistics.
 *
 * @package XWP\CustomXmlSitemap\CLI
 */

namespace XWP\CustomXmlSitemap\CLI;

use WP_CLI;
use WP_CLI_Command;
use WP_Post;
use WP_Query;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Generator;
use XWP\CustomXmlSitemap\Terms_Sitemap_Generator;

/**
 * Sitemap CLI Command.
 *
 * Manages custom sitemaps via WP-CLI. Provides commands to list all sitemaps,
 * regenerate cached XML, validate sitemap structure, and display URL statistics.
 */
class Sitemap_Command extends WP_CLI_Command {

	/**
	 * Register CLI commands.
	 *
	 * @return void
	 */
	public function register(): void {
		WP_CLI::add_command( 'cxs list', [ $this, 'list_sitemaps' ] );
		WP_CLI::add_command( 'cxs generate', [ $this, 'generate' ] );
		WP_CLI::add_command( 'cxs validate', [ $this, 'validate' ] );
		WP_CLI::add_command( 'cxs stats', [ $this, 'stats' ] );
	}

	/**
	 * List all custom sitemaps with their configuration and status.
	 *
	 * Queries the cxs_sitemap CPT and displays a table with sitemap
	 * configuration including post type, taxonomy, granularity, and cache status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, csv, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 * # List all sitemaps in table format.
	 * wp cxs list
	 *
	 * # List all sitemaps in JSON format.
	 * wp cxs list --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_sitemaps( array $args, array $assoc_args ): void {
		$sitemaps = $this->get_all_sitemaps();

		if ( empty( $sitemaps ) ) {
			WP_CLI::warning( 'No custom sitemaps found.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		$items  = [];

		foreach ( $sitemaps as $sitemap ) {
			$config       = Sitemap_CPT::get_sitemap_config( $sitemap->ID );
			$is_terms     = Sitemap_CPT::is_terms_mode( $sitemap->ID );
			$has_cache    = $this->has_cached_xml( $sitemap->ID, $is_terms );
			$url_or_terms = $this->get_total_url_count( $sitemap->ID, $is_terms );

			$items[] = [
				'ID'          => $sitemap->ID,
				'Slug'        => $sitemap->post_name,
				'Title'       => $sitemap->post_title,
				'Mode'        => $is_terms ? 'Terms' : 'Posts',
				'Post Type'   => $is_terms ? '-' : ( $config['post_type'] ?? 'post' ),
				'Taxonomy'    => $config['taxonomy'] ?? '-',
				'Granularity' => $is_terms ? '-' : ( $config['granularity'] ?? 'month' ),
				'Cached'      => $has_cache ? 'Yes' : 'No',
				'URLs'        => $url_or_terms,
			];
		}

		WP_CLI\Utils\format_items( $format, $items, array_keys( $items[0] ) );
	}

	/**
	 * Regenerate sitemap cache for one or all custom sitemaps.
	 *
	 * Clears cached XML and regenerates sitemaps based on current content.
	 * Can target a specific sitemap by slug or regenerate all sitemaps.
	 *
	 * ## OPTIONS
	 *
	 * [<sitemap-slug>]
	 * : The slug of a specific sitemap to regenerate.
	 *
	 * [--all]
	 * : Regenerate all custom sitemaps.
	 *
	 * [--year=<year>]
	 * : Only regenerate sitemaps for a specific year.
	 *
	 * [--dry-run]
	 * : Preview what would be regenerated without making changes.
	 *
	 * ## EXAMPLES
	 *
	 * # Regenerate a specific sitemap.
	 * wp cxs generate news-sitemap
	 *
	 * # Regenerate all sitemaps.
	 * wp cxs generate --all
	 *
	 * # Preview regeneration for all sitemaps.
	 * wp cxs generate --all --dry-run
	 *
	 * # Regenerate 2024 sitemaps only.
	 * wp cxs generate news-sitemap --year=2024
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function generate( array $args, array $assoc_args ): void {
		$sitemap_slug   = $args[0] ?? null;
		$regenerate_all = isset( $assoc_args['all'] ) && $assoc_args['all'];
		$year           = isset( $assoc_args['year'] ) ? absint( $assoc_args['year'] ) : null;
		$dry_run        = isset( $assoc_args['dry-run'] ) && $assoc_args['dry-run'];

		// Validate arguments.
		if ( ! $sitemap_slug && ! $regenerate_all ) {
			WP_CLI::error( 'Please specify a sitemap slug or use --all to regenerate all sitemaps.' );
		}

		if ( $sitemap_slug && $regenerate_all ) {
			WP_CLI::error( 'Cannot specify both a sitemap slug and --all option.' );
		}

		if ( $dry_run ) {
			WP_CLI::line( 'Dry run mode: No changes will be made.' );
		}

		// Get sitemaps to regenerate.
		$sitemaps = $regenerate_all
			? $this->get_all_sitemaps()
			: [ $this->get_sitemap_by_slug( $sitemap_slug ) ];

		// Filter out null values (invalid slugs).
		$sitemaps = array_filter( $sitemaps );

		if ( empty( $sitemaps ) ) {
			WP_CLI::error(
				$regenerate_all
				? 'No custom sitemaps found.'
				: sprintf( 'Sitemap with slug "%s" not found.', $sitemap_slug )
			);
		}

		$total_regenerated = 0;

		foreach ( $sitemaps as $sitemap ) {
			WP_CLI::line( sprintf( 'Processing sitemap: %s (ID: %d)', $sitemap->post_name, $sitemap->ID ) );

			if ( $dry_run ) {
				$this->preview_regeneration( $sitemap, $year );
				continue;
			}

			$summary = $this->regenerate_sitemap( $sitemap, $year );
			$this->display_regeneration_summary( $sitemap, $summary );
			++$total_regenerated;
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run complete. No sitemaps were regenerated.' );
		} else {
			WP_CLI::success( sprintf( 'Successfully regenerated %d sitemap(s).', $total_regenerated ) );
		}
	}

	/**
	 * Validate sitemap XML structure and content.
	 *
	 * Fetches and parses the sitemap XML to verify it is well-formed
	 * and follows the sitemap protocol specification.
	 *
	 * ## OPTIONS
	 *
	 * <sitemap-slug>
	 * : The slug of the sitemap to validate.
	 *
	 * [--verbose]
	 * : Show detailed validation output.
	 *
	 * ## EXAMPLES
	 *
	 * # Validate a sitemap.
	 * wp cxs validate news-sitemap
	 *
	 * # Validate with detailed output.
	 * wp cxs validate news-sitemap --verbose
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function validate( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please specify a sitemap slug to validate.' );
		}

		$sitemap_slug = $args[0];
		$verbose      = isset( $assoc_args['verbose'] ) && $assoc_args['verbose'];

		$sitemap = $this->get_sitemap_by_slug( $sitemap_slug );

		if ( ! $sitemap ) {
			WP_CLI::error( sprintf( 'Sitemap with slug "%s" not found.', $sitemap_slug ) );
		}

		WP_CLI::line( sprintf( 'Validating sitemap: %s (ID: %d)', $sitemap->post_name, $sitemap->ID ) );

		$generator = new Sitemap_Generator( $sitemap );
		$errors    = [];
		$warnings  = [];

		// Validate index XML.
		$index_xml = $generator->get_index();
		$this->validate_xml_content( $index_xml, 'index', $errors, $warnings, $verbose );

		// Get years with content to validate child sitemaps.
		$config      = Sitemap_CPT::get_sitemap_config( $sitemap->ID );
		$granularity = $config['granularity'] ?? 'month';

		// Validate year sitemaps.
		$year_sitemaps = $this->get_cached_year_sitemaps( $sitemap->ID );

		foreach ( $year_sitemaps as $year => $xml ) {
			$this->validate_xml_content( $xml, "year-{$year}", $errors, $warnings, $verbose );
		}

		// Display results.
		if ( ! empty( $errors ) ) {
			WP_CLI::error_multi_line( $errors );
			WP_CLI::error( sprintf( 'Validation failed with %d error(s).', count( $errors ) ) );
		}

		if ( ! empty( $warnings ) ) {
			foreach ( $warnings as $warning ) {
				WP_CLI::warning( $warning );
			}
		}

		if ( empty( $errors ) ) {
			WP_CLI::success( 'Sitemap validation passed.' );
		}
	}

	/**
	 * Display sitemap statistics including URL counts and cache status.
	 *
	 * Shows detailed statistics for one or all sitemaps including
	 * URL counts per time period and cache status.
	 *
	 * ## OPTIONS
	 *
	 * [<sitemap-slug>]
	 * : The slug of a specific sitemap to show stats for.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, csv, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 * # Show stats for all sitemaps.
	 * wp cxs stats
	 *
	 * # Show stats for a specific sitemap.
	 * wp cxs stats news-sitemap
	 *
	 * # Show stats in JSON format.
	 * wp cxs stats --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function stats( array $args, array $assoc_args ): void {
		$sitemap_slug = $args[0] ?? null;
		$format       = $assoc_args['format'] ?? 'table';

		$sitemaps = $sitemap_slug
			? [ $this->get_sitemap_by_slug( $sitemap_slug ) ]
			: $this->get_all_sitemaps();

		// Filter out null values.
		$sitemaps = array_filter( $sitemaps );

		if ( empty( $sitemaps ) ) {
			WP_CLI::warning(
				$sitemap_slug
				? sprintf( 'Sitemap with slug "%s" not found.', $sitemap_slug )
				: 'No custom sitemaps found.'
			);
			return;
		}

		// If showing stats for a single sitemap, show detailed breakdown.
		if ( $sitemap_slug && count( $sitemaps ) === 1 ) {
			$this->display_detailed_stats( $sitemaps[0], $format );
			return;
		}

		// Summary stats for all sitemaps.
		$items = [];

		foreach ( $sitemaps as $sitemap ) {
			$is_terms  = Sitemap_CPT::is_terms_mode( $sitemap->ID );
			$has_cache = $this->has_cached_xml( $sitemap->ID, $is_terms );

			if ( $is_terms ) {
				$term_count = get_post_meta( $sitemap->ID, Terms_Sitemap_Generator::META_KEY_TERM_COUNT, true );
				$total_urls = ! empty( $term_count ) ? (int) $term_count : 0;
				$periods    = $total_urls > 0 ? (int) ceil( $total_urls / Terms_Sitemap_Generator::MAX_TERMS_PER_SITEMAP ) : 0;
			} else {
				$url_counts = $this->get_url_counts_by_period( $sitemap->ID );
				$total_urls = array_sum( $url_counts );
				$periods    = count( $url_counts );
			}

			$items[] = [
				'ID'         => $sitemap->ID,
				'Slug'       => $sitemap->post_name,
				'Mode'       => $is_terms ? 'Terms' : 'Posts',
				'Total URLs' => $total_urls,
				'Periods'    => $is_terms ? sprintf( '%d page(s)', $periods ) : $periods,
				'Cached'     => $has_cache ? 'Yes' : 'No',
			];
		}

		WP_CLI\Utils\format_items( $format, $items, array_keys( $items[0] ) );
	}

	/**
	 * Get all custom sitemap posts.
	 *
	 * @return array<WP_Post> Array of sitemap post objects.
	 */
	private function get_all_sitemaps(): array {
		$query = new WP_Query(
			[
				'post_type'      => Sitemap_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		return $query->posts;
	}

	/**
	 * Get a sitemap post by its slug.
	 *
	 * @param string $slug Sitemap slug (post_name).
	 * @return WP_Post|null Sitemap post or null if not found.
	 */
	private function get_sitemap_by_slug( string $slug ): ?WP_Post {
		$query = new WP_Query(
			[
				'post_type'      => Sitemap_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
			]
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		$post = $query->posts[0];

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Check if a sitemap has cached XML.
	 *
	 * @param int  $sitemap_id Sitemap post ID.
	 * @param bool $is_terms   Whether this is a terms-mode sitemap.
	 * @return bool True if cached XML exists.
	 */
	private function has_cached_xml( int $sitemap_id, bool $is_terms = false ): bool {
		$meta_key  = $is_terms
			? Terms_Sitemap_Generator::META_KEY_INDEX_XML
			: Sitemap_Generator::META_KEY_INDEX_XML;
		$index_xml = get_post_meta( $sitemap_id, $meta_key, true );

		return ! empty( $index_xml );
	}

	/**
	 * Get total URL count for a sitemap.
	 *
	 * For Posts mode: Sums all URL counts stored in post meta.
	 * For Terms mode: Returns the cached term count.
	 *
	 * @param int  $sitemap_id Sitemap post ID.
	 * @param bool $is_terms   Whether this is a terms-mode sitemap.
	 * @return int Total URL/term count.
	 */
	private function get_total_url_count( int $sitemap_id, bool $is_terms = false ): int {
		if ( $is_terms ) {
			// Terms mode: return cached term count.
			$term_count = get_post_meta( $sitemap_id, Terms_Sitemap_Generator::META_KEY_TERM_COUNT, true );
			return ! empty( $term_count ) ? (int) $term_count : 0;
		}

		// Posts mode: sum URL counts by period.
		$url_counts = $this->get_url_counts_by_period( $sitemap_id );

		return array_sum( $url_counts );
	}

	/**
	 * Get URL counts grouped by time period.
	 *
	 * @param int $sitemap_id Sitemap post ID.
	 * @return array<string, int> Associative array of period => count.
	 */
	private function get_url_counts_by_period( int $sitemap_id ): array {
		global $wpdb;

		// Query all URL count meta keys.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE post_id = %d AND meta_key LIKE %s",
				$sitemap_id,
				$wpdb->esc_like( Sitemap_Generator::META_KEY_URL_COUNT ) . '%'
			),
			ARRAY_A
		);

		$counts = [];

		foreach ( $results as $row ) {
			// Extract period from meta key (e.g., 'cxs_sitemap_url_count_2024_01' -> '2024_01').
			/** @var string $period */
			$period            = str_replace( Sitemap_Generator::META_KEY_URL_COUNT, '', $row['meta_key'] );
			$counts[ $period ] = (int) $row['meta_value'];
		}

		// Sort by period descending.
		krsort( $counts );

		return $counts;
	}

	/**
	 * Get cached year sitemaps for a sitemap.
	 *
	 * @param int $sitemap_id Sitemap post ID.
	 * @return array<string, string> Associative array of year => XML content.
	 */
	private function get_cached_year_sitemaps( int $sitemap_id ): array {
		global $wpdb;

		// Query all year sitemap meta keys (4-digit year pattern).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE post_id = %d AND meta_key REGEXP %s",
				$sitemap_id,
				'^' . Sitemap_Generator::META_KEY_XML_PREFIX . '[0-9]{4}$'
			),
			ARRAY_A
		);

		$sitemaps = [];

		foreach ( $results as $row ) {
			/** @var string $year */
			$year              = str_replace( Sitemap_Generator::META_KEY_XML_PREFIX, '', $row['meta_key'] );
			$sitemaps[ $year ] = $row['meta_value'];
		}

		return $sitemaps;
	}

	/**
	 * Regenerate a sitemap's cached XML.
	 *
	 * Uses appropriate generator based on sitemap mode (Posts or Terms).
	 *
	 * @param WP_Post  $sitemap Sitemap post object.
	 * @param int|null $year    Optional year to filter regeneration (Posts mode only).
	 * @return array{index: bool, years?: array<int>, months?: array<string>, days?: array<string>, pages?: array<int>} Regeneration summary.
	 */
	private function regenerate_sitemap( WP_Post $sitemap, ?int $year = null ): array {
		// Use appropriate generator based on sitemap mode.
		if ( Sitemap_CPT::is_terms_mode( $sitemap->ID ) ) {
			$generator = new Terms_Sitemap_Generator( $sitemap );
			// Terms mode ignores year filter - always full regeneration.
			return $generator->regenerate_all();
		}

		$generator = new Sitemap_Generator( $sitemap );

		// If year is specified, only invalidate and regenerate that year.
		if ( $year ) {
			$generator->invalidate_cache( $year );
			$generator->get_index( true );
			$generator->get_year_sitemap( $year, true );

			return [
				'index'  => true,
				'years'  => [ $year ],
				'months' => [],
				'days'   => [],
			];
		}

		// Full regeneration.
		return $generator->regenerate_all();
	}

	/**
	 * Preview what would be regenerated for a sitemap.
	 *
	 * @param WP_Post  $sitemap Sitemap post object.
	 * @param int|null $year    Optional year filter.
	 * @return void
	 */
	private function preview_regeneration( WP_Post $sitemap, ?int $year = null ): void {
		$config      = Sitemap_CPT::get_sitemap_config( $sitemap->ID );
		$granularity = $config['granularity'] ?? 'month';

		if ( $year ) {
			WP_CLI::line( sprintf( '  Would regenerate: index, year %d sitemaps', $year ) );
		} else {
			WP_CLI::line( sprintf( '  Would regenerate: index and all %s-level sitemaps', $granularity ) );
		}
	}

	/**
	 * Display regeneration summary.
	 *
	 * @param WP_Post                                                                                                    $sitemap Sitemap post object.
	 * @param array{index: bool, years?: array<int>, months?: array<string>, days?: array<string>, pages?: array<int>} $summary Regeneration summary.
	 * @return void
	 */
	private function display_regeneration_summary( WP_Post $sitemap, array $summary ): void {
		$parts = [];

		if ( $summary['index'] ) {
			$parts[] = 'index';
		}

		if ( ! empty( $summary['years'] ) ) {
			$parts[] = sprintf( '%d year(s)', count( $summary['years'] ) );
		}

		if ( ! empty( $summary['months'] ) ) {
			$parts[] = sprintf( '%d month(s)', count( $summary['months'] ) );
		}

		if ( ! empty( $summary['days'] ) ) {
			$parts[] = sprintf( '%d day(s)', count( $summary['days'] ) );
		}

		if ( ! empty( $summary['pages'] ) ) {
			$parts[] = sprintf( '%d page(s)', count( $summary['pages'] ) );
		}

		WP_CLI::line( sprintf( '  Regenerated: %s', implode( ', ', $parts ) ) );
	}

	/**
	 * Validate XML content.
	 *
	 * @param string        $xml      XML content to validate.
	 * @param string        $label    Label for error messages.
	 * @param array<string> $errors   Array to collect errors (passed by reference).
	 * @param array<string> $warnings Array to collect warnings (passed by reference).
	 * @param bool          $verbose  Whether to output verbose information.
	 * @return void
	 */
	private function validate_xml_content( string $xml, string $label, array &$errors, array &$warnings, bool $verbose ): void {
		if ( empty( $xml ) ) {
			$errors[] = sprintf( '[%s] Empty XML content.', $label );
			return;
		}

		// Suppress XML errors to handle them manually.
		$use_errors = libxml_use_internal_errors( true );

		$doc = simplexml_load_string( $xml );

		if ( false === $doc ) {
			$xml_errors = libxml_get_errors();
			libxml_clear_errors();

			foreach ( $xml_errors as $xml_error ) {
				$errors[] = sprintf(
					'[%s] Line %d: %s',
					$label,
					$xml_error->line,
					trim( $xml_error->message )
				);
			}
		} elseif ( $verbose ) {
			// Check for sitemap elements.
			$root_name = $doc->getName();

			if ( 'sitemapindex' === $root_name ) {
				$sitemap_count = count( $doc->sitemap );
				WP_CLI::line( sprintf( '  [%s] Valid sitemap index with %d sitemap(s).', $label, $sitemap_count ) );
			} elseif ( 'urlset' === $root_name ) {
				$url_count = count( $doc->url );
				WP_CLI::line( sprintf( '  [%s] Valid urlset with %d URL(s).', $label, $url_count ) );
			} else {
				$warnings[] = sprintf( '[%s] Unexpected root element: %s', $label, $root_name );
			}
		}

		libxml_use_internal_errors( $use_errors );
	}

	/**
	 * Display detailed statistics for a single sitemap.
	 *
	 * Shows different statistics based on sitemap mode:
	 * - Posts mode: URL counts by time period
	 * - Terms mode: Total term count and taxonomy info
	 *
	 * @param WP_Post $sitemap Sitemap post object.
	 * @param string  $format  Output format.
	 * @return void
	 */
	private function display_detailed_stats( WP_Post $sitemap, string $format ): void {
		$config    = Sitemap_CPT::get_sitemap_config( $sitemap->ID );
		$is_terms  = Sitemap_CPT::is_terms_mode( $sitemap->ID );
		$has_cache = $this->has_cached_xml( $sitemap->ID, $is_terms );

		WP_CLI::line( sprintf( 'Sitemap: %s (ID: %d)', $sitemap->post_name, $sitemap->ID ) );
		WP_CLI::line( sprintf( 'Mode: %s', $is_terms ? 'Terms' : 'Posts' ) );

		if ( $is_terms ) {
			$this->display_terms_mode_stats( $sitemap, $config, $has_cache );
		} else {
			$this->display_posts_mode_stats( $sitemap, $config, $has_cache, $format );
		}
	}

	/**
	 * Display statistics for a Terms mode sitemap.
	 *
	 * @param WP_Post              $sitemap   Sitemap post object.
	 * @param array<string, mixed> $config    Sitemap configuration.
	 * @param bool                 $has_cache Whether sitemap has cached XML.
	 * @return void
	 */
	private function display_terms_mode_stats( WP_Post $sitemap, array $config, bool $has_cache ): void {
		$term_count    = get_post_meta( $sitemap->ID, Terms_Sitemap_Generator::META_KEY_TERM_COUNT, true );
		$hide_empty    = ! empty( $config['terms_hide_empty'] );
		$taxonomy      = $config['taxonomy'] ?? '-';
		$taxonomy_obj  = get_taxonomy( $taxonomy );
		$taxonomy_name = $taxonomy_obj ? $taxonomy_obj->labels->name : $taxonomy;

		WP_CLI::line( sprintf( 'Taxonomy: %s (%s)', $taxonomy_name, $taxonomy ) );
		WP_CLI::line( sprintf( 'Hide Empty Terms: %s', $hide_empty ? 'Yes' : 'No' ) );
		WP_CLI::line( sprintf( 'Cache Status: %s', $has_cache ? 'Cached' : 'Not cached' ) );
		WP_CLI::line( '' );

		if ( empty( $term_count ) ) {
			WP_CLI::line( 'No term count recorded. Try regenerating the sitemap.' );
			return;
		}

		$page_count = (int) ceil( (int) $term_count / Terms_Sitemap_Generator::MAX_TERMS_PER_SITEMAP );

		WP_CLI::line( sprintf( 'Total Terms: %d', (int) $term_count ) );
		WP_CLI::line( sprintf( 'Sitemap Pages: %d', $page_count ) );
	}

	/**
	 * Display statistics for a Posts mode sitemap.
	 *
	 * @param WP_Post              $sitemap   Sitemap post object.
	 * @param array<string, mixed> $config    Sitemap configuration.
	 * @param bool                 $has_cache Whether sitemap has cached XML.
	 * @param string               $format    Output format.
	 * @return void
	 */
	private function display_posts_mode_stats( WP_Post $sitemap, array $config, bool $has_cache, string $format ): void {
		$url_counts = $this->get_url_counts_by_period( $sitemap->ID );

		WP_CLI::line( sprintf( 'Post Type: %s', $config['post_type'] ?? 'post' ) );
		WP_CLI::line( sprintf( 'Taxonomy: %s', $config['taxonomy'] ?? '-' ) );
		WP_CLI::line( sprintf( 'Granularity: %s', $config['granularity'] ?? 'month' ) );
		WP_CLI::line( sprintf( 'Cache Status: %s', $has_cache ? 'Cached' : 'Not cached' ) );
		WP_CLI::line( '' );

		if ( empty( $url_counts ) ) {
			WP_CLI::line( 'No URL counts recorded. Try regenerating the sitemap.' );
			return;
		}

		WP_CLI::line( 'URL Counts by Period:' );

		$items = [];

		foreach ( $url_counts as $period => $count ) {
			$items[] = [
				'Period' => $period,
				'URLs'   => $count,
			];
		}

		WP_CLI\Utils\format_items( $format, $items, [ 'Period', 'URLs' ] );

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Total URLs: %d', array_sum( $url_counts ) ) );
	}
}
