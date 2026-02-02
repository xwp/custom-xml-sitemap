/**
 * Webpack configuration for Custom XML Sitemap plugin.
 *
 * @package
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin/settings-panel': path.resolve(
			process.cwd(),
			'assets/src/admin',
			'settings-panel.js'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'assets/build' ),
	},
};
