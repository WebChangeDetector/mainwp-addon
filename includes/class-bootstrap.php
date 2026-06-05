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

        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);

        // Single, prominent entry point: the hero banner at the top of the dashboard body. The same
        // hook fires on the Operations dashboard (bulk) and an individual child-site overview
        // (single site); we detect the scope inside the callback.
        add_action('mainwp_before_overview_widgets', [self::class, 'renderOverviewBanner']);
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
        // No API token yet: nothing to offer.
        if ('' === WCD_MainWP_Site_Settings::getGlobal()) {
            return;
        }

        $util   = '\\MainWP\\Dashboard\\MainWP_System_Utility';
        $siteId = (class_exists($util) && method_exists($util, 'get_current_wpid')) ? (int) $util::get_current_wpid() : 0;

        if ($siteId > 0) {
            // Individual child-site overview: only when this site is enabled for WCD.
            if (! WCD_MainWP_Site_Map::isEnabled($siteId)) {
                return;
            }
            $scope         = 'site';
            $sites_count   = 1;
            $updates_count = WCD_MainWP_Update_Flow::pendingUpdatesCount([$siteId]);
        } else {
            // Operations dashboard: bulk over every enabled site.
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

        $site_id = $siteId; // 0 for bulk.
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

        wp_enqueue_script(
            'wcd-mainwp',
            WCD_MAINWP_PLUGIN_URL . 'assets/js/wcd-mainwp.js',
            ['jquery'],
            WCD_MAINWP_VERSION,
            true
        );

        wp_localize_script('wcd-mainwp', 'wcdMainWP', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(WCD_MainWP_Ajax::NONCE),
            'strings' => self::jsStrings(),
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
            'confirmRun'      => __('Capture & update', 'webchangedetector'),
            'enoughCredits'   => __('Enough credits', 'webchangedetector'),
            'notEnough'       => __('Not enough credits', 'webchangedetector'),
            'runningTitle'    => __('Safe Updates with WebChange Detector', 'webchangedetector'),
            // Circular stepper + per-site phase badge.
            'phasePre'        => __('Pre', 'webchangedetector'),
            'phaseUpdates'    => __('Updates', 'webchangedetector'),
            'phasePost'       => __('Post', 'webchangedetector'),
            'phaseDone'       => __('Done', 'webchangedetector'),
            'phaseFailed'     => __('Failed', 'webchangedetector'),
            // Run-card activity area (single "in progress" counter + the updates indicator).
            'stepUpdate'      => __('Installing updates', 'webchangedetector'),
            'inProgress'      => __('In progress', 'webchangedetector'),
            // Result summary + actions.
            'allGood'         => __('All good', 'webchangedetector'),
            'changeDetected'  => __('change detected', 'webchangedetector'),
            'changesDetected' => __('changes detected', 'webchangedetector'),
            'aiSkipped'       => __('AI summary skipped', 'webchangedetector'),
            'recheck'         => __('Re-check', 'webchangedetector'),
            'stillRunning'    => __('Still running. Open in WebChange Detector.', 'webchangedetector'),
            'noChecks'        => __('No URLs configured for this site.', 'webchangedetector'),
            'genericError'    => __('Something went wrong.', 'webchangedetector'),
            'syncing'         => __('Syncing URLs…', 'webchangedetector'),
            'close'           => __('Close', 'webchangedetector'),
        ];
    }
}
