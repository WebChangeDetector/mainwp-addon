<?php
/**
 * WebChange Detector setup hint banner (no API token yet).
 *
 * Rendered by the Safe Update dashboard widget (WCD_MainWP_Widget::render_safe_update_metabox())
 * when no API token is configured, so first-run users get an actionable next step: create an
 * account and add the token.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

$wcd_mainwp_settings_url = WCD_MainWP_Bootstrap::tab_url( 'account' );
?>
<div class="wcd-hero wcd-hero--setup">
	<div class="wcd-hero__icon"><i class="eye icon"></i></div>
	<div class="wcd-hero__body">
		<span class="wcd-hero__badge"><?php esc_html_e( 'WebChange Detector', 'webchangedetector-for-mainwp' ); ?></span>
		<div class="wcd-hero__title"><?php esc_html_e( 'Connect WebChange Detector to start visual checks', 'webchangedetector-for-mainwp' ); ?></div>
		<div class="wcd-hero__desc"><?php echo wp_kses_post( WCD_MainWP_Bootstrap::no_token_hint_html() ); ?></div>
	</div>
	<a class="ui primary button" href="<?php echo esc_url( $wcd_mainwp_settings_url ); ?>">
		<i class="cog icon"></i>
		<?php esc_html_e( 'Open settings', 'webchangedetector-for-mainwp' ); ?>
	</a>
</div>
