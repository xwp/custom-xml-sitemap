/**
 * Sitemap URL routing e2e.
 *
 * Walks the public URL surface for both Posts and Terms mode sitemaps and
 * confirms each route returns valid XML with the right shape (urlset vs
 * sitemapindex), the right content-type header, and the expected URLs.
 */
import { expect, test } from '@playwright/test';
import {
	createPost,
	createSitemap,
	createTerm,
	fetchSitemap,
	regenerateSitemap,
} from '../helpers/fixtures';
import { wpCli } from '../helpers/wp-cli';

test.describe( 'Sitemap routing', () => {
	test.describe( 'Posts mode', () => {
		let sitemapId: number;
		let categoryId: number;
		let postId: number;

		test.beforeAll( () => {
			categoryId = createTerm( 'category', 'e2e-Routing Cat', 'e2e-routing-cat' );
			postId = createPost( {
				title: 'e2e-routing-post',
				slug: 'e2e-routing-post',
				postDate: '2024-06-15 10:00:00',
				terms: [ { taxonomy: 'category', ids: [ categoryId ] } ],
			} );
			sitemapId = createSitemap( {
				title: 'e2e Posts Routing',
				slug: 'e2e-posts-routing',
				mode: 'posts',
				postType: 'post',
				granularity: 'month',
			} );
			regenerateSitemap( sitemapId );
		} );

		test.afterAll( () => {
			wpCli( [ 'post', 'delete', String( sitemapId ), '--force' ] );
			wpCli( [ 'post', 'delete', String( postId ), '--force' ] );
			wpCli( [ 'term', 'delete', 'category', String( categoryId ) ] );
		} );

		test( 'index.xml returns sitemapindex with month entries', async () => {
			const res = await fetchSitemap( '/sitemaps/e2e-posts-routing/index.xml' );
			expect( res.status ).toBe( 200 );
			expect( res.contentType ).toMatch( /application\/xml/ );
			expect( res.body ).toContain( '<sitemapindex' );
			expect( res.body ).toContain( '/sitemaps/e2e-posts-routing/2024.xml' );
		} );

		test( '{year}.xml returns sitemapindex of months under year granularity parent', async () => {
			const res = await fetchSitemap( '/sitemaps/e2e-posts-routing/2024.xml' );
			expect( res.status ).toBe( 200 );
			// Month granularity → year is an index of months.
			expect( res.body ).toContain( '<sitemapindex' );
			expect( res.body ).toContain( '2024-06.xml' );
		} );

		test( '{year}-{month}.xml returns urlset with the seeded post', async () => {
			const res = await fetchSitemap( '/sitemaps/e2e-posts-routing/2024-06.xml' );
			expect( res.status ).toBe( 200 );
			expect( res.body ).toContain( '<urlset' );
			expect( res.body ).toMatch( /e2e-routing-post/ );
		} );

		test( 'unknown slug returns 404', async () => {
			const res = await fetchSitemap( '/sitemaps/does-not-exist/index.xml' );
			expect( res.status ).toBe( 404 );
		} );
	} );

	test.describe( 'Terms mode (small taxonomy)', () => {
		// Use post_tag for the sub-1000 case so the seed fixture's 1100
		// fx-cat categories don't bleed into the assertions.
		let sitemapId: number;
		let termIds: number[] = [];

		test.beforeAll( () => {
			for ( let i = 1; i <= 3; i++ ) {
				const tid = createTerm( 'post_tag', `e2e-term-${ i }`, `e2e-term-${ i }` );
				termIds.push( tid );
				createPost( {
					title: `e2e-term-${ i }-post`,
					slug: `e2e-term-${ i }-post`,
					terms: [ { taxonomy: 'post_tag', ids: [ tid ] } ],
				} );
			}

			sitemapId = createSitemap( {
				title: 'e2e Terms Routing',
				slug: 'e2e-terms-routing',
				mode: 'terms',
				taxonomy: 'post_tag',
				termsHideEmpty: true,
			} );
			regenerateSitemap( sitemapId );
		} );

		test.afterAll( () => {
			wpCli( [ 'post', 'delete', String( sitemapId ), '--force' ] );
			for ( const tid of termIds ) {
				wpCli( [ 'term', 'delete', 'post_tag', String( tid ) ] );
			}
		} );

		test( 'index.xml returns urlset (≤1000 terms inline)', async () => {
			const res = await fetchSitemap( '/sitemaps/e2e-terms-routing/index.xml' );
			expect( res.status ).toBe( 200 );
			expect( res.body ).toContain( '<urlset' );
			expect( res.body ).toMatch( /e2e-term-1/ );
			expect( res.body ).toMatch( /e2e-term-2/ );
			expect( res.body ).toMatch( /e2e-term-3/ );
			// Terms sitemaps don't include lastmod.
			expect( res.body ).not.toContain( '<lastmod>' );
		} );

		test( 'page-{n}.xml returns empty urlset for out-of-range page', async () => {
			const res = await fetchSitemap( '/sitemaps/e2e-terms-routing/page-99.xml' );
			expect( res.status ).toBe( 200 );
			expect( res.body ).toContain( '<urlset' );
			expect( res.body ).not.toContain( '<url>' );
		} );
	} );

	test.describe( 'Terms mode (paginated, >1000)', () => {
		// Backed by the seed fixture's 1100 fx-cat categories, all attached
		// to fx-spread-2023-* posts so hide_empty=true keeps them. Triggers
		// the page-N.xml split in Terms_Sitemap_Generator.
		let sitemapId: number;

		test.beforeAll( () => {
			sitemapId = createSitemap( {
				title: 'e2e Terms Paginated',
				slug: 'e2e-terms-paginated',
				mode: 'terms',
				taxonomy: 'category',
				termsHideEmpty: true,
			} );
			regenerateSitemap( sitemapId );
		} );

		test.afterAll( () => {
			wpCli( [ 'post', 'delete', String( sitemapId ), '--force' ] );
		} );

		test( 'index.xml returns sitemapindex with page-* entries', async () => {
			const res = await fetchSitemap( '/sitemaps/e2e-terms-paginated/index.xml' );
			expect( res.status ).toBe( 200 );
			expect( res.body ).toContain( '<sitemapindex' );
			expect( res.body ).toContain( '/sitemaps/e2e-terms-paginated/page-1.xml' );
			expect( res.body ).toContain( '/sitemaps/e2e-terms-paginated/page-2.xml' );
		} );

		test( 'page-1.xml returns the first 1000 term URLs', async () => {
			const res = await fetchSitemap( '/sitemaps/e2e-terms-paginated/page-1.xml' );
			expect( res.status ).toBe( 200 );
			expect( res.body ).toContain( '<urlset' );
			// At least one of the seeded categories should be present.
			expect( res.body ).toMatch( /\/category\/fx-cat-/ );
		} );
	} );

	test( 'XSL stylesheets resolve', async () => {
		const sitemap = await fetchSitemap( '/cxs-sitemap.xsl' );
		expect( sitemap.status ).toBe( 200 );
		expect( sitemap.contentType ).toMatch( /xslt\+xml/ );

		const index = await fetchSitemap( '/cxs-sitemap-index.xsl' );
		expect( index.status ).toBe( 200 );
	} );
} );
