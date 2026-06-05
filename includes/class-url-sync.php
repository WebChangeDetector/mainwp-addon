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

defined('ABSPATH') || exit;

class WCD_MainWP_Url_Sync
{
    const CONNECT_CLASS = '\\MainWP\\Dashboard\\MainWP_Connect';
    const PAGE_HANDLER  = '\\MainWP_REST_Controller'; // global namespace (no namespace declaration in core).
    const MAX_RECORDS   = 9999;

    public static function init(): void
    {
        // After MainWP syncs a child site, sync its URLs to the WCD group (best effort).
        add_action('mainwp_site_synced', [self::class, 'onSiteSynced'], 10, 2);
    }

    /**
     * Auto-sync hook handler.
     *
     * @param object $pWebsite    The synced site object.
     * @param mixed  $information Sync payload (unused; we re-fetch the URL list).
     */
    public static function onSiteSynced($pWebsite, $information = null): void
    {
        $siteId = is_object($pWebsite) && isset($pWebsite->id) ? (int) $pWebsite->id : 0;
        if ($siteId && WCD_MainWP_Site_Map::isEnabled($siteId)) {
            self::syncSite($siteId);
        }
    }

    /**
     * Fetch the child's pages + posts and push them to its WCD group (two-step sync).
     *
     * @return array{ok: bool, error: string, count: int}
     */
    public static function syncSite(int $siteId, string $apiToken = ''): array
    {
        if (! WCD_MainWP_Site_Map::isEnabled($siteId)) {
            return ['ok' => false, 'error' => __('Site is not enabled for checks.', 'webchangedetector'), 'count' => 0];
        }

        $domain = WCD_MainWP_Site_Map::getDomain($siteId);
        if ('' === $domain) {
            return ['ok' => false, 'error' => __('No domain stored for this site.', 'webchangedetector'), 'count' => 0];
        }

        $baseUrl = self::siteBaseUrl($siteId);
        $pages   = self::extractEntries(self::fetchPages($siteId), $baseUrl);
        $posts   = self::extractEntries(self::fetchPosts($siteId), $baseUrl);

        // Key convention mirrors the customer plugin / webapp: "{url_type}%%{url_category}".
        $payload = [];
        if ($pages) {
            $payload['types%%Page'] = $pages;
        }
        if ($posts) {
            $payload['types%%Post'] = $posts;
        }

        if (empty($payload)) {
            return ['ok' => false, 'error' => __('No URLs found on the child site. Is it connected?', 'webchangedetector'), 'count' => 0];
        }

        $upload = WCD_MainWP_API::syncUrls($payload, $domain, $apiToken);
        if (! $upload['ok']) {
            return ['ok' => false, 'error' => $upload['error'], 'count' => 0];
        }

        $start = WCD_MainWP_API::startUrlSync($domain, true, $apiToken);
        if (! $start['ok']) {
            return ['ok' => false, 'error' => $start['error'], 'count' => 0];
        }

        return ['ok' => true, 'error' => '', 'count' => count($pages) + count($posts)];
    }

    /* ─────────────────────────── Fetch from child ──────────────────────── */

    /**
     * Fetch posts via the public hook. Returns the raw child post array.
     */
    protected static function fetchPosts(int $siteId): array
    {
        $result = apply_filters('mainwp_getallposts', [$siteId], [
            'post_type'  => 'post',
            'status'     => 'publish',
            'maxRecords' => self::MAX_RECORDS,
        ]);

        if (is_object($result) && isset($result->results[$siteId]) && is_array($result->results[$siteId])) {
            return $result->results[$siteId];
        }

        return [];
    }

