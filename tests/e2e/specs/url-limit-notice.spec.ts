/**
 * URL-limit admin notice e2e.
 *
 * Asserts the warning notice on the sitemap edit screen:
 *   - appears for posts-mode sitemaps that exceed MAX_URLS_PER_SITEMAP in any bucket;
 *   - is gated to posts mode (terms-mode sitemaps must never see it).
 *
 * We avoid factory-creating 1000+ posts (slow on CI) by directly seeding the
 * cached URL-count meta keys that has_exceeded_url_limit() reads.
 */
import { expect, test } from '@playwright/test';
import { createSitemap } from '../helpers/fixtures';
import { wpCli } from '../helpers/wp-cli';

const NOTICE_PATTERN = /one or more sitemap periods have reached the 1000 URL limit/i;

test.describe( 'URL-limit admin notice', () => {
	let postsSitemapId: number;
	let termsSitemapId: number;

	test.beforeAll( () => {
		postsSitemapId = createSitemap( {
			title: 'e2e URL-Limit Posts',
			slug: 'e2e-url-limit-posts',
			mode: 'posts',
			postType: 'post',
			granularity: 'month',
		} );

		// Seed a URL-count meta value at the cap so has_exceeded_url_limit() returns true.
		// Meta key prefix is Sitemap_Generator::META_KEY_URL_COUNT = 'cxs_sitemap_url_count_'.
		wpCli( [
			'post',
			'meta',
			'update',
			String( postsSitemapId ),
			'cxs_sitemap_url_count_2024_06',
			'1000',
		] );

		termsSitemapId = createSitemap( {
			title: 'e2e URL-Limit Terms',
			slug: 'e2e-url-limit-terms',
			mode: 'terms',
			taxonomy: 'category',
		} );
		// Same count, but terms mode should not display the notice regardless.
		wpCli( [
			'post',
			'meta',
			'update',
			String( termsSitemapId ),
			'cxs_sitemap_url_count_2024_06',
			'1000',
		] );
	} );

	test.afterAll( () => {
		wpCli( [ 'post', 'delete', String( postsSitemapId ), '--force' ] );
		wpCli( [ 'post', 'delete', String( termsSitemapId ), '--force' ] );
	} );

	test( 'posts mode shows warning when bucket exceeds 1000 URLs', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ postsSitemapId }&action=edit` );
		await expect( page.getByText( NOTICE_PATTERN ) ).toBeVisible();
	} );

	test( 'terms mode never shows the URL-limit notice', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ termsSitemapId }&action=edit` );
		await expect( page.getByText( NOTICE_PATTERN ) ).toHaveCount( 0 );
	} );
} );
