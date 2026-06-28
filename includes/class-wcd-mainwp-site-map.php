<?php
/**
 * Maps MainWP child sites to WebChange Detector websites + groups.
 *
 * MainWP owns the list of managed sites (via the mainwp_getdbsites filter). WCD owns the
 * websites/groups. This class is the bridge: enabling a site provisions a WCD website (with a
 * manual + auto detection group) and stores the resulting UUIDs, keyed by the MainWP site id.
 *
 * The domain stored here is normalized once (scheme + trailing slash stripped) and reused
 * byte-for-byte for every WCD call, because the API resolves websites by exact domain match.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps MainWP child sites to WebChange Detector websites and groups.
 */
class WCD_MainWP_Site_Map {

	const OPTION_KEY = 'wcd_site_map';

	// Allowed screenshot region values. 'auto' (default) lets the API geolocate the site and pick the
	// nearest region; 'us'/'eu' pin it. The API owns resolving 'auto' to a concrete value.
	const REGIONS = array( 'us', 'eu', 'auto' );

	const DEFAULT_REGION = 'auto';

	/**
	 * Normalize a site URL the same way the WCD API expects: strip the scheme and any trailing
	 * slash, keep www + path. Mirrors the webapp's mm_normalize_domain.
	 *
	 * @param string $raw The raw site URL to normalize.
	 * @return string The normalized domain, or an empty string when invalid.
	 */
	public static function normalize_domain( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$candidate = preg_match( '#^https?://#i', $raw ) ? $raw : 'https://' . $raw;
		if ( ! filter_var( $candidate, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$bare = preg_replace( '#^https?://#i', '', $candidate );

		return rtrim( $bare, '/' );
	}

	/**
	 * The whole map, keyed by MainWP site id. Each entry holds the website_uuid, manual_group_uuid,
	 * auto_group_uuid, domain and enabled flag.
	 *
	 * @return array The full site map.
	 */
	public static function all(): array {
		$map = WCD_MainWP_Options::get( self::OPTION_KEY, array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Return the stored entry for a single MainWP site.
	 *
	 * @param int $site_id MainWP site id.
	 * @return array The site entry, or an empty array when unknown.
	 */
	public static function for_site( int $site_id ): array {
		$map = self::all();

		return $map[ $site_id ] ?? array();
	}

	/**
	 * Whether a site is enabled for checks (provisioned and toggled on).
	 *
	 * @param int $site_id MainWP site id.
	 * @return bool True when the site is enabled.
	 */
	public static function is_enabled( int $site_id ): bool {
		$entry = self::for_site( $site_id );

		return ! empty( $entry['enabled'] ) && ! empty( $entry['manual_group_uuid'] );
	}

	/**
	 * The stored manual (on-demand) group UUID for a site.
	 *
	 * @param int $site_id MainWP site id.
	 * @return string The manual group UUID, or an empty string.
	 */
	public static function get_manual_group( int $site_id ): string {
		return (string) ( self::for_site( $site_id )['manual_group_uuid'] ?? '' );
	}

	/**
	 * The normalized domain stored for a site.
	 *
	 * @param int $site_id MainWP site id.
	 * @return string The normalized domain, or an empty string.
	 */
	public static function get_domain( int $site_id ): string {
		return (string) ( self::for_site( $site_id )['domain'] ?? '' );
	}

	/**
	 * The stored screenshot region for a site (the user's choice). Defaults to 'auto' for sites that
	 * predate the per-site selector. This is the chosen value, not necessarily the resolved one (the
	 * API resolves 'auto' to a concrete 'us'/'eu' asynchronously).
	 *
	 * @param int $site_id MainWP site id.
	 * @return string One of 'us', 'eu', 'auto'.
	 */
	public static function get_region( int $site_id ): string {
		return self::sanitize_region( self::for_site( $site_id )['screenshot_region'] ?? self::DEFAULT_REGION );
	}

	/**
	 * Constrain an arbitrary value to a valid screenshot region, falling back to the default.
	 *
	 * @param mixed $value The candidate region value.
	 * @return string One of 'us', 'eu', 'auto'.
	 */
	public static function sanitize_region( $value ): string {
		return in_array( $value, self::REGIONS, true ) ? (string) $value : self::DEFAULT_REGION;
	}

	/**
	 * Persist a site's chosen screenshot region. The value is constrained to a valid region.
	 *
	 * @param int    $site_id MainWP site id.
	 * @param string $region  The chosen region ('us', 'eu' or 'auto').
	 * @return void
	 */
	public static function set_region( int $site_id, string $region ): void {
		self::save_site( $site_id, array( 'screenshot_region' => self::sanitize_region( $region ) ) );
	}

	/**
	 * Persist a single site entry.
	 *
	 * @param int   $site_id MainWP site id.
	 * @param array $entry   The entry fields to merge into the stored entry.
	 * @return void
	 */
	protected static function save_site( int $site_id, array $entry ): void {
		$map             = self::all();
		$map[ $site_id ] = array_merge( $map[ $site_id ] ?? array(), $entry );
		WCD_MainWP_Options::set( self::OPTION_KEY, $map );
	}

	/**
	 * Managed MainWP sites as [ id => [ id, url, name, domain ] ], via the public filter.
	 */
	public static function managed_sites(): array {
		// mainwp_getsites returns ALL managed sites when $websiteid is null. (mainwp_getdbsites
		// only returns rows when an explicit sites/groups/clients filter is passed, so it cannot
		// enumerate "all sites".) The filtered value IS the plugin file, then the child key.
		$sites = apply_filters( 'mainwp_getsites', WCD_MAINWP_PLUGIN_FILE, self::extension_key(), null );

		$result = array();
		if ( is_array( $sites ) ) {
			foreach ( $sites as $site ) {
				$site = (array) $site;
				if ( empty( $site['id'] ) ) {
					continue;
				}
				$id            = (int) $site['id'];
				$result[ $id ] = array(
					'id'     => $id,
					'url'    => $site['url'] ?? '',
					'name'   => $site['name'] ?? ( $site['url'] ?? '' ),
					'domain' => self::normalize_domain( $site['url'] ?? '' ),
				);
			}
		}

		return $result;
	}

	/**
	 * The security key MainWP hands registered extensions. Fetched live via the public filter.
	 * Public: WCD_MainWP_Cache_Purge needs the same key for its child requests.
	 */
	public static function extension_key(): string {
		$info = apply_filters( 'mainwp_extension_enabled_check', WCD_MAINWP_PLUGIN_FILE );

		return is_array( $info ) && ! empty( $info['key'] ) ? (string) $info['key'] : '';
	}

	/**
	 * Enable a site: provision (or reuse) its WCD website + groups, then mark it enabled.
	 *
	 * @param int    $site_id   MainWP site id.
	 * @param string $api_token Optional API token to use for the WCD calls.
	 * @return array Result with 'ok' (bool) and 'error' (string) keys.
	 */
	public static function enable_site( int $site_id, string $api_token = '' ): array {
		$managed = self::managed_sites();
		if ( empty( $managed[ $site_id ] ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Unknown MainWP site.', 'webchangedetector-for-mainwp' ),
			);
		}

		$domain = $managed[ $site_id ]['domain'];
		if ( '' === $domain ) {
			return array(
				'ok'    => false,
				'error' => __( 'Could not determine the site domain.', 'webchangedetector-for-mainwp' ),
			);
		}

		$existing   = self::for_site( $site_id );
		$manual_id  = (string) ( $existing['manual_group_uuid'] ?? '' );
		$auto_id    = (string) ( $existing['auto_group_uuid'] ?? '' );
		$website_id = (string) ( $existing['website_uuid'] ?? '' );
		// The user's chosen region (default 'auto'). Sent on provision so the new groups carry the
		// choice from the start; set_region updates it later without re-provisioning.
		$region = self::sanitize_region( $existing['screenshot_region'] ?? self::DEFAULT_REGION );

		// Already provisioned: keep the group names consistent (pure domain) and flip the flag on.
		if ( '' !== $manual_id && '' !== $website_id ) {
			WCD_MainWP_API::update_group( $manual_id, array( 'name' => $domain ), $api_token );
			if ( '' !== $auto_id ) {
				WCD_MainWP_API::update_group( $auto_id, array( 'name' => $domain ), $api_token );
			}
			self::save_site( $site_id, array( 'enabled' => true ) );

			return array(
				'ok'    => true,
				'error' => '',
			);
		}

		// Not provisioned locally. The account may already hold a MainWP website for this domain
		// (e.g. after an API token switch or a reinstall): adopt it instead of creating duplicate WCD
		// resources. The website carries managed_by=mainwp, so this never picks up a first-party site.
		$found = self::find_existing_website( $domain, $api_token );
		if ( ! empty( $found ) ) {
			self::save_site(
				$site_id,
				array_merge(
					$found,
					array(
						'domain'  => $domain,
						'enabled' => true,
					)
				)
			);

			return array(
				'ok'    => true,
				'error' => '',
			);
		}

		// Create the manual + auto detection groups. Both named the bare domain (matches the
		// customer plugin); the monitoring flag distinguishes them. Each UUID is persisted as soon
		// as it exists, so a retry after a partial failure reuses it instead of provisioning
		// duplicate WCD resources.
		if ( '' === $manual_id ) {
			$manual    = WCD_MainWP_API::create_group(
				array(
					'name'              => $domain,
					'monitoring'        => false,
					'enabled'           => true,
					'cms'               => 'wordpress',
					'screenshot_region' => $region,
				),
				$api_token
			);
			$manual_id = self::extract_uuid( $manual );
			if ( ! $manual_id ) {
				return array(
					'ok'    => false,
					'error' => $manual['error'] ? $manual['error'] : __( 'Could not create the on-demand group.', 'webchangedetector-for-mainwp' ),
				);
			}
			self::save_site(
				$site_id,
				array(
					'manual_group_uuid' => $manual_id,
					'domain'            => $domain,
				)
			);
		}

		if ( '' === $auto_id ) {
			$auto    = WCD_MainWP_API::create_group(
				array(
					'name'              => $domain,
					'monitoring'        => true,
					'enabled'           => true,
					'cms'               => 'wordpress',
					'screenshot_region' => $region,
				),
				$api_token
			);
			$auto_id = self::extract_uuid( $auto );
			if ( ! $auto_id ) {
				return array(
					'ok'    => false,
					'error' => $auto['error'] ? $auto['error'] : __( 'Could not create the monitoring group.', 'webchangedetector-for-mainwp' ),
				);
			}
			self::save_site( $site_id, array( 'auto_group_uuid' => $auto_id ) );
		}

		// Create the website that links both groups.
		$website    = WCD_MainWP_API::create_website( $domain, $manual_id, $auto_id, $api_token );
		$website_id = self::extract_uuid( $website );
		$wdata      = ( is_array( $website['data'] ?? null ) && isset( $website['data']['data'] ) && is_array( $website['data']['data'] ) )
			? $website['data']['data']
			: ( $website['data'] ?? array() );
		$link_ok    = is_array( $wdata ) && ! empty( $wdata['manual_detection_group'] ) && ! empty( $wdata['auto_detection_group'] );
		if ( ! $website['ok'] || ! $website_id || ! $link_ok ) {
			return array(
				'ok'    => false,
				'error' => $website['error'] ? $website['error'] : __( 'Could not create the WebChange Detector website (groups not linked).', 'webchangedetector-for-mainwp' ),
			);
		}

		self::save_site(
			$site_id,
			array(
				'website_uuid'      => $website_id,
				'manual_group_uuid' => $manual_id,
				'auto_group_uuid'   => $auto_id,
				'domain'            => $domain,
				'enabled'           => true,
				'screenshot_region' => $region,
			)
		);

		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * Disable a site (keeps the mapping so re-enabling does not re-provision).
	 *
	 * @param int $site_id MainWP site id.
	 * @return void
	 */
	public static function disable_site( int $site_id ): void {
		if ( self::for_site( $site_id ) ) {
			self::save_site( $site_id, array( 'enabled' => false ) );
		}
	}

	/**
	 * Forget ALL provisioning (every site's UUIDs + enabled flags). Used when the bound WebChange
	 * Detector account changes (API token switch): the stored website/group UUIDs belong to the
	 * previous account and would 404, so the map must be rebuilt by re-enabling sites under the new
	 * account. MainWP still owns the list of sites, so the Sites page simply shows them all disabled.
	 *
	 * @return void
	 */
	public static function reset_all(): void {
		WCD_MainWP_Options::delete( self::OPTION_KEY );
	}

	/**
	 * Forget a single site's provisioning so the next enable re-creates fresh WCD resources. Used to
	 * self-heal when the API reports the stored group no longer exists (deleted, or left over from a
	 * different account).
	 *
	 * @param int $site_id MainWP site id.
	 * @return void
	 */
	public static function reset_site( int $site_id ): void {
		$map = self::all();
		if ( isset( $map[ $site_id ] ) ) {
			unset( $map[ $site_id ] );
			WCD_MainWP_Options::set( self::OPTION_KEY, $map );
		}
	}

	/**
	 * Pull a UUID out of a create response (group or website resource).
	 *
	 * @param array $response The API response array.
	 * @return string The extracted UUID, or an empty string.
	 */
	protected static function extract_uuid( array $response ): string {
		if ( empty( $response['ok'] ) || empty( $response['data'] ) ) {
			return '';
		}
		$data = $response['data'];
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}

		return isset( $data['id'] ) ? (string) $data['id'] : '';
	}

	/**
	 * Look up this account's existing MainWP website for a domain and return its UUIDs, so a site can
	 * re-link to it instead of provisioning duplicates. Returns an empty array when none exists or the
	 * lookup fails (caller then creates a fresh one).
	 *
	 * @param string $domain    Normalized domain.
	 * @param string $api_token Bearer token.
	 * @return array { website_uuid, manual_group_uuid, auto_group_uuid } or an empty array.
	 */
	protected static function find_existing_website( string $domain, string $api_token ): array {
		$response = WCD_MainWP_API::get_websites( $domain, $api_token );
		if ( empty( $response['ok'] ) || empty( $response['data'] ) ) {
			return array();
		}

		// The collection endpoint wraps the rows in a `data` envelope.
		$data = $response['data'];
		$list = ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : ( is_array( $data ) ? $data : array() );

		foreach ( $list as $site ) {
			if ( ! is_array( $site ) ) {
				continue;
			}
			// Only ever adopt a website that is actually MainWP-managed and for this exact domain.
			// Guards against an API that ignores the managed_by/domain filter, which would otherwise
			// re-link us onto a first-party website and mix ?p=ID URLs with the clean permalinks.
			if ( WCD_MainWP_API::MANAGED_BY !== (string) ( $site['managed_by'] ?? '' ) ) {
				continue;
			}
			if ( (string) ( $site['domain'] ?? '' ) !== $domain ) {
				continue;
			}
			$website_uuid = (string) ( $site['id'] ?? '' );
			$manual_uuid  = (string) ( $site['manual_detection_group'] ?? '' );
			// A website is only useful to us with its manual group (the on-demand checks live there).
			if ( '' !== $website_uuid && '' !== $manual_uuid ) {
				return array(
					'website_uuid'      => $website_uuid,
					'manual_group_uuid' => $manual_uuid,
					'auto_group_uuid'   => (string) ( $site['auto_detection_group'] ?? '' ),
				);
			}
		}

		return array();
	}
}
