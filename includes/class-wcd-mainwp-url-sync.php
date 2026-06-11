<?php
/**
 * Sync a MainWP child site's URLs into its WebChange Detector group.
 *
 * Posts are fetched via the public `mainwp_getallposts` hook (hooks-first). Pages have no hook,
 * so they use the sanctioned, guarded `MainWP_Connect::fetch_urls_authed('get_all_pages')`.
 * URLs are pushed to the API with the two-step sync (sync-urls -> start-sync); start-sync is
 * queued server-side, so callers must poll the group URLs before screenshotting.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Syncs a MainWP child site's URLs into its WebChange Detector group.
 */
class WCD_MainWP_Url_Sync {

	const CONNECT_CLASS = '\\MainWP\\Dashboard\\MainWP_Connect';
	const PAGE_HANDLER  = '\\MainWP_REST_Controller'; // global namespace (no namespace declaration in core).
	const MAX_RECORDS   = 9999;

	/**
	 * Register the auto-sync hook.
	 *
	 * @return void
	 */
	public static function init(): void {
		// After MainWP syncs a child site, sync its URLs to the WCD group (best effort).
		add_action( 'mainwp_site_synced', array( self::class, 'on_site_synced' ), 10, 2 );
	}

	/**
	 * Auto-sync hook handler.
	 *
	 * @param object $p_website    The synced site object.
	 */
	public static function on_site_synced( $p_website ): void {
		$site_id = is_object( $p_website ) && isset( $p_website->id ) ? (int) $p_website->id : 0;
		if ( $site_id && WCD_MainWP_Site_Map::is_enabled( $site_id ) ) {
			self::sync_site( $site_id );
		}
	}

	/**
	 * Fetch the child's pages + posts and push them to its WCD group (two-step sync).
	 *
	 * @param int    $site_id   MainWP site id.
	 * @param string $api_token Optional API token to use for the WCD calls.
	 * @return array Result with 'ok' (bool), 'error' (string) and 'count' (int) keys.
	 */
	public static function sync_site( int $site_id, string $api_token = '' ): array {
		if ( ! WCD_MainWP_Site_Map::is_enabled( $site_id ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Site is not enabled for checks.', 'webchangedetector' ),
				'count' => 0,
			);
		}

		$domain = WCD_MainWP_Site_Map::get_domain( $site_id );
		if ( '' === $domain ) {
			return array(
				'ok'    => false,
				'error' => __( 'No domain stored for this site.', 'webchangedetector' ),
				'count' => 0,
			);
		}

		$base_url = self::site_base_url( $site_id );
		$pages    = self::extract_entries( self::fetch_pages( $site_id ), $base_url );
		$posts    = self::extract_entries( self::fetch_posts( $site_id ), $base_url );

		// Key convention mirrors the customer plugin / webapp: "{url_type}%%{url_category}".
		$payload = array();
		if ( $pages ) {
			$payload['types%%Page'] = $pages;
		}
		if ( $posts ) {
			$payload['types%%Post'] = $posts;
		}

		if ( empty( $payload ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'No URLs found on the child site. Is it connected?', 'webchangedetector' ),
				'count' => 0,
			);
		}

		$upload = WCD_MainWP_API::sync_urls( $payload, $domain, $api_token );
		if ( ! $upload['ok'] ) {
			return array(
				'ok'    => false,
				'error' => $upload['error'],
				'count' => 0,
			);
		}

		$start = WCD_MainWP_API::start_url_sync( $domain, true, $api_token );
		if ( ! $start['ok'] ) {
			return array(
				'ok'    => false,
				'error' => $start['error'],
				'count' => 0,
			);
		}

