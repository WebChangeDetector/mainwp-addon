<?php
/**
 * Per-site tab (slug WcdVisualRegressionTesting): status + safe-update entry for one site.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

// Resolve the current site id from MainWP's own context (same guarded helper the bootstrap uses)
// instead of reading the unnonced $_GET['id'] navigation parameter.
$wcd_mainwp_util    = '\\MainWP\\Dashboard\\MainWP_System_Utility';
$wcd_mainwp_site_id = ( class_exists( $wcd_mainwp_util ) && method_exists( $wcd_mainwp_util, 'get_current_wpid' ) ) ? (int) $wcd_mainwp_util::get_current_wpid() : 0;
$wcd_mainwp_token   = WCD_MainWP_Site_Settings::get_global();
$wcd_mainwp_pending = WCD_MainWP_Site_Settings::pending_email();
$wcd_mainwp_enabled = $wcd_mainwp_site_id && WCD_MainWP_Site_Map::is_enabled( $wcd_mainwp_site_id );
// Token/account live on the extension page's Account tab; site enabling + URL selection on its
// Settings tab.
$wcd_mainwp_account_page = WCD_MainWP_Bootstrap::tab_url( 'account' );
$wcd_mainwp_settings     = WCD_MainWP_Bootstrap::tab_url( 'settings' );
?>

<?php do_action( 'mainwp_pageheader_sites', 'WcdVisualRegressionTesting' ); ?>

<div class="ui segment">
	<h3 class="ui header"><?php esc_html_e( 'WebChange Detector', 'webchangedetector-for-mainwp' ); ?></h3>

	<?php if ( '' === $wcd_mainwp_token ) : ?>
		<div class="ui negative message">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL. */
					wp_kses_post( __( 'No API token configured. Add one in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector-for-mainwp' ) ),
					esc_url( $wcd_mainwp_account_page )
				);
				?>
			</p>
		</div>
	<?php elseif ( '' !== $wcd_mainwp_pending ) : ?>
		<div class="ui info message">
			<p>
				<?php
				printf(
					/* translators: 1: the signup email address, 2: Account tab URL. */
					wp_kses_post( __( 'Activate your WebChange Detector account first: we sent an activation link to %1$s. Click it, then reload this page. Details on the <a href="%2$s">Account</a> tab.', 'webchangedetector-for-mainwp' ) ),
					'<strong>' . esc_html( $wcd_mainwp_pending ) . '</strong>',
					esc_url( $wcd_mainwp_account_page )
				);
				?>
			</p>
		</div>
	<?php elseif ( ! $wcd_mainwp_enabled ) : ?>
		<div class="ui info message">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL. */
					wp_kses_post( __( 'This site is not enabled for visual checks yet. Enable it in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector-for-mainwp' ) ),
					esc_url( $wcd_mainwp_settings )
				);
				?>
			</p>
		</div>
		<?php
	else :
		$wcd_mainwp_updates_count = WCD_MainWP_Update_Flow::pending_updates_count( array( $wcd_mainwp_site_id ) );
		$wcd_mainwp_no_updates    = ( null !== $wcd_mainwp_updates_count && 0 === (int) $wcd_mainwp_updates_count );
		?>
		<p class="wcd-muted"><?php esc_html_e( 'Run WCD Updates for this site: capture before/after screenshots around the update and review the change detections.', 'webchangedetector-for-mainwp' ); ?></p>
		<button type="button" class="ui green button wcd-safe-update<?php echo $wcd_mainwp_no_updates ? ' disabled' : ''; ?>" data-scope="site" data-site-id="<?php echo esc_attr( (string) $wcd_mainwp_site_id ); ?>" <?php disabled( $wcd_mainwp_no_updates ); ?>>
			<i class="eye icon"></i> <?php esc_html_e( 'Update with Checks', 'webchangedetector-for-mainwp' ); ?>
		</button>
		<a href="<?php echo esc_url( $wcd_mainwp_settings ); ?>" class="ui button"><?php esc_html_e( 'Configure URLs', 'webchangedetector-for-mainwp' ); ?></a>
		<?php if ( $wcd_mainwp_no_updates ) : ?>
			<p class="wcd-muted wcd-mt"><?php esc_html_e( 'No updates available for this site right now.', 'webchangedetector-for-mainwp' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>

<?php // The unified in-card run renders here (this tab's safe-update button has no hero banner). ?>
<div class="wcd-run-host"></div>

<?php do_action( 'mainwp_pagefooter_sites', 'WcdVisualRegressionTesting' ); ?>
