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
		add_filter( 'mainwp_widgets_screen_options', array( self::class, 'register_widget_screen_options' ) );

		// Run, Checks, Settings and Account are tabs on this add-on's own extension page (see
		// render_admin_page()); Site_Settings still registers the per-site tab on the Sites pages.
		WCD_MainWP_Site_Settings::init();
		WCD_MainWP_Url_Sync::init();
		WCD_MainWP_Update_Flow::init();
		WCD_MainWP_Ajax::init();

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );

		// The safe-update entry point on the Operations dashboard and individual child-site overview
		// is the draggable "WebChange Detector: Updates" metabox widget (register_widget()); MainWP
		// renders our metaboxes on both surfaces.

		// Updates-page entry point: a compact selection-aware bar above each update tab's native
		// table (WordPress, Plugins, Themes, Translations). One renderer for all four documented
		// per-tab hooks; the update type is derived from the firing hook. Exactly one tab renders
		// per pageload, so at most one bar exists on the global Updates page.
		$wcd_updates_hooks = array(
			'mainwp_updates_before_wp_updates',
			'mainwp_updates_before_plugin_updates',
			'mainwp_updates_before_theme_updates',
			'mainwp_updates_before_translation_updates',
		);
		foreach ( $wcd_updates_hooks as $wcd_updates_hook ) {
			add_action( $wcd_updates_hook, array( self::class, 'render_updates_bar' ), 10, 2 );
		}

		// Native "Updates Overview" widget (Operations dashboard AND individual child-site
		// overview): a compact WCD Updates card after MainWP's per-type cards.
		add_action( 'mainwp_updates_overview_after_update_details', array( self::class, 'render_overview_card' ), 10, 2 );

		// Individual site's Updates subpage (admin.php?page=managesites&updateid=N): the
		// selection bar inside the native actions bar. The SAME hook also fires on the global
		// Updates page (where the four per-tab hooks above already render the bar) and the tab
		// slugs collide between the two pages, so the renderer gates on the admin page slug.
		add_action( 'mainwp_widget_updates_actions_top', array( self::class, 'render_site_updates_bar' ) );
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
	 * Register the dashboard widget with MainWP's metaboxes list.
	 *
	 * @param array $metaboxes Registered MainWP metaboxes.
	 * @return array Metaboxes list with this add-on's widget appended.
	 */
	public static function register_widget( array $metaboxes ): array {
		// Safe-update entry point: a draggable/hideable full-width card on the dashboard grid. It
		// replaces the old hero banner on the Operations dashboard and the individual child-site
		// overview (both surfaces render the dashboard metaboxes). The default layout places it
		// full-width directly below MainWP's Updates Overview widget (registered at y=0, h=20 in
		// page-mainwp-overview.php), so safe updates sit right under the update list; on surfaces
		// without that widget MainWP's grid compacts it upward. Users can move/resize/hide it like any
		// MainWP widget, and the inline run card scrolls within it.
		$metaboxes[] = array(
			'id'            => 'wcd-safe-update-widget',
			'plugin'        => WCD_MAINWP_PLUGIN_FILE,
			'key'           => 'wcd_safe_update_widget',
			'metabox_title' => 'WebChange Detector: Updates',
			'callback'      => array( 'WCD_MainWP_Widget', 'render_safe_update_metabox' ),
			'layout'        => array( 0, 20, 12, 10 ),
		);

		return $metaboxes;
	}

	/**
	 * Add the add-on's widget to MainWP's "Page Settings" show/hide list so the user can hide it.
	 *
	 * MainWP gates each metabox on the "advanced-{id}" key in the mainwp_settings_show_widgets user
	 * option; a widget only gets a hide checkbox once it is registered here. Default stays shown
	 * (an unknown key renders).
	 *
	 * @param array $widgets Show/hide list ([ widget_id => label ]).
	 * @return array List with this add-on's widget appended.
	 */
	public static function register_widget_screen_options( $widgets ): array {
		$widgets = is_array( $widgets ) ? $widgets : array();

		$widgets['advanced-wcd-safe-update-widget'] = esc_html__( 'WebChange Detector: Updates', 'webchangedetector-for-mainwp' );

		return $widgets;
	}

	/**
	 * Render the extension page: the tab switcher (Run, Checks, Settings, Account) and the active
	 * tab body. The template resolves the active tab from the ?tab= query arg.
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
	 * Admin URL of one of the extension page's tabs (Run, Checks, Settings, Account). The four tabs
	 * all live on the single extension page (settings_page_slug()) and switch via a ?tab= reload.
	 *
	 * @param string $tab Tab key: 'run', 'checks', 'settings' or 'account'.
	 * @return string The admin URL for that tab.
	 */
	public static function tab_url( string $tab ): string {
		return admin_url( 'admin.php?page=' . self::settings_page_slug() . '&tab=' . rawurlencode( $tab ) );
	}

	/**
	 * The shared "no API token yet" setup sentence, with the account and settings links resolved.
	 * One source of truth for the dashboard hint banner and the settings-page notice.
	 *
	 * @return string HTML (safe for wp_kses_post output): the instruction with two anchors.
	 */
	public static function no_token_hint_html(): string {
		$settings_url = self::tab_url( 'account' );

		return sprintf(
			/* translators: 1: webchangedetector.com account link, 2: settings page link. */
			esc_html__( 'Create an account at %1$s if you do not have one yet, then enter your API token in %2$s to enable visual checks.', 'webchangedetector-for-mainwp' ),
			'<a href="https://www.webchangedetector.com" target="_blank" rel="noopener">webchangedetector.com</a>',
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'the settings', 'webchangedetector-for-mainwp' ) . '</a>'
		);
	}

	/* ───────────────────────────── Entry point ─────────────────────────── */

	/**
	 * Render the compact safe-update bar above one of the native Updates page's per-type tables.
	 * Bound to all four per-tab hooks; the update type comes from the firing hook. Only rendered
	 * when a token is configured (silent no-op otherwise) and the tab has pending updates (the
	 * hook passes the total).
	 *
	 * @param mixed $websites Child sites in the updates view (unused; hook signature).
	 * @param int   $total    Number of available updates of this type in scope.
	 */
	public static function render_updates_bar( $websites = null, $total = 0 ): void {
		unset( $websites );
		if ( '' === WCD_MainWP_Site_Settings::get_global() || (int) $total < 1 ) {
			return;
		}

		$wcd_mainwp_hook_types  = array(
			'mainwp_updates_before_wp_updates'          => 'core',
			'mainwp_updates_before_plugin_updates'      => 'plugins',
			'mainwp_updates_before_theme_updates'       => 'themes',
			'mainwp_updates_before_translation_updates' => 'translations',
		);
		$wcd_mainwp_update_type = $wcd_mainwp_hook_types[ current_action() ] ?? '';
		if ( '' === $wcd_mainwp_update_type ) {
			return;
		}

		include WCD_MAINWP_PLUGIN_PATH . 'templates/updates-bar.php';
	}

	/**
	 * Render the compact "WCD Updates" card inside MainWP's native Updates Overview widget.
	 *
	 * The hook fires inside the widget's cards grid on the Operations dashboard (global view)
	 * and on an individual child-site overview (site view, $current_site set). Site view renders
	 * only when that site is enabled for visual checks; global view when any site is enabled.
	 *
	 * @param mixed $current_site MainWP website object in site view, null in global view.
	 * @param bool  $global_view  Whether the widget renders the global (all sites) view.
	 */
	public static function render_overview_card( $current_site = null, $global_view = true ): void {
		if ( '' === WCD_MainWP_Site_Settings::get_global() ) {
			return;
		}

		$wcd_mainwp_site_id = ( ! $global_view && is_object( $current_site ) && isset( $current_site->id ) ) ? (int) $current_site->id : 0;
		if ( $wcd_mainwp_site_id > 0 ) {
			if ( ! WCD_MainWP_Site_Map::is_enabled( $wcd_mainwp_site_id ) ) {
				return;
			}
			$wcd_mainwp_scope = 'site';
		} else {
			$wcd_mainwp_enabled = array_filter(
				WCD_MainWP_Site_Map::all(),
				static function ( $entry ) {
					return ! empty( $entry['enabled'] );
				}
			);
			if ( empty( $wcd_mainwp_enabled ) ) {
				return;
			}
			$wcd_mainwp_scope = 'bulk';
		}

		include WCD_MAINWP_PLUGIN_PATH . 'templates/widget-overview-card.php';
	}

	/**
	 * Render the selection bar on an individual site's Updates subpage
	 * (admin.php?page=managesites&updateid=N), inside the native actions bar's middle column.
	 *
	 * The `mainwp_widget_updates_actions_top` hook also fires on the global Updates page, where
	 * the per-tab bar already renders, and the $active_tab slugs collide between the two pages
	 * (e.g. the abandoned tabs), so the gate is the resolved admin page slug, never the hook
	 * argument. The subpage switches its update-type tabs client-side without a reload, so the
	 * buttons carry an empty data-update-type and the JS resolves the type at click time.
	 *
	 * @param mixed $active_tab The native page's active tab slug (unused; page-gated instead).
	 */
	public static function render_site_updates_bar( $active_tab = '' ): void {
		unset( $active_tab );
		if ( '' === WCD_MainWP_Site_Settings::get_global() ) {
			return;
		}

		// Same page-slug source as on_our_page(): resolved by core before rendering.
		$page = isset( $GLOBALS['plugin_page'] ) ? (string) $GLOBALS['plugin_page'] : '';
		if ( 'managesites' !== $page ) {
			return;
		}

		// The subpage sets the current wpid before rendering (set_current_wpid), same guarded
		// read as resolve_banner_scope().
		$util    = '\\MainWP\\Dashboard\\MainWP_System_Utility';
		$site_id = ( class_exists( $util ) && method_exists( $util, 'get_current_wpid' ) ) ? (int) $util::get_current_wpid() : 0;
		if ( $site_id < 1 || ! isset( WCD_MainWP_Site_Map::managed_sites()[ $site_id ] ) ) {
			return;
		}

		// Skip when the site has no pending updates (mirrors the global bar's total gate); an
		// unknown count (MainWP DB layer unavailable) fails open and renders the bar.
		$wcd_mainwp_by_site = WCD_MainWP_Update_Flow::pending_updates_by_site( array( $site_id ) );
		if ( is_array( $wcd_mainwp_by_site ) && ( $wcd_mainwp_by_site[ $site_id ] ?? 0 ) < 1 ) {
			return;
		}

		$wcd_mainwp_update_type = '';
		$wcd_mainwp_site_id     = $site_id;
		$wcd_mainwp_inline      = true;
		include WCD_MAINWP_PLUGIN_PATH . 'templates/updates-bar.php';
	}

	/**
	 * Resolve the entry-point scope (single enabled site vs. bulk over all enabled sites) and the
	 * pending-update aggregates for the dashboard "WebChange Detector: Updates" widget and the Run tab
	 * (WCD_MainWP_Widget). Assumes a token is configured (callers check first).
	 *
	 * @param bool $force_enabled Force the CTA enabled even when the pending count is 0 (caller knows
	 *                            updates exist).
	 * @return array|null { scope, site_id, sites_count, updates_count, updates_sites_count,
	 *                      force_enabled } or null when nothing should render (no enabled sites, or a
	 *                      single site that is not enabled for WCD).
	 */
	public static function resolve_banner_scope( bool $force_enabled = false ): ?array {
		$util    = '\\MainWP\\Dashboard\\MainWP_System_Utility';
		$site_id = ( class_exists( $util ) && method_exists( $util, 'get_current_wpid' ) ) ? (int) $util::get_current_wpid() : 0;

		if ( $site_id > 0 ) {
			// Individual child-site context: only when this site is enabled for WCD.
			if ( ! WCD_MainWP_Site_Map::is_enabled( $site_id ) ) {
				return null;
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
				return null;
			}
			$scope       = 'bulk';
			$sites_count = count( $enabled );
			$by_site     = WCD_MainWP_Update_Flow::pending_updates_by_site( $enabled );
		}

		// Null = MainWP's DB layer unavailable: drop both the pending-update number and the
		// "X / Y sites with updates" split (fail open).
		return array(
			'scope'               => $scope,
			'site_id'             => 'site' === $scope ? $site_id : 0,
			'sites_count'         => $sites_count,
			'updates_count'       => null === $by_site ? null : array_sum( $by_site ),
			'updates_sites_count' => null === $by_site ? null : count( array_filter( $by_site ) ),
			'force_enabled'       => $force_enabled,
		);
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

		// Single stylesheet for the whole add-on (the Checks-tab styles, scoped to .wcd-runs, live in
		// the same file): both surfaces always load together, so one file = one request.
		wp_enqueue_style(
			'wcd-mainwp',
			WCD_MAINWP_PLUGIN_URL . 'assets/css/wcd-mainwp.css',
			array(),
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
				'visualChecksUrl' => self::tab_url( 'checks' ),
				'upgradeUrl'      => 'https://www.webchangedetector.com/pricing/',
				'strings'         => self::js_strings(),
			)
		);
	}

	/**
	 * Public wrapper around on_our_page() so callers outside the class (the
	 * beta-channel admin notice in the main plugin file) can reuse the same
	 * screen gating instead of duplicating the $GLOBALS['plugin_page'] logic.
	 *
	 * @return bool True when the current admin request is one of the add-on's pages.
	 */
	public static function is_on_our_page(): bool {
		return self::on_our_page();
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
			'cancel'             => __( 'Cancel', 'webchangedetector-for-mainwp' ),
			'resetConfirm'       => __( 'Reset the WebChange Detector connection? This disconnects all sites and you will have to enable them again. Your data on WebChange Detector is not deleted.', 'webchangedetector-for-mainwp' ),
			'preflightTitle'     => __( 'Pre-update visual check', 'webchangedetector-for-mainwp' ),
			'preflightLead'      => __( 'WebChange Detector captures every selected page before the updates, installs all updates, then re-captures and compares.', 'webchangedetector-for-mainwp' ),
			'confirmRun'         => __( 'Capture & update', 'webchangedetector-for-mainwp' ),
			'enoughCredits'      => __( 'Enough credits', 'webchangedetector-for-mainwp' ),
			'upgradePlan'        => __( 'Upgrade plan', 'webchangedetector-for-mainwp' ),
			// Preflight summary strip + sections.
			'sites'              => __( 'Sites', 'webchangedetector-for-mainwp' ),
			'pages'              => __( 'Pages', 'webchangedetector-for-mainwp' ),
			'checks'             => __( 'Checks', 'webchangedetector-for-mainwp' ),
			'desktop'            => __( 'Desktop', 'webchangedetector-for-mainwp' ),
			'mobile'             => __( 'Mobile', 'webchangedetector-for-mainwp' ),
			'page'               => __( 'page', 'webchangedetector-for-mainwp' ),
			'pagesPlural'        => __( 'pages', 'webchangedetector-for-mainwp' ),
			'site'               => __( 'site', 'webchangedetector-for-mainwp' ),
			'sitesPlural'        => __( 'sites', 'webchangedetector-for-mainwp' ),
			/* translators: %1$d used checks, %2$d available, %3$d total. */
			'creditUsage'        => __( 'This run uses %1$d checks · %2$d of %3$d available', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of checks the plan is short by. */
			'creditShort'        => __( '%d short', 'webchangedetector-for-mainwp' ),
			'updatesToInstall'   => __( 'updates will be installed (core, plugins & themes)', 'webchangedetector-for-mainwp' ),
			'noUpdatesBadge'     => __( 'No updates', 'webchangedetector-for-mainwp' ),
			'noEligibleUpdates'  => __( 'No pending updates on the sites enabled for visual checks.', 'webchangedetector-for-mainwp' ),
			'liveNote'           => __( 'Sites stay live; only screenshots are taken.', 'webchangedetector-for-mainwp' ),
			'runningTitle'       => __( 'WCD Updates', 'webchangedetector-for-mainwp' ),
			'running'            => __( 'Running', 'webchangedetector-for-mainwp' ),
			'dontClose'          => __( "don't close this tab", 'webchangedetector-for-mainwp' ),
			// Timeline steps.
			'phasePre'           => __( 'Pre', 'webchangedetector-for-mainwp' ),
			'phaseUpdates'       => __( 'Updates', 'webchangedetector-for-mainwp' ),
			'phasePost'          => __( 'Post', 'webchangedetector-for-mainwp' ),
			'phaseDone'          => __( 'Done', 'webchangedetector-for-mainwp' ),
			'phaseFailed'        => __( 'Failed', 'webchangedetector-for-mainwp' ),
			'verbPre'            => __( 'Capturing pre-update screenshots', 'webchangedetector-for-mainwp' ),
			'verbUpdate'         => __( 'Installing all updates', 'webchangedetector-for-mainwp' ),
			'verbPost'           => __( 'Capturing post-update screenshots', 'webchangedetector-for-mainwp' ),
			'verbDone'           => __( 'Creating change detections', 'webchangedetector-for-mainwp' ),
			// Run-card stat panels.
			'panelPre'           => __( 'Pre-update screenshots', 'webchangedetector-for-mainwp' ),
			'panelUpdates'       => __( 'Installing updates', 'webchangedetector-for-mainwp' ),
			'panelPost'          => __( 'Post-update screenshots', 'webchangedetector-for-mainwp' ),
			'queue'              => __( 'Queue', 'webchangedetector-for-mainwp' ),
			'processing'         => __( 'Processing', 'webchangedetector-for-mainwp' ),
			'doneCount'          => __( 'Done', 'webchangedetector-for-mainwp' ),
			'failed'             => __( 'Failed', 'webchangedetector-for-mainwp' ),
			'queued'             => __( 'Queued', 'webchangedetector-for-mainwp' ),
			'capturing'          => __( 'Capturing…', 'webchangedetector-for-mainwp' ),
			'captured'           => __( 'Captured', 'webchangedetector-for-mainwp' ),
			'installing'         => __( 'Installing…', 'webchangedetector-for-mainwp' ),
			'installed'          => __( 'Installed', 'webchangedetector-for-mainwp' ),
			'updatesUnit'        => __( 'updates', 'webchangedetector-for-mainwp' ),
			// Per-site row statuses.
			'statusPre'          => __( 'Capturing pre', 'webchangedetector-for-mainwp' ),
			'statusUpdating'     => __( 'Updating', 'webchangedetector-for-mainwp' ),
			'statusPost'         => __( 'Capturing post', 'webchangedetector-for-mainwp' ),
			'statusComparing'    => __( 'Comparing', 'webchangedetector-for-mainwp' ),
			'clean'              => __( 'Clean', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of pages to review. */
			'toReview'           => __( '%d to review', 'webchangedetector-for-mainwp' ),
			// Result summary + actions.
			'allGood'            => __( 'All good', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of pages to review. */
			'pagesToReview'      => __( '%d pages to review', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of pages to review (singular). */
			'pageToReview'       => __( '%d page to review', 'webchangedetector-for-mainwp' ),
			'viewResults'        => __( 'View results', 'webchangedetector-for-mainwp' ),
			'recheck'            => __( 'Re-check', 'webchangedetector-for-mainwp' ),
			'runFooterNote'      => __( 'Sites stay live; only screenshots are taken. Keep this tab open until the run finishes.', 'webchangedetector-for-mainwp' ),
			'stillRunning'       => __( 'Still running. Open in WebChange Detector.', 'webchangedetector-for-mainwp' ),
			'noChecks'           => __( 'No URLs configured for this site.', 'webchangedetector-for-mainwp' ),
			// URL panel: search, pagination, select-all.
			'searchUrls'         => __( 'Search URLs…', 'webchangedetector-for-mainwp' ),
			'selectAll'          => __( 'Select all:', 'webchangedetector-for-mainwp' ),
			'prev'               => __( 'Prev', 'webchangedetector-for-mainwp' ),
			'next'               => __( 'Next', 'webchangedetector-for-mainwp' ),
			/* translators: %s: total URL count. */
			'urlsTotal'          => __( '%s URLs', 'webchangedetector-for-mainwp' ),
			'noResults'          => __( 'No URLs match your search.', 'webchangedetector-for-mainwp' ),
			/* translators: %s: device name (Desktop or Mobile). */
			'confirmDisableAll'  => __( 'Disable all %s checks for this site?', 'webchangedetector-for-mainwp' ),
			'noSites'            => __( 'No sites are enabled for visual checks.', 'webchangedetector-for-mainwp' ),
			'genericError'       => __( 'Something went wrong.', 'webchangedetector-for-mainwp' ),
			'disabled'           => __( 'Inactive', 'webchangedetector-for-mainwp' ),
			// "Activate checks for all websites" on the Sites & pages settings tab.
			/* translators: 1: current site number, 2: total sites. */
			'bulkSyncProgress'   => __( 'Activating %1$d of %2$d websites…', 'webchangedetector-for-mainwp' ),
			/* translators: 1: activated site count, 2: total sites. */
			'bulkSyncDone'       => __( 'Activated %1$d of %2$d websites.', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of websites that failed to activate. */
			'bulkSyncFailed'     => __( '%d failed.', 'webchangedetector-for-mainwp' ),
			'bulkSyncConfirm'    => __( 'This activates visual checks for every managed website. Websites are unlimited on every plan; only the checks you run count against it. Continue?', 'webchangedetector-for-mainwp' ),
			'ctaRunning'         => __( 'Visual check running…', 'webchangedetector-for-mainwp' ),
			// Reopen button shown next to the widget heading while the run popup is closed.
			'reopenRunning'      => __( 'Updates running', 'webchangedetector-for-mainwp' ),
			'keepOpenWarning'    => __( 'Please keep this popup open until the run finishes for a smooth update flow.', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of sites (singular). */
			'metaErrorSingle'    => __( 'The checks for %d site could not be loaded. It would be updated WITHOUT visual checks.', 'webchangedetector-for-mainwp' ),
			/* translators: %d: number of sites. */
			'metaErrorPlural'    => __( 'The checks for %d sites could not be loaded. They would be updated WITHOUT visual checks.', 'webchangedetector-for-mainwp' ),
			// Updates-page bar: empty-selection guard + preflight badge for sites without visual checks.
			'noSelection'        => __( 'Select at least one update first.', 'webchangedetector-for-mainwp' ),
			'checksNotActivated' => __( 'Checks not activated', 'webchangedetector-for-mainwp' ),
		);
	}
}
