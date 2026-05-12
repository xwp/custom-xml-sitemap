/**
 * Playwright global setup.
 *
 * Runs once before any spec. Logs into wp-admin as the default wp-env admin
 * user and persists the storage state to disk; specs reuse it via the
 * storageState option in playwright.config.ts. Also nukes the AS queue and
 * any leftover sitemap CPTs so each run starts from a clean baseline.
 */
import { chromium, FullConfig, request } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { ensurePrettyPermalinks, loadSeedFixture, wpCli } from './wp-cli';

const STORAGE_DIR = path.join( __dirname, '..', '.auth' );
const STORAGE_STATE = path.join( STORAGE_DIR, 'admin.json' );

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASSWORD || 'password';

export default async function globalSetup( config: FullConfig ): Promise<void> {
	const baseURL =
		config.projects[ 0 ]?.use?.baseURL || process.env.WP_BASE_URL || 'http://localhost:8889';

	fs.mkdirSync( STORAGE_DIR, { recursive: true } );

	// Reload the committed seed fixture (1000 bulk posts in 2024-06,
	// 500 spread posts across 2023, 1100 categories, 25 posts with featured
	// images). Replaces whatever's currently in the tests DB so each run
	// starts from a deterministic baseline. Also re-applies pretty
	// permalinks afterwards.
	loadSeedFixture();

	// Reset transient state on top of the fixture: drop any e2e- (not fx-)
	// CPT/term/post fixtures left behind by prior runs and cancel pending AS
	// jobs that may have been written into the dump.
	resetBaselineState();

	// Log in once and persist auth.
	const browser = await chromium.launch();
	const context = await browser.newContext( { baseURL, ignoreHTTPSErrors: true } );
	const page = await context.newPage();

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /\/wp-admin\/?/ );

	await context.storageState( { path: STORAGE_STATE } );
	await browser.close();
}

/**
 * Wipe e2e-prefixed fixtures and pending AS jobs so every run is hermetic.
 *
 * We deliberately do not touch built-in WP fixtures (Hello World post, etc.)
 * because some specs assert default categories survive.
 */
function resetBaselineState(): void {
	// Plugin activation is idempotent. wp-env activates plugins for the dev
	// container automatically but the tests container needs a manual nudge
	// after every fixture reload, otherwise WP-CLI eval can't see plugin
	// classes.
	try {
		wpCli( [ 'plugin', 'activate', 'custom-xml-sitemap' ] );
	} catch ( _e ) {
		// Already active is not an error worth surfacing.
	}

	// Delete any leftover sitemap CPTs (e.g. fixtures left mid-run). The
	// dump itself doesn't include any.
	wpCli( [
		'post',
		'list',
		'--post_type=cxs_sitemap',
		'--post_status=any',
		'--format=ids',
	] )
		.split( /\s+/ )
		.filter( Boolean )
		.forEach( ( id ) => {
			wpCli( [ 'post', 'delete', id, '--force' ] );
		} );

	// Cancel any pending Action Scheduler jobs in our group.
	try {
		wpCli( [ 'action-scheduler', 'cancel', '--group=cxs-sitemap' ] );
	} catch ( _e ) {
		// AS WP-CLI extension may not be loaded; not fatal.
	}

	// Drop any leftover terms whose name OR slug starts with "e2e-". Specs
	// rename terms (e.g. the debounce test sets name=edit-3) so a name-only
	// filter would leave them behind. Slug is preserved across edits in WP
	// unless explicitly changed, so combining both catches all our fixtures.
	deleteTermsMatching( 'post_tag', 'e2e-' );
	deleteTermsMatching( 'category', 'e2e-' );

	// Drop any e2e-prefixed posts left behind. `wp post list --name__like` is
	// silently ignored (not a real filter), so we list ID+post_name and
	// filter on the host. Scanning ~1500 fixture posts is still ~1s.
	const json = wpCli( [
		'post',
		'list',
		'--post_type=post',
		'--post_status=any',
		'--fields=ID,post_name',
		'--format=json',
		'--posts_per_page=-1',
	] );
	const posts = ( JSON.parse( json || '[]' ) as Array< {
		ID: number;
		post_name: string;
	} > ).filter( ( p ) => p.post_name.startsWith( 'e2e-' ) );
	for ( const p of posts ) {
		wpCli( [ 'post', 'delete', String( p.ID ), '--force' ] );
	}
}

/**
 * Delete terms in `taxonomy` whose name OR slug starts with the given prefix.
 *
 * `wp term list` supports `--name__like` (server-side LIKE), but slug filtering
 * isn't exposed natively, so we list all terms once and filter in JS.
 */
function deleteTermsMatching( taxonomy: string, prefix: string ): void {
	const json = wpCli( [
		'term',
		'list',
		taxonomy,
		'--fields=term_id,name,slug',
		'--format=json',
	] );
	const terms = ( JSON.parse( json || '[]' ) as Array< {
		term_id: number;
		name: string;
		slug: string;
	} > ).filter(
		( t ) => t.name.startsWith( prefix ) || t.slug.startsWith( prefix )
	);

	for ( const t of terms ) {
		wpCli( [ 'term', 'delete', taxonomy, String( t.term_id ) ] );
	}
}
