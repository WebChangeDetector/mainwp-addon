<?php
/**
 * Safe-update dashboard widget: the entry point that drives the before/after screenshot run around
 * MainWP updates, rendered both as a draggable metabox and as the Visual Checks "Run" tab panel.
 *
 * Sites + the pending-update count are server-rendered (cheap); Pages/Checks load progressively via
 * the banner_stats AJAX, so the MainWP dashboard never blocks on WCD API calls.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the MainWP "WebChange Detector: Updates" widget (dashboard metabox + Visual Checks "Run" tab panel).
 */
class WCD_MainWP_Widget {

	/**
	 * Render the safe-update entry-point metabox body (draggable/hideable dashboard widget).
	 *
	 * The same content as the Updates-page hero banner but in MainWP's native widget chrome, and
	 * scope-aware exactly like the banner (single site on the individual-site overview, bulk on the
	 * Operations dashboard). Carries the same JS-contract hooks (.wcd-safe-update, [data-role],
	 * [data-stats-scope], .wcd-run-host) so assets/js/wcd-mainwp.js drives both surfaces unchanged.
	 *
	 * @return void
	 */
	public static function render_safe_update_metabox(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// No API token yet: show the setup hint (reuses the dashboard no-token template).
		if ( '' === WCD_MainWP_Site_Settings::get_global() ) {
			include WCD_MAINWP_PLUGIN_PATH . 'templates/entry-banner-no-token.php';
			return;
		}

		// Signup activation pending: the metabox cannot be unregistered per-state, so its body shows
		// ONLY the activate-account hint (no stats, no Run CTA).
		if ( ! WCD_MainWP_Site_Settings::is_ready() ) {
			self::render_pending_activation_notice();
			return;
		}

		$wcd_scope = WCD_MainWP_Bootstrap::resolve_banner_scope( false );
		if ( null === $wcd_scope ) {
			// No enabled sites (bulk) or this single site is not enabled for WCD: nudge the user to the
			// Settings tab. get_current_wpid() returns the site id on an individual-site overview
			// (single-site message) and 0 on the Operations dashboard (bulk message).
			$util     = '\\MainWP\\Dashboard\\MainWP_System_Utility';
			$wcd_wpid = ( class_exists( $util ) && method_exists( $util, 'get_current_wpid' ) ) ? (int) $util::get_current_wpid() : 0;
			self::render_not_enabled_notice( $wcd_wpid );
			return;
		}

		$scope               = $wcd_scope['scope'];
		$site_id             = $wcd_scope['site_id'];
		$sites_count         = $wcd_scope['sites_count'];
		$updates_count       = $wcd_scope['updates_count'];
		$updates_sites_count = $wcd_scope['updates_sites_count'];
		$force_enabled       = $wcd_scope['force_enabled'];
		$settings_url        = WCD_MainWP_Bootstrap::tab_url( 'settings' );

		include WCD_MAINWP_PLUGIN_PATH . 'templates/widget-safe-update.php';
	}

