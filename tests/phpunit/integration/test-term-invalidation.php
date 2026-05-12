<?php
/**
 * Integration tests for term CRUD invalidation of terms-mode sitemaps.
 *
 * Verifies that creating, editing, or deleting a taxonomy term schedules a
 * single debounced Action Scheduler job (per affected sitemap) to regenerate
 * the corresponding terms-mode sitemap. Debounce semantics are pinned by
 * triggering multiple rapid changes and asserting only one job is queued.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests;

use WP_UnitTestCase;
use XWP\CustomXmlSitemap\Sitemap_CPT;
use XWP\CustomXmlSitemap\Sitemap_Scheduler;

/**
 * Pins the term CRUD invalidation flow.
 */
class Test_Term_Invalidation extends WP_UnitTestCase {

	/**
	 * Sitemap post ID for tests (terms mode, category taxonomy).
	 *
	 * @var int
	 */
	private int $sitemap_id;

	/**
	 * Set up: create a published terms-mode sitemap targeting the category taxonomy.
	 *
	 * The Sitemap_Scheduler is already wired up by the plugin's bootstrap, so
	 * its `init()` runs automatically when the plugin loads. There is no need
	 * to instantiate it inside the test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded in the test environment.' );
		}

		$this->sitemap_id = self::factory()->post->create(
			[
				'post_type'   => Sitemap_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Term Invalidation Sitemap',
				'post_name'   => 'term-invalidation-sitemap',
			]
		);

		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_SITEMAP_MODE, Sitemap_CPT::SITEMAP_MODE_TERMS );
		update_post_meta( $this->sitemap_id, Sitemap_CPT::META_KEY_TAXONOMY, 'category' );

		// Refresh the sitemap configs cache so the scheduler sees this fixture.
		Sitemap_CPT::clear_sitemap_configs_cache();

		// Clear any previously scheduled jobs.
		$this->cancel_scheduled_regenerations();
	}

	/**
	 * Tear down: clear scheduled jobs and remove the sitemap.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->cancel_scheduled_regenerations();
		wp_delete_post( $this->sitemap_id, true );
		Sitemap_CPT::clear_sitemap_configs_cache();
		parent::tear_down();
	}

	/**
	 * Cancel any pending regeneration jobs for this sitemap.
	 *
	 * @return void
	 */
	private function cancel_scheduled_regenerations(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions(
			Sitemap_Scheduler::AS_HOOK_REGENERATE_SITEMAP_ALL,
			[ 'sitemap_id' => $this->sitemap_id ],
			Sitemap_Scheduler::AS_GROUP
		);
	}

	/**
	 * Count pending regeneration jobs for this sitemap.
	 *
	 * @return int Number of pending actions.
	 */
	private function count_scheduled_regenerations(): int {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => Sitemap_Scheduler::AS_HOOK_REGENERATE_SITEMAP_ALL,
				'args'     => [ 'sitemap_id' => $this->sitemap_id ],
				'group'    => Sitemap_Scheduler::AS_GROUP,
				'status'   => 'pending',
				'per_page' => 10,
			]
		);

		return count( $actions );
	}

	/**
	 * Creating a term in the watched taxonomy schedules a regeneration job.
	 *
	 * @return void
	 */
	public function test_created_term_schedules_regeneration(): void {
		$this->assertSame( 0, $this->count_scheduled_regenerations() );

		self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'New Category',
			]
		);

		$this->assertSame( 1, $this->count_scheduled_regenerations() );
	}

	/**
	 * Editing a term in the watched taxonomy schedules a regeneration job.
	 *
	 * @return void
	 */
	public function test_edited_term_schedules_regeneration(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Original Name',
			]
		);

		// The create above will have scheduled one job; clear it.
		$this->cancel_scheduled_regenerations();
		$this->assertSame( 0, $this->count_scheduled_regenerations() );

		wp_update_term( $term_id, 'category', [ 'name' => 'Renamed' ] );

		$this->assertSame( 1, $this->count_scheduled_regenerations() );
	}

	/**
	 * Deleting a term in the watched taxonomy schedules a regeneration job.
	 *
	 * @return void
	 */
	public function test_deleted_term_schedules_regeneration(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'To Delete',
			]
		);

		$this->cancel_scheduled_regenerations();
		$this->assertSame( 0, $this->count_scheduled_regenerations() );

		wp_delete_term( $term_id, 'category' );

		$this->assertSame( 1, $this->count_scheduled_regenerations() );
	}

	/**
	 * Multiple rapid term changes are debounced into a single pending job.
	 *
	 * @return void
	 */
	public function test_multiple_term_changes_debounce_to_single_job(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Original',
			]
		);

		// Subsequent edits should not pile up additional jobs.
		wp_update_term( $term_id, 'category', [ 'name' => 'Edit 1' ] );
		wp_update_term( $term_id, 'category', [ 'name' => 'Edit 2' ] );
		wp_update_term( $term_id, 'category', [ 'name' => 'Edit 3' ] );

		$this->assertSame( 1, $this->count_scheduled_regenerations() );
	}

	/**
	 * Term changes in an unrelated taxonomy do not schedule a regeneration.
	 *
	 * @return void
	 */
	public function test_unrelated_taxonomy_changes_do_not_schedule(): void {
		// Sitemap targets `category`; create a tag instead.
		self::factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'Some Tag',
			]
		);

		$this->assertSame( 0, $this->count_scheduled_regenerations() );
	}
}
