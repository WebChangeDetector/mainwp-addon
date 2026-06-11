<?php
/**
 * HTTP client for the WebChange Detector API (v2).
 *
 * All methods are static. Every call returns a normalized result via {@see WCD_MainWP_API::request()}:
 *   [ 'ok' => bool, 'status' => int, 'data' => mixed, 'error' => string ]
 * so callers can show real errors instead of treating a network/HTTP failure as "no data".
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static HTTP client for the WebChange Detector API (v2).
 */
class WCD_MainWP_API {

	const DEFAULT_API_URL = 'https://api.webchangedetector.com/api/v2';

	/**
	 * Resolve the API base URL. Supports both override constants (WCD_API_URL and the
	 * historical WCD_API_URL_V2 used by .wp-env.json). Trailing slash is trimmed.
	 *
	 * @return string The resolved API base URL.
	 */
	protected static function get_api_url(): string {
		if ( defined( 'WCD_API_URL' ) && WCD_API_URL ) {
			return rtrim( WCD_API_URL, '/' );
		}

		if ( defined( 'WCD_API_URL_V2' ) && WCD_API_URL_V2 ) {
			return rtrim( WCD_API_URL_V2, '/' );
		}

		return self::DEFAULT_API_URL;
	}

	/**
	 * Perform an API request and normalize the result.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint  Endpoint path, leading slash (e.g. '/account').
	 * @param array  $body      Request body for non-GET requests.
	 * @param string $api_token  Bearer token; falls back to the stored token.
	 * @param array  $query     Query args appended to the URL.
	 * @param array  $headers   Extra request headers (e.g. x-wcd-domain).
	 * @param array  $req_opts  Request options: 'timeout' (int) and 'blocking' (bool).
	 * @return array Normalized result with keys 'ok' (bool), 'status' (int), 'data' (mixed), 'error' (string).
	 */
	protected static function request( string $method, string $endpoint, array $body = array(), string $api_token = '', array $query = array(), array $headers = array(), array $req_opts = array() ): array {
		if ( empty( $api_token ) ) {
			$api_token = WCD_MainWP_Site_Settings::get_global();
		}

		if ( empty( $api_token ) ) {
			return self::result( false, 0, null, __( 'No API token configured.', 'webchangedetector' ) );
		}

		$args = array(
			'method'   => $method,
			'timeout'  => isset( $req_opts['timeout'] ) ? (int) $req_opts['timeout'] : 30,
			'blocking' => ! isset( $req_opts['blocking'] ) || $req_opts['blocking'],
			// NOTE: we intentionally do NOT send x-wcd-plugin. That header makes the API treat the
			// caller as the customer WP plugin: the CheckWpVersion middleware would reject our
			// independently-versioned addon (0.1.0 -> 10 < config app.version 107), and WebsiteResource
			// would return the legacy shape. The webapp (the sibling agency dashboard) omits it too.
			'headers'  => array_merge(
				array(
					'Authorization' => 'Bearer ' . $api_token,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				$headers
			),
		);

		if ( 'GET' !== $method && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url = self::get_api_url() . $endpoint;
		if ( ! empty( $query ) ) {
			// add_query_arg already URL-encodes values; do not pre-encode (that double-encodes
			// comma-separated filters like status=open,processing).
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_request( $url, $args );

		// Fire-and-forget (non-blocking) request: nothing to parse, assume dispatched.
		if ( empty( $args['blocking'] ) ) {
			return self::result( ! is_wp_error( $response ), 0, null, is_wp_error( $response ) ? $response->get_error_message() : '' );
		}

		if ( is_wp_error( $response ) ) {
			return self::result( false, 0, null, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			return self::result( false, $status, $data, self::extract_error( $data, $status ) );
		}

		return self::result( true, $status, $data, '' );
	}

	/**
	 * Build a normalized result array.
	 *
	 * @param bool   $ok     Whether the request succeeded.
	 * @param int    $status HTTP status code (0 when no response).
	 * @param mixed  $data   Decoded response body.
	 * @param string $error  Human-readable error message (empty on success).
	 * @return array Normalized result with keys 'ok' (bool), 'status' (int), 'data' (mixed), 'error' (string).
	 */
	protected static function result( bool $ok, int $status, $data, string $error ): array {
		return array(
			'ok'     => $ok,
			'status' => $status,
			'data'   => $data,
			'error'  => $error,
		);
	}

	/**
	 * Pull a human-readable error message out of an API error body.
	 *
	 * @param mixed $data   Decoded body.
	 * @param int   $status HTTP status.
	 * @return string The extracted or generated error message.
	 */
	protected static function extract_error( $data, int $status ): string {
		if ( is_array( $data ) ) {
			if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
				return $data['message'];
			}
			if ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
				return $data['error'];
			}
			if ( ! empty( $data['errors'] ) ) {
				$first = is_array( $data['errors'] ) ? reset( $data['errors'] ) : $data['errors'];
				if ( is_array( $first ) ) {
					$first = reset( $first );
				}
				if ( is_string( $first ) ) {
					return $first;
				}
			}
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'API request failed (HTTP %d).', 'webchangedetector' ), $status );
	}

	/* ────────────────────────────── Account ────────────────────────────── */

	/**
	 * Get the account associated with the token. Used to verify the token + show plan/credits.
	 *
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function get_account( string $api_token = '' ): array {
		return self::request( 'GET', '/account', array(), $api_token );
	}

	/* ─────────────────────────────── Groups ────────────────────────────── */

	/**
	 * List groups.
	 *
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @param int    $per_page  Results per page.
	 * @return array Normalized API result.
	 */
	public static function list_groups( string $api_token = '', int $per_page = 100 ): array {
		return self::request( 'GET', '/groups', array(), $api_token, array( 'per_page' => $per_page ) );
	}

	/**
	 * Create a group. Only known fields are forwarded.
	 *
	 * @param array  $args      Group fields, e.g. 'name', 'monitoring' (false), 'cms' ('WordPress').
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function create_group( array $args, string $api_token = '' ): array {
		$allowed = array( 'name', 'monitoring', 'enabled', 'hour_of_day', 'interval_in_h', 'alert_emails', 'css', 'js', 'threshold', 'cms' );
		$body    = array_intersect_key( $args, array_flip( $allowed ) );

		return self::request( 'POST', '/groups', $body, $api_token );
	}

	/**
	 * Update a group (e.g. its name). Only known fields are forwarded.
	 *
	 * @param string $group_id  Group UUID.
	 * @param array  $args      Group fields to update (same allow-list as create_group()).
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function update_group( string $group_id, array $args, string $api_token = '' ): array {
		$allowed = array( 'name', 'monitoring', 'enabled', 'hour_of_day', 'interval_in_h', 'alert_emails', 'css', 'js', 'threshold', 'cms' );
		$body    = array_intersect_key( $args, array_flip( $allowed ) );

		return self::request( 'PUT', '/groups/' . rawurlencode( $group_id ), $body, $api_token );
	}

	/**
	 * Get the URLs configured in a group.
	 *
	 * @param string $group_id  Group UUID.
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @param array  $filters   Query filters appended to the URL.
	 * @return array Normalized API result.
	 */
	public static function get_group_urls( string $group_id, string $api_token = '', array $filters = array() ): array {
		return self::request( 'GET', '/groups/' . rawurlencode( $group_id ) . '/urls', array(), $api_token, $filters );
	}

	/**
	 * Bulk update URLs in a group (desktop/mobile booleans per URL).
	 *
	 * @param string $group_id  Group UUID.
	 * @param array  $urls      Array of items with keys 'id' (group_url_id), 'desktop' (bool), 'mobile' (bool).
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function update_urls_in_group( string $group_id, array $urls, string $api_token = '' ): array {
		return self::request( 'PUT', '/groups/' . rawurlencode( $group_id ) . '/urls', array( 'urls' => $urls ), $api_token );
	}

	/**
	 * Update a single URL in a group.
	 *
	 * @param string $group_id  Group UUID.
	 * @param string $url_id    Group URL ID.
	 * @param array  $fields    Fields to update, e.g. 'desktop' (true), 'mobile' (false).
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function update_url_in_group( string $group_id, string $url_id, array $fields, string $api_token = '' ): array {
		return self::request( 'PUT', '/groups/' . rawurlencode( $group_id ) . '/urls/' . rawurlencode( $url_id ), $fields, $api_token );
	}

	/* ───────────────────────────── Websites ────────────────────────────── */

	/**
	 * Create a website linked to a manual + auto detection group. The API backfills
	 * cms = 'WordPress' on the linked groups.
	 *
	 * @param string $domain          Website domain.
	 * @param string $manual_group_id Manual detection group UUID.
	 * @param string $auto_group_id   Auto detection group UUID.
	 * @param string $api_token       Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function create_website( string $domain, string $manual_group_id, string $auto_group_id, string $api_token = '' ): array {
		return self::request(
			'POST',
			'/websites',
			array(
				'domain'                    => $domain,
				'manual_detection_group_id' => $manual_group_id,
				'auto_detection_group_id'   => $auto_group_id,
			),
			$api_token
		);
	}

	/* ──────────────────────────── URL syncing ──────────────────────────── */

	/**
	 * Upload URLs for a domain (step 1 of 2). The domain is sent via the x-wcd-domain header.
	 *
	 * @param array  $urls      Keyed by "{url_type}%%{url_category}", each value an array of items with keys 'url' and 'html_title'.
	 * @param string $domain    Normalized domain (must match the website's stored domain byte-for-byte).
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function sync_urls( array $urls, string $domain, string $api_token = '' ): array {
		return self::request( 'POST', '/sync-urls', array( 'urls' => $urls ), $api_token, array(), array( 'x-wcd-domain' => $domain ) );
	}

	/**
	 * Start syncing the uploaded URLs into the live URL set (step 2 of 2). Queued server-side.
	 *
	 * @param string $domain              Normalized domain (must match the website's stored domain byte-for-byte).
	 * @param bool   $delete_missing_urls Whether to delete URLs no longer present in the upload.
	 * @param string $api_token           Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function start_url_sync( string $domain, bool $delete_missing_urls = true, string $api_token = '' ): array {
		return self::request( 'POST', '/start-sync', array( 'delete_missing_urls' => $delete_missing_urls ), $api_token, array(), array( 'x-wcd-domain' => $domain ) );
	}

	/* ─────────────────────────── Screenshots ───────────────────────────── */

	/**
	 * Trigger screenshots for groups.
	 *
	 * @param array  $group_ids Group UUIDs.
	 * @param string $sc_type   'pre' (baseline) or 'post' (compare + diff).
	 * @param string $source    'manual' | 'auto_update' | 'monitoring'.
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @param bool   $blocking  Whether to wait for the response (false dispatches fire-and-forget).
	 * @return array Normalized API result.
	 */
	public static function take_screenshot( array $group_ids, string $sc_type = 'pre', string $source = 'manual', string $api_token = '', bool $blocking = true ): array {
		$req_opts = $blocking ? array() : array(
			'blocking' => false,
			'timeout'  => 1,
		);

		return self::request(
			'POST',
			'/screenshots/take',
			array(
				'group_ids' => array_values( $group_ids ),
				'sc_type'   => $sc_type,
				'source'    => $source,
			),
			$api_token,
			array(),
			array(),
			$req_opts
		);
	}

	/* ─────────────────────────────── Queues ────────────────────────────── */

	/**
	 * Poll queue status for batches.
	 *
	 * @param mixed  $batch_ids Batch UUID(s) as an array or comma-separated string.
	 * @param mixed  $status    Status filter as an array or comma-separated string (e.g. 'open,processing,done,failed').
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @param int    $per_page  Results per page.
	 * @return array Normalized API result.
	 */
	public static function get_queues( $batch_ids = '', $status = '', string $api_token = '', int $per_page = 200 ): array {
		$query = array( 'per_page' => $per_page );
		if ( ! empty( $batch_ids ) ) {
			$query['batches'] = is_array( $batch_ids ) ? implode( ',', $batch_ids ) : $batch_ids;
		}
		if ( ! empty( $status ) ) {
			$query['status'] = is_array( $status ) ? implode( ',', $status ) : $status;
		}

		return self::request( 'GET', '/queues', array(), $api_token, $query );
	}

	/* ──────────────────────────── Comparisons ──────────────────────────── */

	/**
	 * Get comparisons. Pass e.g. 'batches' => 'uuid1,uuid2' or 'groups' => ... as filters.
	 *
	 * @param array  $filters   Query filters appended to the URL.
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function get_comparisons( array $filters = array(), string $api_token = '' ): array {
		return self::request( 'GET', '/comparisons', array(), $api_token, $filters );
	}

	/**
	 * Get a single batch (counts + summary).
	 *
	 * @param string $batch_id  Batch UUID.
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function get_batch( string $batch_id, string $api_token = '' ): array {
		return self::request( 'GET', '/batches/' . rawurlencode( $batch_id ), array(), $api_token );
	}

	/**
	 * List batches (runs). Accepts the same filters the webapp uses: 'page', 'per_page',
	 * 'from', 'to' (Y-m-d), 'source' (manual|monitoring|auto_update),
	 * 'status' ('new,ok,to_fix,false_positive'), 'group_ids' (csv), 'above_threshold' (bool).
	 *
	 * @param array  $filters   Query filters appended to the URL.
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function list_batches( array $filters = array(), string $api_token = '' ): array {
		return self::request( 'GET', '/batches', array(), $api_token, $filters );
	}

	/**
	 * Update a comparison status: 'ok' | 'to_fix' | 'false_positive'.
	 *
	 * @param string $id        Comparison ID.
	 * @param string $status    New status ('ok' | 'to_fix' | 'false_positive').
	 * @param string $api_token Bearer token; falls back to the stored token.
	 * @return array Normalized API result.
	 */
	public static function update_comparison( string $id, string $status, string $api_token = '' ): array {
		return self::request( 'PUT', '/comparisons/' . rawurlencode( $id ), array( 'status' => $status ), $api_token );
	}
}
