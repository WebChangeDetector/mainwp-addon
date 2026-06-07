<?php
/**
 * Plugin bootstrap: load classes, wire MainWP hooks, enqueue assets, inject entry points.
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

class WCD_MainWP_Bootstrap
{
    /** Admin page slugs where we load our CSS/JS and entry points. */
    const OUR_PAGES = [
        'Extensions-Mainwp-Addon',
        'ManageSitesWcdVisualRegressionTesting',
        'UpdatesManage',
        'managesites',
        'ManageSites',
        'mainwp_tab', // Dashboard overview (widget).
    ];

    public static function init(): void
    {
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-options.php';
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-api.php';
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-site-map.php';
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-site-settings.php';
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-url-sync.php';
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-update-flow.php';
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-ajax.php';
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-widget.php';
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-runs-view.php';

        add_action('plugins_loaded', [self::class, 'setup']);
    }

    public static function setup(): void
    {
        if (! defined('MAINWP_VERSION')) {
            return;
        }

        add_filter('mainwp_getextensions', [self::class, 'register_extension']);
        add_filter('mainwp_getmetaboxes', [self::class, 'registerWidget']);

        WCD_MainWP_Site_Settings::init();
        WCD_MainWP_Url_Sync::init();
        WCD_MainWP_Update_Flow::init();
        WCD_MainWP_Ajax::init();
        WCD_MainWP_Runs_View::init();

        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);

        // Single, prominent entry point: the hero banner at the top of the dashboard body. The same
        // hook fires on the Operations dashboard (bulk) and an individual child-site overview
        // (single site); we detect the scope inside the callback.
        add_action('mainwp_before_overview_widgets', [self::class, 'renderOverviewBanner']);

        // Second entry point: the same hero banner above the native Updates page's plugin list,
        // shown only when plugin updates are available (the hook passes the total count).
        add_action('mainwp_updates_before_plugin_updates', [self::class, 'renderUpdatesPluginBanner'], 10, 2);
    }

    /* ─────────────────────────── MainWP registration ───────────────────── */

    public static function register_extension(array $extensions): array
    {
        $extensions[] = [
            'plugin'   => WCD_MAINWP_PLUGIN_FILE,
            'api'      => 'WebChange Detector',
            'mainwp'   => true,
            'callback' => [self::class, 'render_admin_page'],
            'icon'     => WCD_MAINWP_PLUGIN_URL . 'assets/images/logo.png',
        ];

        return $extensions;
    }

    public static function registerWidget(array $metaboxes): array
    {
        $metaboxes[] = [
            'id'            => 'wcd-checks-widget',
            'plugin'        => WCD_MAINWP_PLUGIN_FILE,
            'key'           => 'wcd_checks_widget',
            'metabox_title' => 'WebChange Detector',
            'callback'      => ['WCD_MainWP_Widget', 'renderMetabox'],
        ];

        return $metaboxes;
    }

    public static function render_admin_page(): void
    {
        include WCD_MAINWP_PLUGIN_PATH . 'templates/admin-page.php';
    }

    /**
     * The admin page slug MainWP derives for this extension from its directory name.
     * Computed (not hardcoded) so it stays correct if the plugin folder is renamed.
     */
    public static function settingsPageSlug(): string
    {
        $dir = dirname(plugin_basename(WCD_MAINWP_PLUGIN_FILE));

        return 'Extensions-' . str_replace(' ', '-', ucwords(str_replace('-', ' ', $dir)));
    }

    /* ───────────────────────────── Entry point ─────────────────────────── */

    /**
     * Render the hero banner at the top of the dashboard body.
     *
     * Fires for both the Operations dashboard and an individual child-site overview (both go through
     * MainWP_Overview::render_dashboard_body, which passes the 'dashboard' context). We tell them
     * apart with MainWP_System_Utility::get_current_wpid(): a site id means single-site scope.
     *
     * @param string $context The overview context ('dashboard', 'clients', 'insights', ...).
     */
    public static function renderOverviewBanner($context = ''): void
    {
        if ('dashboard' !== $context) {
            return;
        }
        self::renderScopedBanner();
    }

    /**
     * Render the same hero banner above the native Updates page's plugin list. Only shown when the
     * plugin updates tab actually has updates (the hook passes the total). Scope is detected the same
     * way as the dashboard banner (current site id => single site, else bulk).
     *
     * @param mixed $websites             Child sites in the updates view (unused).
     * @param int   $totalPluginUpgrades  Number of available plugin updates in scope.
     */
    public static function renderUpdatesPluginBanner($websites = null, $totalPluginUpgrades = 0): void
    {
        if ((int) $totalPluginUpgrades > 0) {
            // The hook already told us updates exist on this page, so keep the CTA enabled even if our
            // own (WCD-enabled-sites-only) pending count disagrees with MainWP's network-wide total.
            self::renderScopedBanner(true);
        }
    }

    /**
     * Detect scope (single enabled site vs. bulk over all enabled sites) and render the hero banner.
     * Shared by the dashboard and Updates-page entry points. No-ops without a token or enabled sites.
     *
     * @param bool $updatesKnownPresent Force the CTA enabled (caller already knows updates exist).
     */
    protected static function renderScopedBanner(bool $updatesKnownPresent = false): void
    {
        // No API token yet: nothing to offer.
        if ('' === WCD_MainWP_Site_Settings::getGlobal()) {
            return;
        }

        $util   = '\\MainWP\\Dashboard\\MainWP_System_Utility';
        $siteId = (class_exists($util) && method_exists($util, 'get_current_wpid')) ? (int) $util::get_current_wpid() : 0;

        if ($siteId > 0) {
            // Individual child-site context: only when this site is enabled for WCD.
            if (! WCD_MainWP_Site_Map::isEnabled($siteId)) {
                return;
            }
            $scope         = 'site';
            $sites_count   = 1;
            $updates_count = WCD_MainWP_Update_Flow::pendingUpdatesCount([$siteId]);
        } else {
            // Operations dashboard / global updates: bulk over every enabled site.
            $enabled = array_keys(array_filter(WCD_MainWP_Site_Map::all(), static function ($entry) {
                return ! empty($entry['enabled']);
            }));
            if (count($enabled) < 1) {
                return;
            }
            $scope         = 'bulk';
            $sites_count   = count($enabled);
            $updates_count = WCD_MainWP_Update_Flow::pendingUpdatesCount($enabled);
        }

        $site_id       = $siteId; // 0 for bulk.
        $force_enabled = $updatesKnownPresent;
        include WCD_MAINWP_PLUGIN_PATH . 'templates/entry-banner.php';
    }

    /* ───────────────────────────────── Assets ──────────────────────────── */

    public static function enqueueAssets(): void
    {
        if (! self::onOurPage()) {
            return;
        }

        wp_enqueue_style(
            'wcd-mainwp',
            WCD_MAINWP_PLUGIN_URL . 'assets/css/wcd-mainwp.css',
            [],
            WCD_MAINWP_VERSION
        );

        // Change Detections overview styles (scoped to .wcd-runs; harmless on other pages).
        wp_enqueue_style(
            'wcd-runs',
            WCD_MAINWP_PLUGIN_URL . 'assets/css/wcd-runs.css',
            ['wcd-mainwp'],
            WCD_MAINWP_VERSION
        );

        wp_enqueue_script(
            'wcd-mainwp',
            WCD_MAINWP_PLUGIN_URL . 'assets/js/wcd-mainwp.js',
            ['jquery'],
            WCD_MAINWP_VERSION,
            true
        );

        wp_localize_script('wcd-mainwp', 'wcdMainWP', [
            'ajaxUrl'             => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce(WCD_MainWP_Ajax::NONCE),
            // MainWP prefixes a sites-submenu slug with "ManageSites" for the admin page hook.
            'changeDetectionsUrl' => admin_url('admin.php?page=ManageSites' . WCD_MainWP_Runs_View::PAGE_SLUG),
            'upgradeUrl'          => 'https://www.webchangedetector.com/pricing/',
            'strings'             => self::jsStrings(),
        ]);
    }

    protected static function onOurPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ('' === $page) {
            return false;
        }
        if ($page === self::settingsPageSlug()) {
            return true;
        }
        foreach (self::OUR_PAGES as $slug) {
            if ($page === $slug || 0 === strpos($page, 'ManageSites')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translatable strings handed to the JS (keeps copy out of the script file).
     */
    protected static function jsStrings(): array
    {
        return [
            'cancel'          => __('Cancel', 'webchangedetector'),
            'preflightTitle'  => __('Pre-update visual check', 'webchangedetector'),
            'preflightLead'   => __('WebChange Detector captures every selected page before the updates, installs all updates, then re-captures and compares.', 'webchangedetector'),
            'confirmRun'      => __('Capture & update', 'webchangedetector'),
            'enoughCredits'   => __('Enough credits', 'webchangedetector'),
            'upgradePlan'     => __('Upgrade plan', 'webchangedetector'),
            // Preflight summary strip + sections.
            'sites'           => __('Sites', 'webchangedetector'),
            'pages'           => __('Pages', 'webchangedetector'),
            'screenshots'     => __('Screenshots', 'webchangedetector'),
            'checks'          => __('Checks', 'webchangedetector'),
            'desktop'         => __('Desktop', 'webchangedetector'),
            'mobile'          => __('Mobile', 'webchangedetector'),
            'page'            => __('page', 'webchangedetector'),
            'pagesPlural'     => __('pages', 'webchangedetector'),
            'site'            => __('site', 'webchangedetector'),
            'sitesPlural'     => __('sites', 'webchangedetector'),
            /* translators: %1$d used checks, %2$d available, %3$d total. */
            'creditUsage'     => __('This run uses %1$d checks · %2$d of %3$d available', 'webchangedetector'),
            /* translators: %d: number of checks the plan is short by. */
            'creditShort'     => __('%d short', 'webchangedetector'),
            'updatesToInstall'=> __('updates will be installed (core, plugins & themes)', 'webchangedetector'),
            'liveNote'        => __('Sites stay live; only screenshots are taken.', 'webchangedetector'),
            'runningTitle'    => __('Safe update', 'webchangedetector'),
            'running'         => __('Running', 'webchangedetector'),
            'dontClose'       => __("don't close this tab", 'webchangedetector'),
            // Timeline steps.
            'phasePre'        => __('Pre', 'webchangedetector'),
            'phaseUpdates'    => __('Updates', 'webchangedetector'),
            'phasePost'       => __('Post', 'webchangedetector'),
            'phaseDone'       => __('Done', 'webchangedetector'),
            'phaseFailed'     => __('Failed', 'webchangedetector'),
            'verbPre'         => __('Capturing pre-update screenshots', 'webchangedetector'),
            'verbUpdate'      => __('Installing all updates', 'webchangedetector'),
            'verbPost'        => __('Capturing post-update screenshots', 'webchangedetector'),
            'verbDone'        => __('Creating change detections', 'webchangedetector'),
            // Run-card stat panels.
            'panelPre'        => __('Pre-update screenshots', 'webchangedetector'),
            'panelUpdates'    => __('Installing updates', 'webchangedetector'),
            'panelPost'       => __('Post-update screenshots', 'webchangedetector'),
            'queue'           => __('Queue', 'webchangedetector'),
            'processing'      => __('Processing', 'webchangedetector'),
            'doneCount'       => __('Done', 'webchangedetector'),
            'failed'          => __('Failed', 'webchangedetector'),
            'queued'          => __('Queued', 'webchangedetector'),
            'capturing'       => __('Capturing…', 'webchangedetector'),
            'captured'        => __('Captured', 'webchangedetector'),
            'installing'      => __('Installing…', 'webchangedetector'),
            'installed'       => __('Installed', 'webchangedetector'),
            'corePluginTheme' => __('core · plugins · themes', 'webchangedetector'),
            // Per-site row statuses.
            'statusPre'       => __('Capturing pre', 'webchangedetector'),
            'statusUpdating'  => __('Updating', 'webchangedetector'),
            'statusPost'      => __('Capturing post', 'webchangedetector'),
            'statusComparing' => __('Comparing', 'webchangedetector'),
            'clean'           => __('Clean', 'webchangedetector'),
            /* translators: %d: number of pages to review. */
            'toReview'        => __('%d to review', 'webchangedetector'),
            // Result summary + actions.
            'allGood'         => __('All good', 'webchangedetector'),
            /* translators: %d: number of pages to review. */
            'pagesToReview'   => __('%d pages to review', 'webchangedetector'),
            /* translators: %d: number of pages to review (singular). */
            'pageToReview'    => __('%d page to review', 'webchangedetector'),
            'viewResults'     => __('View results', 'webchangedetector'),
            'recheck'         => __('Re-check', 'webchangedetector'),
            'runFooterNote'   => __('Sites stay live; only screenshots are taken. This usually takes a minute.', 'webchangedetector'),
            'stillRunning'    => __('Still running. Open in WebChange Detector.', 'webchangedetector'),
            'noChecks'        => __('No URLs configured for this site.', 'webchangedetector'),
            'noSites'         => __('No sites are enabled for visual checks.', 'webchangedetector'),
            'genericError'    => __('Something went wrong.', 'webchangedetector'),
            'syncing'         => __('Syncing URLs…', 'webchangedetector'),
            'ctaRunning'      => __('Visual check running…', 'webchangedetector'),
            'closeRunning'    => __('Updates are still running. Close anyway? The run keeps going in the background.', 'webchangedetector'),
        ];
    }
}
