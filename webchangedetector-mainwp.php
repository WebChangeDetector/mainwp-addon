<?php
/**
 * Plugin Name: WebChange Detector
 * Description: Visual Regression Testing
 * Version: 0.1.0
 * Author: WebChange Detector
 * Documentation URI: https://api.webchangedetector.com/docs/
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCD_MAINWP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WCD_MAINWP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-bootstrap.php';

WCD_MainWP_Bootstrap::init();
