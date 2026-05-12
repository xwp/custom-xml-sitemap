/**
 * Term CRUD invalidation e2e.
 *
 * Creating, editing, and deleting a term in a watched taxonomy should each
 * enqueue exactly one debounced AS job. Running the queue then materialises
 * the change in the rendered XML.
 */
import { expect, test } from '@playwright/test';
import {
	countPendingScheduledJobs,
	createSitemap,
	createTerm,
	fetchSitemap,
	regenerateSitemap,
	runScheduledJobs,
} from '../helpers/fixtures';
import { wpCli, wpEval } from '../helpers/wp-cli';

/**
 * Best-effort cancellation of all pending AS jobs in our group. Tries the
 * action-scheduler WP-CLI subcommand first; falls back to a direct PHP call
 * when the extension isn't loaded.
 */
function cancelAllPendingJobs(): void {
	try {
		wpCli( [ 'action-scheduler', 'cancel', '--group=cxs-sitemap' ] );
		return;
	} catch ( _e ) {
		/* fall through */
	}
	wpEval( `
$ids = as_get_scheduled_actions(
	[
		'group'    => 'cxs-sitemap',
		'status'   => \\ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 100,
	],
	'ids'
);
foreach ( $ids as $id ) {
	\\ActionScheduler::store()->cancel_action( $id );
}
` );
}

test.describe( 'Term CRUD invalidation', () => {
	let sitemapId: number;

	test.beforeAll( () => {
		// post_tag is empty in the seed fixture; using `category` would put us
		// over 1000 terms and trigger the paginated <sitemapindex> path. The
		// invalidation logic itself is taxonomy-agnostic, so post_tag exercises
		// the same scheduler hooks with simpler XML to assert against.
		sitemapId = createSitemap( {
			title: 'e2e Term Invalidation',
			slug: 'e2e-term-invalidation',
			mode: 'terms',
			taxonomy: 'post_tag',
			termsHideEmpty: false,
		} );
		regenerateSitemap( sitemapId );
	} );

	test.beforeEach( () => {
		// Cancel any leftover jobs so each test in this spec starts clean.
		// Has to be in beforeEach (not beforeAll) because earlier tests in this
		// describe block enqueue jobs that bleed into later ones.
		cancelAllPendingJobs();
	} );

	test.afterAll( () => {
		wpCli( [ 'post', 'delete', String( sitemapId ), '--force' ] );
	} );

	test( 'created_term schedules a regeneration job', () => {
		expect( countPendingScheduledJobs( 'cxs_regenerate_sitemap_all' ) ).toBe( 0 );

		createTerm( 'post_tag', 'e2e-invalidation-tag', 'e2e-invalidation-tag' );

		expect( countPendingScheduledJobs( 'cxs_regenerate_sitemap_all' ) ).toBe( 1 );
	} );

	test( 'multiple rapid edits debounce to a single pending job', () => {
		// Use a unique slug so retries on this single test don't collide with
		// a leftover term in the DB.
		const slug = `e2e-debounce-tag-${ Date.now() }`;
		const termId = createTerm( 'post_tag', slug, slug );

		// Multiple updates in quick succession. Each triggers `edited_term`,
		// but the scheduler should collapse them into a single pending job
		// alongside the one enqueued by `created_term` above.
		wpCli( [ 'term', 'update', 'post_tag', String( termId ), '--name=edit-1' ] );
		wpCli( [ 'term', 'update', 'post_tag', String( termId ), '--name=edit-2' ] );
		wpCli( [ 'term', 'update', 'post_tag', String( termId ), '--name=edit-3' ] );

		// One job total: the create + edits all coalesce on the same hook+args.
		expect( countPendingScheduledJobs( 'cxs_regenerate_sitemap_all' ) ).toBe( 1 );
	} );

	test( 'running the scheduler queue refreshes the XML', async () => {
		// Use a unique slug per run so retries don't collide on the WP unique-name check.
		const uniq = `e2e-fresh-${ Date.now() }`;
		const newTermId = createTerm( 'post_tag', uniq, uniq );

		runScheduledJobs();

		const res = await fetchSitemap( '/sitemaps/e2e-term-invalidation/index.xml' );
		expect( res.body ).toContain( uniq );

		// Cleanup the term.
		wpCli( [ 'term', 'delete', 'post_tag', String( newTermId ) ] );
	} );
} );
