<?php
/**
 * Settings storage, token verification and the extension settings page.
 *
 * Owns the single dashboard-level API token (`wcd_api_token`) and the cached account details.
 * The settings page renders the design's layout: account/credits card + per-site cards with
 * URL configuration. All site/URL mutations happen via AJAX (see WCD_MainWP_Ajax).
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles settings storage, token verification and the extension settings page.
 */
class WCD_MainWP_Site_Settings {

	const OPTION_KEY = 'wcd_api_token';

	// The transients carry the add-on's own prefix: the customer WCD plugin uses a plain
	// `wcd_account_details` transient, and both plugins can live on the same dashboard site.
	const ACCOUNT_CACHE   = 'wcd_mainwp_account_details';
	const ERROR_CACHE     = 'wcd_mainwp_token_error';
	const VERIFIED_CACHE  = 'wcd_mainwp_token_verified';
	const RESET_CACHE     = 'wcd_mainwp_token_reset';
	const ACCOUNT_TTL     = 300; // 5 minutes.
	const AUTO_ENABLE_KEY = 'wcd_auto_enable_sites';

	/**
	 * Register the settings tab, save handler and auto-enable hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'mainwp_getsubpages_sites', array( self::class, 'register_site_tab' ) );
		add_action( 'admin_post_wcd_save_settings', array( self::class, 'handle_save_settings' ) );
		add_action( 'admin_post_wcd_reset_token', array( self::class, 'handle_reset_token' ) );
		// Auto-enable newly added MainWP child sites for WCD (opt-out via the settings toggle).
		add_action( 'mainwp_added_new_site', array( self::class, 'on_site_added' ), 10, 2 );
	}

	/**
	 * Append the per-site WebChange Detector tab to the MainWP sites tabs. The account-wide Settings
	 * (sites & URL selection) is a tab on the extension page (render_sites_settings_page()), not a
	 * Sites subpage.
	 *
	 * @param array $sub_pages The existing sub-pages.
	 * @return array The sub-pages with the per-site WCD tab appended.
	 */
	public static function register_site_tab( array $sub_pages ): array {
		$sub_pages[] = array(
			'title'       => 'WebChange Detector',
			'slug'        => 'WcdVisualRegressionTesting',
			'sitetab'     => true,
			'menu_hidden' => true,
			'callback'    => array( self::class, 'render_site_tab' ),
		);

		return $sub_pages;
	}

	/**
	 * Render the "Settings" tab body (sites & URL selection) of the extension page. The page shell
	 * renders the chrome + tab switcher around it.
	 *
	 * @return void
	 */
	public static function render_sites_settings_page(): void {
		$token = self::get_global();
		$sites = WCD_MainWP_Site_Map::managed_sites();
		$map   = WCD_MainWP_Site_Map::all();

		include WCD_MAINWP_PLUGIN_PATH . 'templates/sites-settings-page.php';

		// The per-site On-Demand settings modal is one reused instance; the JS fills its values on
		// open. Only included when a token is configured (the template above returns early otherwise,
		// so no enabled sites exist to open it).
		if ( '' !== $token ) {
			include WCD_MAINWP_PLUGIN_PATH . 'templates/site-settings-modal.php';
		}
	}

	/**
	 * Render the per-site WebChange Detector tab.
	 *
	 * @return void
	 */
	public static function render_site_tab(): void {
		include WCD_MAINWP_PLUGIN_PATH . 'templates/site-tab.php';
	}

	/* ─────────────────────────── Token storage ─────────────────────────── */

	/**
	 * The single dashboard-level API token.
	 *
	 * @return string The stored API token, or an empty string.
	 */
	public static function get_global(): string {
		return (string) WCD_MainWP_Options::get( self::OPTION_KEY, '' );
	}

