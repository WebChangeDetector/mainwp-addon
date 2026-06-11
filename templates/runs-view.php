<?php
/**
 * "Visual Checks" overview page (Sites subpage, linked from the Monitoring menu group).
 *
 * Native MainWP chrome (pageheader/pagefooter) around a Fomantic filter bar + an empty results
 * container; wcd-mainwp.js detects #wcd-runs and loads the runs via AJAX (no inline JS/CSS).
 * Only On-Demand Checks are shown; the source filter is fixed server-side.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

$wcd_token = WCD_MainWP_Site_Settings::get_global();

do_action( 'mainwp_pageheader_sites', WCD_MainWP_Runs_View::PAGE_SLUG );

if ( '' === $wcd_token ) {
	$wcd_settings_url = admin_url( 'admin.php?page=' . WCD_MainWP_Bootstrap::settings_page_slug() );
	WCD_MainWP_Runs_View::render_tabs( 'checks' );
	?>
	<div class="ui bottom attached padded segment">
		<div class="ui info message"><p>
			<?php
			printf(
				/* translators: %s: settings page URL. */
				wp_kses_post( __( 'No API token configured. Add one in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector-for-mainwp' ) ),
				esc_url( $wcd_settings_url )
			);
			?>
		</p></div>
	</div>
	<?php
	do_action( 'mainwp_pagefooter_sites', WCD_MainWP_Runs_View::PAGE_SLUG );
	return;
}

WCD_MainWP_Runs_View::render_tabs( 'checks' );

$wcd_status_options  = WCD_MainWP_Runs_View::status_options();
$wcd_website_options = WCD_MainWP_Runs_View::website_options();
// Dashboard timezone (matches the JS date presets, which compute local dates).
$wcd_from_initial = wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
$wcd_to_initial   = wp_date( 'Y-m-d' );
?>
<div class="ui bottom attached padded segment wcd-runs" id="wcd-runs" data-from="<?php echo esc_attr( $wcd_from_initial ); ?>" data-to="<?php echo esc_attr( $wcd_to_initial ); ?>">

	<div class="ui grid">
		<div class="equal width row ui mini form">
			<div class="middle aligned column">
				<div class="ui mini buttons wcd-runs-view-toggle">
					<button type="button" class="ui button active wcd-runs-view-btn" data-view="batch"><?php esc_html_e( 'Runs', 'webchangedetector-for-mainwp' ); ?></button>
					<button type="button" class="ui button wcd-runs-view-btn" data-view="flat"><?php esc_html_e( 'List', 'webchangedetector-for-mainwp' ); ?></button>
				</div>
			</div>
			<div class="right aligned middle aligned column wcd-runs-filters">
				<div id="wcd-runs-period" class="ui selection dropdown">
					<input type="hidden" value="30">
					<i class="dropdown icon"></i>
					<div class="default text"><?php esc_html_e( 'Last 30 days', 'webchangedetector-for-mainwp' ); ?></div>
					<div class="menu">
						<div class="item" data-value="7"><?php esc_html_e( 'Last 7 days', 'webchangedetector-for-mainwp' ); ?></div>
						<div class="item" data-value="30"><?php esc_html_e( 'Last 30 days', 'webchangedetector-for-mainwp' ); ?></div>
						<div class="item" data-value="90"><?php esc_html_e( 'Last 90 days', 'webchangedetector-for-mainwp' ); ?></div>
						<div class="item" data-value="all"><?php esc_html_e( 'All time', 'webchangedetector-for-mainwp' ); ?></div>
						<div class="item" data-value="custom"><?php esc_html_e( 'Custom range', 'webchangedetector-for-mainwp' ); ?></div>
					</div>
				</div>
				<span class="wcd-runs-daterange" hidden>
					<input type="date" id="wcd-runs-from" value="<?php echo esc_attr( $wcd_from_initial ); ?>" aria-label="<?php esc_attr_e( 'From', 'webchangedetector-for-mainwp' ); ?>">
					<input type="date" id="wcd-runs-to" value="<?php echo esc_attr( $wcd_to_initial ); ?>" aria-label="<?php esc_attr_e( 'To', 'webchangedetector-for-mainwp' ); ?>">
				</span>
				<div id="wcd-runs-status" class="ui selection multiple dropdown">
					<input type="hidden" value="">
					<i class="dropdown icon"></i>
					<div class="default text"><?php esc_html_e( 'All statuses', 'webchangedetector-for-mainwp' ); ?></div>
					<div class="menu">
						<?php foreach ( $wcd_status_options as $wcd_status_key => $wcd_status_label ) : ?>
							<div class="item" data-value="<?php echo esc_attr( $wcd_status_key ); ?>"><?php echo esc_html( $wcd_status_label ); ?></div>
						<?php endforeach; ?>
					</div>
				</div>
				<div id="wcd-runs-website" class="ui selection multiple dropdown">
					<input type="hidden" value="">
					<i class="dropdown icon"></i>
					<div class="default text"><?php esc_html_e( 'All websites', 'webchangedetector-for-mainwp' ); ?></div>
					<div class="menu">
						<?php foreach ( $wcd_website_options as $wcd_site ) : ?>
							<div class="item" data-value="<?php echo esc_attr( (string) $wcd_site['site_id'] ); ?>"><?php echo esc_html( $wcd_site['name'] ); ?></div>
						<?php endforeach; ?>
					</div>
				</div>
				<div id="wcd-runs-visual" class="ui selection dropdown">
					<input type="hidden" value="0">
					<i class="dropdown icon"></i>
					<div class="default text"><?php esc_html_e( 'All detections', 'webchangedetector-for-mainwp' ); ?></div>
					<div class="menu">
						<div class="item" data-value="0"><?php esc_html_e( 'All detections', 'webchangedetector-for-mainwp' ); ?></div>
						<div class="item" data-value="1"><?php esc_html_e( 'With changes only', 'webchangedetector-for-mainwp' ); ?></div>
					</div>
				</div>
				<button type="button" class="ui tiny green button wcd-runs-apply"><?php esc_html_e( 'Filter', 'webchangedetector-for-mainwp' ); ?></button>
				<button type="button" class="ui tiny basic button wcd-runs-reset"><?php esc_html_e( 'Reset', 'webchangedetector-for-mainwp' ); ?></button>
			</div>
		</div>
	</div>

	<div id="wcd-runs-list" class="wcd-runs-list">
		<div class="wcd-runs-loading"><div class="ui active inline loader"></div></div>
	</div>
	<div id="wcd-runs-pagination" class="wcd-runs-pagination-wrap"></div>
</div>
<?php
do_action( 'mainwp_pagefooter_sites', WCD_MainWP_Runs_View::PAGE_SLUG );
