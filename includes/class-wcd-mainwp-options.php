<?php
/**
 * Network-aware option + transient storage.
 *
 * MainWP runs in the network admin on multisite, so the add-on's settings live at the
 * dashboard/network level. These helpers transparently pick the site-wide store on multisite
 * and the regular store on single-site installs, so callers never have to branch.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Network-aware wrapper around the WordPress option and transient APIs.
 */
class WCD_MainWP_Options {

	/**
	 * Read an option from the network-aware store.
	 *
	 * @param string $key           Option name.
	 * @param mixed  $default_value Value to return when the option is missing.
	 * @return mixed Stored option value, or the default.
	 */
	public static function get( string $key, $default_value = false ) {
		return is_multisite() ? get_site_option( $key, $default_value ) : get_option( $key, $default_value );
	}

	/**
	 * Write an option to the network-aware store.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Value to store.
	 * @return bool True on success, false on failure.
	 */
	public static function set( string $key, $value ): bool {
		return is_multisite() ? update_site_option( $key, $value ) : update_option( $key, $value );
	}

	/**
	 * Delete an option from the network-aware store.
	 *
	 * @param string $key Option name.
	 * @return bool True on success, false on failure.
	 */
	public static function delete( string $key ): bool {
		return is_multisite() ? delete_site_option( $key ) : delete_option( $key );
	}

	/**
	 * Read a transient from the network-aware store.
	 *
	 * @param string $key Transient name.
	 * @return mixed Stored transient value, or false when missing/expired.
	 */
	public static function get_transient( string $key ) {
		return is_multisite() ? get_site_transient( $key ) : get_transient( $key );
	}

	/**
	 * Write a transient to the network-aware store.
	 *
	 * @param string $key   Transient name.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Time to live in seconds.
	 * @return bool True on success, false on failure.
	 */
	public static function set_transient( string $key, $value, int $ttl ): bool {
		return is_multisite() ? set_site_transient( $key, $value, $ttl ) : set_transient( $key, $value, $ttl );
	}

	/**
	 * Delete a transient from the network-aware store.
	 *
	 * @param string $key Transient name.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_transient( string $key ): bool {
		return is_multisite() ? delete_site_transient( $key ) : delete_transient( $key );
	}
}
