<?php
/**
 * Per-site tab (slug WcdVisualRegressionTesting): status + safe-update entry for one site.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

// Resolve the current site id from MainWP's own context (same guarded helper the bootstrap uses)
// instead of reading the unnonced $_GET['id'] navigation parameter.
$wcd_util = '\\MainWP\\Dashboard\\MainWP_System_Utility';
$site_id  = ( class_exists( $wcd_util ) && method_exists( $wcd_util, 'get_current_wpid' ) ) ? (int) $wcd_util::get_current_wpid() : 0;
$token    = WCD_MainWP_Site_Settings::get_global();
$enabled  = $site_id && WCD_MainWP_Site_Map::is_enabled( $site_id );
$settings = admin_url( 'admin.php?page=' . WCD_MainWP_Bootstrap::settings_page_slug() );
?>

<?php do_action( 'mainwp_pageheader_sites', 'WcdVisualRegressionTesting' ); ?>

<div class="ui segment">
	<h3 class="ui header"><?php esc_html_e( 'WebChange Detector', 'webchangedetector' ); ?></h3>

	<?php if ( '' === $token ) : ?>
		<div class="ui negative message">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL. */
					wp_kses_post( __( 'No API token configured. Add one in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector' ) ),
					esc_url( $settings )
				);
				?>
			</p>
		</div>
	<?php elseif ( ! $enabled ) : ?>
		<div class="ui info message">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL. */
					wp_kses_post( __( 'This site is not enabled for visual checks yet. Enable it in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector' ) ),
					esc_url( $settings )
				);
				?>
			</p>
		</div>
		<?php
	else :
		$updates_count = WCD_MainWP_Update_Flow::pending_updates_count( array( $site_id ) );
		$no_updates    = ( null !== $updates_count && 0 === (int) $updates_count );
		?>
		<p class="wcd-muted"><?php esc_html_e( 'Run a safe update for this site: capture before/after screenshots around the update and review the change detections.', 'webchangedetector' ); ?></p>
		<button type="button" class="ui green button wcd-safe-update<?php echo $no_updates ? ' disabled' : ''; ?>" data-scope="site" data-site-id="<?php echo esc_attr( (string) $site_id ); ?>" <?php disabled( $no_updates ); ?>>
			<i class="eye icon"></i> <?php esc_html_e( 'On-Demand Safe Update', 'webchangedetector' ); ?>
		</button>
		<a href="<?php echo esc_url( $settings ); ?>" class="ui button"><?php esc_html_e( 'Configure URLs', 'webchangedetector' ); ?></a>
		<?php if ( $no_updates ) : ?>
			<p class="wcd-muted wcd-mt"><?php esc_html_e( 'No updates available for this site right now.', 'webchangedetector' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>

<?php // The unified in-card run renders here (this tab's safe-update button has no hero banner). ?>
<div class="wcd-run-host"></div>

<?php do_action( 'mainwp_pagefooter_sites', 'WcdVisualRegressionTesting' ); ?>