	/**
	 * Whether newly added MainWP sites are auto-enabled for WCD. Defaults to ON until the user saves
	 * the settings form (an unchecked box then persists '0').
	 */
	public static function auto_enable_new_sites(): bool {
		return '0' !== (string) WCD_MainWP_Options::get( self::AUTO_ENABLE_KEY, '1' );
	}

	/**
	 * Auto-enable a freshly added MainWP child site: provision its WCD website + groups, then sync
	 * its URLs best-effort. Gated by the settings toggle + a configured token. Never throws into
	 * MainWP's add-site flow if the WCD API is unavailable.
	 *
	 * @param int $id MainWP site id.
	 * @return void
	 */
	public static function on_site_added( $id ): void {
		$site_id = (int) $id;
		if ( $site_id <= 0 || ! self::auto_enable_new_sites() ) {
			return;
		}

		$token = self::get_global();
		if ( '' === $token ) {
			return;
		}

		try {
			$result = WCD_MainWP_Site_Map::enable_site( $site_id, $token );
			if ( ! empty( $result['ok'] ) ) {
				// URLs also sync via mainwp_site_synced once the child finishes syncing; this is a
				// best-effort head start for content that already exists.
				WCD_MainWP_Url_Sync::sync_site( $site_id, $token );
			}
		} catch ( \Throwable $e ) {
			// A WCD API/SSL failure must not break MainWP adding the site. Surface it through a
			// prefixed action so it can be logged/observed instead of being silently swallowed.
			do_action( 'wcd_mainwp_auto_enable_failed', $site_id, $e );
		}
	}

	/**
	 * Verify a token by calling /account. On success, caches the account; returns the result.
	 *
	 * @param string $token The API token to verify.
	 * @return array Result with 'ok' (bool), 'account' (array) and 'error' (string) keys.
	 */
	public static function verify_token( string $token ): array {
		$response = WCD_MainWP_API::get_account( $token );

		if ( ! $response['ok'] || empty( $response['data'] ) ) {
			$error = $response['error'] ? $response['error'] : __( 'Could not retrieve account data.', 'webchangedetector-for-mainwp' );
			if ( ! empty( $response['status'] ) ) {
				$error .= ' (HTTP ' . (int) $response['status'] . ')';
			}

			return array(
				'ok'      => false,
				'account' => array(),
				'error'   => $error,
			);
		}

		$account = self::unwrap( $response['data'] );
		WCD_MainWP_Options::set_transient( self::ACCOUNT_CACHE, $account, self::ACCOUNT_TTL );

		return array(
			'ok'      => true,
			'account' => $account,
			'error'   => '',
		);
	}

