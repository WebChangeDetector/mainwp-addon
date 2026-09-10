<?php
/**
 * Compact safe-update bar: global Updates page (per-tab) and individual site Updates subpage.
 *
 * Global: rendered above each update tab's table (WordPress, Plugins, Themes, Translations) by
 * WCD_MainWP_Bootstrap::render_updates_bar() via the four documented per-tab hooks, with the tab's
 * update type baked into the buttons. Site subpage: rendered inline into the native actions bar by
 * render_site_updates_bar(); the subpage switches its tabs client-side, so the buttons carry the
 * site id and an EMPTY update type, which the JS resolves from the active tab at click time.
 *
 * Both start the safe-update flow (preflight -> pre -> update -> post -> results) scoped to
 * MainWP's native checkbox selection: "Update Selected with Checks" reads the checked rows
 * client-side, "Update All with Checks" covers every pending update of the type. Both buttons are
 * driven by the shared `.wcd-updates-run` JS handler.
 *
 * @var string $wcd_mainwp_update_type Update type of the hosting tab: core|plugins|themes|translations
 *                                     ('' on the site subpage: resolved client-side at click time).
 * @var int    $wcd_mainwp_site_id     Optional. MainWP site id in site context (0 = global). Default 0.
 * @var bool   $wcd_mainwp_inline      Optional. True renders WITHOUT the mainwp-actions-bar frame (the
 *                                     site subpage hook target is already a column inside the native
 *                                     actions bar). Default false.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

$wcd_mainwp_update_type = isset( $wcd_mainwp_update_type ) ? (string) $wcd_mainwp_update_type : '';
$wcd_mainwp_site_id     = isset( $wcd_mainwp_site_id ) ? (int) $wcd_mainwp_site_id : 0;
$wcd_mainwp_inline      = ! empty( $wcd_mainwp_inline );
?>
<div class="<?php echo $wcd_mainwp_inline ? 'wcd-updates-bar wcd-updates-bar--inline' : 'mainwp-actions-bar wcd-updates-bar'; ?>">
	<span class="wcd-updates-bar__label">
		<i class="eye icon"></i>
		<strong><?php esc_html_e( 'WebChange Detector', 'webchangedetector-for-mainwp' ); ?></strong>
		<span class="wcd-muted"><?php esc_html_e( 'Update with before/after visual checks.', 'webchangedetector-for-mainwp' ); ?></span>
	</span>
	<span>
		<?php // Standalone buttons in MainWP's native convention: Selected = outline (basic), All = filled. ?>
		<button type="button" class="ui mini green basic button wcd-updates-run" data-mode="selected" data-update-type="<?php echo esc_attr( $wcd_mainwp_update_type ); ?>"<?php echo $wcd_mainwp_site_id > 0 ? ' data-site-id="' . esc_attr( (string) $wcd_mainwp_site_id ) . '"' : ''; ?>>
			<?php esc_html_e( 'Update Selected with Checks', 'webchangedetector-for-mainwp' ); ?>
		</button>
		<button type="button" class="ui mini green button wcd-updates-run" data-mode="all" data-update-type="<?php echo esc_attr( $wcd_mainwp_update_type ); ?>"<?php echo $wcd_mainwp_site_id > 0 ? ' data-site-id="' . esc_attr( (string) $wcd_mainwp_site_id ) . '"' : ''; ?>>
			<?php esc_html_e( 'Update All with Checks', 'webchangedetector-for-mainwp' ); ?>
		</button>
	</span>
</div>
<?php // The live run card renders in a modal popup; this host holds the "Updates running" reopen button fallback. ?>
<div class="wcd-run-host"></div>
