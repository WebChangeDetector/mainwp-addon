<?php
/**
 * Dashboard widget showing the connected account's plan + check credits.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the MainWP dashboard widget with the connected account's plan and check credits.
 */
class WCD_MainWP_Widget {

	/**
	 * Render the dashboard widget metabox body.
	 *
	 * @return void
	 */
	public static function render_metabox(): void {
		$token   = WCD_MainWP_Site_Settings::get_global();
		$account = array();
		$error   = '';

		if ( '' === $token ) {
			$error = __( 'No API token configured. Add one in the WebChange Detector settings.', 'webchangedetector' );
		} else {
			$account = WCD_MainWP_Site_Settings::get_account();
			if ( empty( $account ) ) {
				$error = __( 'Could not retrieve account data. Please check the API token.', 'webchangedetector' );
			}
		}

		include WCD_MAINWP_PLUGIN_PATH . 'templates/widget.php';
	}
}
