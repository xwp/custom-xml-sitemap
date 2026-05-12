/**
 * Playwright configuration for custom-xml-sitemap e2e tests.
 *
 * Runs against the wp-env "tests" instance (http://localhost:8889) so the
 * suite never collides with the dev instance on :8888. Boot wp-env separately
 * (`pnpm env:start`) before invoking Playwright; see HANDOFF.md.
 */
import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const PLUGIN_ROOT = path.resolve( __dirname, '..', '..' );
const STORAGE_STATE = path.join( __dirname, '.auth', 'admin.json' );

export default defineConfig( {
	testDir: path.join( __dirname, 'specs' ),
	outputDir: path.join( PLUGIN_ROOT, 'test-results' ),
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false, // Specs share global WP state; serialise to keep fixtures sane.
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: path.join( PLUGIN_ROOT, 'playwright-report' ), open: 'never' } ],
		[ 'json', { outputFile: path.join( PLUGIN_ROOT, 'playwright-report', 'results.json' ) } ],
	],
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
		storageState: STORAGE_STATE,
		actionTimeout: 10_000,
		navigationTimeout: 30_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		ignoreHTTPSErrors: true,
	},
	globalSetup: path.join( __dirname, 'helpers', 'global-setup.ts' ),
	globalTeardown: path.join( __dirname, 'helpers', 'global-teardown.ts' ),
	projects: [
		{
			name: 'chromium',
			// Spread Desktop Chrome first so the project's `use` keeps the
			// top-level baseURL/storageState/etc. set above. Without this the
			// device defaults blow away baseURL and `page.goto('/wp-admin/...')`
			// fails with "Cannot navigate to invalid URL".
			use: {
				...devices[ 'Desktop Chrome' ],
				baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
			},
		},
	],
} );
