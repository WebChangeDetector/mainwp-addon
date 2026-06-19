<?php
/**
 * Safe-update dashboard widget body (MainWP-native look).
 *
 * Rendered by WCD_MainWP_Widget::render_safe_update_metabox() as a draggable/hideable MainWP
 * metabox on the Operations dashboard and the individual child-site overview. It is the safe-update
 * entry point (decision -> preflight -> pre -> update -> post -> results), the same flow as the
 * Updates-page hero banner (templates/entry-banner.php) but in MainWP's own widget chrome so it
 * blends in with the native dashboard widgets.
 *
 * Layout mirrors MainWP's own Recent Activity widget so the footer actions stay visible at ANY widget
 * height: a title-only `mainwp-widget-header` (which also holds the "Updates running" reopen button
 * slot), the stat cards in the flex-grow `mainwp-scrolly-overflow` middle, and the actions in a
 * `mainwp-widget-footer`. Because
 * the scroll area is the only flexible part, it shrinks and scrolls when the widget is resized short
 * instead of pushing the footer out of the (overflow:hidden) widget.
 *
 * It carries the same JS-contract hooks as the banner so assets/js/wcd-mainwp.js drives both
 * surfaces unchanged: the `.wcd-safe-update` CTA, the lazy `[data-role=pages|checks]` stats, the
 * `data-stats-scope`/`data-site-id` container the `banner_stats` AJAX reads, and the `.wcd-run-host`
 * (the live run card itself plays out in a locked modal popup; the "Updates running" button in the
 * header slot reopens it while it is closed).
 *
 * Sites + the pending-update count are rendered server-side (cheap). Pages/Checks load progressively
 * via the `banner_stats` AJAX call so the dashboard never blocks on WCD API calls.
 *
 * @var string   $scope               'bulk' (all enabled sites) or 'site' (one site).
 * @var int      $site_id             Site id when scope is 'site' (0 otherwise).
 * @var int      $sites_count         Number of sites in scope.
 * @var int|null $updates_count       Pending MainWP updates in scope (null when unknown).
 * @var int|null $updates_sites_count Sites in scope with pending updates (null when unknown).
 * @var bool     $force_enabled       Keep the CTA enabled even when $updates_count is 0.
 * @var string   $settings_url        Visual Checks Settings tab URL (sites & URLs).
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

$wcd_mainwp_no_updates = empty( $force_enabled ) && null !== $updates_count && 0 === (int) $updates_count;
?>
<?php WCD_MainWP_Widget::render_widget_heading( true ); ?>
<div class="mainwp-scrolly-overflow">
	<div data-stats-scope="<?php echo esc_attr( $scope ); ?>" data-site-id="<?php echo esc_attr( (string) $site_id ); ?>">
		<div class="ui mainwp-cards small three cards">
			<div class="ui card">
				<div class="content">
					<div class="header">
						<span class="ui large text"><i class="globe icon"></i>
							<?php
							if ( null !== $updates_sites_count ) {
								// Only sites with pending updates participate in a run, so show the split.
								echo esc_html( $updates_sites_count . ' / ' . $sites_count );
							} else {
								// Update info unknown (MainWP DB layer unavailable): plain total, no split.
								echo esc_html( (string) $sites_count );
							}
							?>
						</span>
					</div>
					<div class="description"><strong>
						<?php
						echo null !== $updates_sites_count
							? esc_html__( 'Sites with updates', 'webchangedetector-for-mainwp' )
							: esc_html( _n( 'Site', 'Sites', $sites_count, 'webchangedetector-for-mainwp' ) );
						?>
					</strong></div>
				</div>
			</div>
			<div class="ui card">
				<div class="content">
					<div class="header">
						<span class="ui large text"><i class="file outline icon"></i> <span data-role="pages">&hellip;</span></span>
					</div>
					<div class="description"><strong><?php esc_html_e( 'Pages to check', 'webchangedetector-for-mainwp' ); ?></strong></div>
				</div>
			</div>
			<div class="ui card">
				<div class="content">
					<div class="header">
						<span class="ui large text"><i class="chart bar icon"></i> <span data-role="checks">&hellip;</span></span>
					</div>
					<div class="description"><strong><?php esc_html_e( 'Checks needed', 'webchangedetector-for-mainwp' ); ?></strong></div>
				</div>
			</div>
		</div>
	</div>
	<?php // The live run card renders in a modal popup; this host is the banner-fallback reopen-button slot. ?>
	<div class="wcd-run-host" data-scope="<?php echo esc_attr( $scope ); ?>" data-site-id="<?php echo esc_attr( (string) $site_id ); ?>"></div>
</div>
<div class="ui two column grid mainwp-widget-footer">
	<div class="left aligned middle aligned column">
		<a href="<?php echo esc_url( $settings_url ); ?>" class="ui mini basic button"><?php esc_html_e( 'Settings', 'webchangedetector-for-mainwp' ); ?></a>
	</div>
	<div class="right aligned middle aligned column">
		<button type="button" class="ui small green button wcd-safe-update<?php echo $wcd_mainwp_no_updates ? ' disabled' : ''; ?>" data-scope="<?php echo esc_attr( $scope ); ?>" data-site-id="<?php echo esc_attr( (string) $site_id ); ?>" <?php disabled( $wcd_mainwp_no_updates ); ?>>
			<i class="<?php echo $wcd_mainwp_no_updates ? 'ban' : 'play'; ?> icon"></i>
			<?php echo esc_html( $wcd_mainwp_no_updates ? __( 'No updates available', 'webchangedetector-for-mainwp' ) : __( 'Run visual checks & updates', 'webchangedetector-for-mainwp' ) ); ?>
		</button>
	</div>
</div>
