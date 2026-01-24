<?php
/**
 * Plugin test case.
 *
 * Tests core plugin initialization and constants.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;

/**
 * Plugin test class.
 *
 * Tests that the plugin loads correctly and constants are defined.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Test that plugin constants are defined.
	 *
	 * Verifies all required plugin constants are available after loading.
	 *
	 * @return void
	 */
	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'CXS_PLUGIN_FILE' ), 'CXS_PLUGIN_FILE should be defined' );
		$this->assertTrue( defined( 'CXS_PLUGIN_DIR' ), 'CXS_PLUGIN_DIR should be defined' );
		$this->assertTrue( defined( 'CXS_PLUGIN_URL' ), 'CXS_PLUGIN_URL should be defined' );
		$this->assertTrue( defined( 'CXS_VERSION' ), 'CXS_VERSION should be defined' );
	}

	/**
	 * Test that plugin version is valid.
	 *
	 * Ensures version follows semantic versioning format.
	 *
	 * @return void
	 */
	public function test_plugin_version_is_valid(): void {
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			CXS_VERSION,
			'Version should follow semantic versioning format'
		);
	}

	/**
	 * Test that plugin directory path is valid.
	 *
	 * Ensures the plugin directory constant points to an existing directory.
	 *
	 * @return void
	 */
	public function test_plugin_directory_exists(): void {
		$this->assertDirectoryExists( CXS_PLUGIN_DIR );
	}

	/**
	 * Test that main plugin file exists.
	 *
	 * Verifies the main plugin file path is correct.
	 *
	 * @return void
	 */
	public function test_plugin_file_exists(): void {
		$this->assertFileExists( CXS_PLUGIN_FILE );
	}

	/**
	 * Test that the Sitemap_CPT class exists.
	 *
	 * Ensures the CPT class is autoloaded correctly.
	 *
	 * @return void
	 */
	public function test_sitemap_cpt_class_exists(): void {
		$this->assertTrue(
			class_exists( 'XWP\CustomXmlSitemap\Sitemap_CPT' ),
			'Sitemap_CPT class should exist'
		);
	}

	/**
	 * Test that the Sitemap_Generator class exists.
	 *
	 * Ensures the generator class is autoloaded correctly.
	 *
	 * @return void
	 */
	public function test_sitemap_generator_class_exists(): void {
		$this->assertTrue(
			class_exists( 'XWP\CustomXmlSitemap\Sitemap_Generator' ),
			'Sitemap_Generator class should exist'
		);
	}

	/**
	 * Test that the Plugin class exists.
	 *
	 * Ensures the main plugin class is autoloaded correctly.
	 *
	 * @return void
	 */
	public function test_plugin_class_exists(): void {
		$this->assertTrue(
			class_exists( 'XWP\CustomXmlSitemap\Plugin' ),
			'Plugin class should exist'
		);
	}

	/**
	 * Test that CPT is registered.
	 *
	 * Verifies the custom sitemap post type is registered in WordPress.
	 *
	 * @return void
	 */
	public function test_cpt_is_registered(): void {
		$this->assertTrue(
			post_type_exists( 'cxs_sitemap' ),
			'Custom sitemap post type should be registered'
		);
	}
}
