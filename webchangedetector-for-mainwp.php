<?php
/**
 * Plugin Name:       WebChange Detector for MainWP
 * Plugin URI:        https://www.webchangedetector.com/mainwp/
 * Description:       Visual checks for MainWP updates: capture before/after screenshots around your updates and instantly see what changed.
 * Version:           1.1.0-beta.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  mainwp
 * GitHub Plugin URI: https://github.com/WebChangeDetector/mainwp-addon
 * Primary Branch:    main
 * Author:            Mike Miler
 * Author URI:        https://www.webchangedetector.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webchangedetector-for-mainwp
 *
 * @package WebChangeDetector_MainWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamically derive the current version from the plugin header so it is only
 * maintained in one place (this header). The build/release scripts and Git
 * Updater read the same header line, so there is no separate literal to keep in
 * sync.
 */
if ( ! defined( 'WCD_MAINWP_VERSION' ) ) {
	if ( ! function_exists( 'get_file_data' ) ) {
		// Ensure get_file_data is available even on the front-end.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$wcd_mainwp_plugin_data = get_file_data(
		__FILE__,
		array( 'Version' => 'Version' ),
		false
	);

	define( 'WCD_MAINWP_VERSION', isset( $wcd_mainwp_plugin_data['Version'] ) ? $wcd_mainwp_plugin_data['Version'] : '0.0.0' );
}

define( 'WCD_MAINWP_PLUGIN_FILE', __FILE__ );
define( 'WCD_MAINWP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCD_MAINWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Beta update channel opt-in (dev and staging sites only).
 *
 * WCD_MAINWP_USE_DEV_BRANCH must be defined explicitly in wp-config.php. There
 * is deliberately NO auto-detection: an earlier approach enabled the beta
 * channel whenever the Git Updater plugin happened to be active, which silently
 * served unreleased dev-branch code to every site that had Git Updater
 * installed.
 *
 * Default (constant absent or false): no branch override, so Git Updater
 * resolves updates from the published releases/tags of the repository named in
 * the "GitHub Plugin URI" header, against "Primary Branch: main".
 *
 * Opt-in (constant defined as true): updates track the `dev` branch instead.
 */
if ( ! defined( 'WCD_MAINWP_USE_DEV_BRANCH' ) ) {
	define( 'WCD_MAINWP_USE_DEV_BRANCH', false );
}

/**
 * Point Git Updater at the `dev` branch for this add-on.
 *
 * Registered only while the WCD_MAINWP_USE_DEV_BRANCH opt-in is active, so the
 * default (tag based) update path is never overridden. The add-on has no
 * namespace, so this function name is globally unique on purpose.
 *
 * @param string $branch The branch Git Updater resolved.
 * @param string $slug   The plugin slug.
 * @return string The branch to use for updates.
 */
function wcd_mainwp_set_git_updater_branch( $branch, $slug ) {
	// Only apply to our add-on. Git Updater passes the REPOSITORY slug (the last
	// segment of the "GitHub Plugin URI" header, "mainwp-addon"), which differs
	// from the plugin directory name ("webchangedetector-for-mainwp") for this
	// add-on. Match both so the override fires whichever identifier is passed;
	// both uniquely identify this add-on and the filter is only registered under
	// the explicit WCD_MAINWP_USE_DEV_BRANCH opt-in.
	if ( ! in_array( $slug, array( 'mainwp-addon', 'webchangedetector-for-mainwp' ), true ) ) {
		return $branch;
	}

	return 'dev';
}

// Only override the branch when the beta channel was explicitly opted into.
if ( WCD_MAINWP_USE_DEV_BRANCH ) {
	add_filter( 'gu_primary_branch', 'wcd_mainwp_set_git_updater_branch', 10, 2 );
}

require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-bootstrap.php';

WCD_MainWP_Bootstrap::init();

/**
 * Show an admin notice while the beta (dev branch) update channel is active.
 *
 * Rendered only to administrators, only when the opt-in is on, and only on the
 * add-on's own admin pages, reusing the bootstrap's existing screen gating.
 *
 * @return void
 */
function wcd_mainwp_update_mode_admin_notice() {
	// Only show when the beta channel is opted into.
	if ( ! WCD_MAINWP_USE_DEV_BRANCH ) {
		return;
	}

	// Only show to administrators.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only show on the add-on's own admin pages.
	if ( ! class_exists( 'WCD_MainWP_Bootstrap' ) || ! WCD_MainWP_Bootstrap::is_on_our_page() ) {
		return;
	}

	$notice = sprintf(
		'<div class="notice notice-warning"><p><strong>%1$s:</strong> %2$s <strong>%3$s</strong> %4$s</p></div>',
		esc_html__( 'WebChange Detector for MainWP', 'webchangedetector-for-mainwp' ),
		esc_html__( 'Currently running in', 'webchangedetector-for-mainwp' ),
		esc_html__( 'Beta (dev branch)', 'webchangedetector-for-mainwp' ),
		esc_html__( 'update mode. You will receive beta updates.', 'webchangedetector-for-mainwp' )
	);

	echo wp_kses_post( $notice );
}
add_action( 'admin_notices', 'wcd_mainwp_update_mode_admin_notice' );
