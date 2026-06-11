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
	$wcd_account_url = admin_url( 'admin.php?page=' . WCD_MainWP_Bootstrap::settings_page_slug() );
	WCD_MainWP_Runs_View::render_tabs( 'settings' );
	?>
	<div class="ui bottom attached padded segment">
		<div class="ui info message"><p>
			<?php
			printf(
				/* translators: %s: settings page URL. */
				wp_kses_post( __( 'No API token configured. Add one in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector-for-mainwp' ) ),
				esc_url( $wcd_account_url )
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
	<p class="wcd-muted"><?php esc_html_e( 'Enable a site and pick which URLs are checked. Checks run when you update from the Updates page.', 'webchangedetector-for-mainwp' ); ?></p>

	<?php if ( empty( $sites ) ) : ?>
		<div class="ui message"><p><?php esc_html_e( 'No managed sites found in MainWP.', 'webchangedetector-for-mainwp' ); ?></p></div>
	<?php else : ?>
		<div class="ui segments">
			<?php
			foreach ( $sites as $site ) :
				$sid     = (int) $site['id'];
				$enabled = ! empty( $map[ $sid ]['enabled'] );
				?>
				<div class="ui segment wcd-site" data-site-id="<?php echo esc_attr( (string) $sid ); ?>">
					<div class="wcd-site-head">
						<div class="ui toggle checkbox">
							<input type="checkbox" class="wcd-site-toggle" <?php checked( $enabled ); ?> />
							<label></label>
						</div>
						<div class="wcd-site-meta">
							<div class="wcd-site-name"><?php echo esc_html( $site['name'] ); ?></div>
							<div class="wcd-site-domain"><?php echo esc_html( $site['domain'] ); ?></div>
						</div>
						<div class="wcd-site-urlcount" data-role="urlcount"><?php echo $enabled ? '' : esc_html__( 'Disabled', 'webchangedetector-for-mainwp' ); ?></div>
						<div class="wcd-site-actions">
							<button type="button" class="ui mini button wcd-sync-urls" <?php disabled( ! $enabled ); ?>>
								<?php esc_html_e( 'Sync WP URLs', 'webchangedetector-for-mainwp' ); ?>
							</button>
							<button type="button" class="ui mini button wcd-configure-urls" <?php disabled( ! $enabled ); ?>>
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
