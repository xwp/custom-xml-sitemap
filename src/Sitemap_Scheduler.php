<?php
/**
 * Sitemap Scheduler.
 *
 * Manages Action Scheduler integration for automated sitemap regeneration.
 * Handles cron jobs for detecting modified posts and scheduling regeneration.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap;

use WP_Post;

/**
 * Sitemap Scheduler.
 *
 * Coordinates automated sitemap updates using Action Scheduler:
 * - 15-minute cron job detects modified posts via post_modified_gmt
 * - Unpublish events trigger immediate async regeneration
 * - Term deletion triggers full sitemap regeneration for affected sitemaps
 */
class Sitemap_Scheduler {

	/**
	 * Action Scheduler hook for the 15-minute cron job.
	 *
	 * @var string
	 */
	public const CRON_HOOK_UPDATE_SITEMAPS = 'cxs_cron_update_sitemaps';

	/**
	 * Action Scheduler hook for immediate sitemap regeneration (single date).
	 *
	 * @var string
	 */
	public const AS_HOOK_REGENERATE_SITEMAP = 'cxs_regenerate_sitemap';

	/**
	 * Action Scheduler hook for full sitemap regeneration.
	 *
	 * @var string
	 */
	public const AS_HOOK_REGENERATE_SITEMAP_ALL = 'cxs_regenerate_sitemap_all';

	/**
	 * Cron interval in seconds (15 minutes).
	 *
	 * @var int
	 */
	public const CRON_INTERVAL_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * Debounce delay for terms sitemap regeneration (5 minutes).
	 *
	 * Allows multiple rapid term changes to be batched into a single regeneration.
	 *
	 * @var int
	 */
	public const TERMS_DEBOUNCE_DELAY = 5 * MINUTE_IN_SECONDS;

	/**
	 * Option name for storing the last modified check timestamp.
	 *
	 * @var string
	 */
	public const OPTION_LAST_MODIFIED_CHECK = 'cxs_sitemap_last_modified_check';

	/**
	 * Action Scheduler group for sitemap jobs.
	 *
	 * @var string
	 */
	public const AS_GROUP = 'cxs-sitemap';

	/**
	 * Initialize the scheduler.
	 *
	 * Registers all WordPress hooks for scheduling and handling sitemap updates.
	 *
	 * @return void
	 */
	public function init(): void {
		// Schedule the recurring cron job.
		add_action( 'init', [ $this, 'schedule_cron_job' ] );

		// Cron callback: detect modified posts and regenerate sitemaps.
		add_action( self::CRON_HOOK_UPDATE_SITEMAPS, [ $this, 'cron_detect_and_regenerate_sitemaps' ] );

		// Immediate trigger: unpublish (publish → non-publish) schedules AS job.
		add_action( 'transition_post_status', [ $this, 'handle_post_unpublish' ], 10, 3 );

		// Immediate trigger: term deletion schedules AS job for affected sitemaps (Posts mode).
		add_action( 'pre_delete_term', [ $this, 'handle_term_deletion' ], 10, 2 );

		// Term changes: invalidate terms-mode sitemaps when taxonomy terms are created/edited/deleted.
		add_action( 'created_term', [ $this, 'handle_term_change_for_terms_sitemap' ], 10, 3 );
		add_action( 'edited_term', [ $this, 'handle_term_change_for_terms_sitemap' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'handle_term_deletion_for_terms_sitemap' ], 10, 4 );

		// Action Scheduler callback for single-date sitemap regeneration.
		add_action( self::AS_HOOK_REGENERATE_SITEMAP, [ $this, 'handle_sitemap_regeneration' ], 10, 4 );

		// Action Scheduler callback for full sitemap regeneration.
		add_action( self::AS_HOOK_REGENERATE_SITEMAP_ALL, [ $this, 'handle_sitemap_regeneration_all' ] );

		// Clear caches when sitemap CPT status changes (publish, unpublish, trash, etc.).
		add_action( 'transition_post_status', [ $this, 'clear_caches_on_sitemap_status_change' ], 10, 3 );
	}

