/**
 * Playwright global teardown.
 *
 * Best-effort cleanup of fixtures and any mu-plugin drop-ins individual specs
 * may have installed. Each spec is also responsible for cleaning up its own
 * state in afterAll, so this is a backstop.
 */
import { wpCli, wpEval } from './wp-cli';
import fs from 'node:fs';
import path from 'node:path';

export default async function globalTeardown(): Promise<void> {
	// Remove any mu-plugin drop-ins installed by specs.
	try {
		wpEval(
			"foreach ( glob( WPMU_PLUGIN_DIR . '/cxs-e2e-*.php' ) as $f ) { @unlink( $f ); }"
		);
	} catch ( _e ) {
		// Ignore: container may already be down.
	}

	// Delete e2e fixtures one more time, in case a spec failed mid-run.
	try {
		wpCli( [
			'post',
			'list',
			'--post_type=cxs_sitemap',
			'--post_status=any',
			'--format=ids',
		] )
			.split( /\s+/ )
			.filter( Boolean )
			.forEach( ( id ) => wpCli( [ 'post', 'delete', id, '--force' ] ) );
	} catch ( _e ) {
		// Ignore.
	}

	// Clean Playwright storage state.
	const stateFile = path.join( __dirname, '..', '.auth', 'admin.json' );
	if ( fs.existsSync( stateFile ) ) {
		fs.unlinkSync( stateFile );
	}
}
