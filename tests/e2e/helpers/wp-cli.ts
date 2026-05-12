/**
 * WP-CLI helper.
 *
 * Shells out to wp-env's tests-cli container to run WP-CLI commands against
 * the test WordPress instance (port 8889). Used for fixture seeding,
 * Action Scheduler queue inspection, and anything else that the REST API
 * doesn't expose.
 *
 * The first call in a process bootstraps a single child_process spawn per
 * invocation; on CI this is the dominant per-test cost, so prefer batching
 * via `wp eval-file` when seeding multiple things.
 */
import { execFileSync, ExecFileSyncOptions } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const PLUGIN_ROOT = path.resolve( __dirname, '..', '..', '..' );
const WP_ENV_BIN = path.join( PLUGIN_ROOT, 'node_modules', '.bin', 'wp-env' );

// Directory on the host that's also visible inside the wp-env container at
// /var/www/html/wp-content/plugins/custom-xml-sitemap/. We use a subdirectory
// here for `wp eval-file` tempfiles so PHP doesn't need to be passed via argv.
const TMP_DIR_HOST = path.join( PLUGIN_ROOT, 'tests', 'e2e', '.tmp' );
const TMP_DIR_CONTAINER = '/var/www/html/wp-content/plugins/custom-xml-sitemap/tests/e2e/.tmp';

export interface WpCliResult {
	stdout: string;
	stderr: string;
	exitCode: number;
}

/**
 * Run a WP-CLI command inside the wp-env tests-cli container.
 *
 * Invokes the local wp-env binary directly to avoid pnpm script-arg parsing
 * stripping flags like `--quiet` from the chain.
 *
 * @param args Arguments to pass after `wp`. e.g. `['post', 'list', '--format=json']`.
 * @param opts Override options (cwd, env, etc.).
 * @returns Stdout from the WP-CLI invocation, trimmed.
 */
export function wpCli( args: string[], opts: ExecFileSyncOptions = {} ): string {
	const fullArgs = [ 'run', 'tests-cli', 'wp', ...args ];

	try {
		const stdout = execFileSync( WP_ENV_BIN, fullArgs, {
			cwd: PLUGIN_ROOT,
			encoding: 'utf-8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			...opts,
		} );

		return stdout.toString().trim();
	} catch ( error: unknown ) {
		const err = error as { stdout?: Buffer; stderr?: Buffer; status?: number };
		throw new Error(
			`wp-cli failed (exit ${ err.status ?? '?' }): wp ${ args.join( ' ' ) }\n` +
				`stdout: ${ err.stdout?.toString() ?? '' }\n` +
				`stderr: ${ err.stderr?.toString() ?? '' }`
		);
	}
}

/**
 * Run a WP-CLI command and parse the JSON response.
 *
 * @param args Arguments to pass after `wp`. Should produce JSON output, e.g. include `--format=json`.
 */
export function wpCliJson<T = unknown>( args: string[] ): T {
	const out = wpCli( args );
	if ( ! out ) {
		return [] as unknown as T;
	}
	return JSON.parse( out ) as T;
}

/**
 * Run arbitrary PHP via `wp eval-file`. We don't use `wp eval` because passing
 * PHP through argv requires shell-level escaping that gets mangled by
 * execFileSync (no shell) and by Docker's argv handling. Instead, we write the
 * snippet to a tempfile inside the plugin tree (which is bind-mounted into
 * the container) and invoke `wp eval-file <path>`.
 *
 * @param php PHP snippet. The snippet runs in the WP-loaded process, so all
 *            functions/classes are available. Should `echo` or `var_export`
 *            the result.
 */
export function wpEval( php: string ): string {
	const tmp = mkdtempSync( path.join( os.tmpdir(), 'cxs-eval-' ) );
	const fileName = `eval-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }.php`;
	// Place the file under the plugin tree so it's visible inside the container.
	mkdirpSync( TMP_DIR_HOST );
	const hostPath = path.join( TMP_DIR_HOST, fileName );
	const containerPath = `${ TMP_DIR_CONTAINER }/${ fileName }`;
	const wrapped = `<?php\n${ php }\n`;

	writeFileSync( hostPath, wrapped );
	try {
		return wpCli( [ 'eval-file', containerPath ] );
	} finally {
		rmSync( hostPath, { force: true } );
		rmSync( tmp, { recursive: true, force: true } );
	}
}

function mkdirpSync( dir: string ): void {
	try {
		// eslint-disable-next-line @typescript-eslint/no-var-requires
		require( 'node:fs' ).mkdirSync( dir, { recursive: true } );
	} catch ( _e ) {
		// Already exists.
	}
}

/**
 * Helper: ensure pretty permalinks are active. Many of our rewrite-driven
 * features depend on this; the wp-env defaults already enable it but tests
 * shouldn't trust that.
 */
export function ensurePrettyPermalinks(): void {
	wpCli( [ 'option', 'update', 'permalink_structure', '/%postname%/' ] );
	wpCli( [ 'rewrite', 'flush', '--hard' ] );
}

/**
 * Paths to the seed SQL fixture, both on the host and inside the wp-env
 * container. The container path mirrors the host because the plugin tree is
 * bind-mounted into wp-env.
 */
const SEED_FIXTURE_HOST_GZ = path.join(
	PLUGIN_ROOT,
	'tests/e2e/fixtures/seed.sql.gz'
);
const SEED_FIXTURE_HOST_SQL = path.join(
	PLUGIN_ROOT,
	'tests/e2e/.tmp/seed.sql'
);
const SEED_FIXTURE_CONTAINER_SQL =
	'/var/www/html/wp-content/plugins/custom-xml-sitemap/tests/e2e/.tmp/seed.sql';

/**
 * Load the e2e seed fixture into the tests DB, replacing whatever's there.
 *
 * Decompresses the committed seed.sql.gz on the host (Node has zlib), drops
 * it into the bind-mounted .tmp/ directory, then runs `wp db import` from
 * inside the container. Finally restores pretty permalinks because the
 * exported `permalink_structure` is preserved but the rewrite rules cache
 * still needs flushing against the current site URL.
 */
export function loadSeedFixture(): void {
	if ( ! require( 'node:fs' ).existsSync( SEED_FIXTURE_HOST_GZ ) ) {
		throw new Error(
			`Seed fixture not found at ${ SEED_FIXTURE_HOST_GZ }. ` +
				'Run tests/e2e/fixtures/build-fixture.php to regenerate it.'
		);
	}

	mkdirpSync( path.dirname( SEED_FIXTURE_HOST_SQL ) );

	// Decompress on the host. Sync to keep the helper non-async and match
	// the rest of the wp-cli helper signatures.
	const fs = require( 'node:fs' ) as typeof import( 'node:fs' );
	const zlib = require( 'node:zlib' ) as typeof import( 'node:zlib' );
	const compressed = fs.readFileSync( SEED_FIXTURE_HOST_GZ );
	fs.writeFileSync( SEED_FIXTURE_HOST_SQL, zlib.gunzipSync( compressed ) );

	try {
		wpCli( [ 'db', 'import', SEED_FIXTURE_CONTAINER_SQL ] );
		ensurePrettyPermalinks();
	} finally {
		fs.rmSync( SEED_FIXTURE_HOST_SQL, { force: true } );
	}
}
