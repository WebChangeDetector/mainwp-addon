<?php
/**
 * Update orchestration helpers.
 *
 * Two responsibilities:
 *  1. Trigger a single site's MainWP update synchronously (sanctioned internal call, guarded).
 *  2. The after-update hook safety-net (post recovery for the card flow + coverage for updates
 *     started outside our card). Suppressed while the card flow drives the update itself.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates triggering MainWP updates and the after-update post-screenshot safety net.
 */
class WCD_MainWP_Update_Flow {

	const ABILITIES_CLASS = '\\MainWP\\Dashboard\\MainWP_Abilities_Updates';

	/**
	 * Request-scoped suppression: true while our own card run owns the update.
	 *
	 * @var bool
	 */
	protected static $suppress = false;

	/** How long a "post already triggered" marker lives, to dedupe multiple after-hooks per run. */
	const POST_DEDUPE_TTL = 180;

	/**
	 * Register the after-update hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'mainwp_after_wp_update', array( self::class, 'on_after_core_update' ), 10, 2 );
		add_action( 'mainwp_after_plugin_theme_translation_update', array( self::class, 'on_after_plugin_theme_update' ), 10, 4 );
	}

	/* ─────────────────────────── Update trigger ────────────────────────── */

	/**
	 * Whether the sanctioned MainWP update API is available.
	 */
	public static function can_trigger_updates(): bool {
		return class_exists( self::ABILITIES_CLASS )
			&& method_exists( self::ABILITIES_CLASS, 'execute_update_site_plugins' );
	}

	/**
	 * Trigger all available updates for one site, synchronously. Suppresses the after-update
	 * hooks for the duration so we do not double-fire post screenshots.
	 *
	 * @param int $site_id MainWP site id.
	 * @return array Result with ok, updated, offline and error keys.
	 */
	public static function trigger_site_update( int $site_id ): array {
		if ( ! self::can_trigger_updates() ) {
			return array(
				'ok'      => false,
				'updated' => 0,
				'offline' => false,
				'error'   => __( 'MainWP update API is unavailable. Run the native update instead.', 'webchangedetector-for-mainwp' ),
			);
		}

		self::$suppress = true;
		$updated        = 0;
		$offline        = false;
		$errors         = array();

		$methods = array(
			'execute_update_site_core',
			'execute_update_site_plugins',
			'execute_update_site_themes',
			'execute_update_site_translations',
		);

		try {
			foreach ( $methods as $method ) {
				if ( ! method_exists( self::ABILITIES_CLASS, $method ) ) {
					continue;
				}

				// Each update type can take minutes with no other AJAX activity; keep the tracked
				// run from looking abandoned to another tab in the meantime.
				self::touch_run();

				$result = call_user_func( array( self::ABILITIES_CLASS, $method ), array( 'site_id_or_domain' => $site_id ) );

				if ( is_wp_error( $result ) ) {
					$code = $result->get_error_code();
					if ( 'mainwp_site_offline' === $code ) {
						$offline = true;
					} elseif ( 'mainwp_no_updates' !== $code ) {
						// "no updates available" is a normal, non-error outcome.
						$errors[] = $result->get_error_message();
					}
					continue;
				}

				if ( is_array( $result ) && ! empty( $result['updated'] ) ) {
					// Core returns a single associative array; plugins/themes return a list.
					$updated += array_keys( $result['updated'] ) === range( 0, count( $result['updated'] ) - 1 )
						? count( $result['updated'] )
						: 1;
				}
				if ( is_array( $result ) && ! empty( $result['errors'] ) ) {
					foreach ( $result['errors'] as $err ) {
						$errors[] = is_string( $err ) ? $err : wp_json_encode( $err );
					}
				}
			}
		} finally {
			self::$suppress = false;
		}

		// Offline is a hard failure; other per-type errors are tolerated as long as something updated.
		if ( $offline ) {
			return array(
				'ok'      => false,
				'updated' => $updated,
				'offline' => true,
				'error'   => __( 'Site is offline.', 'webchangedetector-for-mainwp' ),
			);
		}

		return array(
			'ok'      => true,
			'updated' => $updated,
			'offline' => false,
			'error'   => $errors ? implode( '; ', $errors ) : '',
		);
	}

	/* ───────────────────────── After-update hooks ──────────────────────── */

	/**
	 * Core update finished for a site.
	 *
	 * @param mixed  $information Update response.
	 * @param object $site        Site object.
	 */
	public static function on_after_core_update( $information, $site ): void {
		self::maybe_take_post( $site );
	}

	/**
	 * Plugin/theme/translation update finished for a site.
	 *
	 * @param mixed  $information Update response.
	 * @param string $type        plugin|theme|translation.
	 * @param string $slugs       Comma-separated slugs.
	 * @param object $site        Site object.
	 */
	public static function on_after_plugin_theme_update( $information, $type, $slugs, $site ): void {
		self::maybe_take_post( $site );
	}

