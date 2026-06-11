<?php
/**
 * "Change Detections" overview page (Sites submenu). Renders the filter bar + an empty results
 * container; wcd-mainwp.js detects #wcd-runs and loads the runs via AJAX (no inline JS/CSS).
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

$token = WCD_MainWP_Site_Settings::get_global();

if ( '' === $token ) {
	$settings_url = admin_url( 'admin.php?page=' . WCD_MainWP_Bootstrap::settings_page_slug() );
	?>
	<div class="wcd-runs">
		<div class="ui info message"><p>
			<?php
			printf(
				/* translators: %s: settings page URL. */
				wp_kses_post( __( 'No API token configured. Add one in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector' ) ),
				esc_url( $settings_url )
			);
			?>
		</p></div>
	</div>
	<?php
	return;
}

$status_options  = WCD_MainWP_Runs_View::status_options();
$source_options  = WCD_MainWP_Runs_View::source_options();
$website_options = WCD_MainWP_Runs_View::website_options();
$from_initial    = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
$to_initial      = gmdate( 'Y-m-d' );
$period_label    = WCD_MainWP_Runs_View::period_label( $from_initial, $to_initial );
?>
<div class="wcd-runs" id="wcd-runs" data-from="<?php echo esc_attr( $from_initial ); ?>" data-to="<?php echo esc_attr( $to_initial ); ?>">
	<h2 class="ui header"><?php esc_html_e( 'Change Detections', 'webchangedetector' ); ?></h2>
	<p class="wcd-muted"><?php esc_html_e( 'All runs across your enabled sites. Filter by period, status, type or website, then open a run to see its comparisons.', 'webchangedetector' ); ?></p>

	<div class="ui segment wcd-runs-filterbar">
		<!-- PERIOD -->
		<div class="wcd-filter-pill-wrap" data-filter="period">
			<button type="button" class="wcd-filter-pill" data-pop="period">
				<span class="dashicons dashicons-clock"></span>
				<span class="wcd-pill-label"><?php esc_html_e( 'Period', 'webchangedetector' ); ?></span>
				<span class="wcd-pill-value" id="wcd-runs-period-value"><?php echo esc_html( $period_label ); ?></span>
				<span class="dashicons dashicons-arrow-down-alt2 wcd-pill-caret"></span>
			</button>
			<div class="ui segment wcd-filter-popover">
				<div class="wcd-date-presets">
					<button type="button" class="ui mini button wcd-date-preset" data-days="7">7 <?php esc_html_e( 'days', 'webchangedetector' ); ?></button>
					<button type="button" class="ui mini button wcd-date-preset" data-days="30">30 <?php esc_html_e( 'days', 'webchangedetector' ); ?></button>
					<button type="button" class="ui mini button wcd-date-preset" data-days="90">90 <?php esc_html_e( 'days', 'webchangedetector' ); ?></button>
					<button type="button" class="ui mini button wcd-date-preset" data-days="all"><?php esc_html_e( 'All time', 'webchangedetector' ); ?></button>
				</div>
				<label class="wcd-date-field"><?php esc_html_e( 'From', 'webchangedetector' ); ?>
					<input type="date" id="wcd-runs-from" value="<?php echo esc_attr( $from_initial ); ?>">
				</label>
				<label class="wcd-date-field"><?php esc_html_e( 'To', 'webchangedetector' ); ?>
					<input type="date" id="wcd-runs-to" value="<?php echo esc_attr( $to_initial ); ?>">
				</label>
				<div class="wcd-popover-actions">
					<button type="button" class="ui mini primary button wcd-runs-apply-period"><?php esc_html_e( 'Apply', 'webchangedetector' ); ?></button>
				</div>
			</div>
		</div>

		<!-- STATUS -->
		<div class="wcd-filter-pill-wrap" data-filter="status">
			<button type="button" class="wcd-filter-pill" data-pop="status">
				<span class="dashicons dashicons-flag"></span>
				<span class="wcd-pill-label"><?php esc_html_e( 'Status', 'webchangedetector' ); ?></span>
				<span class="wcd-pill-value" data-default="<?php esc_attr_e( 'All status', 'webchangedetector' ); ?>"><?php esc_html_e( 'All status', 'webchangedetector' ); ?></span>
				<span class="dashicons dashicons-arrow-down-alt2 wcd-pill-caret"></span>
			</button>
			<div class="ui segment wcd-filter-popover">
				<select id="wcd-runs-status" class="wcd-filter-multi" multiple size="4">
					<?php foreach ( $status_options as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<!-- TYPE -->
		<div class="wcd-filter-pill-wrap" data-filter="source">
			<button type="button" class="wcd-filter-pill" data-pop="source">
				<span class="dashicons dashicons-category"></span>
				<span class="wcd-pill-label"><?php esc_html_e( 'Type', 'webchangedetector' ); ?></span>
				<span class="wcd-pill-value" data-default="<?php esc_attr_e( 'All types', 'webchangedetector' ); ?>"><?php esc_html_e( 'All types', 'webchangedetector' ); ?></span>
				<span class="dashicons dashicons-arrow-down-alt2 wcd-pill-caret"></span>
			</button>
			<div class="ui segment wcd-filter-popover">
				<select id="wcd-runs-source" class="wcd-filter-native">
					<?php foreach ( $source_options as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<!-- WEBSITE -->
		<div class="wcd-filter-pill-wrap" data-filter="website">
			<button type="button" class="wcd-filter-pill" data-pop="website">
				<span class="dashicons dashicons-admin-site-alt3"></span>
				<span class="wcd-pill-label"><?php esc_html_e( 'Website', 'webchangedetector' ); ?></span>
				<span class="wcd-pill-value" data-default="<?php esc_attr_e( 'All websites', 'webchangedetector' ); ?>"><?php esc_html_e( 'All websites', 'webchangedetector' ); ?></span>
				<span class="dashicons dashicons-arrow-down-alt2 wcd-pill-caret"></span>
			</button>
			<div class="ui segment wcd-filter-popover">
				<?php if ( empty( $website_options ) ) : ?>
					<p class="wcd-muted"><?php esc_html_e( 'No enabled sites yet.', 'webchangedetector' ); ?></p>
				<?php else : ?>
					<select id="wcd-runs-website" class="wcd-filter-multi" multiple size="6">
						<?php foreach ( $website_options as $site ) : ?>
							<option value="<?php echo esc_attr( (string) $site['site_id'] ); ?>"><?php echo esc_html( $site['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
			</div>
		</div>

		<!-- VISUAL -->
		<div class="wcd-filter-pill-wrap" data-filter="visual">
			<button type="button" class="wcd-filter-pill" data-pop="visual">
				<span class="dashicons dashicons-visibility"></span>
				<span class="wcd-pill-label"><?php esc_html_e( 'Visual', 'webchangedetector' ); ?></span>
				<span class="wcd-pill-value" data-default="<?php esc_attr_e( 'All detections', 'webchangedetector' ); ?>"><?php esc_html_e( 'All detections', 'webchangedetector' ); ?></span>
				<span class="dashicons dashicons-arrow-down-alt2 wcd-pill-caret"></span>
			</button>
			<div class="ui segment wcd-filter-popover">
				<select id="wcd-runs-visual" class="wcd-filter-native">
					<option value="0"><?php esc_html_e( 'All detections', 'webchangedetector' ); ?></option>
					<option value="1"><?php esc_html_e( 'With changes only', 'webchangedetector' ); ?></option>
				</select>
			</div>
		</div>

		<button type="button" class="ui icon button wcd-runs-reset" title="<?php esc_attr_e( 'Reset filters', 'webchangedetector' ); ?>">
			<span class="dashicons dashicons-image-rotate"></span>
		</button>

		<div class="ui buttons wcd-runs-view-toggle">
			<button type="button" class="ui button active wcd-runs-view-btn" data-view="batch"><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Batch', 'webchangedetector' ); ?></button>
			<button type="button" class="ui button wcd-runs-view-btn" data-view="flat"><span class="dashicons dashicons-editor-ul"></span> <?php esc_html_e( 'List', 'webchangedetector' ); ?></button>
		</div>
	</div>

	<div id="wcd-runs-list" class="wcd-runs-list">
		<div class="wcd-runs-loading"><div class="ui active inline loader"></div></div>
	</div>
	<div id="wcd-runs-pagination" class="wcd-runs-pagination-wrap"></div>
</div>
