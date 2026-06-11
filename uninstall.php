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
);

foreach ( $wcd_mainwp_keys as $wcd_mainwp_key ) {
	delete_option( $wcd_mainwp_key );
	if ( is_multisite() ) {
		delete_site_option( $wcd_mainwp_key );
	}
}

delete_transient( 'wcd_account_details' );
if ( is_multisite() ) {
	delete_site_transient( 'wcd_account_details' );
}
