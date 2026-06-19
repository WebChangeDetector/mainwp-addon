<?php
/**
 * "Run" tab body of the WebChange Detector extension page.
 *
 * The safe-update entry point as a tab: the Safe-Update widget body (the same one as the dashboard
 * "Safe Update" metabox and the Updates-page hero banner). WCD_MainWP_Widget::render_safe_update_panel()
 * owns the token/empty-state branching; the extension page shell (templates/admin-page.php) supplies
 * the MainWP chrome + tab switcher. assets/js/wcd-mainwp.js drives the run via the widget's JS contract
 * (no inline JS/CSS).
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ui padded segment wcd-run-tab">
	<?php WCD_MainWP_Widget::render_safe_update_panel(); ?>
</div>
