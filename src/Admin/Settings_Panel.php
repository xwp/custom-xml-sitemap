<?php
/**
 * Settings Panel.
 *
 * Handles the admin meta box and settings UI for custom sitemaps.
 * Uses React-based settings with @wordpress/components for the admin interface.
 *
 * @package XWP\CustomXmlSitemap\Admin
 */

namespace XWP\CustomXmlSitemap\Admin;

use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Generator;

/**
 * Settings Panel.
 *
 * Registers meta boxes and REST endpoints for the custom sitemap editor.
 * Provides a React-based admin UI replacing the previous ACF implementation.
 */
class Settings_Panel {

	/**
	 * Nonce action for sitemap settings.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'cxs_sitemap_settings';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	public const NONCE_FIELD = 'cxs_sitemap_nonce';

	/**
	 * Initialize the settings panel.
	 *
	 * Registers all WordPress hooks for admin functionality.
	 *
	 * @return void
	 */
	public function init(): void {
		// Register meta box for sitemap settings.
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );

		// Save meta box data on post save.
		add_action( 'save_post_' . Sitemap_CPT::POST_TYPE, [ $this, 'save_meta_box' ], 10, 2 );

		// Enqueue admin scripts.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

		// Display sitemap URL after title.
		add_action( 'edit_form_after_title', [ $this, 'render_sitemap_url' ] );

