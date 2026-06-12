<?php
/**
 * Child-site cache purging.
 *
 * Screenshots must show the freshly generated state of a child site, not a stale cached page:
 * before the pre-update screenshots and after the updates we purge the child's page cache.
 * The purge itself ships with the MainWP Child core plugin (MainWP_Child_Cache_Purge, 20+
 * supported cache plugins, auto-detected on the child) and is exposed through the documented
 * `mainwp_fetchurlauthed` filter with the `cache_purge_action` child callable, so no extra
 * extension is required on either side.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Purges the page cache on child sites via MainWP's documented dashboard-to-child filter.
 */
class WCD_MainWP_Cache_Purge {

	/**
	 * The MainWP Child callable that force-purges the detected cache solution. It runs
	 * auto_purge_cache('true'), which purges even when the child's Cache Control setting is off,
	 * and is a no-op when no supported cache plugin is detected.
	 */
	const CHILD_ACTION = 'cache_purge_action';

	/**
	 * Purge the cache on several child sites, sequentially. Best effort: failures are logged
	 * and skipped so a single unreachable site never blocks the run.
	 *
	 * @param int[] $site_ids MainWP site ids.
	 * @return void
	 */
	public static function purge_sites( array $site_ids ): void {
		foreach ( array_unique( array_map( 'intval', $site_ids ) ) as $site_id ) {
			if ( $site_id > 0 ) {
				self::purge_site( $site_id );
			}
		}
	}

	/**
	 * Purge the cache on one child site, synchronously: when this returns true the purge has
	 * completed on the child, so screenshots started afterwards see regenerated pages.
	 *
	 * Never throws and never blocks the caller beyond the one child request; on any failure
	 * (MainWP missing, no extension key, offline site, old mainwp-child without the callable)
	 * it logs and returns false.
	 *
	 * @param int $site_id MainWP site id.
	 * @return bool True when the child confirmed the purge request.
	 */
	public static function purge_site( int $site_id ): bool {
		if ( $site_id <= 0 || ! has_filter( 'mainwp_fetchurlauthed' ) ) {
			return false;
		}

		$key = WCD_MainWP_Site_Map::extension_key();
		if ( '' === $key ) {
			self::log( $site_id, 'no extension key available' );

			return false;
		}

		// Skip sites MainWP already knows are unreachable: the purge request would only burn its
		// connection timeout (run-wide in the PRE phase). Read-only best-effort check against
		// MainWP's website row, same pattern as the pending-update reads (see MAINWP-HOOKS.md).
		if ( self::known_unreachable( $site_id ) ) {
			self::log( $site_id, 'skipped, site has MainWP sync errors' );

			return false;
		}

		try {
			// The filtered value IS the plugin file, then the child key (same convention as
			// the mainwp_getsites filter).
			$response = apply_filters( 'mainwp_fetchurlauthed', WCD_MAINWP_PLUGIN_FILE, $key, $site_id, self::CHILD_ACTION, array() );
		} catch ( \Throwable $e ) {
			self::log( $site_id, $e->getMessage() );

			return false;
		}

		// MainWP returns false on key verification failure and array('error' => ...) on child
		// errors (offline, unknown callable on pre-4.3 mainwp-child). The child reports
		// non-purges as action ERROR; "no cache plugin detected" comes back as SUCCESS/Disabled,
		// which counts as done.
		if ( ! is_array( $response ) || ! empty( $response['error'] ) || ( isset( $response['action'] ) && 'ERROR' === $response['action'] ) ) {
			if ( is_array( $response ) ) {
				$reason = (string) ( $response['error'] ?? ( $response['result'] ?? 'unknown error' ) );
			} else {
				// MainWP returns false for exactly one reason: hook_verify rejected the key.
				$reason = false === $response ? 'extension key rejected by MainWP' : 'request failed';
			}
			self::log( $site_id, $reason );

			return false;
		}

		return true;
	}

	/**
	 * Whether MainWP's own website row marks the site as unreachable (non-empty sync_errors).
	 * Read-only and best effort: when MainWP's DB layer is unavailable we assume reachable and
	 * let the purge request find out itself.
	 *
	 * @param int $site_id MainWP site id.
	 * @return bool True when the site should be skipped.
	 */
	protected static function known_unreachable( int $site_id ): bool {
		$db = '\\MainWP\\Dashboard\\MainWP_DB';
		if ( ! class_exists( $db ) || ! method_exists( $db, 'instance' ) ) {
			return false;
		}

		$website = $db::instance()->get_website_by_id( $site_id );

		return is_object( $website ) && ! empty( $website->sync_errors );
	}

	/**
	 * Signal a failed purge. Purging is best effort, so failures never surface as run errors and
	 * there is no user-facing notice; this fires an action so integrators (or a debug logger) can
	 * trace failures without the plugin writing to the error log itself.
	 *
	 * @param int    $site_id MainWP site id.
	 * @param string $reason  Human-readable failure reason.
	 * @return void
	 */
	protected static function log( int $site_id, string $reason ): void {
		/**
		 * Fires when a best-effort child-site cache purge fails.
		 *
		 * @param int    $site_id MainWP site id.
		 * @param string $reason  Human-readable failure reason.
		 */
		do_action( 'wcd_mainwp_cache_purge_failed', $site_id, $reason );
	}
}
