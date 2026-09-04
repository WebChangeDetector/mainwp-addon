<?php
/**
 * AJAX endpoints for the settings page and the safe-update orchestration.
 *
 * The browser is the scheduler: it drives each site's state machine (preflight -> pre -> update ->
 * post -> results) by calling these discrete endpoints, so sites advance independently and results
 * stream in. Every handler verifies the nonce AND the manage_options capability.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the settings-page and safe-update AJAX endpoints.
 */
class WCD_MainWP_Ajax {

	const NONCE = 'wcd_mainwp_ajax';

	// Sites per bundled take-screenshot call (batch_per_group). Bounds a single API request's
	// execution window: 50 sites cost ceil(50/10) take calls instead of 50 (same as the webapp).
	const TAKE_CHUNK = 10;

	// URL list page size on the Settings tab.
	const URL_PAGE_SIZE = 50;

	// Valid update-type scopes for the Updates-page flow (preflight/run_update/run_start).
	const UPDATE_TYPES = array( 'core', 'plugins', 'themes', 'translations' );

	// Maps an update type to the item 'kind' used by WCD_MainWP_Update_Flow's item lists.
	const TYPE_KINDS = array(
		'core'         => 'core',
		'plugins'      => 'plugin',
		'themes'       => 'theme',
		'translations' => 'translation',
	);

	/**
	 * Register every AJAX action handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		$actions = array(
			'toggle_site',
			'sync_urls',
			'get_site_urls',
			'get_site_settings',
			'save_site_settings',
			'update_url',
			'update_all_urls',
			'banner_stats',
			'preflight',
			'take_pre',
			'run_update',
			'take_post',
			'poll',
			'results',
			'runs_render',
			'runs_comparisons',
			'run_start',
			'run_status',
			'run_heartbeat',
			'run_resume_post',
			'run_discard',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_wcd_mainwp_' . $action, array( self::class, $action ) );
		}
	}

	/* ──────────────────────────── Guards/helpers ───────────────────────── */

	/**
	 * Reject the request unless the AJAX nonce is valid and the user may manage options.
	 *
	 * The nonce is verified inline in each handler (via check_ajax_referer) so static analysis
	 * can see it in the handler scope; this helper centralises the capability check and the
	 * shared 403 response.
	 *
	 * @param bool $nonce_valid Result of the in-handler check_ajax_referer() call.
	 * @return void
	 */
	protected static function verify( bool $nonce_valid ): void {
		if ( ! $nonce_valid || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webchangedetector-for-mainwp' ) ), 403 );
		}
	}

	/**
	 * Return the global WCD API token.
	 *
	 * @return string
	 */
	protected static function token(): string {
		return WCD_MainWP_Site_Settings::get_global();
	}

	/**
	 * Resolve the requested site id from the POST payload after verifying the nonce.
	 *
	 * @return int
	 */
	protected static function site_id(): int {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			return 0;
		}