	/**
	 * Render the widget heading band ("WCD Updates" title + sub header).
	 *
	 * Shared by the activated widget body (templates/widget-safe-update.php) and the not-activated
	 * notice below, so the WCD branding/wording stays in one place. The activated body also needs the
	 * JS "Updates running" reopen slot; the not-activated state never has a run, so it omits it.
	 *
	 * @param bool $with_reopen_slot Render the `.wcd-run-reopen-slot` JS hook (activated body only).
	 * @return void
	 */
	public static function render_widget_heading( bool $with_reopen_slot = false ): void {
		?>
		<div class="mainwp-widget-header wcd-widget-header">
			<h2 class="ui header handle-drag">
				<?php esc_html_e( 'WCD Updates', 'webchangedetector-for-mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'Capture before/after screenshots around your updates, then compare.', 'webchangedetector-for-mainwp' ); ?></div>
			</h2>
			<?php if ( $with_reopen_slot ) : ?>
				<?php // While a run is active and the popup is closed, JS renders the "Updates running" reopen button here. ?>
				<div class="wcd-run-reopen-slot"></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "site/account not activated for visual checks" info notice, scope-aware on the site id.
	 *
	 * Shared by the safe-update metabox (per-site overview + Operations dashboard) and the Visual Checks
	 * "Run" tab, so the wording stays in one place. A positive site id renders the single-site message;
	 * 0 renders the bulk (account-wide) message. Both link to the Visual Checks Settings tab. Renders the
	 * same WCD heading as the activated widget so the box reads as WebChange Detector, and wraps the
	 * notice in a padded body so it does not touch the metabox edges.
	 *
	 * @param int $site_id MainWP site id (>0 = individual site, 0 = bulk/account-wide).
	 * @return void
	 */
	protected static function render_not_enabled_notice( int $site_id ): void {
		$settings_url = WCD_MainWP_Bootstrap::tab_url( 'settings' );
		$message      = $site_id > 0
			/* translators: %s: Visual Checks Settings tab URL. */
			? __( 'This site is not activated for visual checks yet. Activate it in <a href="%s">Settings</a> to use it in WCD Updates.', 'webchangedetector-for-mainwp' )
			/* translators: %s: Visual Checks Settings tab URL. */
			: __( 'No websites activated yet. Activate a website in <a href="%s">Settings</a> to run visual checks.', 'webchangedetector-for-mainwp' );

		self::render_widget_heading();
		?>
		<div class="wcd-empty-state">
			<div class="ui info message"><p>
				<?php printf( wp_kses_post( $message ), esc_url( $settings_url ) ); ?>
			</p></div>
		</div>
		<?php
	}

	/**
	 * Render the "activate your account first" notice: the widget-body state while a signup
	 * activation is pending. Shows ONLY the hint (no stats, no Run CTA); the Account tab hosts the
	 * full activate-account panel.
	 *
	 * @return void
	 */
	protected static function render_pending_activation_notice(): void {
		$account_url   = WCD_MainWP_Bootstrap::tab_url( 'account' );
		$pending_email = WCD_MainWP_Site_Settings::pending_email();

		self::render_widget_heading();
		?>
		<div class="wcd-empty-state">
			<div class="ui info message"><p>
				<?php
				printf(
					/* translators: 1: the signup email address, 2: Account tab URL. */
					wp_kses_post( __( 'Activate your WebChange Detector account first: we sent an activation link to %1$s. Click it, then reload this page. Details on the <a href="%2$s">Account</a> tab.', 'webchangedetector-for-mainwp' ) ),
					'<strong>' . esc_html( $pending_email ) . '</strong>',
					esc_url( $account_url )
				);
				?>
			</p></div>
		</div>
		<?php
	}

	/**
	 * Render the safe-update entry point as a full-page panel for the Visual Checks "Run" tab.
	 *
	 * Same widget body as render_safe_update_metabox(), but tab-friendly: it owns the token and
	 * empty-state branching so templates/run-view.php stays dumb. On the account-wide Visual Checks
	 * page get_current_wpid() is 0, so resolve_banner_scope() resolves to bulk over all enabled sites.
	 *
	 * @return void
	 */
	public static function render_safe_update_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// No API token yet: point at the Account tab (the dashboard no-token banner does not fit a
		// tab; mirror the other tabs' info notice instead).
		if ( '' === WCD_MainWP_Site_Settings::get_global() ) {
			$settings_url = WCD_MainWP_Bootstrap::tab_url( 'account' );
			?>
			<div class="ui info message"><p>
				<?php
				printf(
					/* translators: %s: Account tab URL. */
					wp_kses_post( __( 'No API token configured. Add one on the <a href="%s">Account</a> tab.', 'webchangedetector-for-mainwp' ) ),
					esc_url( $settings_url )
				);
				?>
			</p></div>
			<?php
			return;
		}

		// Signup activation pending: only the activate-account hint (defensive; the extension page
		// shell already forces the Account tab while pending, so this render is normally unreachable).
		if ( ! WCD_MainWP_Site_Settings::is_ready() ) {
			self::render_pending_activation_notice();
			return;
		}

		$wcd_scope = WCD_MainWP_Bootstrap::resolve_banner_scope( false );
		if ( null === $wcd_scope ) {
			// No sites are enabled for visual checks: nudge the user to the Settings tab. The Run tab is
			// account-wide (get_current_wpid() is 0 here), so this always shows the bulk message.
			self::render_not_enabled_notice( 0 );
			return;
		}

		$scope               = $wcd_scope['scope'];
		$site_id             = $wcd_scope['site_id'];
		$sites_count         = $wcd_scope['sites_count'];
		$updates_count       = $wcd_scope['updates_count'];
		$updates_sites_count = $wcd_scope['updates_sites_count'];
		$force_enabled       = $wcd_scope['force_enabled'];
		$settings_url        = WCD_MainWP_Bootstrap::tab_url( 'settings' );

		include WCD_MAINWP_PLUGIN_PATH . 'templates/widget-safe-update.php';
	}
}
