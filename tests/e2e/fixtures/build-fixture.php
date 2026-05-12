<?php
/**
 * Build the e2e seed fixture.
 *
 * Run inside the wp-env tests container against a clean DB:
 *
 *     wp-env clean tests
 *     wp-env run tests-cli wp eval-file \
 *         /var/www/html/wp-content/plugins/custom-xml-sitemap/tests/e2e/fixtures/build-fixture.php
 *     wp-env run tests-cli wp db export \
 *         /var/www/html/wp-content/plugins/custom-xml-sitemap/tests/e2e/fixtures/seed.sql \
 *         --add-drop-table
 *     gzip -f tests/e2e/fixtures/seed.sql
 *
 * The resulting `seed.sql.gz` is committed and loaded by global-setup before
 * each e2e run via `wp db import`.
 *
 * Datasets seeded:
 *
 *   - 1000 published posts dated within 2024-06 (URL-limit notice scenario).
 *     All slugs prefixed `fx-bulk-202406-`.
 *   - 500 published posts spread across the 12 months of 2023 (~42/month) for
 *     granularity assertions. Slugs prefixed `fx-spread-2023-MM-`.
 *   - 1100 categories with slug prefix `fx-cat-`. Each is assigned to one of
 *     the 2023 spread posts so `hide_empty=true` doesn't drop them.
 *   - 25 posts in 2024-08 with attached featured images. Slugs prefixed
 *     `fx-img-202408-`. The attachments are real wp_posts entries with the
 *     `_wp_attached_file` meta pointing to a tiny inline PNG so the news /
 *     image extensions have something to render.
 *
 * Idempotency: this script is meant to run on a clean DB. It does NOT clean
 * up before inserting; if you rerun on a non-empty DB you'll just get
 * duplicates. The intended flow is `wp-env clean tests` first.
 *
 * @package XWP\CustomXmlSitemap
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress (use `wp eval-file`).\n";
	exit( 1 );
}

ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

global $wpdb;

// -- 1. Pretty permalinks ----------------------------------------------------
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules( false );

// Force the admin user so `post_author` resolves cleanly.
$author = get_user_by( 'login', 'admin' );
if ( ! $author ) {
	$author = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;
}
if ( ! $author ) {
	echo "No admin user found; cannot seed.\n";
	exit( 1 );
}
$author_id = (int) $author->ID;

echo "Seeding fixture as user #{$author_id}\n";

// Disable counters / cache invalidation churn while bulk inserting.
wp_defer_term_counting( true );
wp_defer_comment_counting( true );

// -- 2. 1000 posts in 2024-06 -----------------------------------------------
echo "Seeding 1000 bulk posts in 2024-06...\n";
$bulk_start = microtime( true );
for ( $i = 1; $i <= 1000; $i++ ) {
	$day  = ( ( $i - 1 ) % 28 ) + 1; // Spread across the month.
	$hour = ( ( $i - 1 ) % 24 );
	$date = sprintf( '2024-06-%02d %02d:00:00', $day, $hour );

	wp_insert_post(
		[
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => "fx-bulk-202406-{$i}",
			'post_name'    => "fx-bulk-202406-{$i}",
			'post_content' => '',
			'post_author'  => $author_id,
			'post_date'    => $date,
			'post_date_gmt' => $date,
		],
		true
	);
}
echo sprintf( "  done in %.1fs\n", microtime( true ) - $bulk_start );

// -- 3. 500 posts spread across 2023 ----------------------------------------
echo "Seeding 500 spread posts across 2023...\n";
$spread_post_ids = [];
$spread_start    = microtime( true );
for ( $i = 1; $i <= 500; $i++ ) {
	$month = ( ( $i - 1 ) % 12 ) + 1;
	$day   = ( ( $i - 1 ) % 27 ) + 1;
	$date  = sprintf( '2023-%02d-%02d 12:00:00', $month, $day );

	$post_id = wp_insert_post(
		[
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => sprintf( 'fx-spread-2023-%02d-%d', $month, $i ),
			'post_name'    => sprintf( 'fx-spread-2023-%02d-%d', $month, $i ),
			'post_content' => '',
			'post_author'  => $author_id,
			'post_date'    => $date,
			'post_date_gmt' => $date,
		],
		true
	);
	if ( ! is_wp_error( $post_id ) ) {
		$spread_post_ids[] = (int) $post_id;
	}
}
echo sprintf( "  done in %.1fs (%d posts)\n", microtime( true ) - $spread_start, count( $spread_post_ids ) );

// -- 4. 1100 categories ------------------------------------------------------
echo "Seeding 1100 categories...\n";
$cat_start = microtime( true );
$cat_ids   = [];
for ( $i = 1; $i <= 1100; $i++ ) {
	$res = wp_insert_term(
		"fx-cat-{$i}",
		'category',
		[ 'slug' => "fx-cat-{$i}" ]
	);
	if ( is_wp_error( $res ) ) {
		continue;
	}
	$cat_ids[] = (int) $res['term_id'];
}
echo sprintf( "  done in %.1fs (%d terms)\n", microtime( true ) - $cat_start, count( $cat_ids ) );

// -- 5. Distribute categories onto the 2023 spread posts --------------------
echo "Linking categories to spread posts so hide_empty=true keeps them...\n";
$link_start = microtime( true );
foreach ( $cat_ids as $idx => $cat_id ) {
	$post_id = $spread_post_ids[ $idx % max( 1, count( $spread_post_ids ) ) ] ?? null;
	if ( $post_id ) {
		wp_set_post_terms( $post_id, [ $cat_id ], 'category', true );
	}
}
echo sprintf( "  done in %.1fs\n", microtime( true ) - $link_start );

// -- 6. 25 posts with featured images in 2024-08 ----------------------------
echo "Seeding 25 posts with featured images in 2024-08...\n";

// Create a single 1x1 PNG attachment we can re-use as the thumbnail. Using a
// real upload keeps the image-extension renderer happy.
$png_data = base64_decode(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
);
$upload   = wp_upload_dir();
if ( ! is_dir( $upload['path'] ) ) {
	wp_mkdir_p( $upload['path'] );
}
$png_path = $upload['path'] . '/fx-thumb.png';
file_put_contents( $png_path, $png_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$attachment_id = wp_insert_attachment(
	[
		'post_mime_type' => 'image/png',
		'post_title'     => 'fx-thumb',
		'post_content'   => '',
		'post_status'    => 'inherit',
		'post_author'    => $author_id,
	],
	$png_path
);

if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
	echo "  WARN: failed to create thumbnail attachment; skipping image posts.\n";
} else {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$meta = wp_generate_attachment_metadata( $attachment_id, $png_path );
	wp_update_attachment_metadata( $attachment_id, $meta );

	$img_start = microtime( true );
	for ( $i = 1; $i <= 25; $i++ ) {
		$day  = ( ( $i - 1 ) % 28 ) + 1;
		$date = sprintf( '2024-08-%02d 09:00:00', $day );

		$post_id = wp_insert_post(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => "fx-img-202408-{$i}",
				'post_name'    => "fx-img-202408-{$i}",
				'post_content' => '',
				'post_author'  => $author_id,
				'post_date'    => $date,
				'post_date_gmt' => $date,
			],
			true
		);

		if ( ! is_wp_error( $post_id ) ) {
			set_post_thumbnail( $post_id, (int) $attachment_id );
		}
	}
	echo sprintf( "  done in %.1fs\n", microtime( true ) - $img_start );
}

// -- 7. Re-enable counters and rebuild ---------------------------------------
wp_defer_term_counting( false );
wp_defer_comment_counting( false );

echo "Re-counting term relationships...\n";
foreach ( array_chunk( $cat_ids, 100 ) as $chunk ) {
	wp_update_term_count_now( $chunk, 'category' );
}

echo "Done. Now run: wp db export\n";
