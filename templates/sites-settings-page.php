<?php
/**
 * "Settings" tab of the Visual Checks area: enable sites + pick the URLs to check.
 *
 * Provided by WCD_MainWP_Site_Settings::render_sites_settings_page():
 *
 * @var string $token Stored API token.
 * @var array  $sites Managed MainWP sites [ id => [ id, url, name, domain ] ].
 * @var array  $map   Stored site map [ id => [ enabled, ... ] ].
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

do_action( 'mainwp_pageheader_sites', WCD_MainWP_Site_Settings::SUBPAGE_SLUG );

if ( '' === $token ) {
	$wcd_mainwp_account_url = admin_url( 'admin.php?page=' . WCD_MainWP_Bootstrap::settings_page_slug() );
	WCD_MainWP_Runs_View::render_tabs( 'settings' );
	?>
	<div class="ui bottom attached padded segment">
		<div class="ui info message"><p>
			<?php
			printf(
				/* translators: %s: settings page URL. */
				wp_kses_post( __( 'No API token configured. Add one in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector-for-mainwp' ) ),
				esc_url( $wcd_mainwp_account_url )
			);
			?>
		</p></div>
	</div>
	<?php
	do_action( 'mainwp_pagefooter_sites', WCD_MainWP_Site_Settings::SUBPAGE_SLUG );
	return;
}

WCD_MainWP_Runs_View::render_tabs( 'settings' );
?>
<div class="ui bottom attached padded segment">
	<h3 class="ui header"><?php esc_html_e( 'Sites & pages', 'webchangedetector-for-mainwp' ); ?></h3>
	<p class="wcd-muted"><?php esc_html_e( 'Activate a website and pick which URLs are checked. Checks run when you update from the Updates page.', 'webchangedetector-for-mainwp' ); ?></p>

	<div class="ui info message">
		<p><?php esc_html_e( 'Activate a website to run visual checks automatically when you update it. Websites are unlimited on every plan; only the checks you run count against it.', 'webchangedetector-for-mainwp' ); ?></p>
	</div>

	<?php if ( empty( $sites ) ) : ?>
		<div class="ui message"><p><?php esc_html_e( 'No managed sites found in MainWP.', 'webchangedetector-for-mainwp' ); ?></p></div>
	<?php else : ?>
		<div class="wcd-bulk-bar">
			<button type="button" class="ui primary button wcd-activate-all"><?php esc_html_e( 'Activate checks for all websites', 'webchangedetector-for-mainwp' ); ?></button>
			<span class="wcd-bulk-progress" data-role="bulkprogress"></span>
		</div>
		<div class="ui segments">
			<?php
			foreach ( $sites as $wcd_mainwp_site ) :
				$wcd_mainwp_sid     = (int) $wcd_mainwp_site['id'];
				$wcd_mainwp_enabled = ! empty( $map[ $wcd_mainwp_sid ]['enabled'] );
				?>
				<div class="ui segment wcd-site<?php echo $wcd_mainwp_enabled ? ' wcd-on' : ''; ?>" data-site-id="<?php echo esc_attr( (string) $wcd_mainwp_sid ); ?>">
					<div class="wcd-site-head">
						<div class="ui toggle checkbox">
							<input type="checkbox" class="wcd-site-toggle" <?php checked( $wcd_mainwp_enabled ); ?> />
							<label></label>
						</div>
						<div class="wcd-site-meta">
							<div class="wcd-site-name"><?php echo esc_html( $wcd_mainwp_site['name'] ); ?></div>
							<div class="wcd-site-domain"><?php echo esc_html( $wcd_mainwp_site['domain'] ); ?></div>
						</div>
						<div class="wcd-site-urlcount" data-role="urlcount"><?php echo $wcd_mainwp_enabled ? '' : esc_html__( 'Inactive', 'webchangedetector-for-mainwp' ); ?></div>
						<div class="wcd-site-actions">
							<button type="button" class="ui mini button wcd-configure-urls" <?php disabled( ! $wcd_mainwp_enabled ); ?>>
								<?php esc_html_e( 'Configure URLs', 'webchangedetector-for-mainwp' ); ?>
							</button>
						</div>
					</div>
					<div class="wcd-url-config" data-role="urlconfig" hidden></div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
<?php
do_action( 'mainwp_pagefooter_sites', WCD_MainWP_Site_Settings::SUBPAGE_SLUG );
