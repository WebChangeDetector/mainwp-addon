<?php
/**
 * Extension page shell: the WebChange Detector tab switcher and the active tab body.
 *
 * The add-on consolidates Run, Checks, Settings and Account onto this single extension page
 * (Extensions -> WebChange Detector). Tabs switch via a full page reload (?tab=...); the active
 * tab body is rendered by its owning class. The Account tab keeps the token + auto-enable form and
 * the account/credits card. Default landing: Account when no token is configured, else Run. While a
 * signup activation is pending the page is locked to the activate-account state: the Account tab is
 * forced regardless of ?tab= and the switcher is hidden.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

// Read-only tab navigation. filter_input (not the $_GET superglobal) + sanitize_key + the explicit
// whitelist below keep this free of unsanitized input; nothing here changes state, so no nonce applies.
$wcd_mainwp_tab = sanitize_key( (string) filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

// Re-verify a pending signup BEFORE deciding the layout: the first load after the emailed
// activation link clears the flag right here, so the tabs unlock without an extra step.
WCD_MainWP_Site_Settings::refresh_pending_activation();

// While a signup activation is pending, the page shows ONLY the activate-account state: the tab is
// forced to Account regardless of ?tab= and the switcher is not rendered at all.
$wcd_mainwp_locked = '' !== WCD_MainWP_Site_Settings::get_global() && '' !== WCD_MainWP_Site_Settings::pending_email();

$wcd_mainwp_default = '' === WCD_MainWP_Site_Settings::get_global() ? 'account' : 'run';
if ( $wcd_mainwp_locked ) {
	$wcd_mainwp_tab = 'account';
} elseif ( ! in_array( $wcd_mainwp_tab, array( 'run', 'checks', 'settings', 'account' ), true ) ) {
	$wcd_mainwp_tab = $wcd_mainwp_default;
}

do_action( 'mainwp_pageheader_extensions', WCD_MAINWP_PLUGIN_PATH . 'webchangedetector-for-mainwp.php' );

if ( ! $wcd_mainwp_locked ) {
	WCD_MainWP_Runs_View::render_tabs( $wcd_mainwp_tab );
}

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
