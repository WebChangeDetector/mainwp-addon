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

defined('ABSPATH') || exit;

class WCD_MainWP_Ajax
{
    const NONCE = 'wcd_mainwp_ajax';

    public static function init(): void
    {
        $actions = [
            'toggle_site',
            'sync_urls',
            'get_site_urls',
            'update_url',
            'banner_stats',
            'preflight',
            'take_pre',
            'run_update',
            'take_post',
            'poll',
            'results',
            'mark_comparison',
        ];

        foreach ($actions as $action) {
            add_action('wp_ajax_wcd_mainwp_' . $action, [self::class, $action]);
        }
    }

    /* ──────────────────────────── Guards/helpers ───────────────────────── */

    protected static function guard(): void
    {
        if (! check_ajax_referer(self::NONCE, 'nonce', false) || ! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'webchangedetector')], 403);
        }
    }

    protected static function token(): string
    {
        return WCD_MainWP_Site_Settings::getGlobal();
    }

    protected static function siteId(): int
    {
        return isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
    }

    /**
     * Unwrap a { data: ... } envelope from an API payload.
     */
    protected static function unwrap($data)
    {
        if (is_array($data) && isset($data['data'])) {
            return $data['data'];
        }

        return $data;
    }

    /* ─────────────────────────── Settings actions ──────────────────────── */

    public static function toggle_site(): void
    {
        self::guard();
        $siteId  = self::siteId();
        $enabled = ! empty($_POST['enabled']) && 'false' !== $_POST['enabled'];

        if (! $enabled) {
            WCD_MainWP_Site_Map::disableSite($siteId);
            wp_send_json_success(['enabled' => false]);
        }

        $result = WCD_MainWP_Site_Map::enableSite($siteId, self::token());
        if (! $result['ok']) {
            wp_send_json_error(['message' => $result['error']]);
        }

        // Auto-sync the site's URLs so the group is populated immediately (best effort; do not
        // fail the enable if there is nothing to sync yet).
        $sync = WCD_MainWP_Url_Sync::syncSite($siteId, self::token());

        wp_send_json_success([
            'enabled'      => true,
            'synced'       => $sync['ok'],
            'sync_message' => $sync['ok'] ? '' : $sync['error'],
        ]);
    }

    public static function sync_urls(): void
    {
        self::guard();
        $result = WCD_MainWP_Url_Sync::syncSite(self::siteId(), self::token());

        if (! $result['ok']) {
            wp_send_json_error(['message' => $result['error']]);
        }

        wp_send_json_success(['message' => __('URL sync started.', 'webchangedetector'), 'count' => $result['count']]);
    }

    public static function get_site_urls(): void
    {
        self::guard();
        $siteId  = self::siteId();
        $groupId = WCD_MainWP_Site_Map::getManualGroup($siteId);
        if ('' === $groupId) {
            wp_send_json_error(['message' => __('Site is not enabled.', 'webchangedetector')]);
        }

        $response = WCD_MainWP_API::getGroupUrls($groupId, self::token(), ['per_page' => 1000]);
        if (! $response['ok']) {
            wp_send_json_error(['message' => $response['error']]);
        }

        $urls   = WCD_MainWP_Update_Flow::extractUrls($response['data']);
        $active = 0;
        $clean  = [];
        foreach ($urls as $url) {
            $desktop = ! empty($url['desktop']);
            $mobile  = ! empty($url['mobile']);
            if ($desktop || $mobile) {
                $active++;
            }
            $clean[] = [
                'id'      => $url['id'] ?? '',
                'url'     => $url['url'] ?? '',
                'title'   => $url['html_title'] ?? '',
                'desktop' => $desktop,
                'mobile'  => $mobile,
            ];
        }

        wp_send_json_success(['urls' => $clean, 'active' => $active, 'total' => count($clean)]);
    }

    public static function update_url(): void
    {
        self::guard();
        $siteId  = self::siteId();
        $urlId   = isset($_POST['url_id']) ? sanitize_text_field(wp_unslash($_POST['url_id'])) : '';
        $desktop = ! empty($_POST['desktop']) && 'false' !== $_POST['desktop'];
        $mobile  = ! empty($_POST['mobile']) && 'false' !== $_POST['mobile'];
        $groupId = WCD_MainWP_Site_Map::getManualGroup($siteId);

        if ('' === $groupId || '' === $urlId) {
            wp_send_json_error(['message' => __('Missing group or URL.', 'webchangedetector')]);
        }

        $response = WCD_MainWP_API::updateUrlInGroup($groupId, $urlId, ['desktop' => $desktop, 'mobile' => $mobile], self::token());
        if (! $response['ok']) {
            wp_send_json_error(['message' => $response['error']]);
        }

        wp_send_json_success(['desktop' => $desktop, 'mobile' => $mobile]);
    }

    /**
     * Progressive stats for the hero banner: total Pages (URLs) and Checks (selected viewports)
     * across the scope. Loaded after the dashboard renders so WCD API calls never block it.
     */
    public static function banner_stats(): void
    {
        self::guard();

        $scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'bulk';
        if ('site' === $scope) {
            $siteId = self::siteId();
            $ids    = ($siteId && WCD_MainWP_Site_Map::isEnabled($siteId)) ? [$siteId] : [];
        } else {
            $ids = self::scopeSiteIds();
        }

        $token  = self::token();
        $pages  = 0;
        $checks = 0;
        foreach ($ids as $sid) {
            $groupId = WCD_MainWP_Site_Map::getManualGroup((int) $sid);
            if ('' === $groupId) {
                continue;
            }
            // The API aggregates the SELECTED (active) counts group-wide in `meta`, independent of
            // pagination: `selected_urls_count` = URLs with desktop or mobile enabled (= Pages),
            // `selected_checks_count` = total selected viewports (= Checks). So we only need the meta,
            // not the URL list: per_page=1 keeps the payload tiny instead of pulling every URL.
            $response = WCD_MainWP_API::getGroupUrls($groupId, $token, ['per_page' => 1]);
            if (! $response['ok'] || ! is_array($response['data'])) {
                continue;
            }
            $meta    = isset($response['data']['meta']) && is_array($response['data']['meta']) ? $response['data']['meta'] : [];
            $pages  += isset($meta['selected_urls_count']) ? (int) $meta['selected_urls_count'] : 0;
            $checks += isset($meta['selected_checks_count']) ? (int) $meta['selected_checks_count'] : 0;
        }

        wp_send_json_success(['sites' => count($ids), 'pages' => $pages, 'checks' => $checks]);
    }

    /* ───────────────────────── Orchestration actions ───────────────────── */

    public static function preflight(): void
    {
        self::guard();
        $scope = self::scopeSiteIds();
        $token = self::token();

        $sites    = [];
        $checks   = 0;
        $managed  = WCD_MainWP_Site_Map::managedSites();
        foreach ($scope as $siteId) {
            $groupId = WCD_MainWP_Site_Map::getManualGroup($siteId);
            if ('' === $groupId) {
                continue;
            }
            $siteChecks = WCD_MainWP_Update_Flow::checksForGroup($groupId, $token);
            $sites[]    = [
                'site_id' => $siteId,
                'name'    => $managed[$siteId]['name'] ?? '',
                'checks'  => $siteChecks,
            ];
            $checks += $siteChecks;
        }

        $account   = WCD_MainWP_Site_Settings::getAccount();
        $checksLeft = isset($account['checks_left']) ? (int) $account['checks_left'] : null;

        wp_send_json_success([
            'sites'       => $sites,
            'checks'      => $checks,
            'screenshots' => $checks * 2,
            'checks_left' => $checksLeft,
            'enough'      => null === $checksLeft ? true : ($checksLeft >= $checks),
        ]);
    }

    public static function take_pre(): void
    {
        self::takeScreenshot('pre');
    }

    public static function take_post(): void
    {
        self::takeScreenshot('post');
    }

    protected static function takeScreenshot(string $scType): void
    {
        self::guard();
        $groupId = WCD_MainWP_Site_Map::getManualGroup(self::siteId());
        if ('' === $groupId) {
            wp_send_json_error(['message' => __('Site is not enabled.', 'webchangedetector')]);
        }

        $response = WCD_MainWP_API::takeScreenshot([$groupId], $scType, 'manual', self::token());
        if (! $response['ok']) {
            $message = 402 === $response['status'] ? __('Not enough check credits.', 'webchangedetector') : $response['error'];
            wp_send_json_error(['message' => $message, 'status' => $response['status']]);
        }

        $data  = self::unwrap($response['data']);
        $batch = is_array($data) && ! empty($data['batch']) ? $data['batch'] : '';

        wp_send_json_success(['batch' => $batch]);
    }

    public static function run_update(): void
    {
        self::guard();
        $result = WCD_MainWP_Update_Flow::triggerSiteUpdate(self::siteId());

        if (! $result['ok']) {
            wp_send_json_error(['message' => $result['error'], 'offline' => $result['offline']]);
        }

        wp_send_json_success(['updated' => $result['updated'], 'message' => $result['error']]);
    }

    public static function poll(): void
    {
        self::guard();
        $batch = isset($_POST['batch']) ? sanitize_text_field(wp_unslash($_POST['batch'])) : '';
        if ('' === $batch) {
            wp_send_json_error(['message' => __('Missing batch id.', 'webchangedetector')]);
        }

        // The queues endpoint pre-aggregates per-batch status counts (and a per-sc_type breakdown) in
        // `meta`, so we only need the meta, not the items: per_page=1 keeps the payload tiny.
        $response = WCD_MainWP_API::getQueues($batch, '', self::token(), 1);
        if (! $response['ok']) {
            wp_send_json_error(['message' => $response['error']]);
        }

        $meta    = (is_array($response['data']) && isset($response['data']['meta']) && is_array($response['data']['meta'])) ? $response['data']['meta'] : [];
        $byBatch = (isset($meta['status_counts_by_batch'][$batch]) && is_array($meta['status_counts_by_batch'][$batch]))
            ? $meta['status_counts_by_batch'][$batch]
            : ((isset($meta['status_counts']) && is_array($meta['status_counts'])) ? $meta['status_counts'] : []);

        $bucket = static function ($counts): array {
            $counts = is_array($counts) ? $counts : [];

            return [
                'queue'      => (int) ($counts['open'] ?? 0),
                'processing' => (int) ($counts['processing'] ?? 0),
                'done'       => (int) ($counts['done'] ?? 0),
                'failed'     => (int) ($counts['failed'] ?? 0),
            ];
        };

        $aggregate = $bucket($byBatch);
        $remaining = $aggregate['queue'] + $aggregate['processing'];
        $finished  = $aggregate['done'] + $aggregate['failed'];

        wp_send_json_success(array_merge($aggregate, [
            'remaining' => $remaining,
            // Only "complete" once the queue is empty AND something finished, so we never stop on a
            // batch whose queue has not been populated yet.
            'complete'  => 0 === $remaining && $finished > 0,
        ]));
    }

    public static function results(): void
    {
        self::guard();
        $batch = isset($_POST['batch']) ? sanitize_text_field(wp_unslash($_POST['batch'])) : '';
        if ('' === $batch) {
            wp_send_json_error(['message' => __('Missing batch id.', 'webchangedetector')]);
        }

        $response = WCD_MainWP_API::getComparisons(['batches' => $batch, 'per_page' => 100], self::token());
        if (! $response['ok']) {
            wp_send_json_error(['message' => $response['error']]);
        }

        $comparisons = WCD_MainWP_Update_Flow::extractUrls($response['data']);
        wp_send_json_success(['comparisons' => array_map([self::class, 'shapeComparison'], $comparisons)]);
    }

    public static function mark_comparison(): void
    {
        self::guard();
        $id     = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        $valid  = ['ok', 'to_fix', 'false_positive'];

        if ('' === $id || ! in_array($status, $valid, true)) {
            wp_send_json_error(['message' => __('Invalid comparison or status.', 'webchangedetector')]);
        }

        $response = WCD_MainWP_API::updateComparison($id, $status, self::token());
        if (! $response['ok']) {
            wp_send_json_error(['message' => $response['error']]);
        }

        wp_send_json_success(['status' => $status]);
    }

    /* ────────────────────────────── Internals ──────────────────────────── */

    /**
     * Resolve the in-scope, enabled site ids from the request: an explicit site_ids[] list, a
     * single site_id, or (for a bulk run) all enabled sites.
     *
     * @return int[]
     */
    protected static function scopeSiteIds(): array
    {
        $ids = [];
        if (! empty($_POST['site_ids']) && is_array($_POST['site_ids'])) {
            $ids = array_map('intval', wp_unslash($_POST['site_ids']));
        } elseif (! empty($_POST['site_id'])) {
            $ids = [(int) $_POST['site_id']];
        } else {
            $ids = array_map('intval', array_keys(WCD_MainWP_Site_Map::all()));
        }

        return array_values(array_filter($ids, [WCD_MainWP_Site_Map::class, 'isEnabled']));
    }

    /**
     * Reduce a comparison resource to the fields the results view needs.
     */
    protected static function shapeComparison($c): array
    {
        $c       = (array) $c;
        $percent = isset($c['difference_percent']) ? (float) $c['difference_percent'] : 0.0;
        $status  = $c['status'] ?? 'new';

        return [
            'id'         => $c['id'] ?? '',
            'url'        => $c['url'] ?? '',
            'device'     => $c['device'] ?? '',
            'percent'    => $percent,
            'status'     => $status,
            'public'     => $c['public_link'] ?? '',
            'before'     => $c['screenshot_1_link'] ?? ($c['screenshot_1'] ?? ''),
            'after'      => $c['screenshot_2_link'] ?? ($c['screenshot_2'] ?? ''),
            'ai_summary' => self::aiSummary($c),
        ];
    }

    /**
     * Best-effort AI summary text (only present when the account has the feature). Never exposes
     * model names or crop URLs (the API already strips those server-side).
     */
    protected static function aiSummary(array $c): string
    {
        if (! empty($c['ai_verification_result']) && is_array($c['ai_verification_result'])) {
            $r = $c['ai_verification_result'];
            if (! empty($r['summary']) && is_string($r['summary'])) {
                return sanitize_text_field($r['summary']);
            }
            if (! empty($r['reason']) && is_string($r['reason'])) {
                return sanitize_text_field($r['reason']);
            }
        }

        return '';
    }
}
