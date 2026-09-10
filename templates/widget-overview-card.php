<?php
/**
 * Compact "WCD Updates" card inside MainWP's native Updates Overview widget.
 *
 * Rendered by WCD_MainWP_Bootstrap::render_overview_card() via the documented
 * `mainwp_updates_overview_after_update_details` hook, which fires inside the widget's
 * `ui small cards mainwp-cards` grid after the per-type cards, on both the Operations dashboard
 * (bulk scope) and an individual child-site overview (site scope). The card mirrors the native
 * cards' structure and button classes; its CTA is the shared `.wcd-safe-update` trigger, so the
 * existing JS flow (preflight popup, run card, resume lock) drives it unchanged. The
 * `.wcd-run-host` keeps the resume/reopen fallback working even when the add-on's own dashboard
 * widget is hidden.
 *
 * @var string $wcd_mainwp_scope   'site' (individual child-site overview) or 'bulk' (Operations dashboard).
 * @var int    $wcd_mainwp_site_id MainWP site id in site scope (0 in bulk scope).
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ui card">
	<div class="content">
		<div class="header">
			<span class="ui large text"><i class="eye icon"></i> <?php esc_html_e( 'WCD Updates', 'webchangedetector-for-mainwp' ); ?></span>
		</div>
		<div class="description"><?php esc_html_e( 'Update with before/after visual checks.', 'webchangedetector-for-mainwp' ); ?></div>
	</div>
	<div class="extra content">
		<div class="ui grid">
			<div class="center aligned middle aligned column">
				<button type="button" class="ui mini basic green fluid button wcd-safe-update" data-scope="<?php echo esc_attr( $wcd_mainwp_scope ); ?>" data-site-id="<?php echo esc_attr( (string) $wcd_mainwp_site_id ); ?>">
					<?php esc_html_e( 'Update with Checks', 'webchangedetector-for-mainwp' ); ?>
				</button>
			</div>
		</div>
		<?php // Resume/reopen fallback slot; keeps the run reachable even if the WCD widget is hidden. ?>
		<div class="wcd-run-host"></div>
	</div>
</div>
