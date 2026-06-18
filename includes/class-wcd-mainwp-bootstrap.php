<?php
/**
 * Plugin bootstrap: load classes, wire MainWP hooks, enqueue assets, inject entry points.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the add-on: loads classes, wires MainWP hooks, enqueues assets and renders entry points.
 */
class WCD_MainWP_Bootstrap {

	/**
	 * Exact admin page slugs where we load our CSS/JS and entry points. The extension settings
	 * page is matched via settings_page_slug() (computed from the plugin directory name) and all
	 * Sites subpages via their shared "ManageSites" prefix; see on_our_page().
	 */
	const OUR_PAGES = array(
		'UpdatesManage',
		'managesites',
		'mainwp_tab', // Dashboard overview (widget).
	);

	/**
	 * Load the add-on's classes and defer the rest of the setup to plugins_loaded.
	 *
	 * @return void
	 */
	public static function init(): void {
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-options.php';
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-api.php';
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-site-map.php';
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-site-settings.php';
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-url-sync.php';
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-cache-purge.php';
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-update-flow.php';
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-ajax.php';
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-widget.php';
		require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-wcd-mainwp-runs-view.php';

		add_action( 'plugins_loaded', array( self::class, 'setup' ) );
	}

	/**
	 * Register MainWP integrations once MainWP itself is loaded.
	 *
	 * @return void
	 */
	public static function setup(): void {
		if ( ! defined( 'MAINWP_VERSION' ) ) {
			return;
		}

		add_filter( 'mainwp_getextensions', array( self::class, 'register_extension' ) );
		add_filter( 'mainwp_getmetaboxes', array( self::class, 'register_widget' ) );

		// The "Visual Checks" page lives in the left menu's Monitoring category group. This is the
		// documented MainWP filter for placing a third-party page inside a category group.
		add_filter( 'mainwp_menu_extensions_left_menu', array( self::class, 'register_left_menu_item' ) );

		// Runs view first: the page navigation preserves subpage registration order, and the
		// Visual Checks tab leads the area (Settings second).
		WCD_MainWP_Runs_View::init();
		WCD_MainWP_Site_Settings::init();
		WCD_MainWP_Url_Sync::init();
		WCD_MainWP_Update_Flow::init();
		WCD_MainWP_Ajax::init();

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );

		// Single, prominent entry point: the hero banner at the top of the dashboard body. The same
		// hook fires on the Operations dashboard (bulk) and an individual child-site overview
		// (single site); we detect the scope inside the callback.
		add_action( 'mainwp_before_overview_widgets', array( self::class, 'render_overview_banner' ) );

