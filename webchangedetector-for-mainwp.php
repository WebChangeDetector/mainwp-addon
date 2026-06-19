<?php
/**
 * Plugin Name:       WebChange Detector for MainWP
 * Plugin URI:        https://www.webchangedetector.com/mainwp/
 * Description:       Visual checks for MainWP updates: capture before/after screenshots around your updates and instantly see what changed.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  mainwp
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

define( 'WCD_MAINWP_VERSION', '1.0.2' );
define( 'WCD_MAINWP_PLUGIN_FILE', __FILE__ );
define( 'WCD_MAINWP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCD_MAINWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-bootstrap.php';

WCD_MainWP_Bootstrap::init();
