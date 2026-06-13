<?php
/**
 * WebChange Detector setup hint banner (no API token yet).
 *
 * Rendered in place of the hero banner on the MainWP dashboard overview when no API token is
 * configured (see WCD_MainWP_Bootstrap::render_scoped_banner()). It replaces the previous silent
 * no-op so first-run users get an actionable next step: create an account and add the token.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

$wcd_mainwp_settings_url = admin_url( 'admin.php?page=' . WCD_MainWP_Bootstrap::settings_page_slug() );
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