	/**
	 * Schedule the recurring 15-minute cron job for sitemap updates.
	 *
	 * Uses Action Scheduler for reliable background processing.
	 *
	 * @return void
	 */
	public function schedule_cron_job(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( false === as_has_scheduled_action( self::CRON_HOOK_UPDATE_SITEMAPS, [], self::AS_GROUP ) ) {
			as_schedule_recurring_action(
				time(),
				self::CRON_INTERVAL_SECONDS,
				self::CRON_HOOK_UPDATE_SITEMAPS,
				[],
				self::AS_GROUP
			);
		}
	}

	/**
	 * Cron callback: Detect modified posts and regenerate affected sitemaps.
	 *
	 * Runs every 15 minutes. For each custom sitemap CPT:
	 * 1. Query posts modified since last check via post_modified_gmt
	 * 2. Extract unique dates at appropriate granularity
	 * 3. Regenerate affected sitemaps directly
	 *
	 * @return void
	 */
	public function cron_detect_and_regenerate_sitemaps(): void {
		// Capture current time before querying to avoid missing posts modified during regeneration.
		$current_time = time();
		$last_check   = (int) get_option( self::OPTION_LAST_MODIFIED_CHECK, 0 );

		// First run: check last 15 minutes only.
		if ( 0 === $last_check ) {
			$last_check = $current_time - self::CRON_INTERVAL_SECONDS;
		}

		$sitemap_configs = Sitemap_CPT::get_all_sitemap_configs();

		if ( empty( $sitemap_configs ) ) {
			update_option( self::OPTION_LAST_MODIFIED_CHECK, $current_time, false );
			return;
		}

		foreach ( $sitemap_configs as $sitemap_data ) {
			$sitemap   = $sitemap_data['post'];
			$generator = new Sitemap_Generator( $sitemap );
			$dates     = $generator->get_dates_with_modified_posts( $last_check );

			if ( empty( $dates ) ) {
				continue;
			}

			// Regenerate sitemaps for each affected date.
			$this->regenerate_sitemaps_for_dates( $generator, $dates );
		}

		update_option( self::OPTION_LAST_MODIFIED_CHECK, $current_time, false );
	}

	/**
	 * Regenerate sitemaps for a list of dates.
	 *
	 * Handles regeneration at the appropriate granularity level and
	 * also regenerates parent sitemaps (year index, main index) as needed.
	 *
	 * @param Sitemap_Generator                           $generator The sitemap generator instance.
	 * @param array<array{year: int, month: int, day: int}> $dates     Array of dates.
	 * @return void
	 */
	private function regenerate_sitemaps_for_dates( Sitemap_Generator $generator, array $dates ): void {
		$granularity     = $generator->get_granularity();
		$years_to_update = [];

		foreach ( $dates as $date ) {
			$year  = (int) $date['year'];
			$month = (int) $date['month'];
			$day   = (int) $date['day'];

			// Track years for index regeneration.
			$years_to_update[ $year ] = true;

			// Regenerate at appropriate granularity level.
			switch ( $granularity ) {
				case Sitemap_CPT::GRANULARITY_DAY:
					$generator->get_day_sitemap( $year, $month, $day, true );
					// Also regenerate month index (lists days).
					$generator->get_month_sitemap( $year, $month, true );
					break;

				case Sitemap_CPT::GRANULARITY_MONTH:
					$generator->get_month_sitemap( $year, $month, true );
					break;

				case Sitemap_CPT::GRANULARITY_YEAR:
					$generator->get_year_sitemap( $year, true );
					break;
			}
		}

		// Regenerate year sitemaps (they list months/days).
		if ( Sitemap_CPT::GRANULARITY_YEAR !== $granularity ) {
			foreach ( array_keys( $years_to_update ) as $year ) {
				$generator->get_year_sitemap( $year, true );
			}
		}

		// Always regenerate main index.
		$generator->get_index( true );
	}

