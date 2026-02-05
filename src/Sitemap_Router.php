<?php
/**
 * Sitemap Router.
 *
 * Handles URL rewrite rules, query variables, and template loading for
 * custom XML sitemaps. Manages the routing layer between WordPress and
 * sitemap generation.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap;

/**
 * Sitemap Router.
 *
 * Registers rewrite rules for sitemap URLs and handles template loading
 * when sitemap URLs are requested.
 */
class Sitemap_Router {

	/**
	 * Query variable for custom sitemap type (slug).
	 *
	 * @var string
	 */
	public const QUERY_VAR_SITEMAP = 'cxs-sitemap';

	/**
	 * Query variable for sitemap year.
	 *
	 * @var string
	 */
	public const QUERY_VAR_YEAR = 'sitemap-year';

	/**
	 * Query variable for sitemap month.
	 *
	 * @var string
	 */
	public const QUERY_VAR_MONTH = 'sitemap-month';

	/**
	 * Query variable for sitemap day.
	 *
	 * @var string
	 */
	public const QUERY_VAR_DAY = 'sitemap-day';

	/**
	 * Query variable for XSL stylesheets.
	 *
	 * @var string
	 */
	public const QUERY_VAR_XSL = 'cxs-xsl';

	/**
	 * Query variable for terms sitemap page number.
	 *
	 * Used for paginated terms mode sitemaps (e.g., page-1.xml, page-2.xml).
	 *
	 * @var string
	 */
	public const QUERY_VAR_PAGE = 'sitemap-page';

	/**
	 * Initialize the router.
	 *
	 * Registers all WordPress hooks for routing sitemap requests.
	 *
	 * @return void
	 */
	public function init(): void {
		// Register rewrite rules for custom sitemap URLs.
		add_action( 'init', [ $this, 'register_rewrite_rules' ] );

		// Register query variables.
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );

		// Handle sitemap template loading.
		add_filter( 'template_include', [ $this, 'load_sitemap_template' ] );

		// Prevent canonical redirects for sitemap XML URLs.
		add_filter( 'redirect_canonical', [ $this, 'disable_canonical_redirects_for_sitemaps' ], 10, 2 );