		return array(
			'ok'    => true,
			'error' => '',
			'count' => count( $pages ) + count( $posts ),
		);
	}

	/* ─────────────────────────── Fetch from child ──────────────────────── */

	/**
	 * Fetch posts via the public hook. Returns the raw child post array.
	 *
	 * @param int $site_id MainWP site id.
	 * @return array The raw child post array.
	 */
	protected static function fetch_posts( int $site_id ): array {
		$result = apply_filters(
			'mainwp_getallposts',
			array( $site_id ),
			array(
				'post_type'  => 'post',
				'status'     => 'publish',
				'maxRecords' => self::MAX_RECORDS,
			)
		);

		if ( is_object( $result ) && isset( $result->results[ $site_id ] ) && is_array( $result->results[ $site_id ] ) ) {
			return $result->results[ $site_id ];
		}

		return array();
	}

	/**
	 * Fetch pages via the sanctioned, guarded internal call (no hook exists).
	 *
	 * Mirrors MainWP's own "Manage Pages" fetch (page-mainwp-page.php): the site MUST be mapped
	 * with MainWP_Utility::map_site(...) using the default map fields, or fetch_urls_authed returns
	 * nothing. The structured data handler is MainWP_REST_Controller::posts_pages_search_handler,
	 * which writes $output->results[$website->id] (the table handler pages_search_handler only
	 * builds HTML).
	 *
	 * @param int $site_id MainWP site id.
	 * @return array The raw child page array.
	 */
	protected static function fetch_pages( int $site_id ): array {
		$db      = '\\MainWP\\Dashboard\\MainWP_DB';
		$util    = '\\MainWP\\Dashboard\\MainWP_Utility';
		$sysutil = '\\MainWP\\Dashboard\\MainWP_System_Utility';

		if ( ! class_exists( self::CONNECT_CLASS ) || ! method_exists( self::CONNECT_CLASS, 'fetch_urls_authed' )
			|| ! class_exists( $db ) || ! method_exists( $db, 'instance' )
			|| ! class_exists( $util ) || ! method_exists( $util, 'map_site' )
			|| ! class_exists( $sysutil ) || ! method_exists( $sysutil, 'get_default_map_site_fields' )
			|| ! method_exists( self::PAGE_HANDLER, 'posts_pages_search_handler' ) ) {
			return array();
		}

		$website = $db::instance()->get_website_by_id( $site_id );
		if ( empty( $website ) || empty( $website->id ) ) {
			return array();
		}

		// Map the site exactly as MainWP does before an authed child fetch.
		$fields     = $sysutil::get_default_map_site_fields();
		$mapped     = $util::map_site( $website, $fields );
		$dbwebsites = array( (int) $website->id => $mapped );

		$output          = new \stdClass();
		$output->results = array();
		$output->errors  = array();

		$post_data = apply_filters(
			'mainwp_get_all_pages_data',
			array(
				'keyword'    => '',
				'dtsstart'   => '',
				'dtsstop'    => '',
				'status'     => '',
				'search_on'  => '',
				'maxRecords' => self::MAX_RECORDS,
			)
		);

		call_user_func_array(
			array( self::CONNECT_CLASS, 'fetch_urls_authed' ),
			array( &$dbwebsites, 'get_all_pages', $post_data, array( self::PAGE_HANDLER, 'posts_pages_search_handler' ), &$output )
		);

		return isset( $output->results[ $website->id ] ) && is_array( $output->results[ $website->id ] ) ? $output->results[ $website->id ] : array();
	}

	/**
	 * Resolve the child site's base URL (with scheme), used to build per-item URLs.
	 *
	 * MainWP's bulk page/post fetch returns no permalink, so we rebuild the URL from the site URL
	 * (exactly as MainWP's own screens do). Prefer the stored MainWP website URL (carries the real
	 * scheme); fall back to the normalized site-map domain assuming https.
	 *
	 * @param int $site_id MainWP site id.
	 * @return string The base URL with scheme, or an empty string.
	 */
	protected static function site_base_url( int $site_id ): string {
		$db = '\\MainWP\\Dashboard\\MainWP_DB';
		if ( class_exists( $db ) && method_exists( $db, 'instance' ) ) {
			$website = $db::instance()->get_website_by_id( $site_id );
			if ( ! empty( $website->url ) ) {
				return $website->url;
			}
		}

		$domain = WCD_MainWP_Site_Map::get_domain( $site_id );

		return '' === $domain ? '' : 'https://' . $domain;
	}

	/* ──────────────────────────── Normalization ────────────────────────── */

	/**
	 * Turn a raw child page/post list into a list of [ 'url' => ..., 'html_title' => ... ] entries.
	 *
	 * @param mixed  $items    The raw child page/post list.
	 * @param string $base_url The site base URL used to build per-item URLs.
	 * @return array The normalized list of URL entries.
	 */
	protected static function extract_entries( $items, string $base_url ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$entries = array();
		foreach ( $items as $item ) {
			$item = (array) $item;
			$url  = self::url_of( $item, $base_url );
			if ( '' === $url ) {
				continue;
			}
			$entries[] = array(
				'url'        => $url,
				'html_title' => self::title_of( $item ),
			);
		}

		return $entries;
	}

	/**
	 * Resolve a single item's URL, building it from the base URL + id when no permalink exists.
	 *
	 * @param array  $item     The raw child page/post item.
	 * @param string $base_url The site base URL used to build the URL.
	 * @return string The resolved URL, or an empty string.
	 */
	protected static function url_of( array $item, string $base_url ): string {
		foreach ( array( 'link', 'url', 'permalink', 'guid' ) as $key ) {
			if ( ! empty( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
				return esc_url_raw( $item[ $key ] );
			}
		}

		// MainWP's bulk fetch returns only an id (no permalink). Build the URL exactly as MainWP's
		// own Manage Pages/Posts screens do; WordPress redirects ?p={id} to the real permalink.
		if ( '' !== $base_url && ! empty( $item['id'] ) ) {
			return esc_url_raw( trailingslashit( $base_url ) . '?p=' . (int) $item['id'] );
		}

		return '';
	}

	/**
	 * Resolve a single item's title from the first available title field.
	 *
	 * @param array $item The raw child page/post item.
	 * @return string The sanitized title, or an empty string.
	 */
	protected static function title_of( array $item ): string {
		foreach ( array( 'title', 'post_title', 'html_title' ) as $key ) {
			if ( ! empty( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
				return sanitize_text_field( $item[ $key ] );
			}
		}

		return '';
	}
}