		return isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
	}

	/**
	 * Resolve the optional update-type scope from the POST payload (Updates-page flow).
	 *
	 * '' when the request carries no update_type (the legacy whole-site flow). An invalid value is
	 * rejected hard instead of silently falling back to the legacy path, which would escalate a
	 * scoped request to an all-types update.
	 *
	 * @return string One of UPDATE_TYPES, or '' when absent.
	 */
	protected static function update_type_from_request(): string {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! isset( $_POST['update_type'] ) ) {
			return '';
		}

		$type = sanitize_text_field( wp_unslash( $_POST['update_type'] ) );
		if ( ! in_array( $type, self::UPDATE_TYPES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid update type.', 'webchangedetector-for-mainwp' ) ) );
		}

		return $type;
	}

	/**
	 * Resolve the optional selection map from the POST payload: [ site_id => item slugs[] ].
	 *
	 * Shape: selection[<site_id>][] = slug. Site ids are cast to int, every slug is sanitized;
	 * empty slug entries are dropped (the core tab has no slugs, so its sites arrive with one
	 * empty placeholder entry that only keeps the site key present).
	 *
	 * @return array Map of site id => list of slugs (possibly empty).
	 */
	protected static function selection_from_request(): array {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! isset( $_POST['selection'] ) || ! is_array( $_POST['selection'] ) ) {
			return array();
		}

		$selection = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested array; every leaf is sanitized in the loop below.
		foreach ( wp_unslash( $_POST['selection'] ) as $site_id => $slugs ) {
			$site_id = (int) $site_id;
			if ( $site_id < 1 ) {
				continue;
			}
			$selection[ $site_id ] = is_array( $slugs )
				? array_values( array_filter( array_map( 'sanitize_text_field', $slugs ) ) )
				: array();
		}

		return $selection;
	}

	/**
	 * Resolve the optional item slugs list from the POST payload (run_update).
	 *
	 * @return string[] Sanitized, non-empty slugs.
	 */
	protected static function slugs_from_request(): array {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! isset( $_POST['slugs'] ) || ! is_array( $_POST['slugs'] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['slugs'] ) ) ) );
	}

	/**
	 * Filter a site's pending-update items down to one update type and (optionally) a slug set.
	 *
	 * @param array  $items       Item list from WCD_MainWP_Update_Flow::update_items_for_site().
	 * @param string $update_type One of UPDATE_TYPES.
	 * @param array  $slugs       Item slugs to keep; empty = every item of the type (non-core only;
	 *                            core items never carry a slug).
	 * @return array Filtered items.
	 */
	protected static function filter_update_items( array $items, string $update_type, array $slugs ): array {
		$kind = self::TYPE_KINDS[ $update_type ] ?? '';

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $kind, $slugs ) {
					if ( ( $item['kind'] ?? '' ) !== $kind ) {
						return false;
					}

					return 'core' === $kind || empty( $slugs ) || in_array( (string) ( $item['slug'] ?? '' ), $slugs, true );
				}
			)
		);
	}

	/**
	 * Unwrap a { data: ... } envelope from an API payload.
	 *
	 * @param mixed $data The API payload, possibly wrapped in a data envelope.
	 * @return mixed
	 */
	protected static function unwrap( $data ) {
		if ( is_array( $data ) && isset( $data['data'] ) ) {
			return $data['data'];
		}

		return $data;
	}

	/**
	 * Self-heal a stale group mapping. When the API answers a group call with 404, the stored UUID
	 * points at a deleted group or one from a different account (e.g. after an API token switch).
	 * Forget this site's provisioning so re-enabling it re-creates fresh WCD resources, and send a
	 * clear message instead of the raw "No query results" API error. No-op (returns) for any other
	 * failure, so the caller's generic error handling still runs.
	 *
	 * @param int   $site_id  MainWP site id.
	 * @param array $response Normalized API result (expects an integer 'status').
	 * @return void
	 */
	protected static function reject_if_group_gone( int $site_id, array $response ): void {
		if ( 404 !== (int) ( $response['status'] ?? 0 ) ) {
			return;
		}

		WCD_MainWP_Site_Map::reset_site( $site_id );
		wp_send_json_error(
			array(
				'message'  => __( 'This site is no longer linked to your WebChange Detector account. Enable it again to re-sync.', 'webchangedetector-for-mainwp' ),
				'unlinked' => true,
			)
		);
	}

	/* ─────────────────────────── Settings actions ──────────────────────── */

	/**
	 * Enable or disable a managed site and auto-sync its URLs on enable.
	 *
	 * @return void
	 */
	public static function toggle_site(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id = self::site_id();
		$enabled = ! empty( $_POST['enabled'] ) && 'false' !== $_POST['enabled'];

		if ( ! $enabled ) {
			WCD_MainWP_Site_Map::disable_site( $site_id );
			wp_send_json_success( array( 'enabled' => false ) );
		}

		$result = WCD_MainWP_Site_Map::enable_site( $site_id, self::token() );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		// Auto-sync the site's URLs so the group is populated immediately (best effort; do not
		// fail the enable if there is nothing to sync yet).
		$sync = WCD_MainWP_Url_Sync::sync_site( $site_id, self::token() );

		wp_send_json_success(
			array(
				'enabled'      => true,
				'synced'       => $sync['ok'],
				'sync_message' => $sync['ok'] ? '' : $sync['error'],
			)
		);
	}

	/**
	 * Sync a single site's URLs into its WCD group.
	 *
	 * @return void
	 */
	public static function sync_urls(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$result = WCD_MainWP_Url_Sync::sync_site( self::site_id(), self::token() );

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'URL sync started.', 'webchangedetector-for-mainwp' ),
				'count'   => $result['count'],
			)
		);
	}

	/**
	 * Build the group-urls API filters from the request.
	 *
	 * No `page` param means legacy mode: the full list in one call (the preflight popup depends on
	 * this shape). With `page`, the Settings tab gets a paginated slice, selected URLs first, with
	 * an optional title/url search.
	 *
	 * @return array
	 */
	protected static function url_list_filters(): array {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! isset( $_POST['page'] ) ) {
			return array( 'per_page' => 1000 );
		}

		$filters = array(
			'page'     => max( 1, (int) $_POST['page'] ),
			'per_page' => self::URL_PAGE_SIZE,
			'sorted'   => 'selected',
		);

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		if ( '' !== $search ) {
			$filters['search'] = $search;
		}

		return $filters;
	}

	/**
	 * Return a site's group URLs with desktop/mobile selection state and active counts.
	 *
	 * Legacy mode (no `page` param) returns the full list with per-list counts; paginated mode adds
	 * a `meta` block and group-wide counts from the API meta (with a search, `total` is the
	 * filtered total the pager needs).
	 *
	 * @return void
	 */
	public static function get_site_urls(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id   = self::site_id();
		$paginated = isset( $_POST['page'] );
		$group_id  = WCD_MainWP_Site_Map::get_manual_group( $site_id );
		if ( '' === $group_id ) {
			wp_send_json_error( array( 'message' => __( 'Site is not enabled.', 'webchangedetector-for-mainwp' ) ) );
		}

		$response = WCD_MainWP_API::get_group_urls( $group_id, self::token(), self::url_list_filters() );
		if ( ! $response['ok'] ) {
			self::reject_if_group_gone( $site_id, $response );
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		$urls   = WCD_MainWP_Update_Flow::extract_urls( $response['data'] );
		$active = 0;
		$clean  = array();
		foreach ( $urls as $url ) {
			$desktop = ! empty( $url['desktop'] );
			$mobile  = ! empty( $url['mobile'] );
			if ( $desktop || $mobile ) {
				++$active;
			}
			$clean[] = array(
				'id'      => $url['id'] ?? '',
				'url'     => $url['url'] ?? '',
				'title'   => $url['html_title'] ?? '',
				'desktop' => $desktop,
				'mobile'  => $mobile,
			);
		}

		$payload = array(
			'urls'   => $clean,
			'active' => $active,
			'total'  => count( $clean ),
		);

		if ( $paginated ) {
			$meta              = is_array( $response['data'] ) && isset( $response['data']['meta'] ) ? (array) $response['data']['meta'] : array();
			$payload['active'] = (int) ( $meta['selected_urls_count'] ?? $active );
			$payload['total']  = (int) ( $meta['total'] ?? count( $clean ) );
			$payload['meta']   = array(
				'current_page' => (int) ( $meta['current_page'] ?? 1 ),
				'last_page'    => (int) ( $meta['last_page'] ?? 1 ),
				'per_page'     => self::URL_PAGE_SIZE,
			);
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Read a site's On-Demand (manual) check settings to prefill the per-site settings modal. One
	 * site per request, one GET on the manual group. The password is never returned by the API; the
	 * `has_basic_auth` boolean signals whether a password is stored so the modal can show a "set"
	 * state with a blank field.
	 *
	 * @return void
	 */
	public static function get_site_settings(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id = self::site_id();

		if ( ! $site_id || ! WCD_MainWP_Site_Map::is_enabled( $site_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Site is not enabled.', 'webchangedetector-for-mainwp' ) ) );
		}

		$group_id = WCD_MainWP_Site_Map::get_manual_group( $site_id );
		if ( '' === $group_id ) {
			wp_send_json_error( array( 'message' => __( 'Site is not enabled.', 'webchangedetector-for-mainwp' ) ) );
		}

		$response = WCD_MainWP_API::get_group( $group_id, self::token() );
		if ( ! $response['ok'] ) {
			self::reject_if_group_gone( $site_id, $response );
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		$group = self::unwrap( $response['data'] );
		$group = is_array( $group ) ? $group : array();

		// off => the API stores 'none' (or empty for older rows); any other value (e.g. 'static') is on.
		// The add-on exposes a binary toggle (none/static), so a group set to 'residential' elsewhere
		// (e.g. the webapp) prefills as on and saving writes 'static'. That downgrade is intentional
		// under this binary contract, not a bug.
		$proxy_type = (string) ( $group['proxy_type'] ?? 'none' );

		wp_send_json_success(
			array(
				// The region the user picked is owned by the site map; the API stores the resolved
				// value, so prefer the API value and fall back to the stored choice.
				'screenshot_region' => WCD_MainWP_Site_Map::sanitize_region( $group['screenshot_region'] ?? WCD_MainWP_Site_Map::get_region( $site_id ) ),
				'default_desktop'   => ! empty( $group['default_desktop'] ),
				'default_mobile'    => ! empty( $group['default_mobile'] ),
				'threshold'         => isset( $group['threshold'] ) ? (float) $group['threshold'] : 0,
				// The API returns the alert recipients as a comma-separated string.
				'alert_emails'      => (string) ( $group['alert_emails'] ?? '' ),
				'basic_auth_user'   => (string) ( $group['basic_auth_user'] ?? '' ),
				// Password is never returned; this flag drives the "password is set" hint.
				'has_basic_auth'    => ! empty( $group['has_basic_auth'] ),
				'proxy_on'          => '' !== $proxy_type && 'none' !== $proxy_type,
				'screenshot_delay'  => isset( $group['screenshot_delay'] ) && '' !== $group['screenshot_delay'] ? (int) $group['screenshot_delay'] : '',
				'css'               => (string) ( $group['css'] ?? '' ),
				'js'                => (string) ( $group['js'] ?? '' ),
			)
		);
	}

	/**
	 * Save a site's On-Demand (manual) check settings from the per-site settings modal. The capture
	 * settings are written to the MANUAL group; the screenshot region is written to BOTH groups
	 * (manual + auto) and persisted in the site map (the API owns sibling-sync + resolving 'auto'
	 * to a concrete region, so no loop or poll here). One site per request.
	 *
	 * Per-field contract (see the API's GroupRequest "sometimes" rules):
	 * - A field is only written when present in the request, so we omit a key to leave it unchanged.
	 * - basic_auth_password: present => write it (a non-empty value SETs, an empty string CLEARs);
	 *   absent => leave the stored password unchanged. The JS owns the dots-sentinel UX and only
	 *   sends this key when the user wants a change, so this endpoint stays contract-simple. There
	 *   is no password_action field on the API.
	 * - proxy_type: 'static' when on, 'none' when off (never '').
	 * - screenshot_delay: integer clamped 7-60, or omitted when the field is left empty.
	 * - alert_emails: present => written as an array (an empty field sends [] and clears the list,
	 *   so no alert mails are sent); absent => leave unchanged.
	 *
	 * @return void
	 */
	public static function save_site_settings(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id = self::site_id();

		if ( ! $site_id || ! WCD_MainWP_Site_Map::is_enabled( $site_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Site is not enabled.', 'webchangedetector-for-mainwp' ) ) );
		}

		$entry     = WCD_MainWP_Site_Map::for_site( $site_id );
		$manual_id = (string) ( $entry['manual_group_uuid'] ?? '' );
		if ( '' === $manual_id ) {
			wp_send_json_error( array( 'message' => __( 'Site is not enabled.', 'webchangedetector-for-mainwp' ) ) );
		}

		$region = isset( $_POST['screenshot_region'] )
			? WCD_MainWP_Site_Map::sanitize_region( sanitize_text_field( wp_unslash( $_POST['screenshot_region'] ) ) )
			: WCD_MainWP_Site_Map::DEFAULT_REGION;

		// Capture settings written to the manual group. The region is included here AND mirrored to
		// the auto group below (so both detection groups carry the choice).
		$fields = array(
			'screenshot_region' => $region,
			'default_desktop'   => ! empty( $_POST['default_desktop'] ) && 'false' !== $_POST['default_desktop'],
			'default_mobile'    => ! empty( $_POST['default_mobile'] ) && 'false' !== $_POST['default_mobile'],
			'basic_auth_user'   => isset( $_POST['basic_auth_user'] ) ? sanitize_text_field( wp_unslash( $_POST['basic_auth_user'] ) ) : '',
			// off => 'none', on => 'static'. Never send '' (the API enum is none|static|residential).
			'proxy_type'        => ( ! empty( $_POST['proxy_on'] ) && 'false' !== $_POST['proxy_on'] ) ? 'static' : 'none',
		);

		if ( isset( $_POST['threshold'] ) ) {
			$fields['threshold'] = (float) sanitize_text_field( wp_unslash( $_POST['threshold'] ) );
		}

		// alert_emails: comma-separated field => array (trimmed, empty entries dropped). An empty
		// field sends [] so the API clears the list; the API validates each entry as an email.
		if ( isset( $_POST['alert_emails'] ) ) {
			$emails                 = explode( ',', sanitize_text_field( wp_unslash( $_POST['alert_emails'] ) ) );
			$fields['alert_emails'] = array_values( array_filter( array_map( 'trim', $emails ) ) );
		}

		// screenshot_delay: empty leaves it unchanged (omit the key); a value is clamped 7-60.
		$delay_raw = isset( $_POST['screenshot_delay'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['screenshot_delay'] ) ) ) : '';
		if ( '' !== $delay_raw ) {
			$fields['screenshot_delay'] = max( 7, min( 60, (int) $delay_raw ) );
		}

		// css / js are stored verbatim (the API/webapp keep them as-is). Only unslash; do not strip
		// or escape the user's stylesheet/script.
		if ( isset( $_POST['css'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw CSS stored verbatim; matches the API/webapp contract.
			$fields['css'] = (string) wp_unslash( $_POST['css'] );
		}
		if ( isset( $_POST['js'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JS stored verbatim; matches the API/webapp contract.
			$fields['js'] = (string) wp_unslash( $_POST['js'] );
		}

		// Basic Auth password: the JS owns the dots-sentinel UX and sends the key ONLY when it wants
		// a change, so the API contract stays simple: present => write it (an empty string clears it),
		// absent => leave the stored password unchanged.
		if ( isset( $_POST['basic_auth_password'] ) ) {
			$fields['basic_auth_password'] = sanitize_text_field( wp_unslash( $_POST['basic_auth_password'] ) );
		}

		$token = self::token();

		$response = WCD_MainWP_API::update_group( $manual_id, $fields, $token );
		if ( ! $response['ok'] ) {
			self::reject_if_group_gone( $site_id, $response );
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		// Region to the sibling (auto) group too, so both groups carry the choice (same pattern the
		// old set_region used). The API mirrors + resolves 'auto' itself, so this is the only write.
		$auto_id = (string) ( $entry['auto_group_uuid'] ?? '' );
		if ( '' !== $auto_id ) {
			$auto = WCD_MainWP_API::update_group( $auto_id, array( 'screenshot_region' => $region ), $token );
			if ( ! $auto['ok'] ) {
				self::reject_if_group_gone( $site_id, $auto );
				wp_send_json_error( array( 'message' => $auto['error'] ) );
			}
		}

		WCD_MainWP_Site_Map::set_region( $site_id, $region );

		wp_send_json_success(
			array(
				'screenshot_region' => $region,
				// Additive: reflects only what this save changed about the stored password. A password
				// key was sent => set (non-empty) or cleared (empty); when the key was absent (left
				// unchanged) the prior state is unknown without re-reading, so this is null and the
				// next modal open re-reads the authoritative has_basic_auth from the API.
				'has_basic_auth'    => array_key_exists( 'basic_auth_password', $fields ) ? ( '' !== $fields['basic_auth_password'] ) : null,
			)
		);
	}

	/**
	 * Update the desktop/mobile selection for a single URL in a site's group.
	 *
	 * @return void
	 */
	public static function update_url(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id  = self::site_id();
		$url_id   = isset( $_POST['url_id'] ) ? sanitize_text_field( wp_unslash( $_POST['url_id'] ) ) : '';
		$desktop  = ! empty( $_POST['desktop'] ) && 'false' !== $_POST['desktop'];
		$mobile   = ! empty( $_POST['mobile'] ) && 'false' !== $_POST['mobile'];
		$group_id = WCD_MainWP_Site_Map::get_manual_group( $site_id );

		if ( '' === $group_id || '' === $url_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing group or URL.', 'webchangedetector-for-mainwp' ) ) );
		}

		$response = WCD_MainWP_API::update_url_in_group(
			$group_id,
			$url_id,
			array(
				'desktop' => $desktop,
				'mobile'  => $mobile,
			),
			self::token()
		);
		if ( ! $response['ok'] ) {
			self::reject_if_group_gone( $site_id, $response );
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		wp_send_json_success(
			array(
				'desktop' => $desktop,
				'mobile'  => $mobile,
			)
		);
	}

	/**
	 * Enable or disable one viewport (desktop|mobile) for ALL URLs of a site's group.
	 *
	 * One API call (`/urls/select-all`) toggles the whole device column server-side in a single SQL
	 * UPDATE, so this stays fast regardless of how many URLs the site has. Only the toggled device
	 * changes; the other viewport is left untouched. Re-running select-all is the idempotent retry.
	 *
	 * @return void
	 */
	public static function update_all_urls(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id  = self::site_id();
		$device   = isset( $_POST['device'] ) ? sanitize_text_field( wp_unslash( $_POST['device'] ) ) : '';
		$enabled  = ! empty( $_POST['enabled'] ) && 'false' !== $_POST['enabled'];
		$group_id = WCD_MainWP_Site_Map::get_manual_group( $site_id );

		if ( '' === $group_id || ! in_array( $device, array( 'desktop', 'mobile' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing group or device.', 'webchangedetector-for-mainwp' ) ) );
		}

		$response = WCD_MainWP_API::select_all_urls_in_group( $group_id, $device, $enabled, self::token() );
		if ( ! $response['ok'] ) {
			self::reject_if_group_gone( $site_id, $response );
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		$counts = is_array( $response['data'] ) ? $response['data'] : array();
		wp_send_json_success(
			array(
				'active' => (int) ( $counts['selected_urls_count'] ?? 0 ),
				'checks' => (int) ( $counts['selected_checks_count'] ?? 0 ),
			)
		);
	}

	/**
	 * Progressive stats for the hero banner: total Pages (URLs) and Checks (selected viewports)
	 * across the scope. Loaded after the dashboard renders so WCD API calls never block it.
	 *
	 * @return void
	 */
	public static function banner_stats(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		$scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'bulk';
		if ( 'site' === $scope ) {
			$site_id = self::site_id();
			$ids     = ( $site_id && WCD_MainWP_Site_Map::is_enabled( $site_id ) ) ? array( $site_id ) : array();
		} else {
			$ids = self::scope_site_ids();
		}

		// The run only covers sites with pending updates, so the banner's Pages/Checks reflect the
		// same set. A null map (MainWP DB layer unavailable) fails open: no filtering. A missing
		// key (site row unresolvable) fails closed, matching the preflight: MainWP cannot update
		// that site anyway.
		$scope_count = count( $ids );
		$by_site     = WCD_MainWP_Update_Flow::pending_updates_by_site( $ids );
		if ( is_array( $by_site ) ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $sid ) use ( $by_site ) {
						return isset( $by_site[ (int) $sid ] ) && $by_site[ (int) $sid ] > 0;
					}
				)
			);
		}

		$token  = self::token();
		$pages  = 0;
		$checks = 0;
		foreach ( $ids as $sid ) {
			$group_id = WCD_MainWP_Site_Map::get_manual_group( (int) $sid );
			if ( '' === $group_id ) {
				continue;
			}
			// The API aggregates the SELECTED (active) counts group-wide in `meta`, independent of
			// pagination: `selected_urls_count` = URLs with desktop or mobile enabled (= Pages),
			// `selected_checks_count` = total selected viewports (= Checks). So we only need the meta,
			// not the URL list: per_page=1 keeps the payload tiny instead of pulling every URL.
			$response = WCD_MainWP_API::get_group_urls( $group_id, $token, array( 'per_page' => 1 ) );
			if ( ! $response['ok'] || ! is_array( $response['data'] ) ) {
				continue;
			}
			$meta    = isset( $response['data']['meta'] ) && is_array( $response['data']['meta'] ) ? $response['data']['meta'] : array();
			$pages  += isset( $meta['selected_urls_count'] ) ? (int) $meta['selected_urls_count'] : 0;
			$checks += isset( $meta['selected_checks_count'] ) ? (int) $meta['selected_checks_count'] : 0;
		}

		wp_send_json_success(
			array(
				// `sites` keeps its original meaning (scope size); the filtered count is additive.
				'sites'          => $scope_count,
				'eligible_sites' => count( $ids ),
				'pages'          => $pages,
				'checks'         => $checks,
			)
		);
	}

	/* ───────────────────────── Orchestration actions ───────────────────── */

	/**
	 * Build the preflight summary (sites, pages, checks, pending updates, credits) for a run.
	 *
	 * Legacy shape (no update_type): the enabled-sites scope from site_ids[]/site_id/all-enabled,
	 * unchanged. With the additive Updates-page fields the run is scoped to one update type and
	 * either an explicit checkbox selection map (selection[<site_id>][] = slug) or mode=all (the
	 * server derives the site set from the pending-update columns). The selection deliberately
	 * includes sites NOT enabled for visual checks (they are updated without checks; the popup
	 * badges them via the additive per-site wcd_enabled flag), so the scoped path validates
	 * against the managed-sites list, not the enabled one.
	 *
	 * @return void
	 */
	public static function preflight(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$token   = self::token();
		$managed = WCD_MainWP_Site_Map::managed_sites();

		$update_type = self::update_type_from_request();
		$mode        = '';
		$selection   = array();
		if ( '' !== $update_type ) {
			// Nonce verified above (update_type_from_request re-checked it too).
			$mode        = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';
			$managed_ids = array_map( 'intval', array_keys( $managed ) );
			if ( 'all' === $mode ) {
				// Every managed site; the per-site item filter below drops the ones without
				// pending updates of this type. No slug filter (empty slugs = all of the type).
				$scope = $managed_ids;
			} else {
				$selection = self::selection_from_request();
				$scope     = array_values( array_intersect( array_map( 'intval', array_keys( $selection ) ), $managed_ids ) );
			}
		} else {
			$scope = self::scope_site_ids();
		}

		$sites         = array();
		$checks        = 0;
		$pages         = 0;
		$total_updates = 0;
		// When MainWP's DB layer is unavailable the per-site update info is unknown; every site
		// then counts as eligible (fail open to the unfiltered run) instead of being skipped.
		$counts_known = WCD_MainWP_Update_Flow::updates_info_available();
		foreach ( $scope as $site_id ) {
			$group_id    = WCD_MainWP_Site_Map::get_manual_group( $site_id );
			$wcd_enabled = '' !== $group_id;
			if ( '' === $update_type && ! $wcd_enabled ) {
				// Legacy scope only ever covers enabled sites; keep that behavior unchanged.
				continue;
			}

			$items = WCD_MainWP_Update_Flow::update_items_for_site( $site_id );
			if ( '' !== $update_type ) {
				$items = self::filter_update_items( $items, $update_type, 'all' === $mode ? array() : ( $selection[ $site_id ] ?? array() ) );
				if ( 'all' === $mode && $counts_known && 0 === count( $items ) ) {
					// mode=all only covers sites with pending updates of this type.
					continue;
				}
			}
			$has_updates = ! $counts_known || count( $items ) > 0;

			$site_pages  = 0;
			$site_checks = 0;
			$meta_error  = false;
			if ( $has_updates && $wcd_enabled ) {
				// Meta-only fetch (same as banner_stats): the API aggregates the SELECTED counts
				// group-wide in `meta`, so per_page=1 keeps the payload tiny. The actual URL list
				// lazy-loads in the preflight when a site row is expanded (get_site_urls). Sites
				// without pending updates skip the fetch (they are not part of the run), and sites
				// not enabled for visual checks never get a group call (checks stay 0).
				$response    = WCD_MainWP_API::get_group_urls( $group_id, $token, array( 'per_page' => 1 ) );
				$meta        = ( $response['ok'] && isset( $response['data']['meta'] ) && is_array( $response['data']['meta'] ) ) ? $response['data']['meta'] : array();
				$site_pages  = (int) ( $meta['selected_urls_count'] ?? 0 );
				$site_checks = (int) ( $meta['selected_checks_count'] ?? 0 );
				// A failed meta fetch degrades to 0 checks; flag it so the preflight can warn
				// instead of silently updating the site without its visual safety net.
				$meta_error = ! $response['ok'];
			}

			$sites[] = array(
				'site_id'     => $site_id,
				'name'        => $managed[ $site_id ]['name'] ?? '',
				'host'        => $managed[ $site_id ]['domain'] ?? '',
				'checks'      => $site_checks,
				'pages'       => $site_pages,
				'meta_error'  => $meta_error,
				// Only sites with pending updates participate in the run; the popup shows the
				// others greyed out. Additive field (backward compatible).
				'has_updates' => $has_updates,
				// Additive: false = updated without pre/post checks (popup badges it); such a
				// site has checks=0, so the aggregates and credit math exclude it automatically.
				'wcd_enabled' => $wcd_enabled,
				'updates'     => array(
					'total' => count( $items ),
					'items' => $items,
				),
			);

			// Aggregates (and therefore the credit math) only cover the sites that will run.
			// Non-enabled sites contribute their update items (they DO get updated) but never
			// checks or pages (both are 0 for them).
			if ( $has_updates ) {
				$checks        += $site_checks;
				$pages         += $site_pages;
				$total_updates += count( $items );
			}
		}

		$account      = WCD_MainWP_Site_Settings::get_account();
		$checks_left  = isset( $account['checks_left'] ) ? (int) $account['checks_left'] : null;
		$checks_limit = isset( $account['checks_limit'] ) ? (int) $account['checks_limit'] : null;
		$checks_done  = isset( $account['checks_done'] ) ? (int) $account['checks_done'] : null;
		$enough       = null === $checks_left ? true : ( $checks_left >= $checks );

		wp_send_json_success(
			array(
				'sites'         => $sites,
				'checks'        => $checks,
				'pages'         => $pages,
				'total_updates' => $total_updates,
				'enough'        => $enough,
				// checks_left/enough kept top-level for backward compatibility.
				'checks_left'   => $checks_left,
				'credits'       => array(
					'plan_name'    => $account['plan_name'] ?? '',
					'checks_left'  => $checks_left,
					'checks_limit' => $checks_limit,
					'checks_done'  => $checks_done,
					'enough'       => $enough,
				),
			)
		);
	}

	/**
	 * Trigger the pre-update screenshots for the requested site.
	 *
	 * @return void
	 */
	public static function take_pre(): void {
		self::take_screenshot( 'pre' );
	}

	/**
	 * Trigger the post-update screenshots for the requested site.
	 *
	 * @return void
	 */
	public static function take_post(): void {
		self::take_screenshot( 'post' );
	}

	/**
	 * Take screenshots for the requested sites' groups for the given screenshot type.
	 *
	 * The unified run dispatches a whole phase at once: accepts site_ids[] (single site_id kept
	 * for backward compatibility) and starts everything via chunked batch-per-group take calls.
	 * Successfully dispatched batches are recorded in the tracked run state even when other
	 * sites fail, so an aborted run stays resumable without taking those screenshots twice.
	 *
	 * @param string $sc_type The screenshot type ('pre' or 'post').
	 * @return void
	 */
	protected static function take_screenshot( string $sc_type ): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		// No all-sites fallback on a missing scope: screenshots cost credits, so the request
		// must always name its sites explicitly.
		$site_ids = array();
		if ( isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ) {
			$site_ids = array_filter( array_map( 'intval', wp_unslash( $_POST['site_ids'] ) ) );
		} elseif ( ! empty( $_POST['site_id'] ) ) {
			$site_ids = array( (int) $_POST['site_id'] );
		}
		$site_ids = array_values( array_unique( $site_ids ) );
		if ( empty( $site_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing site id.', 'webchangedetector-for-mainwp' ) ) );
		}

		$groups = array();
		foreach ( $site_ids as $site_id ) {
			$group_id = WCD_MainWP_Site_Map::get_manual_group( $site_id );
			if ( '' === $group_id ) {
				wp_send_json_error(
					array(
						/* translators: %d: MainWP site id. */
						'message' => sprintf( __( 'Site %d is not enabled.', 'webchangedetector-for-mainwp' ), $site_id ),
					)
				);
			}
			$groups[ $site_id ] = $group_id;
		}

		// Purge the child caches before the PRE screenshots so pre and post both show freshly
		// generated pages (a stale cached pre would diff against a fresh post and produce false
		// positives). Synchronous and best effort; the POST purge happens after run_update.
		if ( 'pre' === $sc_type ) {
			WCD_MainWP_Cache_Purge::purge_sites( array_keys( $groups ) );
		}

		$result = self::take_batches_for_sites( $groups, $sc_type );

		// Track the run's progress server-side so an interrupted run can be resumed (no-ops when
		// the sites are not part of a tracked run, e.g. a re-check after the run completed).
		foreach ( $result['batches'] as $site_id => $batch ) {
			if ( 'pre' === $sc_type ) {
				WCD_MainWP_Update_Flow::record_pre_batch( (int) $site_id, (string) $batch );
			} else {
				WCD_MainWP_Update_Flow::record_post_batch( (int) $site_id, (string) $batch );
			}
		}

		// Any site without a batch fails the request (same semantics as the per-site calls this
		// replaces); the recorded batches above keep the run resumable regardless.
		if ( ! empty( $result['failed'] ) || empty( $result['batches'] ) ) {
			$message = '' !== $result['error'] ? $result['error'] : __( 'Could not start the checks.', 'webchangedetector-for-mainwp' );
			wp_send_json_error(
				array(
					'message' => $message,
					'status'  => $result['status'],
				)
			);
		}

		wp_send_json_success( array( 'batches' => $result['batches'] ) );
	}

	/**
	 * Start screenshots for many sites' manual groups via chunked batch-per-group take calls.
	 *
	 * ONE API call per chunk creates one batch per group and returns the mapping, instead of one
	 * call per site (mirrors the webapp's bulk on-demand start). Fallbacks per chunk:
	 * - TRANSITIONAL (older API that ignored batch_per_group): the screenshots ARE already running
	 *   in ONE shared batch, so re-requesting per group would take everything twice and burn
	 *   credits; every site of the chunk maps to the shared batch instead.
	 * - A group missing from the returned map was skipped by the API (no credits / nothing
	 *   selected): its site is reported as failed.
	 *
	 * Recording into the run state is left to the callers (they differ in clear/record semantics).
	 *
	 * @param array  $groups  Map of site id => manual group uuid.
	 * @param string $sc_type The screenshot type ('pre' or 'post').
	 * @return array { batches: array<int,string>, failed: int[], error: string, status: int }
	 */
	protected static function take_batches_for_sites( array $groups, string $sc_type ): array {
		$batches = array();
		$failed  = array();
		$error   = '';
		$status  = 0;

		foreach ( array_chunk( $groups, self::TAKE_CHUNK, true ) as $chunk ) {
			$response = WCD_MainWP_API::take_screenshot( array_values( $chunk ), $sc_type, 'manual', self::token(), true );
			if ( ! $response['ok'] ) {
				// Keep the FIRST failure's message: it is usually the root cause (e.g. 402).
				if ( '' === $error ) {
					$error  = 402 === $response['status'] ? __( 'Not enough check credits.', 'webchangedetector-for-mainwp' ) : (string) $response['error'];
					$status = (int) $response['status'];
				}
				$failed = array_merge( $failed, array_keys( $chunk ) );
				continue;
			}

			$data = self::unwrap( $response['data'] );
			$map  = ( is_array( $data ) && ! empty( $data['batches'] ) && is_array( $data['batches'] ) ) ? $data['batches'] : null;

			foreach ( $chunk as $site_id => $group_id ) {
				if ( null !== $map ) {
					$batch = isset( $map[ $group_id ] ) ? (string) $map[ $group_id ] : '';
				} else {
					$batch = ( is_array( $data ) && ! empty( $data['batch'] ) ) ? (string) $data['batch'] : '';
				}
				if ( '' === $batch ) {
					// Skipped by the API inside an otherwise successful chunk: make sure the
					// caller still has a message to show (the old per-site call surfaced a 402).
					if ( '' === $error ) {
						$error = __( 'Some checks could not be started: not enough credits or no URLs selected.', 'webchangedetector-for-mainwp' );
					}
					$failed[] = $site_id;
					continue;
				}
				$batches[ $site_id ] = $batch;
			}
		}

		return array(
			'batches' => $batches,
			'failed'  => $failed,
			'error'   => $error,
			'status'  => $status,
		);
	}

	/**
	 * Trigger the WordPress update on the requested site.
	 *
	 * Legacy shape (no update_type): all four update types, gated on the site being enabled for
	 * visual checks. Additive Updates-page shape: update_type (+ optional slugs[]) scopes the
	 * update to one type / specific items, and the enabled gate is dropped because the selection
	 * flow deliberately updates non-enabled sites without checks (the preflight badge + confirm
	 * cover the cost/UX protection; nonce + manage_options remain the security boundary).
	 *
	 * @return void
	 */
	public static function run_update(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id     = self::site_id();
		$update_type = self::update_type_from_request();
		$slugs       = '' !== $update_type ? self::slugs_from_request() : array();

		if ( '' === $update_type ) {
			// Legacy path: only sites enabled for visual checks may be updated. Without this a
			// crafted id could update a non-enabled site, which would then have no pre screenshot
			// to compare against.
			if ( ! $site_id || ! WCD_MainWP_Site_Map::is_enabled( $site_id ) ) {
				wp_send_json_error(
					array(
						/* translators: %d: MainWP site id. */
						'message' => sprintf( __( 'Site %d is not enabled for visual checks.', 'webchangedetector-for-mainwp' ), $site_id ),
						'offline' => false,
					)
				);
			}
		} elseif ( ! $site_id || ! isset( WCD_MainWP_Site_Map::managed_sites()[ $site_id ] ) ) {
			// Scoped path: the site only has to be a managed MainWP site.
			wp_send_json_error(
				array(
					/* translators: %d: MainWP site id. */
					'message' => sprintf( __( 'Site %d is not a managed site.', 'webchangedetector-for-mainwp' ), $site_id ),
					'offline' => false,
				)
			);
		}

		// Keep the tracked run alive: a single site's synchronous update can take minutes with no
		// polling in between, and must not make the run look abandoned to another tab.
		WCD_MainWP_Update_Flow::touch_run();
		$result = WCD_MainWP_Update_Flow::trigger_site_update( $site_id, $update_type, $slugs );

		if ( ! $result['ok'] ) {
			wp_send_json_error(
				array(
					'message' => $result['error'],
					'offline' => $result['offline'],
				)
			);
		}

		// The site's updates ran; its post screenshots are now due (resume picks this up if the
		// browser disappears before take_post). Recorded BEFORE the purge: if the purge request
		// stalls and the PHP request dies, the resume path must still know about this site (it
		// re-purges anyway).
		WCD_MainWP_Update_Flow::record_site_updated( $site_id );

		// Updates installed: purge the child's cache now so the post screenshots (dispatched by
		// the card's later take_post call) capture the updated site instead of a cached old
		// version. Skipped when nothing was updated (the cache cannot be stale then).
		if ( $result['updated'] > 0 ) {
			WCD_MainWP_Cache_Purge::purge_site( $site_id );
		}

		wp_send_json_success(
			array(
				'updated' => $result['updated'],
				'message' => $result['error'],
			)
		);
	}

	/**
	 * Poll one or more batches and aggregate their queue status counts.
	 *
	 * @return void
	 */
	public static function poll(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		// A polling browser is actively driving the run; keep the tracked state fresh.
		WCD_MainWP_Update_Flow::touch_run();

		// The unified run polls all of a phase's batches at once: accept batches[] and aggregate, with
		// single `batch` kept for backward compatibility.
		$batches = array();
		if ( isset( $_POST['batches'] ) && is_array( $_POST['batches'] ) ) {
			$batches = array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['batches'] ) ) );
		} elseif ( isset( $_POST['batch'] ) && '' !== $_POST['batch'] ) {
			$batches = array( sanitize_text_field( wp_unslash( $_POST['batch'] ) ) );
		}
		$batches = array_values( array_unique( $batches ) );
		if ( empty( $batches ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing batch id.', 'webchangedetector-for-mainwp' ) ) );
		}

		// The run's PRE batches (optional, sent during POST-phase polls). Only their failed count is
		// used: a check whose pre screenshot failed never gets a comparison, so without it the derived
		// processing count below could never reach zero.
		$pre_batches = array();
		if ( isset( $_POST['pre_batches'] ) && is_array( $_POST['pre_batches'] ) ) {
			$pre_batches = array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['pre_batches'] ) ) );
		}
		$pre_batches = array_values( array_diff( array_unique( $pre_batches ), $batches ) );

		// One meta-only call for all batches: the queues endpoint pre-aggregates per-batch status
		// counts (incl. the by_type breakdown) in `meta`, so we only need the meta, not the items:
		// per_page=1 keeps the payload tiny.
		$response = WCD_MainWP_API::get_queues( array_merge( $batches, $pre_batches ), '', self::token(), 1 );
		if ( ! $response['ok'] ) {
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		$meta            = ( is_array( $response['data'] ) && isset( $response['data']['meta'] ) && is_array( $response['data']['meta'] ) ) ? $response['data']['meta'] : array();
		$counts_by_batch = ( isset( $meta['status_counts_by_batch'] ) && is_array( $meta['status_counts_by_batch'] ) ) ? $meta['status_counts_by_batch'] : array();
		if ( empty( $counts_by_batch ) && 1 === count( $batches ) && empty( $pre_batches ) && isset( $meta['status_counts'] ) && is_array( $meta['status_counts'] ) ) {
			// Single-batch back-compat only: the global status_counts is for this one batch. Never
			// reuse it across multiple batches (it would multiply the aggregate).
			$counts_by_batch = array( $batches[0] => $meta['status_counts'] );
		}

		$aggregate = array(
			'queue'      => 0,
			'processing' => 0,
			'done'       => 0,
			'failed'     => 0,
		);
		$per_batch = array();
		foreach ( $batches as $batch ) {
			$b                   = self::batch_bucket( $counts_by_batch[ $batch ] ?? array() );
			$per_batch[ $batch ] = $b;
			foreach ( $aggregate as $key => $val ) {
				$aggregate[ $key ] = $val + $b[ $key ];
			}
		}

		// Checks whose PRE screenshot failed have no pair, so the API never creates their comparison:
		// count them as failed instead of leaving them in processing forever (mirrors the webapp).
		// Accepted edge (same tradeoff as the webapp): the API pairs against the latest DONE pre
		// across batches, so a re-check after a pre failure can still get a late comparison that
		// this shift settles as failed one tick too early; the Change Detections page stays correct.
		$pre_failed = 0;
		foreach ( $pre_batches as $batch ) {
			$pre_failed += (int) ( $counts_by_batch[ $batch ]['failed'] ?? 0 );
		}
		$shift                    = min( $pre_failed, $aggregate['processing'] );
		$aggregate['processing'] -= $shift;
		$aggregate['failed']     += $shift;

		$remaining = $aggregate['queue'] + $aggregate['processing'];
		$finished  = $aggregate['done'] + $aggregate['failed'];

		wp_send_json_success(
			array_merge(
				$aggregate,
				array(
					'remaining' => $remaining,
					// Per-batch breakdown lets the unified run card show each site's done/total counter.
					'by_batch'  => $per_batch,
					// Only "complete" once the queue is empty AND something finished, so we never stop on a
					// batch whose queue has not been populated yet.
					'complete'  => 0 === $remaining && $finished > 0,
				)
			)
		);
	}

	/**
	 * Shape one batch's queue status counts into the run card's bucket.
	 *
	 * A POST batch holds TWO queue rows per check: the post screenshot and its comparison, which the
	 * API spawns asynchronously as each screenshot finishes. Raw counts therefore double-count the
	 * run (and can report "complete" in the gap before the comparisons exist). So when a batch has
	 * post/compare rows, count checks instead (mirrors the webapp's on-demand cards): total = post
	 * screenshot rows, done = finished comparisons, processing = derived remainder. Screenshot-only
	 * batches (PRE phase) keep their raw counts.
	 *
	 * @param mixed $counts One batch's entry from the queues endpoint's `status_counts_by_batch`.
	 * @return array Bucket with queue/processing/done/failed.
	 */
	private static function batch_bucket( $counts ): array {
		$counts  = is_array( $counts ) ? $counts : array();
		$by_type = ( isset( $counts['by_type'] ) && is_array( $counts['by_type'] ) ) ? $counts['by_type'] : array();
		$post    = ( isset( $by_type['post'] ) && is_array( $by_type['post'] ) ) ? $by_type['post'] : array();
		$compare = ( isset( $by_type['compare'] ) && is_array( $by_type['compare'] ) ) ? $by_type['compare'] : array();

		$sum        = static fn( array $c ): int => (int) ( $c['open'] ?? 0 ) + (int) ( $c['processing'] ?? 0 ) + (int) ( $c['done'] ?? 0 ) + (int) ( $c['failed'] ?? 0 );
		$post_total = $sum( $post );

		if ( 0 === $post_total + $sum( $compare ) ) {
			return array(
				'queue'      => (int) ( $counts['open'] ?? 0 ),
				'processing' => (int) ( $counts['processing'] ?? 0 ),
				'done'       => (int) ( $counts['done'] ?? 0 ),
				'failed'     => (int) ( $counts['failed'] ?? 0 ),
			);
		}

		$queue  = (int) ( $post['open'] ?? 0 );
		$done   = (int) ( $compare['done'] ?? 0 );
		$failed = min( (int) ( $post['failed'] ?? 0 ) + (int) ( $compare['failed'] ?? 0 ), max( 0, $post_total - $done - $queue ) );

		return array(
			'queue'      => $queue,
			'processing' => max( 0, $post_total - $done - $failed - $queue ),
			'done'       => $done,
			'failed'     => $failed,
		);
	}

	/**
	 * Return the shaped comparison results for a batch.
	 *
	 * @return void
	 */
	public static function results(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$batch = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
		if ( '' === $batch ) {
			wp_send_json_error( array( 'message' => __( 'Missing batch id.', 'webchangedetector-for-mainwp' ) ) );
		}

		$response = WCD_MainWP_API::get_comparisons(
			array(
				'batches'  => $batch,
				'per_page' => 100,
			),
			self::token()
		);
		if ( ! $response['ok'] ) {
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		$comparisons = WCD_MainWP_Update_Flow::extract_urls( $response['data'] );
		wp_send_json_success( array( 'comparisons' => array_map( array( self::class, 'shape_comparison' ), $comparisons ) ) );
	}

	/* ─────────────────────── Visual Checks overview ─────────────────────── */

	/**
	 * Render the runs list (batch or flat view) for the given filters. Returns rendered HTML
	 * fragments ({ html, pagination }) which the JS swaps in. The source is fixed to On-Demand
	 * (manual) server-side; there is no type filter.
	 */
	public static function runs_render(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		$view  = ( isset( $_POST['view'] ) && 'flat' === $_POST['view'] ) ? 'flat' : 'batch';
		$input = array(
			'page'            => isset( $_POST['page'] ) ? (int) $_POST['page'] : 1,
			'from'            => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '',
			'to'              => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '',
			'status'          => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
			'difference_only' => ! empty( $_POST['difference_only'] ) && 'false' !== $_POST['difference_only'],
			'site_ids'        => ( isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ) ? array_map( 'intval', wp_unslash( $_POST['site_ids'] ) ) : array(),
		);

		$filters = WCD_MainWP_Runs_View::build_api_filters( $input );
		$result  = 'flat' === $view
			? WCD_MainWP_Runs_View::render_flat_list( $filters )
			: WCD_MainWP_Runs_View::render_batch_list( $filters );

		wp_send_json_success( $result );
	}

	/**
	 * Render the comparison table for a single batch (the accordion drill-in).
	 */
	public static function runs_comparisons(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		$batch = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
		if ( '' === $batch ) {
			wp_send_json_error( array( 'message' => __( 'Missing batch id.', 'webchangedetector-for-mainwp' ) ) );
		}

		$response = WCD_MainWP_API::get_comparisons(
			array(
				'batches'        => $batch,
				'per_page'       => 100,
				'orderBy'        => 'difference_percent',
				'orderDirection' => 'desc',
			),
			self::token()
		);
		if ( ! $response['ok'] ) {
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		$comparisons = WCD_MainWP_Update_Flow::extract_urls( $response['data'] );
		wp_send_json_success( array( 'html' => WCD_MainWP_Runs_View::render_comparisons_table( $comparisons, false ) ) );
	}

	/* ───────────────────────── Run state (resume) ──────────────────────── */

	/**
	 * Start tracking a run server-side. The payload carries the per-site name + check count the
	 * resume card needs later (the browser already has them from the preflight). The additive
	 * update_type + selection[<site_id>][] fields persist an Updates-page run's scope so a resume
	 * re-applies the original selection.
	 *
	 * @return void
	 */
	public static function run_start(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		// Parallel arrays (site_ids[i] <-> names[i] <-> checks[i]), sanitized per field. A JSON
		// blob would have to be sanitized as a whole string, which mangles site names containing
		// e.g. a "<".
		$ids    = ( isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ) ? array_map( 'intval', wp_unslash( $_POST['site_ids'] ) ) : array();
		$names  = ( isset( $_POST['names'] ) && is_array( $_POST['names'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['names'] ) ) : array();
		$checks = ( isset( $_POST['checks'] ) && is_array( $_POST['checks'] ) ) ? array_map( 'intval', wp_unslash( $_POST['checks'] ) ) : array();

		$sites = array();
		foreach ( array_values( $ids ) as $i => $site_id ) {
			if ( ! $site_id || ! WCD_MainWP_Site_Map::is_enabled( $site_id ) ) {
				continue;
			}
			$sites[ $site_id ] = array(
				'site_id' => $site_id,
				'name'    => (string) ( array_values( $names )[ $i ] ?? '' ),
				'checks'  => (int) ( array_values( $checks )[ $i ] ?? 0 ),
			);
		}

		if ( empty( $sites ) ) {
			wp_send_json_error( array( 'message' => __( 'No sites are enabled for visual checks.', 'webchangedetector-for-mainwp' ) ) );
		}

		$driver      = isset( $_POST['driver'] ) ? sanitize_text_field( wp_unslash( $_POST['driver'] ) ) : '';
		$update_type = self::update_type_from_request();
		$selection   = '' !== $update_type ? self::selection_from_request() : array();
		WCD_MainWP_Update_Flow::start_run( $sites, $driver, $update_type, $selection );
		wp_send_json_success( array( 'tracking' => true ) );
	}

	/**
	 * Report whether an abandoned run with missing post screenshots exists (drives the resume
	 * notice on page load). Cheap: one option read, no API calls.
	 *
	 * @return void
	 */
	public static function run_status(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		$state = WCD_MainWP_Update_Flow::run_state();
		if ( empty( $state['sites'] ) ) {
			wp_send_json_success( array( 'active' => false ) );
		}

		$idle    = WCD_MainWP_Update_Flow::idle_seconds( $state );
		$updated = isset( $state['updated_sites'] ) && is_array( $state['updated_sites'] ) ? $state['updated_sites'] : array();
		$pre     = isset( $state['pre_batches'] ) && is_array( $state['pre_batches'] ) ? $state['pre_batches'] : array();

		// A long-abandoned run that never took a pre screenshot and never installed anything is just
		// leftover state (e.g. the very first call died): drop it instead of re-opening forever. Any
		// run with pre screenshots or installed updates stays resumable regardless of age.
		if ( $idle > WCD_MainWP_Update_Flow::RUN_STALE_AFTER && empty( $updated ) && empty( $pre ) ) {
			WCD_MainWP_Update_Flow::clear_run();
			wp_send_json_success( array( 'active' => false ) );
		}

		// Additive resume scope: each site entry carries its persisted item slugs (empty = all of
		// the run's type), so a resumed Updates-page run never installs more than selected.
		$site_slugs = isset( $state['site_slugs'] ) && is_array( $state['site_slugs'] ) ? $state['site_slugs'] : array();
		$run_sites  = array();
		foreach ( $state['sites'] as $sid => $site ) {
			$site['slugs'] = array_map( 'strval', (array) ( $site_slugs[ $sid ] ?? array() ) );
			$run_sites[]   = $site;
		}

		wp_send_json_success(
			array(
				'active'        => true,
				// Only the page may take over once the heartbeat has gone silent; a fresh heartbeat
				// means another tab is still driving the run. The driver id lets a same-tab reload
				// recognise its own run and reclaim it instantly (see checkResume).
				'resumable'     => $idle >= WCD_MainWP_Update_Flow::RESUME_AFTER,
				'idle'          => $idle,
				'driver'        => (string) ( $state['driver'] ?? '' ),
				'phase'         => (string) ( $state['phase'] ?? 'pre' ),
				// Additive: the run's update-type scope ('' = legacy whole-site run).
				'update_type'   => (string) ( $state['update_type'] ?? '' ),
				'sites'         => $run_sites,
				'pre_batches'   => $pre,
				'post_batches'  => isset( $state['post_batches'] ) && is_array( $state['post_batches'] ) ? $state['post_batches'] : array(),
				'updated_sites' => array_values( array_map( 'intval', $updated ) ),
			)
		);
	}

	/**
	 * Keep the tracked run's activity timestamp fresh while the driving tab is alive. Called on a
	 * short interval by the browser (independently of the awaited phase calls), so even a multi-minute
	 * synchronous update never makes the run look abandoned to another tab.
	 *
	 * @return void
	 */
	public static function run_heartbeat(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$driver = isset( $_POST['driver'] ) ? sanitize_text_field( wp_unslash( $_POST['driver'] ) ) : '';
		WCD_MainWP_Update_Flow::heartbeat( $driver );
		wp_send_json_success();
	}

	/**
	 * Resume an abandoned run: dispatch the post screenshots for every updated site that is still
	 * missing one, hand back all post batches (existing + new) for the card to poll, and stop
	 * tracking the run (everything left finishes server-side).
	 *
	 * @return void
	 */
	public static function run_resume_post(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		$state   = WCD_MainWP_Update_Flow::run_state();
		$updated = isset( $state['updated_sites'] ) && is_array( $state['updated_sites'] ) ? array_map( 'intval', $state['updated_sites'] ) : array();
		if ( empty( $state['sites'] ) || empty( $updated ) ) {
			wp_send_json_error( array( 'message' => __( 'No interrupted run to resume.', 'webchangedetector-for-mainwp' ) ) );
		}

		$existing    = isset( $state['post_batches'] ) && is_array( $state['post_batches'] ) ? $state['post_batches'] : array();
		$pre_state   = isset( $state['pre_batches'] ) && is_array( $state['pre_batches'] ) ? $state['pre_batches'] : array();
		$batches     = array();
		$pre_batches = array();
		$sites       = array();
		$need        = array();
		$skipped     = 0;
		$error       = '';
		foreach ( $updated as $site_id ) {
			$batch = isset( $existing[ $site_id ] ) ? (string) $existing[ $site_id ] : '';
			if ( '' !== $batch ) {
				$batches[ $site_id ] = $batch;
				continue;
			}
			$group_id = WCD_MainWP_Site_Map::get_manual_group( $site_id );
			if ( '' === $group_id ) {
				// No group mapping anymore (site disabled mid-run): permanently unresumable,
				// so it must neither keep the state alive nor resurface the notice.
				continue;
			}
			$need[ $site_id ] = $group_id;
		}

		if ( ! empty( $need ) ) {
			// Belt and braces: run_update already purged after installing, but that purge is best
			// effort and the updates ran a while ago (a resume is only offered after the staleness
			// gate). Re-purge so the late post screenshots never capture a stale cache.
			WCD_MainWP_Cache_Purge::purge_sites( array_keys( $need ) );

			// One chunked batch-per-group call for all missing sites instead of one take call each.
			$result = self::take_batches_for_sites( $need, 'post' );
			foreach ( $result['batches'] as $site_id => $batch ) {
				$batches[ $site_id ] = (string) $batch;
			}
			$skipped = count( $result['failed'] );
			$error   = $result['error'];
		}

		foreach ( $updated as $site_id ) {
			if ( ! isset( $batches[ $site_id ] ) ) {
				continue;
			}
			if ( ! empty( $pre_state[ $site_id ] ) ) {
				$pre_batches[ $site_id ] = (string) $pre_state[ $site_id ];
			}
			$sites[] = $state['sites'][ $site_id ] ?? array(
				'site_id' => $site_id,
				'name'    => '',
				'checks'  => 0,
			);
		}

		if ( empty( $batches ) ) {
			if ( '' === $error ) {
				// Every site was unresumable (no group mapping): drop the state for good.
				WCD_MainWP_Update_Flow::clear_run();
				wp_send_json_error( array( 'message' => __( 'Nothing to resume: the sites are no longer enabled for visual checks.', 'webchangedetector-for-mainwp' ) ) );
			}
			// A retryable failure (e.g. credits): keep the tracked run so the user can retry.
			wp_send_json_error( array( 'message' => $error ) );
		}

		if ( 0 === $skipped ) {
			WCD_MainWP_Update_Flow::clear_run();
		} else {
			// Partial success: persist the dispatched batches so a later retry resumes only what
			// is still missing (the staleness gate delays the next offer; acceptable for this
			// rare path). The warning tells the user what failed.
			foreach ( $batches as $sid => $batch_id ) {
				WCD_MainWP_Update_Flow::record_post_batch( (int) $sid, (string) $batch_id );
			}
		}

		wp_send_json_success(
			array(
				'sites'       => $sites,
				'batches'     => $batches,
				// The resumed sites' PRE batches, so the POST-phase poll can count pre failures.
				'pre_batches' => $pre_batches,
				'warning'     => ( $skipped > 0 && '' !== $error ) ? $error : '',
			)
		);
	}

	/**
	 * Discard the tracked (abandoned) run.
	 *
	 * @return void
	 */
	public static function run_discard(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		WCD_MainWP_Update_Flow::clear_run();
		wp_send_json_success( array( 'discarded' => true ) );
	}

	/* ────────────────────────────── Internals ──────────────────────────── */

	/**
	 * Resolve the in-scope, enabled site ids from the request: an explicit site_ids[] list, a
	 * single site_id, or (for a bulk run) all enabled sites.
	 *
	 * @return int[]
	 */
	protected static function scope_site_ids(): array {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			return array();
		}

		$ids = array();
		if ( ! empty( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ) {
			$ids = array_map( 'intval', wp_unslash( $_POST['site_ids'] ) );
		} elseif ( ! empty( $_POST['site_id'] ) ) {
			$ids = array( (int) $_POST['site_id'] );
		} else {
			$ids = array_map( 'intval', array_keys( WCD_MainWP_Site_Map::all() ) );
		}

		return array_values( array_filter( $ids, array( WCD_MainWP_Site_Map::class, 'is_enabled' ) ) );
	}

	/**
	 * Reduce a comparison resource to the fields the results view needs.
	 *
	 * @param mixed $c The comparison resource (array or object).
	 * @return array
	 */
	protected static function shape_comparison( $c ): array {
		$c       = (array) $c;
		$percent = isset( $c['difference_percent'] ) ? (float) $c['difference_percent'] : 0.0;
		$status  = $c['status'] ?? 'new';

		return array(
			'id'         => $c['id'] ?? '',
			'url'        => $c['url'] ?? '',
			'device'     => $c['device'] ?? '',
			'percent'    => $percent,
			'status'     => $status,
			'public'     => $c['public_link'] ?? '',
			'before'     => $c['screenshot_1_link'] ?? ( $c['screenshot_1'] ?? '' ),
			'after'      => $c['screenshot_2_link'] ?? ( $c['screenshot_2'] ?? '' ),
			'ai_summary' => self::ai_summary( $c ),
		);
	}

	/**
	 * Best-effort AI summary text (only present when the account has the feature). Never exposes
	 * model names or crop URLs (the API already strips those server-side).
	 *
	 * @param array $c The comparison resource.
	 * @return string
	 */
	protected static function ai_summary( array $c ): string {
		if ( ! empty( $c['ai_verification_result'] ) && is_array( $c['ai_verification_result'] ) ) {
			$r = $c['ai_verification_result'];
			if ( ! empty( $r['summary'] ) && is_string( $r['summary'] ) ) {
				return sanitize_text_field( $r['summary'] );
			}
			if ( ! empty( $r['reason'] ) && is_string( $r['reason'] ) ) {
				return sanitize_text_field( $r['reason'] );
			}
		}

		return '';
	}
}
