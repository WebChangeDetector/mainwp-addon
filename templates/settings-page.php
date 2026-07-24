<?php
/**
 * Account tab body: API token (masked, with a reveal toggle), auto-enable toggle and the account
 * plan/credits cards. Enabling sites and per-URL configuration live on the Settings tab.
 *
 * Provided by WCD_MainWP_Site_Settings::render_settings_form():
 *
 * @var string $token                     Stored API token.
 * @var array  $account                   Account details (plan_name, checks_left, checks_limit, ...).
 * @var array  $map                       Stored site map [ id => [ enabled, ... ] ].
 * @var string $wcd_mainwp_pending_email  Signup email while the activation link is unclicked, else ''.
 * @var bool   $wcd_mainwp_just_activated Whether the pending signup was verified on this load.
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
// One-time "connection reset" confirmation, same transient pattern as the save result above.
$wcd_mainwp_reset_flag = WCD_MainWP_Options::get_transient( WCD_MainWP_Site_Settings::RESET_CACHE );
if ( false !== $wcd_mainwp_reset_flag ) {
	WCD_MainWP_Options::delete_transient( WCD_MainWP_Site_Settings::RESET_CACHE );
}
// One-time signup failure notice (message + repopulation data), read once, then clear.
$wcd_mainwp_signup_error = WCD_MainWP_Options::get_transient( WCD_MainWP_Site_Settings::SIGNUP_ERROR_CACHE );
$wcd_mainwp_signup_error = is_array( $wcd_mainwp_signup_error ) ? $wcd_mainwp_signup_error : array();
if ( ! empty( $wcd_mainwp_signup_error ) ) {
	WCD_MainWP_Options::delete_transient( WCD_MainWP_Site_Settings::SIGNUP_ERROR_CACHE );
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
// Used count for the progress bar. Fall back to limit - left when the payload omits checks_done, so
// the bar still reflects usage instead of reading empty.
if ( null === $wcd_mainwp_checks_done && null !== $wcd_mainwp_checks_limit && null !== $wcd_mainwp_checks_left ) {
	$wcd_mainwp_checks_done = max( 0, $wcd_mainwp_checks_limit - $wcd_mainwp_checks_left );
}
$wcd_mainwp_used_pct = ( $wcd_mainwp_checks_limit && $wcd_mainwp_checks_limit > 0 && null !== $wcd_mainwp_checks_done )
	? min( 100, round( ( $wcd_mainwp_checks_done / $wcd_mainwp_checks_limit ) * 100 ) )
	: 0;
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

<?php if ( '1' === $wcd_mainwp_reset_flag ) : ?>
	<div class="ui info message"><p><?php esc_html_e( 'WebChange Detector connection reset. Enter an API token to reconnect.', 'webchangedetector-for-mainwp' ); ?></p></div>
<?php endif; ?>

<?php if ( $wcd_mainwp_just_activated ) : ?>
	<div class="ui positive message"><p><?php esc_html_e( 'Your account is activated. Head to the Settings tab to enable your sites for visual checks.', 'webchangedetector-for-mainwp' ); ?></p></div>
<?php endif; ?>

<?php if ( '' !== $wcd_mainwp_pending_email ) : ?>
	<div class="ui info message">
		<p>
			<?php
			printf(
				/* translators: %s: the signup email address. */
				esc_html__( 'We sent an activation link to %s. Click the link in the email, then reload this page.', 'webchangedetector-for-mainwp' ),
				'<strong>' . esc_html( $wcd_mainwp_pending_email ) . '</strong>'
			);
			?>
		</p>
		<p><a href="<?php echo esc_url( WCD_MainWP_Bootstrap::tab_url( 'account' ) ); ?>"><?php esc_html_e( 'Reload this page', 'webchangedetector-for-mainwp' ); ?></a></p>
	</div>
<?php endif; ?>