		// Redirect trailing slash sitemap URLs to non-trailing slash version.
		// Priority 1 ensures this runs before other template_redirect handlers.
		add_action( 'template_redirect', [ $this, 'redirect_trailing_slash_sitemaps' ], 1 );
	}

	/**
	 * Register rewrite rules for custom sitemap URLs.
	 *
	 * Creates URL structure with configurable granularity (Posts mode):
	 * - /sitemaps/{type}/index.xml
	 * - /sitemaps/{type}/{year}.xml
	 * - /sitemaps/{type}/{year}-{month}.xml
	 * - /sitemaps/{type}/{year}-{month}-{day}.xml (for day granularity)
	 *
	 * Terms mode URL structure (for paginated term sitemaps):
	 * - /sitemaps/{type}/index.xml
	 * - /sitemaps/{type}/page-{n}.xml (when > 1000 terms)
	 *
	 * Also registers rules for XSL stylesheets:
	 * - /cxs-sitemap.xsl
	 * - /cxs-sitemap-index.xsl
	 *
	 * @return void
	 */
	public function register_rewrite_rules(): void {
		// XSL stylesheet: /cxs-sitemap.xsl.
		add_rewrite_rule(
			'^cxs-sitemap\.xsl$',
			'index.php?' . self::QUERY_VAR_XSL . '=sitemap',
			'top'
		);

		// XSL stylesheet: /cxs-sitemap-index.xsl.
		add_rewrite_rule(
			'^cxs-sitemap-index\.xsl$',
			'index.php?' . self::QUERY_VAR_XSL . '=sitemap-index',
			'top'
		);

		// Sitemap index: /sitemaps/{type}/index.xml.
		add_rewrite_rule(
			'^sitemaps/([a-z0-9-]+)/index\.xml$',
			'index.php?' . self::QUERY_VAR_SITEMAP . '=$matches[1]',
			'top'
		);

		// Year sitemap: /sitemaps/{type}/{year}.xml.
		add_rewrite_rule(
			'^sitemaps/([a-z0-9-]+)/([0-9]{4})\.xml$',
			'index.php?' . self::QUERY_VAR_SITEMAP . '=$matches[1]&' . self::QUERY_VAR_YEAR . '=$matches[2]',
			'top'
		);

		// Month sitemap: /sitemaps/{type}/{year}-{month}.xml.
		add_rewrite_rule(
			'^sitemaps/([a-z0-9-]+)/([0-9]{4})-([0-9]{2})\.xml$',
			'index.php?' . self::QUERY_VAR_SITEMAP . '=$matches[1]&' . self::QUERY_VAR_YEAR . '=$matches[2]&' . self::QUERY_VAR_MONTH . '=$matches[3]',
			'top'
		);

		// Day sitemap: /sitemaps/{type}/{year}-{month}-{day}.xml.
		add_rewrite_rule(
			'^sitemaps/([a-z0-9-]+)/([0-9]{4})-([0-9]{2})-([0-9]{2})\.xml$',
			'index.php?' . self::QUERY_VAR_SITEMAP . '=$matches[1]&' . self::QUERY_VAR_YEAR . '=$matches[2]&' . self::QUERY_VAR_MONTH . '=$matches[3]&' . self::QUERY_VAR_DAY . '=$matches[4]',
			'top'
		);

		// Paginated terms sitemap: /sitemaps/{type}/page-{n}.xml.
		// Used by Terms mode sitemaps when taxonomy has > 1000 terms.
		add_rewrite_rule(
			'^sitemaps/([a-z0-9-]+)/page-([0-9]+)\.xml$',
			'index.php?' . self::QUERY_VAR_SITEMAP . '=$matches[1]&' . self::QUERY_VAR_PAGE . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Register query variables for sitemap routing.
	 *
	 * @param array<string> $vars Existing query variables.
	 * @return array<string> Modified query variables.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR_SITEMAP;
		$vars[] = self::QUERY_VAR_YEAR;
		$vars[] = self::QUERY_VAR_MONTH;
		$vars[] = self::QUERY_VAR_DAY;
		$vars[] = self::QUERY_VAR_XSL;
		$vars[] = self::QUERY_VAR_PAGE;

		return $vars;
	}

	/**
	 * Load sitemap template when custom sitemap query var is present.
	 *
	 * Also handles XSL stylesheet requests.
	 *
	 * @param string $template Current template path.
	 * @return string Modified template path.
	 */
	public function load_sitemap_template( string $template ): string {
		// Check for XSL stylesheet request.
		$xsl_type = get_query_var( self::QUERY_VAR_XSL );
		if ( ! empty( $xsl_type ) ) {
			$this->serve_xsl_stylesheet( $xsl_type );
			exit;
		}

		// Check for sitemap request.
		$sitemap_type = get_query_var( self::QUERY_VAR_SITEMAP );

		if ( empty( $sitemap_type ) ) {
			return $template;
		}

		// Validate sitemap type exists (must be published).
		$sitemap_post = Sitemap_CPT::get_sitemap_by_slug( $sitemap_type );
		if ( ! $sitemap_post ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();

			// Return the 404 template.
			return get_404_template();
		}

		// Return our custom sitemap template.
		return CXS_PLUGIN_DIR . 'templates/sitemap.php';
	}

	/**
	 * Serve XSL stylesheet content.
	 *
	 * @param string $type XSL type ('sitemap' or 'sitemap-index').
	 * @return void
	 */
	private function serve_xsl_stylesheet( string $type ): void {
		$xsl_file = match ( $type ) {
			'sitemap'       => CXS_PLUGIN_DIR . 'assets/xsl/sitemap.xsl',
			'sitemap-index' => CXS_PLUGIN_DIR . 'assets/xsl/sitemap-index.xsl',
			default         => '',
		};

		if ( empty( $xsl_file ) || ! file_exists( $xsl_file ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		header( 'Content-Type: application/xslt+xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- XSL file is local and trusted, must be output as-is.
		echo file_get_contents( $xsl_file );
		exit;
	}

	/**
	 * Redirect sitemap URLs with trailing slashes to canonical non-trailing version.
	 *
	 * Runs early on template_redirect to ensure proper 301 redirect before content is served.
	 *
	 * @return void
	 */
	public function redirect_trailing_slash_sitemaps(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used for regex pattern matching, not output.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		// Check if URL ends with .xml/ (trailing slash on sitemap).
		if ( preg_match( '|(/sitemaps/[a-z0-9-]+/.*\.xml)/$|i', $request_uri, $matches ) ) {
			wp_safe_redirect( home_url( $matches[1] ), 301 );
			exit;
		}
	}

	/**
	 * Handle canonical redirects for custom sitemap XML URLs.
	 *
	 * Prevents WordPress from adding trailing slashes to .xml sitemap URLs.
	 *
	 * @param string|false $redirect_url  The redirect URL or false to prevent redirect.
	 * @param string       $requested_url The requested URL.
	 * @return string|false The redirect URL or false to prevent redirect.
	 */
	public function disable_canonical_redirects_for_sitemaps( $redirect_url, $requested_url ) {
		// If URL ends with .xml (no trailing slash), prevent any redirect.
		if ( preg_match( '|/sitemaps/[a-z0-9-]+/.*\.xml$|i', $requested_url ) ) {
			return false;
		}

		return $redirect_url;
	}
}
