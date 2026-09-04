<?php
/**
 * Update orchestration helpers.
 *
 * Triggers a single site's MainWP update synchronously (sanctioned internal call, guarded),
 * reads pending-update counts/items from MainWP's upgrade columns, and persists the run state
 * for the browser-driven safe-update flow (resume).
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates triggering MainWP updates, pending-update reads and the persisted run state.
 *
 * MainWP's after-update hooks (mainwp_after_wp_update, mainwp_after_plugin_theme_translation_update)
 * are deliberately NOT used: updates started outside the WCD flow (native Updates page, cron, REST,
 * WP-CLI) must not produce screenshots or checks. They carry no pre screenshot, so a post-only
 * screenshot would be compared against a stale baseline and burn credits. The card flow dispatches
 * its own pre and post screenshots (take_pre / take_post / run_resume_post).
 */
class WCD_MainWP_Update_Flow {

	const ABILITIES_CLASS = '\\MainWP\\Dashboard\\MainWP_Abilities_Updates';

	/* ─────────────────────────── Update trigger ────────────────────────── */

	/**
	 * Whether the sanctioned MainWP update API is available.
	 */
	public static function can_trigger_updates(): bool {
		return class_exists( self::ABILITIES_CLASS )
			&& method_exists( self::ABILITIES_CLASS, 'execute_update_site_plugins' );
	}