<?php if ( '' === $token ) : ?>
	<?php if ( ! empty( $wcd_mainwp_signup_error['message'] ) ) : ?>
		<div class="ui negative message"><p><?php echo esc_html( $wcd_mainwp_signup_error['message'] ); ?></p></div>
	<?php endif; ?>

	<h3 class="ui header"><?php esc_html_e( 'Start your free trial', 'webchangedetector-for-mainwp' ); ?></h3>
	<p class="wcd-muted"><?php esc_html_e( 'Create your free WebChange Detector account right here. No credit card required.', 'webchangedetector-for-mainwp' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcd-signup-form">
		<?php wp_nonce_field( 'wcd_signup' ); ?>
		<input type="hidden" name="action" value="wcd_signup" />
		<div class="ui form">
			<div class="two fields">
				<div class="field">
					<label for="wcd_signup_name_first"><?php esc_html_e( 'First name', 'webchangedetector-for-mainwp' ); ?></label>
					<input type="text" id="wcd_signup_name_first" name="name_first" required
							value="<?php echo esc_attr( $wcd_mainwp_signup_error['name_first'] ?? '' ); ?>" />
				</div>
				<div class="field">
					<label for="wcd_signup_name_last"><?php esc_html_e( 'Last name', 'webchangedetector-for-mainwp' ); ?></label>
					<input type="text" id="wcd_signup_name_last" name="name_last" required
							value="<?php echo esc_attr( $wcd_mainwp_signup_error['name_last'] ?? '' ); ?>" />
				</div>
			</div>
			<div class="field">
				<label for="wcd_signup_email"><?php esc_html_e( 'Email address', 'webchangedetector-for-mainwp' ); ?></label>
				<input type="email" id="wcd_signup_email" name="email" required
						value="<?php echo esc_attr( $wcd_mainwp_signup_error['email'] ?? '' ); ?>" />
			</div>
			<div class="field">
				<label for="wcd_signup_password"><?php esc_html_e( 'Password', 'webchangedetector-for-mainwp' ); ?></label>
				<input type="password" id="wcd_signup_password" name="password" required minlength="6"
						autocomplete="new-password" />
			</div>
			<button type="submit" class="ui primary button"><?php esc_html_e( 'Start your free trial', 'webchangedetector-for-mainwp' ); ?></button>
		</div>
	</form>

	<div class="ui horizontal divider"><?php esc_html_e( 'Already have an account?', 'webchangedetector-for-mainwp' ); ?></div>
<?php endif; ?>

<?php if ( '' !== $token && ! empty( $account ) ) : ?>
	<div class="ui three stackable cards wcd-account-cards">
		<div class="ui card">
			<div class="content">
				<div class="wcd-account-label"><?php esc_html_e( 'Plan', 'webchangedetector-for-mainwp' ); ?></div>
				<div class="wcd-account-value"><?php echo esc_html( $account['plan_name'] ?? '-' ); ?></div>
			</div>
		</div>
		<div class="ui card">
			<div class="content">
				<div class="wcd-account-label"><?php esc_html_e( 'Checks available', 'webchangedetector-for-mainwp' ); ?></div>
				<div class="wcd-account-value">
					<?php echo null !== $wcd_mainwp_checks_left ? esc_html( (string) $wcd_mainwp_checks_left ) : esc_html( '-' ); ?>
				</div>
				<?php if ( null !== $wcd_mainwp_checks_left && null !== $wcd_mainwp_checks_limit ) : ?>
					<div class="wcd-progress"><div class="wcd-progress-bar wcd-w-<?php echo esc_attr( (string) ( (int) ( round( $wcd_mainwp_used_pct / 5 ) * 5 ) ) ); ?>"></div></div>
					<div class="wcd-muted">
						<?php
						printf(
							/* translators: 1: remaining checks, 2: total checks. */
							esc_html__( '%1$s of %2$s checks available', 'webchangedetector-for-mainwp' ),
							esc_html( (string) $wcd_mainwp_checks_left ),
							esc_html( (string) $wcd_mainwp_checks_limit )
						);
						?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<div class="ui card">
			<div class="content">
				<div class="wcd-account-label"><?php esc_html_e( 'Active sites', 'webchangedetector-for-mainwp' ); ?></div>
				<div class="wcd-account-value"><?php echo esc_html( (string) $wcd_mainwp_active_sites ); ?></div>
			</div>
		</div>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcd-token-form wcd-mt">
	<?php wp_nonce_field( 'wcd_save_settings' ); ?>
	<input type="hidden" name="action" value="wcd_save_settings" />
	<div class="ui form">
		<div class="field">
			<label for="wcd_api_token"><?php esc_html_e( 'WebChange Detector API Token', 'webchangedetector-for-mainwp' ); ?></label>
			<div class="ui action input wcd-token-input">
				<input type="password" id="wcd_api_token" name="<?php echo esc_attr( WCD_MainWP_Site_Settings::OPTION_KEY ); ?>"
						value="<?php echo esc_attr( $token ); ?>"
						autocomplete="off" spellcheck="false"
						placeholder="<?php esc_attr_e( 'Enter your WCD API token', 'webchangedetector-for-mainwp' ); ?>" />
				<button type="button" class="ui icon button wcd-token-toggle" aria-label="<?php esc_attr_e( 'Show or hide the API token', 'webchangedetector-for-mainwp' ); ?>">
					<i class="eye icon"></i>
				</button>
			</div>
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

<?php if ( '' !== $token ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcd-reset-form wcd-mt">
		<?php wp_nonce_field( 'wcd_reset_token' ); ?>
		<input type="hidden" name="action" value="wcd_reset_token" />
		<button type="submit" class="ui basic red button wcd-token-reset">
			<i class="unlink icon"></i><?php esc_html_e( 'Reset connection', 'webchangedetector-for-mainwp' ); ?>
		</button>
		<p class="wcd-muted"><?php esc_html_e( 'Disconnects this MainWP dashboard from your WebChange Detector account and forgets all site activation. Your websites and comparisons on WebChange Detector are not deleted; re-enter the token to reconnect.', 'webchangedetector-for-mainwp' ); ?></p>
	</form>
<?php endif; ?>

<?php if ( '' !== $token && empty( $account ) && '' === $wcd_mainwp_pending_email && ! $wcd_mainwp_just_activated ) : ?>
	<div class="ui warning message wcd-mt">
		<p><?php esc_html_e( 'Could not retrieve account data. Please check your API token.', 'webchangedetector-for-mainwp' ); ?></p>
	</div>
<?php endif; ?>
