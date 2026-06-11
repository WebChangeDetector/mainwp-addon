<?php
/**
 * Extension settings page: renders the WebChange Detector settings form inside MainWP.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;
?>

<?php do_action( 'mainwp_pageheader_extensions', WCD_MAINWP_PLUGIN_PATH . 'webchangedetector-for-mainwp.php' ); ?>

<div class="ui segment">
	<h2 class="ui header">WebChange Detector Settings</h2>
	<?php WCD_MainWP_Site_Settings::render_settings_form(); ?>
</div>

<?php do_action( 'mainwp_pagefooter_extensions', WCD_MAINWP_PLUGIN_PATH . 'webchangedetector-for-mainwp.php' ); ?>
