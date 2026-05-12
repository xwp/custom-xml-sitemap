<?php
/**
 * PHPUnit unit-test bootstrap (Brain\Monkey).
 *
 * Loads only the Composer autoloader and PHPUnit polyfills. Does not
 * require WordPress; tests rely on Brain\Monkey to mock WP functions.
 *
 * @package XWP\CustomXmlSitemap
 */

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require dirname( __DIR__, 3 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// WordPress constants used by the code under test.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// Test fixtures.
require __DIR__ . '/class-mock-wpdb.php';