	/**
	 * Enqueue post screenshots for the site's manual group, unless our card flow already owns the
	 * run or we already did it for this site within the dedupe window.
	 *
	 * @param object $site MainWP site object.
	 * @return void
	 */
	protected static function maybe_take_post( $site ): void {
		if ( self::$suppress ) {
			return;
		}

		$site_id = is_object( $site ) && isset( $site->id ) ? (int) $site->id : 0;
		if ( ! $site_id || ! WCD_MainWP_Site_Map::is_enabled( $site_id ) ) {
			return;
		}

		$dedupe_key = 'wcd_post_done_' . $site_id;
		if ( WCD_MainWP_Options::get_transient( $dedupe_key ) ) {
			return;
		}
		WCD_MainWP_Options::set_transient( $dedupe_key, 1, self::POST_DEDUPE_TTL );

		$group_id = WCD_MainWP_Site_Map::get_manual_group( $site_id );
		if ( '' !== $group_id ) {
			// Purge the child's cache first (synchronous, behind the dedupe above) so the post
			// screenshots capture the updated site, not a cached pre-update version. Adds one
			// child request to MainWP's update response; acceptable at the end of an update.
			WCD_MainWP_Cache_Purge::purge_site( $site_id );

			// Fire-and-forget: never block MainWP's own update response on our screenshot call.
			WCD_MainWP_API::take_screenshot( array( $group_id ), 'post', 'manual', '', false );
		}
	}

	/* ─────────────────────────────── Preflight ─────────────────────────── */

	/**
	 * Normalize a group-urls response into a flat list of url arrays.
	 *
	 * @param mixed $data Group-urls API response.
	 * @return array Flat list of url arrays.
	 */
	public static function extract_urls( $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			return $data['data'];
		}

