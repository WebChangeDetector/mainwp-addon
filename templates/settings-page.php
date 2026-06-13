<?php
/**
 * Extension settings page: API token, account/credits card, per-site URL configuration.
 *
 * Provided by WCD_MainWP_Site_Settings::render_settings_form():
 *
 * @var string $token   Stored API token.
 * @var array  $account Account details (plan_name, checks_left, checks_limit, ...).
 * @var array  $map     Stored site map [ id => [ enabled, ... ] ].
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

// One-time post-save verification result, handed over via a transient (not a $_GET flag) by
// WCD_MainWP_Site_Settings::handle_save_settings(). Read once, then clear.
$wcd_mainwp_verified_flag = WCD_MainWP_Options::get_transient( WCD_MainWP_Site_Settings::VERIFIED_CACHE );
if ( false !== $wcd_mainwp_verified_flag ) {
	WCD_MainWP_Options::delete_transient( WCD_MainWP_Site_Settings::VERIFIED_CACHE );
}
$wcd_mainwp_active_sites = 0;
foreach ( $map as $wcd_mainwp_entry ) {
	if ( ! empty( $wcd_mainwp_entry['enabled'] ) ) {
		++$wcd_mainwp_active_sites;
	}
}

$wcd_mainwp_checks_left  = isset( $account['checks_left'] ) ? (int) $account['checks_left'] : null;
$wcd_mainwp_checks_limit = isset( $account['checks_limit'] ) ? (int) $account['checks_limit'] : null;
$wcd_mainwp_checks_done  = isset( $account['checks_done'] ) ? (int) $account['checks_done'] : null;
$wcd_mainwp_used_pct     = ( $wcd_mainwp_checks_limit && $wcd_mainwp_checks_limit > 0 ) ? min( 100, round( ( $wcd_mainwp_checks_done / $wcd_mainwp_checks_limit ) * 100 ) ) : 0;
?>

<?php if ( '1' === $wcd_mainwp_verified_flag ) : ?>
	<div class="ui positive message"><p><?php esc_html_e( 'Settings saved and API token verified.', 'webchangedetector-for-mainwp' ); ?></p></div>
	<?php
elseif ( '0' === $wcd_mainwp_verified_flag ) :
	$wcd_mainwp_token_error = WCD_MainWP_Options::get_transient( WCD_MainWP_Site_Settings::ERROR_CACHE );
	?>
	<div class="ui negative message">
		<p><?php esc_html_e( 'Settings saved, but the API token could not be verified.', 'webchangedetector-for-mainwp' ); ?></p>
		<?php if ( is_string( $wcd_mainwp_token_error ) && '' !== $wcd_mainwp_token_error ) : ?>
			<p><strong><?php esc_html_e( 'Reason:', 'webchangedetector-for-mainwp' ); ?></strong> <?php echo esc_html( $wcd_mainwp_token_error ); ?></p>
		<?php endif; ?>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcd-token-form">
	<?php wp_nonce_field( 'wcd_save_settings' ); ?>
	<input type="hidden" name="action" value="wcd_save_settings" />
	<div class="ui form">
		<div class="field">
			<label for="wcd_api_token"><?php esc_html_e( 'WebChange Detector API Token', 'webchangedetector-for-mainwp' ); ?></label>
			<input type="text" id="wcd_api_token" name="<?php echo esc_attr( WCD_MainWP_Site_Settings::OPTION_KEY ); ?>"
					value="<?php echo esc_attr( $token ); ?>"
					placeholder="<?php esc_attr_e( 'Enter your WCD API token', 'webchangedetector-for-mainwp' ); ?>" />
		</div>
		<div class="field">
			<div class="ui checkbox">
				<input type="checkbox" id="wcd_auto_enable_sites" name="<?php echo esc_attr( WCD_MainWP_Site_Settings::AUTO_ENABLE_KEY ); ?>" value="1" <?php checked( WCD_MainWP_Site_Settings::auto_enable_new_sites() ); ?> />
				<label for="wcd_auto_enable_sites"><?php esc_html_e( 'Auto-enable new sites', 'webchangedetector-for-mainwp' ); ?></label>
			</div>
			<p class="wcd-muted"><?php esc_html_e( 'When you add a site in MainWP, enable it for visual checks and sync its URLs automatically. Websites are unlimited on every plan; only the checks you run count against it.', 'webchangedetector-for-mainwp' ); ?></p>
		</div>
		<button type="submit" class="ui primary button"><?php esc_html_e( 'Save & verify', 'webchangedetector-for-mainwp' ); ?></button>
	</div>
</form>

<?php if ( '' === $token ) : ?>
	<div class="ui info message wcd-mt">
		<p><?php echo wp_kses_post( WCD_MainWP_Bootstrap::no_token_hint_html() ); ?></p>
	</div>
	<?php return; ?>
<?php endif; ?>

<?php if ( empty( $account ) ) : ?>
	<div class="ui warning message wcd-mt">
		<p><?php esc_html_e( 'Could not retrieve account data. Please check your API token.', 'webchangedetector-for-mainwp' ); ?></p>
	</div>
	<?php return; ?>
<?php endif; ?>

<div class="ui segment wcd-mt">
	<div class="ui stackable grid">
		<div class="four wide column">
			<div class="wcd-account-label"><?php esc_html_e( 'Plan', 'webchangedetector-for-mainwp' ); ?></div>
			<div class="wcd-account-value"><?php echo esc_html( $account['plan_name'] ?? '-' ); ?></div>
		</div>
		<div class="eight wide middle aligned column">
			<div class="wcd-progress"><div class="wcd-progress-bar wcd-w-<?php echo esc_attr( (string) ( (int) ( round( $wcd_mainwp_used_pct / 5 ) * 5 ) ) ); ?>"></div></div>
			<div class="wcd-account-label">
				<?php
				if ( null !== $wcd_mainwp_checks_left && null !== $wcd_mainwp_checks_limit ) {
					printf(
						/* translators: 1: remaining checks, 2: total checks. */
						esc_html__( '%1$s of %2$s checks available', 'webchangedetector-for-mainwp' ),
						'<b>' . esc_html( (string) $wcd_mainwp_checks_left ) . '</b>',
						esc_html( (string) $wcd_mainwp_checks_limit )
					);
				}
				?>
			</div>
		</div>
		<div class="four wide right aligned column">
			<div class="wcd-account-value"><?php echo esc_html( (string) $wcd_mainwp_active_sites ); ?></div>
			<div class="wcd-account-label"><?php esc_html_e( 'Active sites', 'webchangedetector-for-mainwp' ); ?></div>
		</div>
	</div>
</div>

<h3 class="ui header wcd-mt"><?php esc_html_e( 'Sites & pages', 'webchangedetector-for-mainwp' ); ?></h3>
<p class="wcd-muted"><?php esc_html_e( 'Enable sites and pick which URLs are checked on the Visual Checks Settings page.', 'webchangedetector-for-mainwp' ); ?></p>
<a class="ui basic button" href="<?php echo esc_url( admin_url( 'admin.php?page=ManageSites' . WCD_MainWP_Site_Settings::SUBPAGE_SLUG ) ); ?>">
	<?php esc_html_e( 'Configure sites & URLs', 'webchangedetector-for-mainwp' ); ?>
</a>
