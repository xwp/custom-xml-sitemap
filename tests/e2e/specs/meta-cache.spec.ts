/**
 * Memcached-safe meta cache roundtrip e2e.
 *
 * Verifies the get_meta_direct/set_meta_direct path:
 * 1. Generate XML, confirm cached payload matches what's served.
 * 2. Modify the cached blob directly; the cached version is served on next fetch.
 * 3. force_regenerate via WP-CLI rebuilds and overwrites the cache.
 *
 * This pins the contract that XML I/O bypasses the post-meta object cache,
 * which is the whole point of the helper introduced in Slice A.
 */
import { expect, test } from '@playwright/test';
import {
	createPost,
	createSitemap,
	fetchSitemap,
	regenerateSitemap,
} from '../helpers/fixtures';
import { wpCli, wpEval } from '../helpers/wp-cli';

test.describe( 'Meta cache roundtrip', () => {
	let sitemapId: number;
	let postId: number;

	test.beforeAll( () => {
		postId = createPost( {
			title: 'e2e-meta-cache-post',
			slug: 'e2e-meta-cache-post',
			postDate: '2024-08-01 10:00:00',
		} );
		sitemapId = createSitemap( {
			title: 'e2e Meta Cache Sitemap',
			slug: 'e2e-meta-cache',
			mode: 'posts',
			postType: 'post',
			granularity: 'year',
		} );
	} );

	test.afterAll( () => {
		wpCli( [ 'post', 'delete', String( sitemapId ), '--force' ] );
		wpCli( [ 'post', 'delete', String( postId ), '--force' ] );
	} );

	test( 'first fetch generates and caches XML', async () => {
		regenerateSitemap( sitemapId );

		const res = await fetchSitemap( '/sitemaps/e2e-meta-cache/2024.xml' );
		expect( res.status ).toBe( 200 );
		expect( res.body ).toContain( 'e2e-meta-cache-post' );

		// Cache key for year sitemap follows the cxs_sitemap_xml_{year} convention.
		const cached = wpEval(
			`echo \\XWP\\CustomXmlSitemap\\Sitemap_CPT::get_meta_direct( ${ sitemapId }, 'cxs_sitemap_xml_2024' );`
		);
		expect( cached ).toContain( 'e2e-meta-cache-post' );
	} );

	test( 'serves cached XML when payload is mutated externally', async () => {
		// Inject a sentinel into the cached blob.
		wpEval(
			`\\XWP\\CustomXmlSitemap\\Sitemap_CPT::set_meta_direct( ${ sitemapId }, 'cxs_sitemap_xml_2024', '<urlset><!-- e2e-sentinel --></urlset>' );`
		);

		const res = await fetchSitemap( '/sitemaps/e2e-meta-cache/2024.xml' );
		expect( res.body ).toContain( 'e2e-sentinel' );
	} );

	test( 'force regenerate overwrites the cached payload', async () => {
		regenerateSitemap( sitemapId );

		const res = await fetchSitemap( '/sitemaps/e2e-meta-cache/2024.xml' );
		expect( res.body ).not.toContain( 'e2e-sentinel' );
		expect( res.body ).toContain( 'e2e-meta-cache-post' );
	} );
} );
