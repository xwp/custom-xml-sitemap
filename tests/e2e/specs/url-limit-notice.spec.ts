/**
 * URL-limit admin notice e2e.
 *
 * Asserts the warning notice on the sitemap edit screen:
 *   - appears for posts-mode sitemaps that exceed MAX_URLS_PER_SITEMAP in any bucket;
 *   - is gated to posts mode (terms-mode sitemaps must never see it).
 *
 * Backed by the seed fixture (tests/e2e/fixtures/seed.sql.gz), which contains
 * 1000 published posts dated within 2024-06 — exactly the bucket boundary
 * that triggers Sitemap_Generator::has_exceeded_url_limit(). The first
 * regenerate_all() pass writes the cxs_sitemap_url_count_2024_06 meta the
 * notice reads.
 */
import { expect, test } from '@playwright/test';
import { createSitemap, regenerateSitemap } from '../helpers/fixtures';
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
		// Trigger one full pass so url_count_* meta is populated against the
		// 1000-post 2024-06 bucket the fixture provides.
		regenerateSitemap( postsSitemapId );

		termsSitemapId = createSitemap( {
			title: 'e2e URL-Limit Terms',
			slug: 'e2e-url-limit-terms',
			mode: 'terms',
			taxonomy: 'category',
		} );
		regenerateSitemap( termsSitemapId );
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