    /**
     * Fetch pages via the sanctioned, guarded internal call (no hook exists).
     *
     * Mirrors MainWP's own "Manage Pages" fetch (page-mainwp-page.php): the site MUST be mapped
     * with MainWP_Utility::map_site(...) using the default map fields, or fetch_urls_authed returns
     * nothing. The structured data handler is MainWP_REST_Controller::posts_pages_search_handler,
     * which writes $output->results[$website->id] (the table handler pages_search_handler only
     * builds HTML).
     */
    protected static function fetchPages(int $siteId): array
    {
        $db      = '\\MainWP\\Dashboard\\MainWP_DB';
        $util    = '\\MainWP\\Dashboard\\MainWP_Utility';
        $sysutil = '\\MainWP\\Dashboard\\MainWP_System_Utility';

        if (! class_exists(self::CONNECT_CLASS) || ! method_exists(self::CONNECT_CLASS, 'fetch_urls_authed')
            || ! class_exists($db) || ! method_exists($db, 'instance')
            || ! class_exists($util) || ! method_exists($util, 'map_site')
            || ! class_exists($sysutil) || ! method_exists($sysutil, 'get_default_map_site_fields')
            || ! method_exists(self::PAGE_HANDLER, 'posts_pages_search_handler')) {
            return [];
        }

        $website = $db::instance()->get_website_by_id($siteId);
        if (empty($website) || empty($website->id)) {
            return [];
        }

        // Map the site exactly as MainWP does before an authed child fetch.
        $fields     = $sysutil::get_default_map_site_fields();
        $mapped     = $util::map_site($website, $fields);
        $dbwebsites = [(int) $website->id => $mapped];

        $output          = new \stdClass();
        $output->results = [];
        $output->errors  = [];

        $postData = apply_filters('mainwp_get_all_pages_data', [
            'keyword'    => '',
            'dtsstart'   => '',
            'dtsstop'    => '',
            'status'     => '',
            'search_on'  => '',
            'maxRecords' => self::MAX_RECORDS,
        ]);

        call_user_func_array(
            [self::CONNECT_CLASS, 'fetch_urls_authed'],
            [&$dbwebsites, 'get_all_pages', $postData, [self::PAGE_HANDLER, 'posts_pages_search_handler'], &$output]
        );

        return isset($output->results[$website->id]) && is_array($output->results[$website->id]) ? $output->results[$website->id] : [];
    }

    /**
     * Resolve the child site's base URL (with scheme), used to build per-item URLs.
     *
     * MainWP's bulk page/post fetch returns no permalink, so we rebuild the URL from the site URL
     * (exactly as MainWP's own screens do). Prefer the stored MainWP website URL (carries the real
     * scheme); fall back to the normalized site-map domain assuming https.
     */
    protected static function siteBaseUrl(int $siteId): string
    {
        $db = '\\MainWP\\Dashboard\\MainWP_DB';
        if (class_exists($db) && method_exists($db, 'instance')) {
            $website = $db::instance()->get_website_by_id($siteId);
            if (! empty($website->url)) {
                return $website->url;
            }
        }

        $domain = WCD_MainWP_Site_Map::getDomain($siteId);

        return '' === $domain ? '' : 'https://' . $domain;
    }

    /* ──────────────────────────── Normalization ────────────────────────── */

    /**
     * Turn a raw child page/post list into [ [ 'url' => ..., 'html_title' => ... ], ... ].
     */
    protected static function extractEntries($items, string $baseUrl): array
    {
        if (! is_array($items)) {
            return [];
        }

        $entries = [];
        foreach ($items as $item) {
            $item = (array) $item;
            $url  = self::urlOf($item, $baseUrl);
            if ('' === $url) {
                continue;
            }
            $entries[] = [
                'url'        => $url,
                'html_title' => self::titleOf($item),
            ];
        }

        return $entries;
    }

    protected static function urlOf(array $item, string $baseUrl): string
    {
        foreach (['link', 'url', 'permalink', 'guid'] as $key) {
            if (! empty($item[$key]) && is_string($item[$key])) {
                return esc_url_raw($item[$key]);
            }
        }

        // MainWP's bulk fetch returns only an id (no permalink). Build the URL exactly as MainWP's
        // own Manage Pages/Posts screens do; WordPress redirects ?p={id} to the real permalink.
        if ('' !== $baseUrl && ! empty($item['id'])) {
            return esc_url_raw(trailingslashit($baseUrl) . '?p=' . (int) $item['id']);
        }

        return '';
    }

    protected static function titleOf(array $item): string
    {
        foreach (['title', 'post_title', 'html_title'] as $key) {
            if (! empty($item[$key]) && is_string($item[$key])) {
                return sanitize_text_field($item[$key]);
            }
        }

        return '';
    }
}
