<?php
/**
 * "Change Detections" overview page (Sites submenu; slug WcdChangeDetections, MainWP page hook
 * ManageSitesWcdChangeDetections).
 *
 * A dashboard-wide list of runs (batches) across the account, modeled on the webapp's Change
 * Detections view: filter bar (period / status / type / website / visual), batch + list views,
 * pagination, and an inline comparison table per run. Data comes from the WCD API
 * (/batches + /comparisons); the markup/AJAX live here, the styles in assets/css/wcd-runs.css.
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

class WCD_MainWP_Runs_View
{
    const PAGE_SLUG = 'WcdChangeDetections';
    const PER_PAGE  = 20;

    public static function init(): void
    {
        add_filter('mainwp_getsubpages_sites', [self::class, 'registerPage']);
    }

    /**
     * Register the page as a Sites submenu entry (not a per-site tab, so it shows in the left menu).
     */
    public static function registerPage(array $subPages): array
    {
        $subPages[] = [
            'title'    => 'Change Detections',
            'slug'     => self::PAGE_SLUG,
            'sitetab'  => false,
            'callback' => [self::class, 'renderPage'],
        ];

        return $subPages;
    }

    public static function renderPage(): void
    {
        include WCD_MAINWP_PLUGIN_PATH . 'templates/runs-view.php';
    }

    /* ─────────────────────────── Filter options ────────────────────────── */

    public static function statusOptions(): array
    {
        return [
            'new'            => __('New', 'webchangedetector'),
            'ok'             => __('OK', 'webchangedetector'),
            'to_fix'         => __('To Fix', 'webchangedetector'),
            'false_positive' => __('False positive', 'webchangedetector'),
        ];
    }

    public static function sourceOptions(): array
    {
        return [
            ''            => __('All types', 'webchangedetector'),
            'manual'      => __('On-Demand Checks', 'webchangedetector'),
            'monitoring'  => __('Monitoring', 'webchangedetector'),
            'auto_update' => __('Auto-Update Checks', 'webchangedetector'),
        ];
    }

    /**
     * Enabled managed sites for the website filter: [ ['site_id' => int, 'name' => string], ... ].
     */
    public static function websiteOptions(): array
    {
        $map     = WCD_MainWP_Site_Map::all();
        $managed = WCD_MainWP_Site_Map::managedSites();
        $out     = [];
        foreach ($map as $siteId => $entry) {
            if (empty($entry['enabled'])) {
                continue;
            }
            $siteId = (int) $siteId;
            $out[]  = [
                'site_id' => $siteId,
                'name'    => $managed[$siteId]['name'] ?? ($entry['domain'] ?? ('Site #' . $siteId)),
            ];
        }

        return $out;
    }

    /* ──────────────────────────── Filter mapping ───────────────────────── */

    /**
     * Map raw request input to the API filter array used for /batches and /comparisons. Mirrors the
     * webapp's mapping (difference_only -> above_threshold, selected sites -> group_ids).
     */
    public static function buildApiFilters(array $input): array
    {
        // No orderBy here: /batches uses its own default (newest first), matching the webapp. The flat
        // (/comparisons) and drill-in paths set their own ordering.
        $filters = [
            'page'     => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => self::PER_PAGE,
        ];

        $from = isset($input['from']) ? trim((string) $input['from']) : '';
        $to   = isset($input['to']) ? trim((string) $input['to']) : '';
        if ('' !== $from) {
            $filters['from'] = gmdate('Y-m-d', strtotime($from));
        }
        if ('' !== $to) {
            $filters['to'] = gmdate('Y-m-d', strtotime($to));
        }

        $source = isset($input['source']) ? (string) $input['source'] : '';
        if ('' !== $source) {
            $filters['source'] = $source;
        }

        $status            = isset($input['status']) ? (string) $input['status'] : '';
        $filters['status'] = '' !== $status ? $status : 'new,ok,to_fix,false_positive';

        if (! empty($input['difference_only'])) {
            $filters['above_threshold'] = true;
        }

        $groupIds = self::resolveGroupIds($input['site_ids'] ?? []);
        if (! empty($groupIds)) {
            $filters['group_ids'] = implode(',', $groupIds);
        }

        return $filters;
    }

    /**
     * Resolve selected MainWP site ids to their WCD group UUIDs (manual + auto). Empty when nothing
     * selected (the view then shows all of the account's runs).
     *
     * @param mixed $siteIds
     * @return string[]
     */
    protected static function resolveGroupIds($siteIds): array
    {
        if (! is_array($siteIds)) {
            return [];
        }
        $map = WCD_MainWP_Site_Map::all();
        $ids = [];
        foreach ($siteIds as $sid) {
            $entry = $map[(int) $sid] ?? [];
            if (! empty($entry['manual_group_uuid'])) {
                $ids[] = $entry['manual_group_uuid'];
            }
            if (! empty($entry['auto_group_uuid'])) {
                $ids[] = $entry['auto_group_uuid'];
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /* ─────────────────────────────── Rendering ─────────────────────────── */

    /**
     * Render the batch (accordion) list for the given API filters.
     *
     * @return array{html: string, pagination: string}
     */
    public static function renderBatchList(array $apiFilters): array
    {
        $response = WCD_MainWP_API::listBatches($apiFilters);
        if (! $response['ok']) {
            return ['html' => self::messageBox('wcd-error', $response['error']), 'pagination' => ''];
        }

        $data    = is_array($response['data']) ? $response['data'] : [];
        $batches = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];
        $meta    = (isset($data['meta']) && is_array($data['meta'])) ? $data['meta'] : [];

        if (empty($batches)) {
            return ['html' => self::emptyBox(), 'pagination' => ''];
        }

        ob_start();
        foreach ($batches as $batch) {
            self::renderBatchCard((array) $batch);
        }

        return ['html' => ob_get_clean(), 'pagination' => self::renderPagination($meta)];
    }

    /**
     * Render the flat comparison list for the given API filters.
     *
     * @return array{html: string, pagination: string}
     */
    public static function renderFlatList(array $apiFilters): array
    {
        // Newest comparisons first (matches the webapp's flat List view).
        $apiFilters['orderBy']        = 'created_at';
        $apiFilters['orderDirection'] = 'desc';
        $response                     = WCD_MainWP_API::getComparisons($apiFilters);
        if (! $response['ok']) {
            return ['html' => self::messageBox('wcd-error', $response['error']), 'pagination' => ''];
        }

        $data        = is_array($response['data']) ? $response['data'] : [];
        $comparisons = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];
        $meta        = (isset($data['meta']) && is_array($data['meta'])) ? $data['meta'] : [];

        if (empty($comparisons)) {
            return ['html' => self::emptyBox(), 'pagination' => ''];
        }

        return ['html' => self::renderComparisonsTable($comparisons, true), 'pagination' => self::renderPagination($meta)];
    }

    /**
     * Render a comparison table (used by the batch drill-in and the flat view).
     */
    public static function renderComparisonsTable(array $comparisons, bool $withRun = false): string
    {
        if (empty($comparisons)) {
            return '<p class="wcd-muted">' . esc_html__('No comparisons in this run.', 'webchangedetector') . '</p>';
        }

        ob_start();
        ?>
        <table class="wcd-table">
            <thead>
                <tr class="table-headline-row">
                    <th><?php esc_html_e('Status', 'webchangedetector'); ?></th>
                    <?php if ($withRun) : ?><th><?php esc_html_e('Run', 'webchangedetector'); ?></th><?php endif; ?>
                    <th><?php esc_html_e('URL', 'webchangedetector'); ?></th>
                    <th><?php esc_html_e('Compared', 'webchangedetector'); ?></th>
                    <th><?php esc_html_e('Visual change', 'webchangedetector'); ?></th>
                    <th><?php esc_html_e('AI summary', 'webchangedetector'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comparisons as $c) {
                    self::comparisonRow((array) $c, $withRun);
                } ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    /* ──────────────────────── Rendering internals ──────────────────────── */

    protected static function renderBatchCard(array $batch): void
    {
        $batchId     = (string) ($batch['id'] ?? '');
        $failed      = (int) ($batch['queues_count']['failed'] ?? 0);
        $counts      = (isset($batch['comparisons_count']) && is_array($batch['comparisons_count'])) ? $batch['comparisons_count'] : [];
        $displayName = self::displayBatchName($batch['name'] ?? '');
        $sourceLabel = self::sourceLabel($batch['source'] ?? '');
        $groupNames  = (! empty($batch['group_names']) && is_array($batch['group_names'])) ? $batch['group_names'] : [];
        $finishedAt  = $batch['finished_at'] ?? '';
        $aiSummary   = $batch['ai_summary']['summary'] ?? '';
        ?>
        <div class="wcd-runs-batch" data-batch-id="<?php echo esc_attr($batchId); ?>">
            <div class="wcd-runs-batch-head">
                <span class="wcd-runs-caret dashicons dashicons-arrow-right-alt2"></span>
                <div class="wcd-runs-col wcd-runs-col-status">
                    <div class="wcd-status-badges">
                        <?php
                        foreach ($counts as $status => $amount) {
                            if ((int) $amount > 0 && 'above_threshold' !== $status) {
                                self::statusBadge((string) $status, (int) $amount);
                            }
                        }
                        if ($failed > 0) {
                            self::statusBadge('failed', $failed);
                        }
                        ?>
                    </div>
                </div>
                <div class="wcd-runs-col wcd-runs-col-name">
                    <strong><?php esc_html_e('Change Detection', 'webchangedetector'); ?></strong>
                    <span class="wcd-cd-name"><?php echo esc_html($displayName); ?></span>
                    <?php if ($sourceLabel && $sourceLabel !== $displayName) : ?>
                        <span class="wcd-cd-subtitle"><?php echo esc_html($sourceLabel); ?></span>
                    <?php endif; ?>
                    <?php if (! empty($groupNames)) : ?>
                        <span class="wcd-cd-websites"><?php echo esc_html(implode(', ', $groupNames)); ?></span>
                    <?php endif; ?>
                </div>
                <div class="wcd-runs-col wcd-runs-col-date">
                    <strong><?php esc_html_e('Created', 'webchangedetector'); ?></strong>
                    <span>
                        <?php
                        if ($finishedAt) {
                            echo esc_html(self::timeAgo($finishedAt));
                            echo '<br>';
                            echo esc_html(date_i18n('d/m/Y H:i', strtotime($finishedAt)));
                        } else {
                            esc_html_e('Processing', 'webchangedetector');
                        }
                        ?>
                    </span>
                </div>
                <div class="wcd-runs-col wcd-runs-col-summary">
                    <strong><?php esc_html_e('AI Summary', 'webchangedetector'); ?></strong>
                    <?php if ($finishedAt) : ?>
                        <span class="wcd-ai-summary-text"><?php echo esc_html($aiSummary ?: __('AI summary skipped', 'webchangedetector')); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wcd-runs-batch-body" hidden>
                <div class="wcd-runs-loading"><div class="ui active inline loader"></div></div>
            </div>
        </div>
        <?php
    }

    protected static function comparisonRow(array $c, bool $withRun): void
    {
        $status  = $c['status'] ?? 'none';
        if (null === $status || '' === $status) {
            $status = 'none';
        }
        $url     = (string) ($c['url'] ?? '');
        $title   = (string) ($c['html_title'] ?? '');
        $device  = (string) ($c['device'] ?? '');
        $percent = isset($c['difference_percent']) ? (float) $c['difference_percent'] : 0.0;
        $public  = (string) ($c['public_link'] ?? '');
        $before  = (string) ($c['screenshot_1_created_at'] ?? '');
        $after   = (string) ($c['screenshot_2_created_at'] ?? '');
        $ai      = (! empty($c['ai_verification_result']['summary']) && is_string($c['ai_verification_result']['summary']))
            ? $c['ai_verification_result']['summary']
            : '';
        // Presentation-only colour hint (low/high). NOT the per-group "above threshold" decision,
        // which the API owns; this just tints the percentage.
        $sev     = $percent <= 0 ? '' : ($percent < 5 ? 'wcd-vc-sev-low' : 'wcd-vc-sev-high');
        ?>
        <tr>
            <td><?php self::statusBadge((string) $status); ?></td>
            <?php if ($withRun) : ?>
                <td><?php echo esc_html(self::displayBatchName($c['batch_name'] ?? '')); ?></td>
            <?php endif; ?>
            <td>
                <span class="<?php echo esc_attr(self::deviceIconClass($device)); ?>"></span>
                <span class="wcd-url-link"><?php echo esc_html($title ?: $url); ?></span>
                <?php if ($url) : ?><div class="wcd-url-path"><?php echo esc_html($url); ?></div><?php endif; ?>
            </td>
            <td>
                <?php if ($before || $after) : ?>
                    <div class="wcd-compared-row"><span class="wcd-ba-label"><?php esc_html_e('Before', 'webchangedetector'); ?></span><span class="screenshot-date"><?php echo esc_html(self::shortDate($before)); ?></span></div>
                    <div class="wcd-compared-row"><span class="wcd-ba-label"><?php esc_html_e('After', 'webchangedetector'); ?></span><span class="screenshot-date"><?php echo esc_html(self::shortDate($after)); ?></span></div>
                <?php endif; ?>
            </td>
            <td class="wcd-visual-changes-column">
                <span class="wcd-visual-percentage <?php echo esc_attr($sev); ?>"><?php echo esc_html(self::formatPercent($percent)); ?>%</span>
            </td>
            <td><?php echo esc_html($ai); ?></td>
            <td><?php if ($public) : ?><a class="ui mini button" href="<?php echo esc_url($public); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'webchangedetector'); ?></a><?php endif; ?></td>
        </tr>
        <?php
    }

    /**
     * Status dot-pill, mirroring the webapp's prettyPrintComparisonStatus (built with esc_* so it is
     * safe to echo directly).
     *
     * @param int|null $count Optional count shown after the label.
     */
    protected static function statusBadge(string $status, $count = null): void
    {
        $meta = [
            'new'            => ['wcd-status-new', __('New', 'webchangedetector')],
            'ok'             => ['wcd-status-ok', __('OK', 'webchangedetector')],
            'to_fix'         => ['wcd-status-to-fix', __('To Fix', 'webchangedetector')],
            'false_positive' => ['wcd-status-false-positive', __('False positive', 'webchangedetector')],
            'failed'         => ['wcd-status-failed', __('Failed', 'webchangedetector')],
            'none'           => ['wcd-status-none', __('No changes', 'webchangedetector')],
        ];
        $modifier = $meta[$status][0] ?? 'wcd-status-none';
        $label    = $meta[$status][1] ?? ucfirst($status);
        ?>
        <span class="wcd-status-badge <?php echo esc_attr($modifier); ?>">
            <span class="wcd-status-dot"></span>
            <span class="wcd-status-text"><?php echo esc_html($label); ?></span>
            <?php if (null !== $count) : ?><span class="wcd-status-count"><?php echo esc_html((string) $count); ?></span><?php endif; ?>
        </span>
        <?php
    }

    protected static function renderPagination(array $meta): string
    {
        $current = (int) ($meta['current_page'] ?? 1);
        $last    = (int) ($meta['last_page'] ?? 0);
        if ($last < 1 && isset($meta['total'], $meta['per_page']) && (int) $meta['per_page'] > 0) {
            $last = (int) ceil((int) $meta['total'] / (int) $meta['per_page']);
        }
        if ($last <= 1) {
            return '';
        }

        ob_start();
        ?>
        <div class="wcd-runs-pagination">
            <button type="button" class="ui button wcd-runs-page" data-page="<?php echo esc_attr((string) max(1, $current - 1)); ?>" <?php disabled($current <= 1); ?>><?php esc_html_e('Previous', 'webchangedetector'); ?></button>
            <span class="wcd-runs-page-info">
                <?php
                printf(
                    /* translators: 1: current page, 2: total pages. */
                    esc_html__('Page %1$d of %2$d', 'webchangedetector'),
                    (int) $current,
                    (int) $last
                );
                ?>
            </span>
            <button type="button" class="ui button wcd-runs-page" data-page="<?php echo esc_attr((string) min($last, $current + 1)); ?>" <?php disabled($current >= $last); ?>><?php esc_html_e('Next', 'webchangedetector'); ?></button>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function emptyBox(): string
    {
        return self::messageBox('wcd-muted', __('No change detections yet. Run an On-Demand Check or monitoring, or try different filters.', 'webchangedetector'));
    }

    protected static function messageBox(string $class, string $text): string
    {
        return '<div class="wcd-runs-empty ' . esc_attr($class) . '">' . esc_html($text) . '</div>';
    }

    /* ───────────────────────────── Helpers ─────────────────────────────── */

    protected static function sourceLabel(string $source): string
    {
        $map = [
            'manual'      => __('On-Demand Checks', 'webchangedetector'),
            'monitoring'  => __('Monitoring', 'webchangedetector'),
            'auto_update' => __('Auto-Update Checks', 'webchangedetector'),
        ];

        return $map[$source] ?? '';
    }

    protected static function displayBatchName($name): string
    {
        $name = (string) $name;

        return 'Manual Checks' === trim($name) ? __('On-Demand Checks', 'webchangedetector') : $name;
    }

    /**
     * Period pill label from a date range (mirrors the webapp's wcd_period_label, dash-free).
     */
    public static function periodLabel(string $from, string $to): string
    {
        if ('' === $from && '' === $to) {
            return __('All time', 'webchangedetector');
        }
        if ('' === $from || '' === $to) {
            return __('Custom range', 'webchangedetector');
        }

        if ($to === gmdate('Y-m-d')) {
            $diffDays = (int) round((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS);
            foreach ([7, 30, 90] as $preset) {
                if (abs($diffDays - $preset) <= 1) {
                    /* translators: %d: number of days. */
                    return sprintf(__('Last %d days', 'webchangedetector'), $preset);
                }
            }
        }

        return gmdate('d.m.Y', strtotime($from)) . ' to ' . gmdate('d.m.Y', strtotime($to));
    }

    protected static function formatPercent($percent): string
    {
        $percent = (float) $percent;
        if ($percent > 0 && $percent < 0.005) {
            return '< 0.01';
        }

        return (string) round($percent, 2);
    }

    protected static function timeAgo(string $datetime): string
    {
        $ts = strtotime($datetime);
        if (! $ts) {
            return '';
        }

        /* translators: %s: human-readable time difference, e.g. "2 hours". */
        return sprintf(__('%s ago', 'webchangedetector'), human_time_diff($ts, time()));
    }

    protected static function shortDate(string $datetime): string
    {
        $ts = strtotime($datetime);

        return $ts ? date_i18n('d/m/Y H:i', $ts) : '';
    }

    protected static function deviceIconClass(string $device): string
    {
        switch ($device) {
            case 'desktop':
                return 'dashicons dashicons-desktop';
            case 'mobile':
                return 'dashicons dashicons-smartphone';
            default:
                return 'dashicons dashicons-media-default';
        }
    }
}
