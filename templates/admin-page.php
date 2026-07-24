<?php
/**
 * Extension page shell: the WebChange Detector tab switcher and the active tab body.
 *
 * The add-on consolidates Run, Checks, Settings and Account onto this single extension page
 * (Extensions -> WebChange Detector). Tabs switch via a full page reload (?tab=...); the active
 * tab body is rendered by its owning class. The Account tab keeps the token + auto-enable form and
 * the account/credits card. Default landing: Account when no token is configured or a signup
 * activation is pending, else Run.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

// Read-only tab navigation. filter_input (not the $_GET superglobal) + sanitize_key + the explicit
// whitelist below keep this free of unsanitized input; nothing here changes state, so no nonce applies.
$wcd_mainwp_tab = sanitize_key( (string) filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
// Default to the Account tab while there is no token OR a signup activation is still pending (the
// Run tab would only funnel into 403ing calls until the activation link is clicked).
$wcd_mainwp_default = ( '' === WCD_MainWP_Site_Settings::get_global() || '' !== WCD_MainWP_Site_Settings::pending_email() ) ? 'account' : 'run';
if ( ! in_array( $wcd_mainwp_tab, array( 'run', 'checks', 'settings', 'account' ), true ) ) {
	$wcd_mainwp_tab = $wcd_mainwp_default;
}

do_action( 'mainwp_pageheader_extensions', WCD_MAINWP_PLUGIN_PATH . 'webchangedetector-for-mainwp.php' );

WCD_MainWP_Runs_View::render_tabs( $wcd_mainwp_tab );

switch ( $wcd_mainwp_tab ) {
	case 'run':
		WCD_MainWP_Runs_View::render_run_page();
		break;
	case 'checks':
		WCD_MainWP_Runs_View::render_page();
		break;
	case 'settings':
		WCD_MainWP_Site_Settings::render_sites_settings_page();
		break;
	case 'account':
	default:
		?>
		<div class="ui padded segment wcd-account-tab">
			<h2 class="ui header"><?php esc_html_e( 'Account', 'webchangedetector-for-mainwp' ); ?></h2>
			<?php WCD_MainWP_Site_Settings::render_settings_form(); ?>
		</div>
		<?php
		break;
}

do_action( 'mainwp_pagefooter_extensions', WCD_MAINWP_PLUGIN_PATH . 'webchangedetector-for-mainwp.php' );
