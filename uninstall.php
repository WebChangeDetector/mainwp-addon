<?php
/**
 * Uninstall cleanup. Removes the add-on's options + cached account, on both single-site and
 * network installs. WCD-side data (websites/groups/comparisons) is intentionally left intact.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wcd_mainwp_keys = array(
	'wcd_api_token',
	'wcd_api_key', // legacy v0.1.0 option.
	'wcd_site_map',
	'wcd_auto_enable_sites',
	'wcd_mainwp_active_run',
	'wcd_mainwp_verify_secret',
	'wcd_mainwp_activation_pending',
);

foreach ( $wcd_mainwp_keys as $wcd_mainwp_key ) {
	delete_option( $wcd_mainwp_key );
	if ( is_multisite() ) {
		delete_site_option( $wcd_mainwp_key );
	}
}

$wcd_mainwp_transients = array(
	'wcd_mainwp_account_details',
	'wcd_mainwp_token_error',
	'wcd_mainwp_token_verified',
	'wcd_mainwp_token_reset',
	'wcd_mainwp_signup_error',
);

foreach ( $wcd_mainwp_transients as $wcd_mainwp_transient ) {
	delete_transient( $wcd_mainwp_transient );
	if ( is_multisite() ) {
		delete_site_transient( $wcd_mainwp_transient );
	}
}