	/**
	 * Trigger updates for one site, synchronously.
	 *
	 * Without an update type, all four update types run (the legacy whole-site behavior of the
	 * widget, Run tab and per-site tab). With a type, only the matching abilities method runs;
	 * for plugins/themes/translations the optional slugs scope it to specific items (empty slugs
	 * = all of this type, the abilities' own semantics, so the key is omitted then). Core never
	 * takes slugs. Same guards as before; no new internal MainWP call.
	 *
	 * @param int    $site_id     MainWP site id.
	 * @param string $update_type Optional type scope: core|plugins|themes|translations ('' = all).
	 * @param array  $slugs       Optional item slugs (raw upgrade-column keys) for non-core types.
	 * @return array Result with ok, updated, offline and error keys.
	 */
	public static function trigger_site_update( int $site_id, string $update_type = '', array $slugs = array() ): array {
		if ( ! self::can_trigger_updates() ) {
			return array(
				'ok'      => false,
				'updated' => 0,
				'offline' => false,
				'error'   => __( 'MainWP update API is unavailable. Run the native update instead.', 'webchangedetector-for-mainwp' ),
			);
		}

		$methods = array(
			'core'         => 'execute_update_site_core',
			'plugins'      => 'execute_update_site_plugins',
			'themes'       => 'execute_update_site_themes',
			'translations' => 'execute_update_site_translations',
		);
		if ( '' !== $update_type ) {
			$methods = array_intersect_key( $methods, array( $update_type => true ) );
			// A typed call whose single abilities method is missing must degrade to a clear
			// error, never a silent ok/no-op (REVIEW.md: guarded internal calls degrade to a
			// notice). The legacy untyped loop keeps skipping missing methods per type instead.
			if ( ! isset( $methods[ $update_type ] ) || ! method_exists( self::ABILITIES_CLASS, $methods[ $update_type ] ) ) {
				return array(
					'ok'      => false,
					'updated' => 0,
					'offline' => false,
					'error'   => __( 'MainWP update API is unavailable. Run the native update instead.', 'webchangedetector-for-mainwp' ),
				);
			}
		}

		$updated = 0;
		$offline = false;
		$errors  = array();

		foreach ( $methods as $type => $method ) {
			if ( ! method_exists( self::ABILITIES_CLASS, $method ) ) {
				continue;
			}

			// Each update type can take minutes with no other AJAX activity; keep the tracked
			// run from looking abandoned to another tab in the meantime.
			self::touch_run();

			$input = array( 'site_id_or_domain' => $site_id );
			// Slugs only apply on an explicitly typed (Updates-page) call: the legacy all-types
			// loop must never scope three types to one type's slugs.
			if ( '' !== $update_type && 'core' !== $type && ! empty( $slugs ) ) {
				$input['slugs'] = $slugs;
			}

			$result = call_user_func( array( self::ABILITIES_CLASS, $method ), $input );

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
	 * @return array List of update item arrays (kind, slug, name, version).
	 */
	protected static function items_from_website( $website ): array {
		$items = array();

		$core = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
		if ( is_array( $core ) && ! empty( $core ) ) {
			$items[] = array(
				'kind'    => 'core',
				'slug'    => '',
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
					// The raw column key for plugins/themes (the same key the abilities' slugs
					// filter matches); translations carry their slug inside the entry (their
					// column is a plain list, so the key is just a numeric index).
					'slug'    => 'translation' === $kind ? (string) ( $entry['slug'] ?? '' ) : (string) $slug,
					'name'    => (string) ( $entry['Name'] ?? ( $entry['name'] ?? ( is_string( $slug ) ? $slug : '' ) ) ),
					'version' => (string) ( $update['new_version'] ?? ( $entry['new_version'] ?? ( $entry['version'] ?? '' ) ) ),
				);
			}
		}

		return $items;
	}

	/* ───────────────────────── Run state (resume) ──────────────────────── */

	// The browser orchestrates the safe-update run, so navigating away (or a closed tab) would
	// otherwise lose the remaining phases. Every phase transition is therefore persisted
	// dashboard-side (network-aware option): the AJAX endpoints record pre batches, completed
	// updates and post batches as they happen, and the driving tab sends a short heartbeat. When a
	// page next loads with a still-active run whose heartbeat has gone silent (RESUME_AFTER), it
	// automatically reopens the run popup and continues at the persisted phase.

	const RUN_STATE_KEY   = 'wcd_mainwp_active_run';
	const RUN_STALE_AFTER = 600; // Seconds idle before an empty (nothing-happened) run is dropped as leftover.
	const RESUME_AFTER    = 20;  // Seconds without a heartbeat before another page may take over the run.

	/**
	 * The persisted state of the current safe-update run, or an empty array.
	 *
	 * @return array Run state (started_at, last_activity, phase, sites, update_type, site_slugs,
	 *               pre_batches, updated_sites, post_batches).
	 */
	public static function run_state(): array {
		$state = WCD_MainWP_Options::get( self::RUN_STATE_KEY, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Start tracking a new run (replaces any previous state).
	 *
	 * @param array  $sites       Run sites keyed by site id: [ site_id => [ site_id, name, checks ] ].
	 * @param string $driver      Opaque id of the tab driving the run (so it can reclaim it instantly
	 *                            after a same-tab reload/navigation, without waiting out the two-tab guard).
	 * @param string $update_type Optional type scope of an Updates-page run ('' = legacy whole-site run).
	 * @param array  $site_slugs  Optional per-site item slugs: [ site_id => string[] ] (empty list =
	 *                            all items of the type). Only kept for tracked sites.
	 * @return void
	 */
	public static function start_run( array $sites, string $driver = '', string $update_type = '', array $site_slugs = array() ): void {
		self::save_run(
			array(
				'started_at'    => time(),
				'last_activity' => time(),
				'phase'         => 'pre',
				'driver'        => $driver,
				'sites'         => $sites,
				// Scoped (Updates-page) runs persist their type + per-site slugs so a resume
				// re-applies the original selection and never installs more than the user picked.
				'update_type'   => $update_type,
				'site_slugs'    => array_intersect_key( $site_slugs, $sites ),
				'pre_batches'   => array(),
				'updated_sites' => array(),
				'post_batches'  => array(),
			)
		);
	}

	/**
	 * Stop tracking the current run.
	 *
	 * @return void
	 */
	public static function clear_run(): void {
		WCD_MainWP_Options::delete( self::RUN_STATE_KEY );
	}

	/**
	 * Bump the run's activity timestamp (called from the polling endpoint while a run is driven).
	 *
	 * @return void
	 */
	public static function touch_run(): void {
		$state = self::run_state();
		// Throttled: poll ticks every ~3s; one option write per 30s keeps the timestamp fresh
		// enough for the staleness gate.
		if ( $state && ( time() - (int) ( $state['last_activity'] ?? 0 ) ) > 30 ) {
			self::save_run( $state );
		}
	}

	/**
	 * Stamp the run as active right now (the driving tab's heartbeat). Unthrottled: a fresh stamp is
	 * what tells another page that this run is still being driven, so it must never be skipped. Also
	 * records the driver id so a same-tab reload can recognise its own run and reclaim it instantly.
	 *
	 * @param string $driver Opaque id of the tab sending the heartbeat (becomes the current driver).
	 * @return void
	 */
	public static function heartbeat( string $driver = '' ): void {
		$state = self::run_state();
		if ( $state ) {
			if ( '' !== $driver ) {
				$state['driver'] = $driver;
			}
			self::save_run( $state );
		}
	}

	/**
	 * Seconds since the run last showed activity (heartbeat / phase write).
	 *
	 * @param array $state Run state (from run_state()).
	 * @return int
	 */
	public static function idle_seconds( array $state ): int {
		$last = isset( $state['last_activity'] ) ? (int) $state['last_activity'] : 0;

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
	 * Persist the run state with a fresh activity timestamp.
	 *
	 * @param array $state Run state to save.
	 * @return void
	 */
	protected static function save_run( array $state ): void {
		$state['last_activity'] = time();
		WCD_MainWP_Options::set( self::RUN_STATE_KEY, $state );
	}
}
