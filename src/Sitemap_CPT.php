<?php
/**
 * Custom Sitemap CPT.
 *
 * Handles Custom Post Type registration and configuration management for
 * custom sitemaps. Provides static methods for retrieving sitemap configs.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap;

use WP_Post;
use WP_Query;

/**
 * Custom Sitemap CPT.
 *
 * Manages the Custom Sitemap Custom Post Type, including registration,
 * configuration retrieval, and caching.
 */
class Sitemap_CPT {

	/**
	 * Custom Sitemap post type slug.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'cxs_sitemap';

	/**
	 * Meta key for the post type setting.
	 *
	 * @var string
	 */
	public const META_KEY_POST_TYPE = 'cxs_post_type';

	/**
	 * Meta key for the granularity setting.
	 *
	 * @var string
	 */
	public const META_KEY_GRANULARITY = 'cxs_granularity';

	/**
	 * Meta key for the taxonomy filter.
	 *
	 * @var string
	 */
	public const META_KEY_TAXONOMY = 'cxs_taxonomy';

	/**
	 * Meta key for the taxonomy terms filter.
	 *
	 * @var string
	 */
	public const META_KEY_TAXONOMY_TERMS = 'cxs_taxonomy_terms';

	/**
	 * Meta key for the include images setting.
	 *
	 * @var string
	 */
	public const META_KEY_INCLUDE_IMAGES = 'cxs_include_images';

	/**
	 * Meta key for the include news setting.
	 *
	 * @var string
	 */
	public const META_KEY_INCLUDE_NEWS = 'cxs_include_news';

	/**
	 * Include images option: none (no images in sitemap).
	 *
	 * @var string
	 */
	public const INCLUDE_IMAGES_NONE = 'none';

	/**
	 * Include images option: featured image only.
	 *
	 * @var string
	 */
	public const INCLUDE_IMAGES_FEATURED = 'featured';

	/**
	 * Include images option: all images (featured + content).
	 *
	 * @var string
	 */
	public const INCLUDE_IMAGES_ALL = 'all';

	/**
	 * Granularity option: year.
	 *
	 * @var string
	 */
	public const GRANULARITY_YEAR = 'year';

	/**
	 * Granularity option: month.
	 *
	 * @var string
	 */
	public const GRANULARITY_MONTH = 'month';

	/**
	 * Granularity option: day.
	 *
	 * @var string
	 */
	public const GRANULARITY_DAY = 'day';

	/**
	 * Cache group for sitemap data.
	 *
	 * @var string
	 */
	public const CACHE_GROUP = 'cxs_sitemap';

	/**
	 * Cache key for all sitemap configs.
	 *
	 * @var string
	 */
	public const CACHE_KEY_ALL_CONFIGS = 'all_sitemap_configs';

