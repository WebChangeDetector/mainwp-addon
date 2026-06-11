<?php
/**
 * Plugin Name: WebChange Detector for MainWP
 * Description: Visual regression testing in MainWP: capture before/after screenshots around your updates and instantly see what changed.
 * Version: 0.1.0
 * Author: Mike Miler
 * Documentation URI: https://api.webchangedetector.com/docs/
 *
 * @package WebChangeDetector_MainWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCD_MAINWP_VERSION', '0.1.0' );
define( 'WCD_MAINWP_PLUGIN_FILE', __FILE__ );
define( 'WCD_MAINWP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCD_MAINWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-bootstrap.php';

WCD_MainWP_Bootstrap::init();