	/**
	 * Handle post unpublish by scheduling immediate sitemap regeneration.
	 *
	 * Only triggers when a post transitions FROM publish TO any other status.
	 * New publishes and updates are handled by the 15-minute cron.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       The post object.
	 * @return void
	 */
	public function handle_post_unpublish( string $new_status, string $old_status, WP_Post $post ): void {
		// Only act when going FROM publish TO non-publish.
		if ( 'publish' !== $old_status || 'publish' === $new_status ) {
			return;
		}

		// Skip our own CPT - handled by clear_caches_on_sitemap_status_change.
		if ( Sitemap_CPT::POST_TYPE === $post->post_type ) {
			return;
		}

		// Find sitemaps that include this post type.
		$sitemap_configs = Sitemap_CPT::get_configs_for_post_type( $post->post_type );

		if ( empty( $sitemap_configs ) ) {
			return;
		}

		$post_date = get_post_datetime( $post, 'date' );
		if ( ! $post_date ) {
			return;
		}

		$year  = (int) $post_date->format( 'Y' );
		$month = (int) $post_date->format( 'm' );
		$day   = (int) $post_date->format( 'd' );

		// Schedule immediate regeneration for each affected sitemap.
		foreach ( $sitemap_configs as $sitemap_data ) {
			// Skip if sitemap has taxonomy filter and post doesn't match.
			if ( ! $this->post_matches_sitemap_taxonomy( $post, $sitemap_data['config'] ) ) {
				continue;
			}

			$this->schedule_async_regeneration(
				self::AS_HOOK_REGENERATE_SITEMAP,
				[
					'sitemap_id' => $sitemap_data['post']->ID,
					'year'       => $year,
					'month'      => $month,
					'day'        => $day,
				]
			);
		}
	}

	/**
	 * Check if a post matches a sitemap's taxonomy filter.
	 *
	 * Returns true if the sitemap has no taxonomy filter, or if the post
	 * has at least one of the sitemap's configured terms.
	 *
	 * @param WP_Post                                                            $post   The post to check.
	 * @param array{post_type: string, granularity: string, taxonomy: string, terms: array<int>} $config Sitemap configuration.
	 * @return bool True if post matches or sitemap has no filter.
	 */
	private function post_matches_sitemap_taxonomy( WP_Post $post, array $config ): bool {
		// No taxonomy filter = matches all posts of that type.
		if ( empty( $config['taxonomy'] ) ) {
			return true;
		}

		$post_terms = wp_get_post_terms( $post->ID, $config['taxonomy'], [ 'fields' => 'ids' ] );

		if ( is_wp_error( $post_terms ) || empty( $post_terms ) ) {
			return false;
		}

		// Empty terms config = all posts with any term in this taxonomy.
		if ( empty( $config['terms'] ) ) {
			return true;
		}

		// Check if post has any of the sitemap's configured terms.
		// Config terms may be stored as strings, so normalize to integers for comparison.
		$config_term_ids = array_map( 'intval', $config['terms'] );
		return ! empty( array_intersect( $post_terms, $config_term_ids ) );
	}

