/**
 * cxs_sitemap_skip_post filter e2e.
 *
 * Drops a mu-plugin that hooks the filter and skips any post tagged with the
 * `cxs_e2e_skip` meta. Generates a urlset, then asserts the skipped post is
 * absent while the kept post survives. Verifies the filter is the single
 * chokepoint for posts-mode urlset emission.
 */
import { expect, test } from '@playwright/test';
import {
	createPost,
	createSitemap,
	fetchSitemap,
	installMuPlugin,
	regenerateSitemap,
	removeMuPlugin,
} from '../helpers/fixtures';
import { wpCli } from '../helpers/wp-cli';

test.describe( 'cxs_sitemap_skip_post filter', () => {
	let sitemapId: number;
	let keptId: number;
	let skippedId: number;

	test.beforeAll( () => {
		installMuPlugin( 'skip-post-by-meta' );

		keptId = createPost( {
			title: 'e2e-skip-kept',
			slug: 'e2e-skip-kept',
			postDate: '2024-07-10 10:00:00',
		} );
		skippedId = createPost( {
			title: 'e2e-skip-omitted',
			slug: 'e2e-skip-omitted',
			postDate: '2024-07-15 10:00:00',
		} );
		wpCli( [ 'post', 'meta', 'update', String( skippedId ), 'cxs_e2e_skip', '1' ] );

		sitemapId = createSitemap( {
			title: 'e2e Skip Filter Sitemap',
			slug: 'e2e-skip-filter',
			mode: 'posts',
			postType: 'post',
			granularity: 'year',
		} );
		regenerateSitemap( sitemapId );
	} );

	test.afterAll( () => {
		wpCli( [ 'post', 'delete', String( sitemapId ), '--force' ] );
		wpCli( [ 'post', 'delete', String( keptId ), '--force' ] );
		wpCli( [ 'post', 'delete', String( skippedId ), '--force' ] );
		removeMuPlugin( 'skip-post-by-meta' );
	} );

	test( 'kept post is present in urlset', async () => {
		const res = await fetchSitemap( '/sitemaps/e2e-skip-filter/2024.xml' );
		expect( res.status ).toBe( 200 );
		expect( res.body ).toMatch( /e2e-skip-kept/ );
	} );

	test( 'skipped post is absent from urlset', async () => {
		const res = await fetchSitemap( '/sitemaps/e2e-skip-filter/2024.xml' );
		expect( res.body ).not.toMatch( /e2e-skip-omitted/ );
	} );
} );
