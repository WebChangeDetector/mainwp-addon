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

	/** Sites-subpage slug of the "Settings" tab next to Visual Checks (page hook ManageSites + slug). */
	const SUBPAGE_SLUG = 'WcdVisualChecksSettings';
	// The transients carry the add-on's own prefix: the customer WCD plugin uses a plain
	// `wcd_account_details` transient, and both plugins can live on the same dashboard site.
	const ACCOUNT_CACHE   = 'wcd_mainwp_account_details';
	const ERROR_CACHE     = 'wcd_mainwp_token_error';
	const VERIFIED_CACHE  = 'wcd_mainwp_token_verified';
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
		// Auto-enable newly added MainWP child sites for WCD (opt-out via the settings toggle).
		add_action( 'mainwp_added_new_site', array( self::class, 'on_site_added' ), 10, 2 );
	}

	/**
	 * Append the WebChange Detector sub-pages to the MainWP sites tabs: the per-site tab and the
	 * "Settings" page (sites & URL selection), shown next to Visual Checks via the area's own
	 * tabular menu (WCD_MainWP_Runs_View::render_tabs()).
	 *
	 * @param array $sub_pages The existing sub-pages.
	 * @return array The sub-pages with the WCD tabs appended.
	 */
	public static function register_site_tab( array $sub_pages ): array {
		$sub_pages[] = array(
			'title'       => 'WebChange Detector',
			'slug'        => 'WcdVisualRegressionTesting',
			'sitetab'     => true,
			'menu_hidden' => true,
			'callback'    => array( self::class, 'render_site_tab' ),
		);
		$sub_pages[] = array(
			'title'       => __( 'Settings', 'webchangedetector-for-mainwp' ),
			'slug'        => self::SUBPAGE_SLUG,
			'sitetab'     => false,
			'menu_hidden' => true,
			// Explicit href so the Sites page navigation never appends a per-site &id=N.
			'href'        => 'admin.php?page=ManageSites' . self::SUBPAGE_SLUG,
			'callback'    => array( self::class, 'render_sites_settings_page' ),
		);

		return $sub_pages;
	}

	/**
	 * Render the "Settings" tab of the Visual Checks area (sites & URL selection).
	 *
	 * @return void
	 */
	public static function render_sites_settings_page(): void {
		$token = self::get_global();
		$sites = WCD_MainWP_Site_Map::managed_sites();
		$map   = WCD_MainWP_Site_Map::all();

		include WCD_MAINWP_PLUGIN_PATH . 'templates/sites-settings-page.php';
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

		$token = isset( $_POST[ self::OPTION_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_KEY ] ) ) : '';
		WCD_MainWP_Options::set( self::OPTION_KEY, $token );
		WCD_MainWP_Options::delete_transient( self::ACCOUNT_CACHE );

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

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => WCD_MainWP_Bootstrap::settings_page_slug() ),
				admin_url( 'admin.php' )
			)
		);
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