		// Second entry point: the same hero banner above the native Updates page's plugin list,
		// shown only when plugin updates are available (the hook passes the total count).
		add_action( 'mainwp_updates_before_plugin_updates', array( self::class, 'render_updates_plugin_banner' ), 10, 2 );
	}

	/* ─────────────────────────── MainWP registration ───────────────────── */

	/**
	 * Register this add-on with MainWP's extensions list.
	 *
	 * @param array $extensions Registered MainWP extensions.
	 * @return array Extensions list with this add-on appended.
	 */
	public static function register_extension( array $extensions ): array {
		$extensions[] = array(
			'plugin'   => WCD_MAINWP_PLUGIN_FILE,
			'api'      => 'WebChange Detector',
			'mainwp'   => true,
			'callback' => array( self::class, 'render_admin_page' ),
			'icon'     => WCD_MAINWP_PLUGIN_URL . 'assets/images/logo.png',
		);

		return $extensions;
	}

	/**
	 * Add the "Visual Checks" entry to the left menu's Monitoring category group.
	 *
	 * The page itself is registered as a Sites subpage (see WCD_MainWP_Runs_View) but hidden from
	 * the Sites menu; this filter places its menu entry inside the Monitoring group instead, where
	 * visual checks sit naturally next to MainWP's own uptime monitoring.
	 *
	 * @param array $items Left-menu items registered by extensions.
	 * @return array Items with the Visual Checks entry appended.
	 */
	public static function register_left_menu_item( $items ): array {
		$items   = is_array( $items ) ? $items : array();
		$page    = 'ManageSites' . WCD_MainWP_Runs_View::PAGE_SLUG;
		$items[] = array(
			'title'                => esc_html__( 'WebChange Detector', 'webchangedetector-for-mainwp' ),
			'parent_key'           => 'Extensions-Mainwp-Monitoring',
			'slug'                 => $page,
			'href'                 => 'admin.php?page=' . $page,
			'level'                => 2,
			'leftsub_order_level2' => 5,
			// Highlights the Sites bar icon + opens the Monitoring group while on our page.
			'active_path'          => array( $page => 'managesites' ),
		);

		return $items;
	}

	/**
	 * Register the dashboard widget with MainWP's metaboxes list.
	 *
	 * @param array $metaboxes Registered MainWP metaboxes.
	 * @return array Metaboxes list with this add-on's widget appended.
	 */
	public static function register_widget( array $metaboxes ): array {
		$metaboxes[] = array(
			'id'            => 'wcd-checks-widget',
			'plugin'        => WCD_MAINWP_PLUGIN_FILE,
			'key'           => 'wcd_checks_widget',
			'metabox_title' => 'WebChange Detector',
			'callback'      => array( 'WCD_MainWP_Widget', 'render_metabox' ),
		);

		return $metaboxes;
	}

	/**
	 * Render the extension's settings admin page.
	 *
	 * @return void
	 */
	public static function render_admin_page(): void {
		include WCD_MAINWP_PLUGIN_PATH . 'templates/admin-page.php';
	}

	/**
	 * The admin page slug MainWP derives for this extension from its directory name.
	 * Computed (not hardcoded) so it stays correct if the plugin folder is renamed.
	 */
	public static function settings_page_slug(): string {
		$dir = dirname( plugin_basename( WCD_MAINWP_PLUGIN_FILE ) );

		return 'Extensions-' . str_replace( ' ', '-', ucwords( str_replace( '-', ' ', $dir ) ) );
	}

	/**
	 * The shared "no API token yet" setup sentence, with the account and settings links resolved.
	 * One source of truth for the dashboard hint banner and the settings-page notice.
	 *
	 * @return string HTML (safe for wp_kses_post output): the instruction with two anchors.
	 */
	public static function no_token_hint_html(): string {
		$settings_url = admin_url( 'admin.php?page=' . self::settings_page_slug() );

		return sprintf(
			/* translators: 1: webchangedetector.com account link, 2: settings page link. */
			esc_html__( 'Create an account at %1$s if you do not have one yet, then enter your API token in %2$s to enable visual checks.', 'webchangedetector-for-mainwp' ),
			'<a href="https://www.webchangedetector.com" target="_blank" rel="noopener">webchangedetector.com</a>',
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'the settings', 'webchangedetector-for-mainwp' ) . '</a>'
		);
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
	public static function render_overview_banner( $context = '' ): void {
		if ( 'dashboard' !== $context ) {
			return;
		}
		// The dashboard overview is the reliable first-run surface, so it carries the no-token hint
		// (instead of rendering nothing). The Updates-page entry point does not: see render_scoped_banner().
		self::render_scoped_banner( false, true );
	}

	/**
	 * Render the same hero banner above the native Updates page's plugin list. Only shown when the
	 * plugin updates tab actually has updates (the hook passes the total). Scope is detected the same
	 * way as the dashboard banner (current site id => single site, else bulk).
	 *
	 * @param mixed $websites             Child sites in the updates view (unused).
	 * @param int   $total_plugin_upgrades  Number of available plugin updates in scope.
	 */
	public static function render_updates_plugin_banner( $websites = null, $total_plugin_upgrades = 0 ): void {
		if ( (int) $total_plugin_upgrades > 0 ) {
			// The hook already told us updates exist on this page, so keep the CTA enabled even if our
			// own (WCD-enabled-sites-only) pending count disagrees with MainWP's network-wide total.
			self::render_scoped_banner( true );
		}
	}

	/**
	 * Detect scope (single enabled site vs. bulk over all enabled sites) and render the hero banner.
	 * Shared by the dashboard and Updates-page entry points. No-ops without a token or enabled sites.
	 *
	 * @param bool $updates_known_present Force the CTA enabled (caller already knows updates exist).
	 * @param bool $allow_no_token_hint   When no token is set, render the setup hint instead of nothing.
	 *                                    Only the dashboard overview passes true; the Updates-page entry
	 *                                    point keeps the silent no-op (its banner is already conditional).
	 */
	protected static function render_scoped_banner( bool $updates_known_present = false, bool $allow_no_token_hint = false ): void {
		// No API token yet: nothing to run. Offer the setup hint on surfaces that asked for it.
		if ( '' === WCD_MainWP_Site_Settings::get_global() ) {
			if ( $allow_no_token_hint ) {
				include WCD_MAINWP_PLUGIN_PATH . 'templates/entry-banner-no-token.php';
			}
			return;
		}

		$util    = '\\MainWP\\Dashboard\\MainWP_System_Utility';
		$site_id = ( class_exists( $util ) && method_exists( $util, 'get_current_wpid' ) ) ? (int) $util::get_current_wpid() : 0;

		if ( $site_id > 0 ) {
			// Individual child-site context: only when this site is enabled for WCD.
			if ( ! WCD_MainWP_Site_Map::is_enabled( $site_id ) ) {
				return;
			}
			$scope       = 'site';
			$sites_count = 1;
			$by_site     = WCD_MainWP_Update_Flow::pending_updates_by_site( array( $site_id ) );
		} else {
			// Operations dashboard / global updates: bulk over every enabled site.
			$enabled = array_keys(
				array_filter(
					WCD_MainWP_Site_Map::all(),
					static function ( $entry ) {
						return ! empty( $entry['enabled'] );
					}
				)
			);
			if ( count( $enabled ) < 1 ) {
				return;
			}
			$scope       = 'bulk';
			$sites_count = count( $enabled );
			$by_site     = WCD_MainWP_Update_Flow::pending_updates_by_site( $enabled );
		}

		// Null = MainWP's DB layer unavailable: the banner then drops both the pending-update
		// number and the "X / Y sites with updates" split (fail open).
		$updates_count       = null === $by_site ? null : array_sum( $by_site );
		$updates_sites_count = null === $by_site ? null : count( array_filter( $by_site ) );

		$force_enabled = $updates_known_present;
		include WCD_MAINWP_PLUGIN_PATH . 'templates/entry-banner.php';
	}

	/* ───────────────────────────────── Assets ──────────────────────────── */

	/**
	 * Enqueue the add-on's styles and scripts on our admin pages.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! self::on_our_page() ) {
			return;
		}

		wp_enqueue_style(
			'wcd-mainwp',
			WCD_MAINWP_PLUGIN_URL . 'assets/css/wcd-mainwp.css',
			array(),
			WCD_MAINWP_VERSION
		);

		// Visual Checks overview styles (scoped to .wcd-runs; harmless on other pages).
		wp_enqueue_style(
			'wcd-runs',
			WCD_MAINWP_PLUGIN_URL . 'assets/css/wcd-runs.css',
			array( 'wcd-mainwp' ),
			WCD_MAINWP_VERSION
		);

		wp_enqueue_script(
			'wcd-mainwp',
			WCD_MAINWP_PLUGIN_URL . 'assets/js/wcd-mainwp.js',
			array( 'jquery' ),
			WCD_MAINWP_VERSION,
			true
		);

		wp_localize_script(
			'wcd-mainwp',
			'wcdMainWP',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( WCD_MainWP_Ajax::NONCE ),
				// MainWP prefixes a sites-submenu slug with "ManageSites" for the admin page hook.
				'visualChecksUrl' => admin_url( 'admin.php?page=ManageSites' . WCD_MainWP_Runs_View::PAGE_SLUG ),
				'upgradeUrl'      => 'https://www.webchangedetector.com/pricing/',
				'strings'         => self::js_strings(),
			)
		);
	}

	/**
	 * Whether the current admin request is one of the add-on's pages.
	 *
	 * @return bool True when our CSS/JS should load on this page.
	 */
	protected static function on_our_page(): bool {
		// Use the admin page slug WordPress already resolved (and sanitized) into $plugin_page rather
		// than reading $_GET directly. This is set by core before admin_enqueue_scripts fires, needs no
		// nonce (it is read-only navigation context) and preserves the slug's case for the checks below.
		$page = isset( $GLOBALS['plugin_page'] ) ? (string) $GLOBALS['plugin_page'] : '';
		if ( '' === $page ) {
			return false;
		}
		if ( self::settings_page_slug() === $page ) {
			return true;
		}
		// Every Sites subpage (per-site tabs, Visual Checks, per-site overview) carries this prefix.
		if ( 0 === strpos( $page, 'ManageSites' ) ) {
			return true;
		}

		return in_array( $page, self::OUR_PAGES, true );
	}

	/**
	 * Translatable strings handed to the JS (keeps copy out of the script file).
	 */
	protected static function js_strings(): array {
		return array(
			'cancel'            => __( 'Cancel', 'webchangedetector-for-mainwp' ),
			'preflightTitle'    => __( 'Pre-update visual check', 'webchangedetector-for-mainwp' ),
			'preflightLead'     => __( 'WebChange Detector captures every selected page before the updates, installs all updates, then re-captures and compares.', 'webchangedetector-for-mainwp' ),
			'confirmRun'        => __( 'Capture & update', 'webchangedetector-for-mainwp' ),
			'enoughCredits'     => __( 'Enough credits', 'webchangedetector-for-mainwp' ),
			'upgradePlan'       => __( 'Upgrade plan', 'webchangedetector-for-mainwp' ),
			// Preflight summary strip + sections.
			'sites'             => __( 'Sites', 'webchangedetector-for-mainwp' ),
			'pages'             => __( 'Pages', 'webchangedetector-for-mainwp' ),
			'screenshots'       => __( 'Screenshots', 'webchangedetector-for-mainwp' ),
			'checks'            => __( 'Checks', 'webchangedetector-for-mainwp' ),
			'desktop'           => __( 'Desktop', 'webchangedetector-for-mainwp' ),
			'mobile'            => __( 'Mobile', 'webchangedetector-for-mainwp' ),
			'page'              => __( 'page', 'webchangedetector-for-mainwp' ),
			'pagesPlural'       => __( 'pages', 'webchangedetector-for-mainwp' ),
			'site'              => __( 'site', 'webchangedetector-for-mainwp' ),
			'sitesPlural'       => __( 'sites', 'webchangedetector-for-mainwp' ),
			/* translators: %1$d used checks, %2$d available, %3$d total. */
			'creditUsage'       => __( 'This run uses %1$d checks · %2$d of %3$d available', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of checks the plan is short by. */
			'creditShort'       => __( '%d short', 'webchangedetector-for-mainwp' ),
			'updatesToInstall'  => __( 'updates will be installed (core, plugins & themes)', 'webchangedetector-for-mainwp' ),
			'noUpdatesBadge'    => __( 'No updates', 'webchangedetector-for-mainwp' ),
			'noEligibleUpdates' => __( 'No pending updates on the sites enabled for visual checks.', 'webchangedetector-for-mainwp' ),
			'liveNote'          => __( 'Sites stay live; only screenshots are taken.', 'webchangedetector-for-mainwp' ),
			'runningTitle'      => __( 'Safe update', 'webchangedetector-for-mainwp' ),
			'running'           => __( 'Running', 'webchangedetector-for-mainwp' ),
			'dontClose'         => __( "don't close this tab", 'webchangedetector-for-mainwp' ),
			// Timeline steps.
			'phasePre'          => __( 'Pre', 'webchangedetector-for-mainwp' ),
			'phaseUpdates'      => __( 'Updates', 'webchangedetector-for-mainwp' ),
			'phasePost'         => __( 'Post', 'webchangedetector-for-mainwp' ),
			'phaseDone'         => __( 'Done', 'webchangedetector-for-mainwp' ),
			'phaseFailed'       => __( 'Failed', 'webchangedetector-for-mainwp' ),
			'verbPre'           => __( 'Capturing pre-update screenshots', 'webchangedetector-for-mainwp' ),
			'verbUpdate'        => __( 'Installing all updates', 'webchangedetector-for-mainwp' ),
			'verbPost'          => __( 'Capturing post-update screenshots', 'webchangedetector-for-mainwp' ),
			'verbDone'          => __( 'Creating change detections', 'webchangedetector-for-mainwp' ),
			// Run-card stat panels.
			'panelPre'          => __( 'Pre-update screenshots', 'webchangedetector-for-mainwp' ),
			'panelUpdates'      => __( 'Installing updates', 'webchangedetector-for-mainwp' ),
			'panelPost'         => __( 'Post-update screenshots', 'webchangedetector-for-mainwp' ),
			'queue'             => __( 'Queue', 'webchangedetector-for-mainwp' ),
			'processing'        => __( 'Processing', 'webchangedetector-for-mainwp' ),
			'doneCount'         => __( 'Done', 'webchangedetector-for-mainwp' ),
			'failed'            => __( 'Failed', 'webchangedetector-for-mainwp' ),
			'queued'            => __( 'Queued', 'webchangedetector-for-mainwp' ),
			'capturing'         => __( 'Capturing…', 'webchangedetector-for-mainwp' ),
			'captured'          => __( 'Captured', 'webchangedetector-for-mainwp' ),
			'installing'        => __( 'Installing…', 'webchangedetector-for-mainwp' ),
			'installed'         => __( 'Installed', 'webchangedetector-for-mainwp' ),
			'updatesUnit'       => __( 'updates', 'webchangedetector-for-mainwp' ),
			// Per-site row statuses.
			'statusPre'         => __( 'Capturing pre', 'webchangedetector-for-mainwp' ),
			'statusUpdating'    => __( 'Updating', 'webchangedetector-for-mainwp' ),
			'statusPost'        => __( 'Capturing post', 'webchangedetector-for-mainwp' ),
			'statusComparing'   => __( 'Comparing', 'webchangedetector-for-mainwp' ),
			'clean'             => __( 'Clean', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of pages to review. */
			'toReview'          => __( '%d to review', 'webchangedetector-for-mainwp' ),
			// Result summary + actions.
			'allGood'           => __( 'All good', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of pages to review. */
			'pagesToReview'     => __( '%d pages to review', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of pages to review (singular). */
			'pageToReview'      => __( '%d page to review', 'webchangedetector-for-mainwp' ),
			'viewResults'       => __( 'View results', 'webchangedetector-for-mainwp' ),
			'recheck'           => __( 'Re-check', 'webchangedetector-for-mainwp' ),
			'runFooterNote'     => __( 'Sites stay live; only screenshots are taken. Keep this tab open until the run finishes.', 'webchangedetector-for-mainwp' ),
			'stillRunning'      => __( 'Still running. Open in WebChange Detector.', 'webchangedetector-for-mainwp' ),
			'noChecks'          => __( 'No URLs configured for this site.', 'webchangedetector-for-mainwp' ),
			// URL panel: search, pagination, select-all.
			'searchUrls'        => __( 'Search URLs…', 'webchangedetector-for-mainwp' ),
			'selectAll'         => __( 'Select all:', 'webchangedetector-for-mainwp' ),
			'prev'              => __( 'Prev', 'webchangedetector-for-mainwp' ),
			'next'              => __( 'Next', 'webchangedetector-for-mainwp' ),
			/* translators: %s: total URL count. */
			'urlsTotal'         => __( '%s URLs', 'webchangedetector-for-mainwp' ),
			'noResults'         => __( 'No URLs match your search.', 'webchangedetector-for-mainwp' ),
			/* translators: %s: device name (Desktop or Mobile). */
			'confirmDisableAll' => __( 'Disable all %s checks for this site?', 'webchangedetector-for-mainwp' ),
			'noSites'           => __( 'No sites are enabled for visual checks.', 'webchangedetector-for-mainwp' ),
			'genericError'      => __( 'Something went wrong.', 'webchangedetector-for-mainwp' ),
			'disabled'          => __( 'Inactive', 'webchangedetector-for-mainwp' ),
			// "Activate checks for all websites" on the Sites & pages settings tab.
			/* translators: 1: current site number, 2: total sites. */
			'bulkSyncProgress'  => __( 'Activating %1$d of %2$d websites…', 'webchangedetector-for-mainwp' ),
			/* translators: 1: activated site count, 2: total sites. */
			'bulkSyncDone'      => __( 'Activated %1$d of %2$d websites.', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of websites that failed to activate. */
			'bulkSyncFailed'    => __( '%d failed.', 'webchangedetector-for-mainwp' ),
			'bulkSyncConfirm'   => __( 'This activates visual checks for every managed website. Websites are unlimited on every plan; only the checks you run count against it. Continue?', 'webchangedetector-for-mainwp' ),
			'ctaRunning'        => __( 'Visual check running…', 'webchangedetector-for-mainwp' ),
			'closeRunning'      => __( 'Updates are still running. Close anyway? The run keeps going in the background.', 'webchangedetector-for-mainwp' ),
			// Resume of an interrupted run.
			'resumeTitle'       => __( 'Unfinished safe update found', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of sites (singular). */
			'resumeBodySingle'  => __( 'Updates were installed, but the post-update screenshots for %d site are still missing. Take them now to complete your change detections.', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of sites. */
			'resumeBodyPlural'  => __( 'Updates were installed, but the post-update screenshots for %d sites are still missing. Take them now to complete your change detections.', 'webchangedetector-for-mainwp' ),
			'resumePost'        => __( 'Take post-update screenshots', 'webchangedetector-for-mainwp' ),
			'discard'           => __( 'Discard', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of sites (singular). */
			'metaErrorSingle'   => __( 'The checks for %d site could not be loaded. It would be updated WITHOUT visual checks.', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of sites. */
			'metaErrorPlural'   => __( 'The checks for %d sites could not be loaded. They would be updated WITHOUT visual checks.', 'webchangedetector-for-mainwp' ),
		);
	}
}
