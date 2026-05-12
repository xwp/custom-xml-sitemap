<?php
/**
 * E2E mu-plugin: skip posts flagged with the cxs_e2e_skip meta key.
 *
 * Used by skip-post-filter.spec.ts to verify the cxs_sitemap_skip_post filter
 * removes a specific post from the generated urlset.
 *
 * @package XWP\CustomXmlSitemap\Tests\E2E
 */

add_filter(
	'cxs_sitemap_skip_post',
	static function ( bool $skip, int $post_id ): bool {
		if ( '1' === (string) get_post_meta( $post_id, 'cxs_e2e_skip', true ) ) {
			return true;
		}
		return $skip;
	},
	10,
	2
);