	/**
	 * Handle term deletion by scheduling immediate sitemap regeneration.
	 *
	 * When a term is deleted, posts that were associated with it may no longer
	 * appear in sitemaps filtered by that term. This hook fires before the term
	 * is deleted, allowing us to capture affected posts and schedule regeneration.
	 *
	 * @param int    $term_id  Term ID being deleted.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function handle_term_deletion( int $term_id, string $taxonomy ): void {
		// Find sitemaps that filter by this taxonomy.
		$all_configs = Sitemap_CPT::get_all_sitemap_configs();

		if ( empty( $all_configs ) ) {
			return;
		}

		// Filter to sitemaps using this taxonomy and containing this term.
		$affected_sitemaps = [];
		foreach ( $all_configs as $config_data ) {
			$config = $config_data['config'];

			if ( empty( $config['taxonomy'] ) || $config['taxonomy'] !== $taxonomy ) {
				continue;
			}

			// Check if this sitemap includes the deleted term.
			// Config terms may be stored as strings, so cast for comparison.
			$config_term_ids = array_map( 'intval', $config['terms'] );
			if ( empty( $config_term_ids ) || ! in_array( $term_id, $config_term_ids, true ) ) {
				continue;
			}

			$affected_sitemaps[] = $config_data;
		}

		if ( empty( $affected_sitemaps ) ) {
			return;
		}

		// Schedule full regeneration for each affected sitemap (one job per sitemap).
		foreach ( $affected_sitemaps as $sitemap_data ) {
			$this->schedule_async_regeneration(
				self::AS_HOOK_REGENERATE_SITEMAP_ALL,
				[ 'sitemap_id' => $sitemap_data['post']->ID ]
			);
		}
	}

	/**
	 * Handle term create/edit for terms-mode sitemaps.
	 *
	 * When a term is created or edited (name/slug change), terms-mode sitemaps
	 * using that taxonomy need to be regenerated to reflect the new/updated term URL.
	 *
	 * Hook: created_term, edited_term
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function handle_term_change_for_terms_sitemap( int $term_id, int $tt_id, string $taxonomy ): void {
		// Skip during imports to avoid scheduling excessive jobs.
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return;
		}

		$this->invalidate_terms_sitemaps_for_taxonomy( $taxonomy );
	}

	/**
	 * Handle term deletion for terms-mode sitemaps.
	 *
	 * When a term is deleted, terms-mode sitemaps using that taxonomy need to be
	 * regenerated to remove the deleted term URL.
	 *
	 * Hook: delete_term
	 *
	 * @param int    $term_id      Term ID.
	 * @param int    $tt_id        Term taxonomy ID.
	 * @param string $taxonomy     Taxonomy slug.
	 * @param mixed  $deleted_term Deleted term object (WP_Term or WP_Error).
	 * @return void
	 */
	public function handle_term_deletion_for_terms_sitemap( int $term_id, int $tt_id, string $taxonomy, $deleted_term ): void {
		// Skip during imports to avoid scheduling excessive jobs.
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return;
		}