		// Add admin notices for URL limit warning.
		add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
	}

	/**
	 * Register the settings meta box.
	 *
	 * @return void
	 */
	public function register_meta_box(): void {
		add_meta_box(
			'cxs_sitemap_settings',
			__( 'Sitemap Settings', 'custom-xml-sitemap' ),
			[ $this, 'render_meta_box' ],
			Sitemap_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the settings meta box.
	 *
	 * Outputs the container div for the React settings panel and hidden inputs
	 * for form submission.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$config = Sitemap_CPT::get_sitemap_config( $post->ID );
		?>
		<div id="cxs-settings-panel" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<p><?php esc_html_e( 'Loading settings...', 'custom-xml-sitemap' ); ?></p>
		</div>

		<!-- Hidden inputs for form submission -->
		<input type="hidden" name="<?php echo esc_attr( Sitemap_CPT::META_KEY_POST_TYPE ); ?>" 
			id="cxs-post-type" value="<?php echo esc_attr( $config['post_type'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( Sitemap_CPT::META_KEY_GRANULARITY ); ?>" 
			id="cxs-granularity" value="<?php echo esc_attr( $config['granularity'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( Sitemap_CPT::META_KEY_TAXONOMY ); ?>" 
			id="cxs-taxonomy" value="<?php echo esc_attr( $config['taxonomy'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( Sitemap_CPT::META_KEY_TAXONOMY_TERMS ); ?>" 
			id="cxs-taxonomy-terms" value="<?php echo esc_attr( (string) wp_json_encode( $config['terms'] ) ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( Sitemap_CPT::META_KEY_INCLUDE_IMAGES ); ?>" 
			id="cxs-include-images" value="<?php echo esc_attr( $config['include_images'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( Sitemap_CPT::META_KEY_INCLUDE_NEWS ); ?>" 
			id="cxs-include-news" value="<?php echo esc_attr( $config['include_news'] ? '1' : '' ); ?>" />
		<?php
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_scripts( string $hook_suffix ): void {
		// Only load on post edit screens for our CPT.
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || Sitemap_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_file = CXS_PLUGIN_DIR . 'assets/build/admin/settings-panel.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'cxs-settings-panel',
			CXS_PLUGIN_URL . 'assets/build/admin/settings-panel.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Only enqueue CSS if it exists (optional).
		$css_file = CXS_PLUGIN_DIR . 'assets/build/admin/settings-panel.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'cxs-settings-panel',
				CXS_PLUGIN_URL . 'assets/build/admin/settings-panel.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}

		// Get current post for saved values.
		global $post;
		$config = $post ? Sitemap_CPT::get_sitemap_config( $post->ID ) : [
			'post_type'      => 'post',
			'granularity'    => Sitemap_CPT::GRANULARITY_MONTH,
			'taxonomy'       => '',
			'terms'          => [],
			'include_images' => Sitemap_CPT::INCLUDE_IMAGES_NONE,
			'include_news'   => false,
		];

		// Localize script with settings data.
		wp_localize_script(
			'cxs-settings-panel',
			'cxsSettings',
			[
				'postTypes'    => $this->get_available_post_types(),
				'taxonomies'   => $this->get_available_taxonomies(),
				'savedValues'  => [
					'postType'      => $config['post_type'],
					'granularity'   => $config['granularity'],
					'taxonomy'      => $config['taxonomy'],
					'terms'         => $config['terms'],
					'includeImages' => $config['include_images'],
					'includeNews'   => $config['include_news'],
				],
				'granularities' => [
					[
						'value' => Sitemap_CPT::GRANULARITY_YEAR,
						'label' => __( 'Year', 'custom-xml-sitemap' ),
					],
					[
						'value' => Sitemap_CPT::GRANULARITY_MONTH,
						'label' => __( 'Month', 'custom-xml-sitemap' ),
					],
					[
						'value' => Sitemap_CPT::GRANULARITY_DAY,
						'label' => __( 'Day', 'custom-xml-sitemap' ),
					],
				],
				'imageOptions' => [
					[
						'value' => Sitemap_CPT::INCLUDE_IMAGES_NONE,
						'label' => __( 'None', 'custom-xml-sitemap' ),
					],
					[
						'value' => Sitemap_CPT::INCLUDE_IMAGES_FEATURED,
						'label' => __( 'Featured Image Only', 'custom-xml-sitemap' ),
					],
					[
						'value' => Sitemap_CPT::INCLUDE_IMAGES_ALL,
						'label' => __( 'All Images', 'custom-xml-sitemap' ),
					],
				],
				'restUrl'      => rest_url(),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * Get available public post types.
	 *
	 * @return array<string, string> Associative array of slug => label.
	 */
	private function get_available_post_types(): array {
		$post_types = get_post_types(
			[
				'public' => true,
			],
			'objects'
		);

		/**
		 * Filter the post types excluded from sitemap configuration.
		 *
		 * @param array<string> $excluded_types Array of post type slugs to exclude.
		 */
		$excluded = apply_filters( 'cxs_excluded_post_types', [ 'attachment' ] );

		$result = [];
		foreach ( $post_types as $slug => $post_type ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			$result[ $slug ] = $post_type->labels->name;
		}

		return $result;
	}

	/**
	 * Get available public taxonomies with REST support.
	 *
	 * @return array<string, array{label: string, rest_base: string}> Associative array of taxonomy data.
	 */
	private function get_available_taxonomies(): array {
		$taxonomies = get_taxonomies(
			[
				'public'       => true,
				'show_in_rest' => true,
			],
			'objects'
		);

		/**
		 * Filter the taxonomies excluded from sitemap configuration.
		 *
		 * @param array<string> $excluded_taxonomies Array of taxonomy slugs to exclude.
		 */
		$excluded = apply_filters( 'cxs_excluded_taxonomies', [] );

		$result = [];
		foreach ( $taxonomies as $slug => $taxonomy ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}

			// Determine the REST base - use taxonomy slug as fallback.
			$rest_base = is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base
				? $taxonomy->rest_base
				: $slug;

			$result[ $slug ] = [
				'label'     => (string) $taxonomy->labels->name,
				'rest_base' => $rest_base,
			];
		}

		return $result;
	}

	/**
	 * Save meta box data on post save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta_box( int $post_id, \WP_Post $post ): void {
		// Verify nonce.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save post type.
		if ( isset( $_POST[ Sitemap_CPT::META_KEY_POST_TYPE ] ) ) {
			$post_type = sanitize_key( wp_unslash( $_POST[ Sitemap_CPT::META_KEY_POST_TYPE ] ) );
			if ( post_type_exists( $post_type ) ) {
				update_post_meta( $post_id, Sitemap_CPT::META_KEY_POST_TYPE, $post_type );
			}
		}

		// Save granularity.
		if ( isset( $_POST[ Sitemap_CPT::META_KEY_GRANULARITY ] ) ) {
			$granularity = sanitize_key( wp_unslash( $_POST[ Sitemap_CPT::META_KEY_GRANULARITY ] ) );
			$valid       = [
				Sitemap_CPT::GRANULARITY_YEAR,
				Sitemap_CPT::GRANULARITY_MONTH,
				Sitemap_CPT::GRANULARITY_DAY,
			];
			if ( in_array( $granularity, $valid, true ) ) {
				update_post_meta( $post_id, Sitemap_CPT::META_KEY_GRANULARITY, $granularity );
			}
		}

		// Save taxonomy.
		if ( isset( $_POST[ Sitemap_CPT::META_KEY_TAXONOMY ] ) ) {
			$taxonomy = sanitize_key( wp_unslash( $_POST[ Sitemap_CPT::META_KEY_TAXONOMY ] ) );
			if ( empty( $taxonomy ) || taxonomy_exists( $taxonomy ) ) {
				update_post_meta( $post_id, Sitemap_CPT::META_KEY_TAXONOMY, $taxonomy );
			}
		}

		// Save taxonomy terms.
		if ( isset( $_POST[ Sitemap_CPT::META_KEY_TAXONOMY_TERMS ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and sanitized below.
			$terms_json = wp_unslash( $_POST[ Sitemap_CPT::META_KEY_TAXONOMY_TERMS ] );
			$terms      = json_decode( $terms_json, true );
			if ( is_array( $terms ) ) {
				$terms = array_map( 'absint', $terms );
				$terms = array_filter( $terms );
				update_post_meta( $post_id, Sitemap_CPT::META_KEY_TAXONOMY_TERMS, $terms );
			}
		}

		// Save include images setting.
		if ( isset( $_POST[ Sitemap_CPT::META_KEY_INCLUDE_IMAGES ] ) ) {
			$include_images = sanitize_key( wp_unslash( $_POST[ Sitemap_CPT::META_KEY_INCLUDE_IMAGES ] ) );
			$valid_options  = [
				Sitemap_CPT::INCLUDE_IMAGES_NONE,
				Sitemap_CPT::INCLUDE_IMAGES_FEATURED,
				Sitemap_CPT::INCLUDE_IMAGES_ALL,
			];
			if ( in_array( $include_images, $valid_options, true ) ) {
				update_post_meta( $post_id, Sitemap_CPT::META_KEY_INCLUDE_IMAGES, $include_images );
			}
		}

		// Save include news setting.
		$include_news = isset( $_POST[ Sitemap_CPT::META_KEY_INCLUDE_NEWS ] ) && ! empty( $_POST[ Sitemap_CPT::META_KEY_INCLUDE_NEWS ] );
		update_post_meta( $post_id, Sitemap_CPT::META_KEY_INCLUDE_NEWS, $include_news ? '1' : '' );
	}

	/**
	 * Render sitemap URL after the title field.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_sitemap_url( \WP_Post $post ): void {
		if ( Sitemap_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $post->post_status || empty( $post->post_name ) ) {
			return;
		}

		$sitemap_url = home_url( "/sitemaps/{$post->post_name}/index.xml" );
		?>
		<div class="cxs-sitemap-url" style="margin: 10px 0 20px; padding: 10px 12px; background: #f0f6fc; border-left: 4px solid #72aee6;">
			<strong><?php esc_html_e( 'Sitemap URL:', 'custom-xml-sitemap' ); ?></strong>
			<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" style="margin-left: 5px;"><?php echo esc_html( $sitemap_url ); ?></a>
		</div>
		<?php
	}

	/**
	 * Display admin notices for URL limit warning.
	 *
	 * @return void
	 */
	public function display_admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || Sitemap_CPT::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		global $post;
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// Check for URL limit warning.
		$generator = new Sitemap_Generator( $post );
		if ( $generator->has_exceeded_url_limit() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__(
					'Warning: One or more sitemap periods have reached the 1000 URL limit. Consider using a finer granularity setting or splitting into multiple sitemaps.',
					'custom-xml-sitemap'
				)
			);
		}
	}
}
