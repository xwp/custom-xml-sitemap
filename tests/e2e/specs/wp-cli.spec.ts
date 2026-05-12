/**
 * WP-CLI subcommand e2e.
 *
 * Smokes the public surface of the `wp cxs` namespace against a live sitemap.
 * We don't enumerate every flag — that's covered by the underlying generator
 * tests — but we do confirm each subcommand executes without error and emits
 * something recognisable.
 */
import { expect, test } from '@playwright/test';
import { createPost, createSitemap, regenerateSitemap } from '../helpers/fixtures';
import { wpCli } from '../helpers/wp-cli';

test.describe( 'wp cxs commands', () => {
	const SITEMAP_SLUG = 'e2e-cli';
	let sitemapId: number;
	let postId: number;

	test.beforeAll( () => {
		postId = createPost( {
			title: 'e2e-cli-post',
			slug: 'e2e-cli-post',
			postDate: '2024-09-01 10:00:00',
		} );
		sitemapId = createSitemap( {
			title: 'e2e CLI Sitemap',
			slug: SITEMAP_SLUG,
			mode: 'posts',
			postType: 'post',
			granularity: 'year',
		} );
		regenerateSitemap( sitemapId );
	} );

	test.afterAll( () => {
		wpCli( [ 'post', 'delete', String( sitemapId ), '--force' ] );
		wpCli( [ 'post', 'delete', String( postId ), '--force' ] );
	} );

	test( 'wp cxs stats lists the sitemap', () => {
		const out = wpCli( [ 'cxs', 'stats' ] );
		expect( out ).toContain( SITEMAP_SLUG );
	} );

	test( 'wp cxs generate <slug> runs without error', () => {
		// `cxs generate` takes a slug, not an ID.
		const out = wpCli( [ 'cxs', 'generate', SITEMAP_SLUG ] );
		expect( out.length ).toBeGreaterThan( 0 );
	} );

	test( 'wp cxs validate <slug> reports valid XML', () => {
		const out = wpCli( [ 'cxs', 'validate', SITEMAP_SLUG ] );
		expect( out ).toMatch( /valid/i );
	} );
} );