		return $data;
	}

	/* ─────────────────────────── Pending updates ───────────────────────── */

	/**
	 * Whether MainWP's DB layer is available for reading the per-site upgrade columns. Callers use
	 * this to distinguish "no pending updates" from "update info unknown" (both yield empty lists).
	 */
	public static function updates_info_available(): bool {
		$db = '\\MainWP\\Dashboard\\MainWP_DB';

		return class_exists( $db ) && method_exists( $db, 'instance' );
	}

	/**
	 * Best-effort per-site count of pending MainWP updates, read from MainWP's own upgrade columns.
	 * Approximate (it does not subtract ignored/dismissed updates). Null is reserved strictly for
	 * "MainWP's DB layer is unavailable" (info unknown, callers fail open). Sites whose website row
	 * cannot be resolved get no key: MainWP cannot update such a site either, so callers treat a
	 * missing key as "no updates" (fail closed).
	 *
	 * @param array $site_ids MainWP site ids to count updates for.
	 * @return array|null Map of site id => pending update count, or null when unavailable.
	 */
	public static function pending_updates_by_site( array $site_ids ): ?array {
		if ( ! self::updates_info_available() ) {
			return null;
		}

		$db      = '\\MainWP\\Dashboard\\MainWP_DB';
		$by_site = array();
		foreach ( $site_ids as $site_id ) {
			$website = $db::instance()->get_website_by_id( (int) $site_id );
			if ( empty( $website ) ) {
				continue;
			}
			$by_site[ (int) $site_id ] = count( self::items_from_website( $website ) );
		}

		return $by_site;
	}

	/**
	 * Best-effort total of pending MainWP updates across the given sites. Used for the banner copy
	 * ("install the N pending updates"); null when MainWP's DB layer is unavailable, in which case
	 * the banner drops the number.
	 *
	 * @param array $site_ids MainWP site ids to count updates for.
	 * @return int|null Total pending updates, or null when MainWP's DB layer is unavailable.
	 */
	public static function pending_updates_count( array $site_ids ): ?int {
		$by_site = self::pending_updates_by_site( $site_ids );

		return null === $by_site ? null : array_sum( $by_site );
	}

	/**
	 * List one site's pending update items for the preflight "what gets updated" panel. Best-effort
	 * (mirrors {@see pending_updates_count}): returns [] when MainWP's DB layer is unavailable, and the
	 * item list is approximate (it does not subtract ignored/dismissed updates).
	 *
	 * @param int $site_id MainWP site id.
	 * @return array List of update item arrays (kind, name, version).
	 */
	public static function update_items_for_site( int $site_id ): array {
		if ( ! self::updates_info_available() ) {
			return array();
		}

		$db      = '\\MainWP\\Dashboard\\MainWP_DB';
		$website = $db::instance()->get_website_by_id( $site_id );

		return empty( $website ) ? array() : self::items_from_website( $website );
	}

	/**
	 * Parse a MainWP website row's upgrade columns into a flat item list:
	 * WordPress core (0/1) + plugins + themes + translations.
	 *
	 * @param object $website MainWP website row.
	 * @return array List of update item arrays (kind, name, version).
	 */
	protected static function items_from_website( $website ): array {
		$items = array();

		$core = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
		if ( is_array( $core ) && ! empty( $core ) ) {
			$items[] = array(
				'kind'    => 'core',
				'name'    => 'WordPress',
				'version' => (string) ( $core['new'] ?? ( $core['new_version'] ?? '' ) ),
			);
		}

		$map = array(
			'plugin_upgrades'      => 'plugin',
			'theme_upgrades'       => 'theme',
			'translation_upgrades' => 'translation',
		);
		foreach ( $map as $field => $kind ) {
			$raw     = isset( $website->$field ) ? $website->$field : '';
			$decoded = ! empty( $raw ) ? json_decode( $raw, true ) : array();
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $slug => $entry ) {
				$entry   = is_array( $entry ) ? $entry : array();
				$update  = isset( $entry['update'] ) && is_array( $entry['update'] ) ? $entry['update'] : array();
				$items[] = array(
					'kind'    => $kind,
					'name'    => (string) ( $entry['Name'] ?? ( $entry['name'] ?? ( is_string( $slug ) ? $slug : '' ) ) ),
					'version' => (string) ( $update['new_version'] ?? ( $entry['new_version'] ?? ( $entry['version'] ?? '' ) ) ),
				);
			}
		}

		return $items;
	}

	/* ───────────────────────── Run state (resume) ──────────────────────── */

	// The browser orchestrates the safe-update run, so navigating away (or a closed tab) would
	// otherwise lose the remaining pipeline steps. Every per-site transition is therefore persisted
	// dashboard-side (network-aware option): the AJAX endpoints record pre batches, completed
	// updates and post batches as they happen, and the driving tab sends a short heartbeat. When a
	// page next loads with a still-active run whose heartbeat has gone silent (RESUME_AFTER), it
	// derives each site's state from the persisted fields and continues the run.
	//
	// Two options, one writer each:
	// RUN_STATE_KEY holds the STRUCTURAL state (sites, batches, updated sites). It is only
	// written by the JS-serialized mutating endpoints (the JS funnels take_pre / run_update /
	// take_post / run_resume_post through one promise lane), so a concurrent read-modify-write
	// can never drop a recorded batch.
	// ACTIVITY_KEY holds the liveness signal ({ last_activity, driver }). heartbeat() and
	// touch_run() write ONLY this option, so the frequent activity bumps can never clobber a
	// structural record.

	const RUN_STATE_KEY   = 'wcd_mainwp_active_run';
	const ACTIVITY_KEY    = 'wcd_mainwp_run_activity';
	const RUN_STALE_AFTER = 600; // Seconds idle before an empty (nothing-happened) run is dropped as leftover.
	const RESUME_AFTER    = 20;  // Seconds without a heartbeat before another page may take over the run.

	/**
	 * The persisted state of the current safe-update run, or an empty array.
	 *
	 * @return array Run state (started_at, last_activity, phase, sites, pre_batches, updated_sites, post_batches).
	 */
	public static function run_state(): array {
		$state = WCD_MainWP_Options::get( self::RUN_STATE_KEY, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Start tracking a new run (replaces any previous state).
	 *
	 * @param array  $sites  Run sites keyed by site id: [ site_id => [ site_id, name, checks ] ].
	 * @param string $driver Opaque id of the tab driving the run (so it can reclaim it instantly after
	 *                       a same-tab reload/navigation, without waiting out the two-tab guard).
	 * @return void
	 */
	public static function start_run( array $sites, string $driver = '' ): void {
		// A fresh run must not inherit a previous run's activity stamp: drop it and let
		// idle_seconds() fall back to the structural last_activity written right below (the
		// driving tab's first heartbeat re-creates the activity option immediately).
		WCD_MainWP_Options::delete( self::ACTIVITY_KEY );
		self::save_run(
			array(
				'started_at'    => time(),
				'last_activity' => time(),
				'phase'         => 'pre',
				'driver'        => $driver,
				'sites'         => $sites,
				'pre_batches'   => array(),
				'updated_sites' => array(),
				'post_batches'  => array(),
			)
		);
	}

	/**
	 * Stop tracking the current run (structural state AND activity).
	 *
	 * @return void
	 */
	public static function clear_run(): void {
		WCD_MainWP_Options::delete( self::RUN_STATE_KEY );
		WCD_MainWP_Options::delete( self::ACTIVITY_KEY );
	}

	/**
	 * The run's activity option ({ last_activity, driver }), or an empty array.
	 *
	 * @return array
	 */
	public static function activity(): array {
		$activity = WCD_MainWP_Options::get( self::ACTIVITY_KEY, array() );

		return is_array( $activity ) ? $activity : array();
	}

	/**
	 * Bump the run's activity timestamp (called from the polling endpoint while a run is driven).
	 * Writes ONLY the activity option, never the structural run state.
	 *
	 * @return void
	 */
	public static function touch_run(): void {
		if ( empty( self::run_state() ) ) {
			return;
		}
		$activity = self::activity();
		// Throttled: poll ticks every ~3s; one option write per 30s keeps the timestamp fresh
		// enough for the staleness gate.
		if ( ( time() - (int) ( $activity['last_activity'] ?? 0 ) ) > 30 ) {
			self::save_activity( $activity );
		}
	}

	/**
	 * Stamp the run as active right now (the driving tab's heartbeat). Unthrottled: a fresh stamp is
	 * what tells another page that this run is still being driven, so it must never be skipped. Also
	 * records the driver id so a same-tab reload can recognise its own run and reclaim it instantly.
	 * Writes ONLY the activity option, so it can never clobber a concurrent structural record.
	 *
	 * @param string $driver Opaque id of the tab sending the heartbeat (becomes the current driver).
	 * @return void
	 */
	public static function heartbeat( string $driver = '' ): void {
		if ( empty( self::run_state() ) ) {
			return;
		}
		$activity = self::activity();
		if ( '' !== $driver ) {
			$activity['driver'] = $driver;
		}
		self::save_activity( $activity );
	}

	/**
	 * Seconds since the run last showed activity. Prefers the activity option (written by
	 * heartbeat/touch_run); falls back to the structural state's last_activity for a run that
	 * started before the activity split (legacy in-flight run) or whose first heartbeat is
	 * still on the wire.
	 *
	 * @param array $state Run state (from run_state()).
	 * @return int
	 */
	public static function idle_seconds( array $state ): int {
		$activity = self::activity();
		$last     = (int) ( $activity['last_activity'] ?? 0 );
		if ( 0 === $last ) {
			$last = isset( $state['last_activity'] ) ? (int) $state['last_activity'] : 0;
		}

		return max( 0, time() - $last );
	}

	/**
	 * Record a dispatched pre-screenshot batch for a run site.
	 *
	 * @param int    $site_id MainWP site id.
	 * @param string $batch   Batch UUID.
	 * @return void
	 */
	public static function record_pre_batch( int $site_id, string $batch ): void {
		$state = self::run_state();
		if ( empty( $state['sites'][ $site_id ] ) || '' === $batch ) {
			return;
		}
		$state['phase']                   = 'pre';
		$state['pre_batches'][ $site_id ] = $batch;
		self::save_run( $state );
	}

	/**
	 * Record that a run site's updates finished (its post screenshots are now due).
	 *
	 * @param int $site_id MainWP site id.
	 * @return void
	 */
	public static function record_site_updated( int $site_id ): void {
		$state = self::run_state();
		if ( empty( $state['sites'][ $site_id ] ) ) {
			return;
		}
		$state['phase'] = 'updates';
		if ( ! in_array( $site_id, $state['updated_sites'], true ) ) {
			$state['updated_sites'][] = $site_id;
		}
		self::save_run( $state );
	}

	/**
	 * Record a dispatched post-screenshot batch. Once every run site has one, the rest of the run
	 * (screenshots + comparisons) finishes server-side, so the tracked state is cleared.
	 *
	 * @param int    $site_id MainWP site id.
	 * @param string $batch   Batch UUID.
	 * @return void
	 */
	public static function record_post_batch( int $site_id, string $batch ): void {
		$state = self::run_state();
		if ( empty( $state['sites'][ $site_id ] ) || '' === $batch ) {
			return;
		}
		$state['phase']                    = 'post';
		$state['post_batches'][ $site_id ] = $batch;
		if ( count( $state['post_batches'] ) >= count( $state['sites'] ) ) {
			self::clear_run();

			return;
		}
		self::save_run( $state );
	}

	/**
	 * Persist the structural run state. Deliberately does NOT touch last_activity: liveness lives
	 * in the separate activity option (see the section comment), written only by heartbeat/touch_run.
	 *
	 * @param array $state Run state to save.
	 * @return void
	 */
	protected static function save_run( array $state ): void {
		WCD_MainWP_Options::set( self::RUN_STATE_KEY, $state );
	}

	/**
	 * Persist the activity option with a fresh timestamp.
	 *
	 * @param array $activity Activity payload ({ last_activity, driver }).
	 * @return void
	 */
	protected static function save_activity( array $activity ): void {
		$activity['last_activity'] = time();
		WCD_MainWP_Options::set( self::ACTIVITY_KEY, $activity );
	}
}