		$this->invalidate_terms_sitemaps_for_taxonomy( $taxonomy );
	}

	/**
	 * Invalidate all terms-mode sitemaps that use a specific taxonomy.
	 *
	 * Schedules debounced regeneration via Action Scheduler (5-minute delay) for all
	 * terms-mode sitemaps that match the given taxonomy. The delay allows multiple
	 * rapid term changes to be batched into a single regeneration.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	private function invalidate_terms_sitemaps_for_taxonomy( string $taxonomy ): void {
		$all_configs = Sitemap_CPT::get_all_sitemap_configs();

		foreach ( $all_configs as $config_data ) {
			$config = $config_data['config'];

			// Only process terms-mode sitemaps.
			if ( Sitemap_CPT::SITEMAP_MODE_TERMS !== $config['mode'] ) {
				continue;
			}

			// Only process sitemaps using this taxonomy.
			if ( empty( $config['taxonomy'] ) || $config['taxonomy'] !== $taxonomy ) {
				continue;
			}

			// Schedule debounced regeneration (5-minute delay) via Action Scheduler.
			$this->schedule_debounced_regeneration(
				self::AS_HOOK_REGENERATE_SITEMAP_ALL,
				[ 'sitemap_id' => $config_data['post']->ID ]
			);
		}
	}

	/**
	 * Schedule an async Action Scheduler job for sitemap regeneration.
	 *
	 * Prevents duplicate scheduling for the same hook/args combination.
	 *
	 * @param string              $hook Action Scheduler hook name.
	 * @param array<string, mixed> $args Arguments to pass to the hook.
	 * @return void
	 */
	private function schedule_async_regeneration( string $hook, array $args ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( $hook, $args, self::AS_GROUP ) ) {
			return;
		}

		as_enqueue_async_action( $hook, $args, self::AS_GROUP );
	}

	/**
	 * Schedule a debounced Action Scheduler job for sitemap regeneration.
	 *
	 * Schedules the job to run after a delay. If the same job is already scheduled,
	 * it won't be duplicated. This allows multiple rapid changes (e.g., bulk term
	 * operations) to be batched into a single regeneration.
	 *
	 * @param string              $hook  Action Scheduler hook name.
	 * @param array<string, mixed> $args  Arguments to pass to the hook.
	 * @param int                 $delay Delay in seconds before running (default: TERMS_DEBOUNCE_DELAY).
	 * @return void
	 */
	private function schedule_debounced_regeneration( string $hook, array $args, int $delay = self::TERMS_DEBOUNCE_DELAY ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( $hook, $args, self::AS_GROUP ) ) {
			return;
		}

		as_schedule_single_action( time() + $delay, $hook, $args, self::AS_GROUP );
	}

	/**
	 * Get a valid published sitemap post by ID.
	 *
	 * @param int $sitemap_id Sitemap post ID.
	 * @return WP_Post|null The sitemap post if valid and published, null otherwise.
	 */
	private function get_valid_sitemap( int $sitemap_id ): ?WP_Post {
		$sitemap = get_post( $sitemap_id );

		if ( ! $sitemap || Sitemap_CPT::POST_TYPE !== $sitemap->post_type ) {
			return null;
		}

		if ( 'publish' !== $sitemap->post_status ) {
			return null;
		}

		return $sitemap;
	}

	/**
	 * Handle async sitemap regeneration callback (single date).
	 *
	 * @param int $sitemap_id Sitemap post ID.
	 * @param int $year       Year to regenerate.
	 * @param int $month      Month to regenerate.
	 * @param int $day        Day to regenerate.
	 * @return void
	 */
	public function handle_sitemap_regeneration( int $sitemap_id, int $year, int $month, int $day ): void {
		$sitemap = $this->get_valid_sitemap( $sitemap_id );
		if ( ! $sitemap ) {
			return;
		}

		$generator = new Sitemap_Generator( $sitemap );
		$this->regenerate_sitemaps_for_dates( $generator, [ compact( 'year', 'month', 'day' ) ] );
	}

	/**
	 * Handle async full sitemap regeneration callback.
	 *
	 * Uses appropriate generator based on sitemap mode (Posts or Terms).
	 *
	 * @param int $sitemap_id Sitemap post ID.
	 * @return void
	 */
	public function handle_sitemap_regeneration_all( int $sitemap_id ): void {
		$sitemap = $this->get_valid_sitemap( $sitemap_id );
		if ( ! $sitemap ) {
			return;
		}

		// Use appropriate generator based on sitemap mode.
		if ( Sitemap_CPT::is_terms_mode( $sitemap_id ) ) {
			$generator = new Terms_Sitemap_Generator( $sitemap );
		} else {
			$generator = new Sitemap_Generator( $sitemap );
		}
		$generator->regenerate_all();
	}

	/**
	 * Clear sitemap caches when a sitemap post status changes.
	 *
	 * Clears both the configs object cache and XML cache when a sitemap post
	 * transitions to/from publish. This ensures fresh XML is generated if the
	 * sitemap config was modified while in draft and then republished.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function clear_caches_on_sitemap_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		// Only process our custom sitemap CPT.
		if ( Sitemap_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Only act when publish status is involved (publishing, unpublishing, or editing while published).
		if ( ! in_array( 'publish', [ $old_status, $new_status ], true ) ) {
			return;
		}

		// Clear the cached sitemap configs.
		Sitemap_CPT::clear_sitemap_configs_cache();

		// Clear XML cache so it regenerates on next request.
		// Use appropriate generator based on sitemap mode.
		if ( Sitemap_CPT::is_terms_mode( $post->ID ) ) {
			$generator = new Terms_Sitemap_Generator( $post );
		} else {
			$generator = new Sitemap_Generator( $post );
		}
		$generator->clear_all_cached_xml();
	}
}
