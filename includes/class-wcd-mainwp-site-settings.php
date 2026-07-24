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

	// Trial signup (see handle_signup()). The verify secret exists only for the seconds of the
	// signup request: the API GETs back http://{domain}/?wcd-verify=... during the signup POST and
	// maybe_answer_verify() serves the secret; it is deleted right after the attempt.
	const VERIFY_SECRET_KEY = 'wcd_mainwp_verify_secret';
	// While set (value = the signup email), the account exists but its emailed activation link has
	// not been clicked yet; every /api/v2 call answers 403 ActivateAccount until then.
	const PENDING_KEY = 'wcd_mainwp_activation_pending';
	// One-time signup failure notice: ['message', 'name_first', 'name_last', 'email'] for
	// repopulating the form. Never contains the password.
	const SIGNUP_ERROR_CACHE = 'wcd_mainwp_signup_error';
	const SIGNUP_ERROR_TTL   = 120;

	/**
	 * Register the settings tab, save handler and auto-enable hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'mainwp_getsubpages_sites', array( self::class, 'register_site_tab' ) );
		add_action( 'admin_post_wcd_save_settings', array( self::class, 'handle_save_settings' ) );
		add_action( 'admin_post_wcd_reset_token', array( self::class, 'handle_reset_token' ) );
		add_action( 'admin_post_wcd_signup', array( self::class, 'handle_signup' ) );
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
	 * @return array Result with 'ok' (bool), 'status' (int), 'account' (array) and 'error' (string)
	 *               keys. 'status' is additive: a 403 while an activation is pending means "account
	 *               exists but is not activated yet" (the API's ActivateAccount gate).
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
				'status'  => (int) $response['status'],
				'account' => array(),
				'error'   => $error,
			);
		}

		$account = self::unwrap( $response['data'] );
		WCD_MainWP_Options::set_transient( self::ACCOUNT_CACHE, $account, self::ACCOUNT_TTL );

		return array(
			'ok'      => true,
			'status'  => (int) $response['status'],
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

	/* ──────────────────────────── Trial signup ─────────────────────────── */

	/**
	 * The email address of a signup whose activation link has not been clicked yet, or ''.
	 *
	 * @return string The pending signup email, or an empty string.
	 */
	public static function pending_email(): string {
		return (string) WCD_MainWP_Options::get( self::PENDING_KEY, '' );
	}

	/**
	 * Handle the Account tab signup form (admin-post): create a free trial account on the API.
	 *
	 * Flow: validate the input, store a one-shot verify secret (served to the API's synchronous
	 * domain-verification GET by maybe_answer_verify()), POST the signup, delete the secret. On
	 * success the returned token is stored and the pending-activation flag is set; no /api/v2 call
	 * is made (they all 403 until the emailed activation link is clicked). On failure a one-time
	 * error notice with repopulation data (never the password) is stored.
	 *
	 * @return void
	 */
	public static function handle_signup(): void {
		check_admin_referer( 'wcd_signup' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'webchangedetector-for-mainwp' ) );
		}

		$name_first = isset( $_POST['name_first'] ) ? sanitize_text_field( wp_unslash( $_POST['name_first'] ) ) : '';
		$name_last  = isset( $_POST['name_last'] ) ? sanitize_text_field( wp_unslash( $_POST['name_last'] ) ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		// The password is hashed immediately below and never stored, logged or echoed; sanitizing
		// would silently alter it (trimming, tag stripping) and lock the user out of the account.
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $name_first || '' === $name_last || ! is_email( $email ) || strlen( $password ) < 6 ) {
			self::store_signup_error(
				__( 'Please fill in all fields. The password needs at least 6 characters.', 'webchangedetector-for-mainwp' ),
				$name_first,
				$name_last,
				$email
			);
			wp_safe_redirect( WCD_MainWP_Bootstrap::tab_url( 'account' ) );
			exit;
		}

		// One-shot domain-verification secret: the API GETs http://{domain}/?wcd-verify=... during
		// the signup POST below and compares what maybe_answer_verify() serves.
		$secret = wp_generate_password( 40, false, false );
		WCD_MainWP_Options::set( self::VERIFY_SECRET_KEY, $secret );

		$server_addr = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';

		$result = WCD_MainWP_API::create_trial_account(
			array(
				'email'             => $email,
				'name_first'        => $name_first,
				'name_last'         => $name_last,
				'password'          => wp_hash_password( $password ),
				'validation_string' => $secret,
				'domain'            => WCD_MainWP_Site_Map::normalize_domain( home_url() ),
				'ip'                => '' !== $server_addr ? $server_addr : '127.0.0.1',
				'cms'               => 'wordpress',
			)
		);

		// The verification happens synchronously inside the signup request, so the secret has
		// served its purpose either way.
		WCD_MainWP_Options::delete( self::VERIFY_SECRET_KEY );

		if ( $result['ok'] && is_string( $result['data'] ) ) {
			$old_token = self::get_global();
			WCD_MainWP_Options::set( self::OPTION_KEY, $result['data'] );
			WCD_MainWP_Options::delete_transient( self::ACCOUNT_CACHE );
			// A signup over an existing connection points at a different account; the stored
			// website/group UUIDs would 404 (same rule as handle_save_settings()).
			if ( '' !== $old_token && $old_token !== $result['data'] ) {
				WCD_MainWP_Site_Map::reset_all();
			}
			// Gate the UI on the emailed activation link; every /api/v2 call 403s until then.
			WCD_MainWP_Options::set( self::PENDING_KEY, $email );
		} else {
			$error = '' !== $result['error'] ? $result['error'] : __( 'Could not create the account. Please try again.', 'webchangedetector-for-mainwp' );
			self::store_signup_error( $error, $name_first, $name_last, $email );
		}

		wp_safe_redirect( WCD_MainWP_Bootstrap::tab_url( 'account' ) );
		exit;
	}

	/**
	 * Store the one-time signup failure notice (message + repopulation data, never the password).
	 *
	 * @param string $message    Human-readable error message.
	 * @param string $name_first Submitted first name (repopulation).
	 * @param string $name_last  Submitted last name (repopulation).
	 * @param string $email      Submitted email (repopulation).
	 * @return void
	 */
	protected static function store_signup_error( string $message, string $name_first, string $name_last, string $email ): void {
		WCD_MainWP_Options::set_transient(
			self::SIGNUP_ERROR_CACHE,
			array(
				'message'    => $message,
				'name_first' => $name_first,
				'name_last'  => $name_last,
				'email'      => $email,
			),
			self::SIGNUP_ERROR_TTL
		);
	}

	/**
	 * Front-end responder for the signup domain-verification handshake: while a signup request is
	 * in flight (the only time the verify secret exists), answer the API's GET
	 * http://{domain}/?wcd-verify=... with the JSON-encoded secret. Self-disarming: the secret is
	 * deleted right after the signup attempt, and a stored token disables the responder entirely.
	 * Hooked on WP-core 'init' outside the MAINWP_VERSION gate (the GET hits the public front end).
	 *
	 * @return void
	 */
	public static function maybe_answer_verify(): void {
		if ( is_admin() ) {
			return;
		}

		if ( '' !== self::get_global() ) {
			return;
		}

		$secret = (string) WCD_MainWP_Options::get( self::VERIFY_SECRET_KEY, '' );
		if ( '' === $secret ) {
			return;
		}

		// Read-only presence check of a public, unauthenticated verification ping; no nonce applies.
		$param = filter_input( INPUT_GET, 'wcd-verify', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( empty( $param ) ) {
			return;
		}

		echo wp_json_encode( $secret );
		exit;
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

		// A manually saved token supersedes an in-flight or pending signup.
		WCD_MainWP_Options::delete( self::VERIFY_SECRET_KEY );
		WCD_MainWP_Options::delete( self::PENDING_KEY );

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
		// Forget any signup leftovers too: the verify secret, the pending-activation flag and the
		// one-time signup error notice all belong to the connection being reset.
		WCD_MainWP_Options::delete( self::VERIFY_SECRET_KEY );
		WCD_MainWP_Options::delete( self::PENDING_KEY );
		WCD_MainWP_Options::delete_transient( self::SIGNUP_ERROR_CACHE );
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
	 * While a signup activation is pending, each Account-tab load re-verifies the token once
	 * (user-driven, bounded): success clears the pending flag and shows the "activated" notice, a
	 * 403 keeps the pending notice (the API's ActivateAccount gate), any other failure falls back
	 * to the normal error path.
	 *
	 * @return void
	 */
	public static function render_settings_form(): void {
		$token   = self::get_global();
		$account = array();

		$wcd_mainwp_pending_email  = self::pending_email();
		$wcd_mainwp_just_activated = false;

		if ( '' !== $token && '' !== $wcd_mainwp_pending_email ) {
			$verify = self::verify_token( $token );
			if ( $verify['ok'] ) {
				WCD_MainWP_Options::delete( self::PENDING_KEY );
				$wcd_mainwp_pending_email  = '';
				$wcd_mainwp_just_activated = true;
				$account                   = $verify['account'];
			} elseif ( 403 !== $verify['status'] ) {
				// Not the activation gate: show the normal "could not retrieve account" path.
				$wcd_mainwp_pending_email = '';
			}
		} elseif ( '' !== $token ) {
			$account = self::get_account();
		}

		$map = WCD_MainWP_Site_Map::all();

		include WCD_MAINWP_PLUGIN_PATH . 'templates/settings-page.php';
	}
}