	/**
	 * Return the cached account, fetching + verifying once if needed.
	 *
	 * @param bool $force Whether to bypass the cache and re-fetch.
	 * @return array The account details, or an empty array.
	 */
	public static function get_account( bool $force = false ): array {
		if ( ! $force ) {
			$cached = WCD_MainWP_Options::get_transient( self::ACCOUNT_CACHE );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$token = self::get_global();
		if ( '' === $token ) {
			return array();
		}

		$result = self::verify_token( $token );

		return $result['ok'] ? $result['account'] : array();
	}

	/**
	 * Unwrap a { data: {...} } envelope.
	 *
	 * @param mixed $data The response payload, optionally wrapped in a 'data' key.
	 * @return array The unwrapped array, or an empty array.
	 */
	protected static function unwrap( $data ): array {
		if ( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) {
			return $data['data'];
		}

		return is_array( $data ) ? $data : array();
	}

	/* ──────────────────────────── Save handler ─────────────────────────── */

	/**
	 * Handle the settings form submission: store the token, auto-enable toggle and verify.
	 *
	 * @return void
	 */
	public static function handle_save_settings(): void {
		check_admin_referer( 'wcd_save_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'webchangedetector-for-mainwp' ) );
		}

		$old_token = self::get_global();
		$token     = isset( $_POST[ self::OPTION_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_KEY ] ) ) : '';
		WCD_MainWP_Options::set( self::OPTION_KEY, $token );
		WCD_MainWP_Options::delete_transient( self::ACCOUNT_CACHE );

		// A changed token points the add-on at a different WebChange Detector account. The stored
		// website/group UUIDs belong to the previous account and would 404 on every group call, so
		// forget all provisioning: sites must be re-enabled (re-provisioned) under the new token.
		if ( '' !== $old_token && $old_token !== $token ) {
			WCD_MainWP_Site_Map::reset_all();
		}

		// Auto-enable toggle (checkbox: absent in POST means unchecked = off).
		WCD_MainWP_Options::set( self::AUTO_ENABLE_KEY, isset( $_POST[ self::AUTO_ENABLE_KEY ] ) ? '1' : '0' );

		$flag = '1';
		if ( '' !== $token ) {
			$verify = self::verify_token( $token );
			$flag   = $verify['ok'] ? '1' : '0';
			if ( ! $verify['ok'] ) {
				// Surface the exact reason (HTTP status / SSL / API message) on the settings page.
				WCD_MainWP_Options::set_transient( self::ERROR_CACHE, $verify['error'], 120 );
			} else {
				WCD_MainWP_Options::delete_transient( self::ERROR_CACHE );
			}
		}

		// Pass the one-time verification result via a short transient instead of a query arg, so the
		// settings page does not have to read an (unnonced) $_GET parameter to render its notice.
		WCD_MainWP_Options::set_transient( self::VERIFIED_CACHE, $flag, 30 );

		// Land back on the Account tab so the verification notice + credits card are visible.
		wp_safe_redirect( WCD_MainWP_Bootstrap::tab_url( 'account' ) );
		exit;
	}

	/**
	 * Disconnect this MainWP dashboard from its WebChange Detector account: forget the API token and
	 * all local provisioning. This is local only. The WCD account itself (websites, groups,
	 * comparisons) is left intact, so re-entering the same token re-links to it idempotently
	 * (find_existing_website avoids duplicates). Sites must be re-enabled afterwards.
	 *
	 * @return void
	 */
	public static function reset_connection(): void {
		WCD_MainWP_Options::delete( self::OPTION_KEY );
		WCD_MainWP_Options::delete_transient( self::ACCOUNT_CACHE );
		WCD_MainWP_Options::delete_transient( self::ERROR_CACHE );
		WCD_MainWP_Options::delete_transient( self::VERIFIED_CACHE );
		// Forget every site's website/group UUIDs + enabled flags, and drop any in-flight safe-update
		// run (it is meaningless without the account and would otherwise look abandoned/resumable).
		WCD_MainWP_Site_Map::reset_all();
		WCD_MainWP_Update_Flow::clear_run();
	}

	/**
	 * Handle the Account tab "Reset connection" button (admin-post). Own nonce + capability check,
	 * then disconnects and lands back on the Account tab with a one-time confirmation notice.
	 *
	 * @return void
	 */
	public static function handle_reset_token(): void {
		check_admin_referer( 'wcd_reset_token' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'webchangedetector-for-mainwp' ) );
		}

		self::reset_connection();

		// One-time confirmation flag, read once by the Account tab (same transient pattern as the save).
		WCD_MainWP_Options::set_transient( self::RESET_CACHE, '1', 30 );

		wp_safe_redirect( WCD_MainWP_Bootstrap::tab_url( 'account' ) );
		exit;
	}

	/* ──────────────────────────── Settings page ────────────────────────── */

	/**
	 * Render the extension settings page (account card + per-site URL configuration).
	 *
	 * @return void
	 */
	public static function render_settings_form(): void {
		$token   = self::get_global();
		$account = '' !== $token ? self::get_account() : array();
		$map     = WCD_MainWP_Site_Map::all();

		include WCD_MAINWP_PLUGIN_PATH . 'templates/settings-page.php';
	}
}
