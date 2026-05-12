/**
 * Common fixture builders for e2e specs.
 *
 * All fixtures use an `e2e-` prefix so global-setup and global-teardown can
 * reliably scrub them without impacting unrelated WP state.
 */
import { wpCli, wpCliJson, wpEval } from './wp-cli';

export interface SitemapFixtureInput {
	title: string;
	slug: string;
	mode?: 'posts' | 'terms';
	postType?: string;
	granularity?: 'year' | 'month' | 'day';
	taxonomy?: string;
	terms?: number[];
	filterMode?: 'include' | 'exclude';
	includeImages?: 'none' | 'featured' | 'all';
	includeNews?: boolean;
	termsHideEmpty?: boolean;
}

/**
 * Create a published custom-xml-sitemap CPT with the given config and return
 * the new post ID. Meta keys are set via individual WP-CLI calls so that
 * Sitemap_CPT::get_sitemap_config() reads them through its normal path.
 */
export function createSitemap( input: SitemapFixtureInput ): number {
	const id = parseInt(
		wpCli( [
			'post',
			'create',
			'--post_type=cxs_sitemap',
			'--post_status=publish',
			`--post_title=${ input.title }`,
			`--post_name=${ input.slug }`,
			'--porcelain',
		] ),
		10
	);

	if ( ! Number.isFinite( id ) || id <= 0 ) {
		throw new Error( `createSitemap: failed to create CPT (got "${ id }")` );
	}

	const meta: Record<string, string> = {};
	if ( input.mode ) {
		meta.cxs_sitemap_mode = input.mode;
	}
	if ( input.postType ) {
		meta.cxs_post_type = input.postType;
	}
	if ( input.granularity ) {
		meta.cxs_granularity = input.granularity;
	}
	if ( input.taxonomy ) {
		meta.cxs_taxonomy = input.taxonomy;
	}
	if ( input.filterMode ) {
		meta.cxs_filter_mode = input.filterMode;
	}
	if ( input.includeImages ) {
		meta.cxs_include_images = input.includeImages;
	}
	if ( typeof input.includeNews === 'boolean' ) {
		meta.cxs_include_news = input.includeNews ? '1' : '0';
	}
	if ( typeof input.termsHideEmpty === 'boolean' ) {
		meta.cxs_terms_hide_empty = input.termsHideEmpty ? '1' : '0';
	}

	for ( const [ key, value ] of Object.entries( meta ) ) {
		wpCli( [ 'post', 'meta', 'update', String( id ), key, value ] );
	}

	if ( input.terms && input.terms.length > 0 ) {
		// Terms is stored as a serialized array; use eval-file to bypass scalar-only WP-CLI meta updates.
		const json = JSON.stringify( input.terms ).replace( /'/g, "\\'" );
		wpEval(
			`update_post_meta( ${ id }, 'cxs_terms', json_decode( '${ json }', true ) );`
		);
	}

	// Flush rewrite rules so the new sitemap's URLs resolve.
	wpCli( [ 'rewrite', 'flush', '--hard' ] );

	return id;
}

/**
 * Create a published post with optional taxonomy assignments. Returns the post ID.
 */
export function createPost( input: {
	title: string;
	slug: string;
	postType?: string;
	postDate?: string;
	terms?: { taxonomy: string; ids: number[] }[];
} ): number {
	const args = [
		'post',
		'create',
		`--post_type=${ input.postType ?? 'post' }`,
		'--post_status=publish',
		`--post_title=${ input.title }`,
		`--post_name=${ input.slug }`,
		'--porcelain',
	];
	if ( input.postDate ) {
		args.push( `--post_date=${ input.postDate }` );
	}

	const id = parseInt( wpCli( args ), 10 );

	if ( input.terms ) {
		for ( const t of input.terms ) {
			if ( t.ids.length === 0 ) {
				continue;
			}
			// `wp post term set` defaults to --by=slug, so passing numeric
			// term IDs without --by=id silently creates new terms whose name
			// and slug are the stringified IDs. That noise is what produced
			// the rogue `/category/<num>/` URLs in earlier runs.
			wpCli( [
				'post',
				'term',
				'set',
				String( id ),
				t.taxonomy,
				...t.ids.map( ( n ) => String( n ) ),
				'--by=id',
			] );
		}
	}

	return id;
}

/**
 * Create a taxonomy term and return its term_id.
 */
export function createTerm( taxonomy: string, name: string, slug?: string ): number {
	const args = [ 'term', 'create', taxonomy, name, '--porcelain' ];
	if ( slug ) {
		args.push( `--slug=${ slug }` );
	}
	return parseInt( wpCli( args ), 10 );
}

/**
 * Force regeneration of a sitemap (Posts or Terms) by calling the generator
 * directly through WP-CLI. Returns when caches are written.
 */
export function regenerateSitemap( sitemapId: number ): void {
	wpEval( `
$post = get_post( ${ sitemapId } );
if ( ! $post ) { return; }
if ( \\XWP\\CustomXmlSitemap\\Sitemap_CPT::is_terms_mode( ${ sitemapId } ) ) {
	$g = new \\XWP\\CustomXmlSitemap\\Terms_Sitemap_Generator( $post );
} else {
	$g = new \\XWP\\CustomXmlSitemap\\Sitemap_Generator( $post );
}
$g->regenerate_all();
` );
}

/**
 * Drop a one-shot mu-plugin from the tests/e2e/mu-plugins directory into the
 * running container's mu-plugins directory. The file is named with a
 * `cxs-e2e-` prefix so global-teardown can scrub it.
 *
 * @param fileName Source file under tests/e2e/mu-plugins (without .php).
 */
export function installMuPlugin( fileName: string ): void {
	wpEval( `
$src = '/var/www/html/wp-content/plugins/custom-xml-sitemap/tests/e2e/mu-plugins/${ fileName }.php';
$dst = WPMU_PLUGIN_DIR . '/cxs-e2e-${ fileName }.php';
if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { mkdir( WPMU_PLUGIN_DIR, 0755, true ); }
copy( $src, $dst );
` );
}

/**
 * Remove a previously installed mu-plugin drop-in.
 */
export function removeMuPlugin( fileName: string ): void {
	wpEval( `@unlink( WPMU_PLUGIN_DIR . '/cxs-e2e-${ fileName }.php' );` );
}

/**
 * Count pending Action Scheduler jobs in the cxs-sitemap group.
 */
export function countPendingScheduledJobs( hook?: string ): number {
	const phpArgs = hook ? `'hook' => '${ hook }', ` : '';
	const out = wpEval(
		`echo count( as_get_scheduled_actions( [ ${ phpArgs }'group' => 'cxs-sitemap', 'status' => 'pending', 'per_page' => 100 ] ) );`
	);
	return parseInt( out, 10 ) || 0;
}

/**
 * Read post meta as a plain string.
 */
export function getPostMeta( postId: number, key: string ): string {
	return wpCli( [ 'post', 'meta', 'get', String( postId ), key ] );
}

/**
 * Run all pending Action Scheduler jobs synchronously.
 */
export function runScheduledJobs(): void {
	try {
		wpCli( [ 'action-scheduler', 'run', '--group=cxs-sitemap' ] );
		return;
	} catch ( _e ) {
		// CLI extension might not be present; fall back to manual dispatch.
	}

	// Manually fetch pending actions and execute them. Avoids stake_claim,
	// which throws InvalidArgumentException if the group has never been
	// claimed before (the group row in the AS DB doesn't exist yet).
	wpEval( `
$action_ids = as_get_scheduled_actions(
	[
		'group'    => 'cxs-sitemap',
		'status'   => \\ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 50,
	],
	'ids'
);
$runner = \\ActionScheduler::runner();
foreach ( $action_ids as $action_id ) {
	$runner->process_action( $action_id, 'CLI' );
}
` );
}

/**
 * Fetch a sitemap URL using WP-CLI from inside the container so we hit the
 * same hostname WP thinks it is. Returns the response body or throws on
 * non-200.
 */
export interface FetchedSitemap {
	status: number;
	body: string;
	contentType: string;
}

export async function fetchSitemap(
	pathname: string,
	opts: { followRedirects?: boolean } = {}
): Promise<FetchedSitemap> {
	const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8889';
	const url = new URL( pathname, baseUrl ).toString();
	const followRedirects = opts.followRedirects ?? true;

	// Apache in wp-env occasionally drops keep-alive connections mid-flight,
	// surfacing as `SocketError: other side closed` on the first call after
	// PHP container restarts. Retry once with a fresh connection.
	let lastErr: unknown;
	for ( let attempt = 0; attempt < 2; attempt++ ) {
		try {
			const res = await fetch( url, {
				redirect: followRedirects ? 'follow' : 'manual',
				headers: { connection: 'close' },
			} );
			const body = await res.text();

			return {
				status: res.status,
				body,
				contentType: res.headers.get( 'content-type' ) || '',
			};
		} catch ( e ) {
			lastErr = e;
			await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );
		}
	}
	throw lastErr;
}