	/**
	 * Register the Custom Sitemap CPT.
	 *
	 * Creates a CPT for managing custom sitemaps. The CPT is displayed in the
	 * WordPress admin under the Tools menu. The post slug is used as the
	 * sitemap identifier in the URL.
	 *
	 * @return void
	 */
	public function register(): void {
		$labels = [
			'name'          => _x( 'Custom Sitemaps', 'Post type general name', 'custom-xml-sitemap' ),
			'singular_name' => _x( 'Custom Sitemap', 'Post type singular name', 'custom-xml-sitemap' ),
			'menu_name'     => _x( 'Custom Sitemaps', 'Admin Menu text', 'custom-xml-sitemap' ),
			'all_items'     => __( 'Custom Sitemaps', 'custom-xml-sitemap' ),
			'add_new_item'  => __( 'Add New Custom Sitemap', 'custom-xml-sitemap' ),
			'edit_item'     => __( 'Edit Custom Sitemap', 'custom-xml-sitemap' ),
			'search_items'  => __( 'Search Custom Sitemaps', 'custom-xml-sitemap' ),
			'not_found'     => __( 'No custom sitemaps found.', 'custom-xml-sitemap' ),
		];

		$args = [
			'labels'             => $labels,
			'description'        => __(
				'The post slug determines the sitemap URL (e.g., "how-to" creates "/sitemaps/how-to/index.xml")',
				'custom-xml-sitemap'
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'tools.php',
			'show_in_rest'       => true,
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => [ 'title' ],
		];

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Get sitemap configuration for a specific sitemap post.
	 *
	 * @param int $post_id Sitemap post ID.
	 * @return array{post_type: string, granularity: string, taxonomy: string, terms: array<int>, include_images: string, include_news: bool} Configuration array.
	 */
	public static function get_sitemap_config( int $post_id ): array {
		$post_type      = get_post_meta( $post_id, self::META_KEY_POST_TYPE, true );
		$granularity    = get_post_meta( $post_id, self::META_KEY_GRANULARITY, true );
		$taxonomy       = get_post_meta( $post_id, self::META_KEY_TAXONOMY, true );
		$terms          = get_post_meta( $post_id, self::META_KEY_TAXONOMY_TERMS, true );
		$include_images = get_post_meta( $post_id, self::META_KEY_INCLUDE_IMAGES, true );
		$include_news   = get_post_meta( $post_id, self::META_KEY_INCLUDE_NEWS, true );

		return [
			'post_type'      => ! empty( $post_type ) ? $post_type : 'post',
			'granularity'    => ! empty( $granularity ) ? $granularity : self::GRANULARITY_MONTH,
			'taxonomy'       => is_string( $taxonomy ) ? $taxonomy : '',
			'terms'          => is_array( $terms ) ? $terms : [],
			'include_images' => ! empty( $include_images ) ? $include_images : self::INCLUDE_IMAGES_NONE,
			'include_news'   => (bool) $include_news,
		];
	}

	/**
	 * Get all published sitemaps with their configurations.
	 *
	 * Results are cached in the object cache and invalidated when any sitemap changes.
	 *
	 * @return array<array{post: WP_Post, config: array{post_type: string, granularity: string, taxonomy: string, terms: array<int>, include_images: string, include_news: bool}}> Array of sitemap data.
	 */
	public static function get_all_sitemap_configs(): array {
		$cached = wp_cache_get( self::CACHE_KEY_ALL_CONFIGS, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query = new WP_Query(
			[
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => Plugin::MAX_SITEMAPS,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			]
		);

		$result = [];

		/** @var WP_Post $sitemap */
		foreach ( $query->posts as $sitemap ) {
			$result[] = [
				'post'   => $sitemap,
				'config' => self::get_sitemap_config( $sitemap->ID ),
			];
		}

		wp_cache_set( self::CACHE_KEY_ALL_CONFIGS, $result, self::CACHE_GROUP );

		return $result;
	}

	/**
	 * Get sitemap configs that use a specific post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<array{post: WP_Post, config: array{post_type: string, granularity: string, taxonomy: string, terms: array<int>, include_images: string, include_news: bool}}> Array of matching sitemap data.
	 */
	public static function get_configs_for_post_type( string $post_type ): array {
		$all_configs = self::get_all_sitemap_configs();
		$matching    = [];

		foreach ( $all_configs as $config_data ) {
			$config_post_type = ! empty( $config_data['config']['post_type'] )
				? $config_data['config']['post_type']
				: 'post';

			if ( $config_post_type === $post_type ) {
				$matching[] = $config_data;
			}
		}

		return $matching;
	}

	/**
	 * Clear the cached sitemap configurations.
	 *
	 * Should be called when any sitemap is created, updated, deleted, or changes status.
	 *
	 * @return void
	 */
	public static function clear_sitemap_configs_cache(): void {
		wp_cache_delete( self::CACHE_KEY_ALL_CONFIGS, self::CACHE_GROUP );
	}

	/**
	 * Get a sitemap post by its slug.
	 *
	 * @param string $slug Sitemap post slug (post_name).
	 * @return WP_Post|null Sitemap post or null if not found.
	 */
	public static function get_sitemap_by_slug( string $slug ): ?WP_Post {
		$query = new WP_Query(
			[
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'name'                   => sanitize_title( $slug ),
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		if ( ! $query->have_posts() ) {
			return null;
		}

		$post = $query->posts[0];

		return $post instanceof WP_Post ? $post : null;
	}
}
