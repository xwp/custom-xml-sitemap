/**
 * Admin Settings panel (React UI) e2e.
 *
 * Verifies the conditional field logic in the sitemap editor matches the ACF
 * source of truth: mode → granularity/taxonomy/terms visibility, and
 * filter_mode → only visible when mode=posts AND a taxonomy AND ≥1 term is
 * selected. Save round-trip is confirmed by reading back post meta via WP-CLI.
 */
import { expect, test } from '@playwright/test';
import { createSitemap, createTerm, getPostMeta } from '../helpers/fixtures';
import { wpCli } from '../helpers/wp-cli';

test.describe( 'Admin Settings panel', () => {
	let sitemapId: number;
	let categoryId: number;

	test.beforeAll( async () => {
		categoryId = createTerm( 'category', 'e2e-Settings Cat', 'e2e-settings-cat' );
		// Seed a post under that category so wp_count_terms reports >0 if hide_empty respected.
		wpCli( [
			'post',
			'create',
			'--post_type=post',
			'--post_status=publish',
			'--post_title=e2e-settings-seed',
			'--post_name=e2e-settings-seed',
			`--post_category=${ categoryId }`,
			'--porcelain',
		] );

		sitemapId = createSitemap( {
			title: 'e2e Settings Sitemap',
			slug: 'e2e-settings-sitemap',
			mode: 'posts',
			postType: 'post',
			granularity: 'year',
		} );
	} );

	test.afterAll( () => {
		wpCli( [ 'post', 'delete', String( sitemapId ), '--force' ] );
		wpCli( [ 'term', 'delete', 'category', String( categoryId ) ] );
	} );

	test( 'posts mode shows granularity, hides terms_hide_empty', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ sitemapId }&action=edit` );

		// React panel root rendered by the plugin (see assets/src/admin/settings-panel.js).
		const panel = page.locator( '#cxs-settings-panel' );
		await expect( panel ).toBeVisible();

		// Granularity is a posts-mode field.
		await expect( panel.getByLabel( 'Granularity' ) ).toBeVisible();

		// Hide Empty Terms is a terms-mode field; should not be present.
		await expect( panel.getByLabel( 'Hide Empty Terms' ) ).toHaveCount( 0 );
	} );

	test( 'switching to terms mode hides granularity, shows hide empty', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ sitemapId }&action=edit` );
		const panel = page.locator( '#cxs-settings-panel' );

		await panel.getByLabel( 'Sitemap Mode' ).selectOption( 'terms' );

		await expect( panel.getByLabel( 'Granularity' ) ).toHaveCount( 0 );
		await expect( panel.getByLabel( 'Hide Empty Terms' ) ).toBeVisible();

		// Reset back to posts so subsequent tests see expected baseline.
		await panel.getByLabel( 'Sitemap Mode' ).selectOption( 'posts' );
	} );

	test( 'filter_mode hidden until taxonomy + ≥1 term selected', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ sitemapId }&action=edit` );
		const panel = page.locator( '#cxs-settings-panel' );

		// No taxonomy yet → filter_mode hidden.
		await expect( panel.getByLabel( 'Filter Mode' ) ).toHaveCount( 0 );

		// In posts mode the taxonomy field is labelled "Taxonomy Filter".
		await panel.getByLabel( 'Taxonomy Filter' ).selectOption( 'category' );
		await expect( panel.getByLabel( 'Filter Mode' ) ).toHaveCount( 0 );

		// Select a term → filter_mode appears.
		const termsField = panel.getByLabel( 'Filter by Terms' );
		await termsField.click();
		await termsField.fill( 'e2e' );
		await page.getByRole( 'option', { name: /e2e-Settings Cat/i } ).first().click();

		await expect( panel.getByLabel( 'Filter Mode' ) ).toBeVisible();
	} );

	test( 'save round-trip persists filter_mode=exclude', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ sitemapId }&action=edit` );
		const panel = page.locator( '#cxs-settings-panel' );

		// Re-establish state from the previous test (Playwright resets between tests).
		await panel.getByLabel( 'Taxonomy Filter' ).selectOption( 'category' );
		const termsField = panel.getByLabel( 'Filter by Terms' );
		await termsField.click();
		await termsField.fill( 'e2e' );
		await page.getByRole( 'option', { name: /e2e-Settings Cat/i } ).first().click();

		await panel.getByLabel( 'Filter Mode' ).selectOption( 'exclude' );

		// Click the standard WordPress Update button.
		await page.getByRole( 'button', { name: /^update$/i } ).click();

		// WordPress shows a "Post updated" snackbar on success.
		await expect( page.getByText( /post updated/i ) ).toBeVisible( { timeout: 15_000 } );

		expect( getPostMeta( sitemapId, 'cxs_filter_mode' ) ).toBe( 'exclude' );
		expect( getPostMeta( sitemapId, 'cxs_taxonomy' ) ).toBe( 'category' );
	} );
} );
