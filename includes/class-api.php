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

defined('ABSPATH') || exit;

class WCD_MainWP_API
{
    const DEFAULT_API_URL = 'https://api.webchangedetector.com/api/v2';

    /**
     * Resolve the API base URL. Supports both override constants (WCD_API_URL and the
     * historical WCD_API_URL_V2 used by .wp-env.json). Trailing slash is trimmed.
     */
    protected static function getApiUrl(): string
    {
        if (defined('WCD_API_URL') && WCD_API_URL) {
            return rtrim(WCD_API_URL, '/');
        }

        if (defined('WCD_API_URL_V2') && WCD_API_URL_V2) {
            return rtrim(WCD_API_URL_V2, '/');
        }

        return self::DEFAULT_API_URL;
    }

    /**
     * Perform an API request and normalize the result.
     *
     * @param string $method   HTTP method.
     * @param string $endpoint  Endpoint path, leading slash (e.g. '/account').
     * @param array  $body      Request body for non-GET requests.
     * @param string $apiToken  Bearer token; falls back to the stored token.
     * @param array  $query     Query args appended to the URL.
     * @param array  $headers   Extra request headers (e.g. x-wcd-domain).
     * @return array{ok: bool, status: int, data: mixed, error: string}
     */
    protected static function request(string $method, string $endpoint, array $body = [], string $apiToken = '', array $query = [], array $headers = [], array $reqOpts = []): array
    {
        if (empty($apiToken)) {
            $apiToken = WCD_MainWP_Site_Settings::getGlobal();
        }

        if (empty($apiToken)) {
            return self::result(false, 0, null, __('No API token configured.', 'webchangedetector'));
        }

        $args = [
            'method'  => $method,
            'timeout' => isset($reqOpts['timeout']) ? (int) $reqOpts['timeout'] : 30,
            'blocking' => ! isset($reqOpts['blocking']) || $reqOpts['blocking'],
            // NOTE: we intentionally do NOT send x-wcd-plugin. That header makes the API treat the
            // caller as the customer WP plugin: the CheckWpVersion middleware would reject our
            // independently-versioned addon (0.1.0 -> 10 < config app.version 107), and WebsiteResource
            // would return the legacy shape. The webapp (the sibling agency dashboard) omits it too.
            'headers' => array_merge(
                [
                    'Authorization' => 'Bearer ' . $apiToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                $headers
            ),
        ];

        if ('GET' !== $method && ! empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $url = self::getApiUrl() . $endpoint;
        if (! empty($query)) {
            // add_query_arg already URL-encodes values; do not pre-encode (that double-encodes
            // comma-separated filters like status=open,processing).
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_request($url, $args);

        // Fire-and-forget (non-blocking) request: nothing to parse, assume dispatched.
        if (empty($args['blocking'])) {
            return self::result(! is_wp_error($response), 0, null, is_wp_error($response) ? $response->get_error_message() : '');
        }

        if (is_wp_error($response)) {
            return self::result(false, 0, null, $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            return self::result(false, $status, $data, self::extractError($data, $status));
        }

        return self::result(true, $status, $data, '');
    }

    /**
     * Build a normalized result array.
     */
    protected static function result(bool $ok, int $status, $data, string $error): array
    {
        return [
            'ok'     => $ok,
            'status' => $status,
            'data'   => $data,
            'error'  => $error,
        ];
    }

    /**
     * Pull a human-readable error message out of an API error body.
     *
     * @param mixed $data   Decoded body.
     * @param int   $status HTTP status.
     */
    protected static function extractError($data, int $status): string
    {
        if (is_array($data)) {
            if (! empty($data['message']) && is_string($data['message'])) {
                return $data['message'];
            }
            if (! empty($data['error']) && is_string($data['error'])) {
                return $data['error'];
            }
            if (! empty($data['errors'])) {
                $first = is_array($data['errors']) ? reset($data['errors']) : $data['errors'];
                if (is_array($first)) {
                    $first = reset($first);
                }
                if (is_string($first)) {
                    return $first;
                }
            }
        }

        /* translators: %d: HTTP status code. */
        return sprintf(__('API request failed (HTTP %d).', 'webchangedetector'), $status);
    }

    /* ────────────────────────────── Account ────────────────────────────── */

    /**
     * Get the account associated with the token. Used to verify the token + show plan/credits.
     */
    public static function getAccount(string $apiToken = ''): array
    {
        return self::request('GET', '/account', [], $apiToken);
    }

    /* ─────────────────────────────── Groups ────────────────────────────── */

    /**
     * List groups.
     */
    public static function listGroups(string $apiToken = '', int $perPage = 100): array
    {
        return self::request('GET', '/groups', [], $apiToken, ['per_page' => $perPage]);
    }

    /**
     * Create a group. Only known fields are forwarded.
     *
     * @param array $args e.g. [ 'name' => ..., 'monitoring' => false, 'cms' => 'wordpress' ].
     */
    public static function createGroup(array $args, string $apiToken = ''): array
    {
        $allowed = ['name', 'monitoring', 'enabled', 'hour_of_day', 'interval_in_h', 'alert_emails', 'css', 'js', 'threshold', 'cms'];
        $body    = array_intersect_key($args, array_flip($allowed));

        return self::request('POST', '/groups', $body, $apiToken);
    }

    /**
     * Update a group (e.g. its name). Only known fields are forwarded.
     */
    public static function updateGroup(string $groupId, array $args, string $apiToken = ''): array
    {
        $allowed = ['name', 'monitoring', 'enabled', 'hour_of_day', 'interval_in_h', 'alert_emails', 'css', 'js', 'threshold', 'cms'];
        $body    = array_intersect_key($args, array_flip($allowed));

        return self::request('PUT', '/groups/' . rawurlencode($groupId), $body, $apiToken);
    }

    /**
     * Get the URLs configured in a group.
     */
    public static function getGroupUrls(string $groupId, string $apiToken = '', array $filters = []): array
    {
        return self::request('GET', '/groups/' . rawurlencode($groupId) . '/urls', [], $apiToken, $filters);
    }

    /**
     * Bulk update URLs in a group (desktop/mobile booleans per URL).
     *
     * @param array $urls Array of [ 'id' => group_url_id, 'desktop' => bool, 'mobile' => bool ].
     */
    public static function updateUrlsInGroup(string $groupId, array $urls, string $apiToken = ''): array
    {
        return self::request('PUT', '/groups/' . rawurlencode($groupId) . '/urls', ['urls' => $urls], $apiToken);
    }

    /**
     * Update a single URL in a group.
     *
     * @param array $fields e.g. [ 'desktop' => true, 'mobile' => false ].
     */
    public static function updateUrlInGroup(string $groupId, string $urlId, array $fields, string $apiToken = ''): array
    {
        return self::request('PUT', '/groups/' . rawurlencode($groupId) . '/urls/' . rawurlencode($urlId), $fields, $apiToken);
    }

    /* ───────────────────────────── Websites ────────────────────────────── */

    /**
     * Create a website linked to a manual + auto detection group. The API backfills
     * cms = 'wordpress' on the linked groups.
     */
    public static function createWebsite(string $domain, string $manualGroupId, string $autoGroupId, string $apiToken = ''): array
    {
        return self::request('POST', '/websites', [
            'domain'                    => $domain,
            'manual_detection_group_id' => $manualGroupId,
            'auto_detection_group_id'   => $autoGroupId,
        ], $apiToken);
    }

    /* ──────────────────────────── URL syncing ──────────────────────────── */

    /**
     * Upload URLs for a domain (step 1 of 2). The domain is sent via the x-wcd-domain header.
     *
     * @param array  $urls   Keyed by "{url_type}%%{url_category}" => array of [ 'url' => ..., 'html_title' => ... ].
     * @param string $domain Normalized domain (must match the website's stored domain byte-for-byte).
     */
    public static function syncUrls(array $urls, string $domain, string $apiToken = ''): array
    {
        return self::request('POST', '/sync-urls', ['urls' => $urls], $apiToken, [], ['x-wcd-domain' => $domain]);
    }

    /**
     * Start syncing the uploaded URLs into the live URL set (step 2 of 2). Queued server-side.
     */
    public static function startUrlSync(string $domain, bool $deleteMissingUrls = true, string $apiToken = ''): array
    {
        return self::request('POST', '/start-sync', ['delete_missing_urls' => $deleteMissingUrls], $apiToken, [], ['x-wcd-domain' => $domain]);
    }

    /* ─────────────────────────── Screenshots ───────────────────────────── */

    /**
     * Trigger screenshots for groups.
     *
     * @param string[] $groupIds Group UUIDs.
     * @param string   $scType   'pre' (baseline) or 'post' (compare + diff).
     * @param string   $source   'manual' | 'auto_update' | 'monitoring'.
     */
    public static function takeScreenshot(array $groupIds, string $scType = 'pre', string $source = 'manual', string $apiToken = '', bool $blocking = true): array
    {
        $reqOpts = $blocking ? [] : ['blocking' => false, 'timeout' => 1];

        return self::request('POST', '/screenshots/take', [
            'group_ids' => array_values($groupIds),
            'sc_type'   => $scType,
            'source'    => $source,
        ], $apiToken, [], [], $reqOpts);
    }

    /* ─────────────────────────────── Queues ────────────────────────────── */

    /**
     * Poll queue status for batches.
     *
     * @param string[]|string $batchIds Batch UUID(s).
     * @param string[]|string $status   Status filter (e.g. 'open,processing,done,failed').
     */
    public static function getQueues($batchIds = '', $status = '', string $apiToken = '', int $perPage = 200): array
    {
        $query = ['per_page' => $perPage];
        if (! empty($batchIds)) {
            $query['batches'] = is_array($batchIds) ? implode(',', $batchIds) : $batchIds;
        }
        if (! empty($status)) {
            $query['status'] = is_array($status) ? implode(',', $status) : $status;
        }

        return self::request('GET', '/queues', [], $apiToken, $query);
    }

    /* ──────────────────────────── Comparisons ──────────────────────────── */

    /**
     * Get comparisons. Pass e.g. [ 'batches' => 'uuid1,uuid2' ] or [ 'groups' => ... ].
     */
    public static function getComparisons(array $filters = [], string $apiToken = ''): array
    {
        return self::request('GET', '/comparisons', [], $apiToken, $filters);
    }

    /**
     * Get a single batch (counts + summary).
     */
    public static function getBatch(string $batchId, string $apiToken = ''): array
    {
        return self::request('GET', '/batches/' . rawurlencode($batchId), [], $apiToken);
    }

    /**
     * Update a comparison status: 'ok' | 'to_fix' | 'false_positive'.
     */
    public static function updateComparison(string $id, string $status, string $apiToken = ''): array
    {
        return self::request('PUT', '/comparisons/' . rawurlencode($id), ['status' => $status], $apiToken);
    }
}
