<?php
/**
 * "Visual Checks" overview page (slug WcdVisualChecks, MainWP page hook ManageSitesWcdVisualChecks).
 *
 * Registered as a MainWP Sites subpage (hidden from the Sites menu) and linked from the left
 * menu's Monitoring category group (see WCD_MainWP_Bootstrap::register_left_menu_item()). A
 * dashboard-wide list of On-Demand Check runs (batches): filter bar (period / status / website /
 * visual), batch + list views, pagination, and an inline comparison table per run. The source is
 * always `manual` (On-Demand): runs created elsewhere on the account (monitoring, auto-update via
 * the webapp) are out of scope here. Data comes from the WCD API (/batches + /comparisons); the
 * markup/AJAX live here, the styles in assets/css/wcd-runs.css.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the account-wide "Visual Checks" overview page and its filter/run helpers.
 */
class WCD_MainWP_Runs_View {

	const PAGE_SLUG = 'WcdVisualChecks';
	const PER_PAGE  = 20;

	/**
	 * Hook the page registration into MainWP's Sites submenu filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'mainwp_getsubpages_sites', array( self::class, 'register_page' ) );
		// menu_hidden only hides the Sites LEFT-menu entry; the Sites page-navigation tabs ignore
		// it, so we filter our tab out of every Sites page except our own (same pattern as
		// MainWP's own Password Policy page).
		add_filter( 'mainwp_manage_sites_navigation_items', array( self::class, 'filter_navigation_items' ), 10, 3 );
	}

	/**
	 * Keep our entries out of the Sites page navigation. The navigation column is only VISIBLE on
	 * per-site views in MainWP 6 (display:none elsewhere), and there our account-wide pages would
	 * just confuse; on our own pages the filter is a practical no-op (column hidden). The visible
	 * Visual Checks | Settings switcher is render_tabs(), not this navigation.
	 *
	 * @param mixed  $items      Navigation items (title, href, active).
	 * @param int    $site_id    Current site id (0 on overview pages).
	 * @param string $shown_page Current subpage slug.
	 * @return array Filtered navigation items.
	 */
	public static function filter_navigation_items( $items, $site_id = 0, $shown_page = '' ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$our_slugs = array( self::PAGE_SLUG, WCD_MainWP_Site_Settings::SUBPAGE_SLUG );
		$on_ours   = in_array( (string) $shown_page, $our_slugs, true );

		$is_ours = static function ( $item ) use ( $our_slugs ) {
			$href = is_array( $item ) ? (string) ( $item['href'] ?? '' ) : '';
			foreach ( $our_slugs as $slug ) {
				// Exact page match: the Settings slug shares the Visual Checks slug as prefix.
				if ( preg_match( '/page=ManageSites' . preg_quote( $slug, '/' ) . '($|&)/', $href ) ) {
					return true;
				}
			}

			return false;
		};

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $is_ours, $on_ours ) {
					return $on_ours ? $is_ours( $item ) : ! $is_ours( $item );
				}
			)
		);
	}

	/**
	 * Register the page as a Sites subpage. menu_hidden keeps it out of the Sites menu group; its
	 * left-menu entry lives in the Monitoring category instead (registered by the bootstrap).
	 *
	 * @param array $sub_pages Existing MainWP Sites subpages.
	 * @return array Subpages with the Visual Checks entry appended.
	 */
	public static function register_page( array $sub_pages ): array {
		$sub_pages[] = array(
			'title'       => __( 'WebChange Detector', 'webchangedetector-for-mainwp' ),
			'slug'        => self::PAGE_SLUG,
			'sitetab'     => false,
			'menu_hidden' => true,
			// Explicit href so the Sites page navigation never appends a per-site &id=N.
			'href'        => 'admin.php?page=ManageSites' . self::PAGE_SLUG,
			'callback'    => array( self::class, 'render_page' ),
		);

		return $sub_pages;
	}

	/**
	 * Render the page by including its template.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		include WCD_MAINWP_PLUGIN_PATH . 'templates/runs-view.php';
	}

	/**
	 * Echo the Visual Checks area's tab switcher: a native Fomantic "top attached tabular menu"
	 * (the same element MainWP's own modules use for in-page tabs; the Sites page-navigation
	 * column is display:none on non-per-site pages in MainWP 6, so it cannot serve as the
	 * switcher here). The content below should use an "attached segment" to dock onto the tabs.
	 *
	 * @param string $active Active tab: 'checks' or 'settings'.
	 * @return void
	 */
	public static function render_tabs( string $active ): void {
		$tabs = array(
			'checks'   => array(
				'href'  => admin_url( 'admin.php?page=ManageSites' . self::PAGE_SLUG ),
				'label' => __( 'Checks', 'webchangedetector-for-mainwp' ),
			),
			'settings' => array(
				'href'  => admin_url( 'admin.php?page=ManageSites' . WCD_MainWP_Site_Settings::SUBPAGE_SLUG ),
				'label' => __( 'Settings', 'webchangedetector-for-mainwp' ),
			),
		);
		?>
		<div class="ui top attached tabular menu wcd-vc-tabs">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<a class="item<?php echo $key === $active ? ' active' : ''; ?>" href="<?php echo esc_url( $tab['href'] ); ?>"><?php echo esc_html( $tab['label'] ); ?></a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ─────────────────────────── Filter options ────────────────────────── */

	/**
	 * Status filter options keyed by API status value.
	 *
	 * @return array Status value => translated label.
	 */
	public static function status_options(): array {
		return array(
			'new'            => __( 'New', 'webchangedetector-for-mainwp' ),
			'ok'             => __( 'OK', 'webchangedetector-for-mainwp' ),
			'to_fix'         => __( 'To Fix', 'webchangedetector-for-mainwp' ),
			'false_positive' => __( 'False positive', 'webchangedetector-for-mainwp' ),
		);
	}

	/**
	 * Enabled managed sites for the website filter: [ ['site_id' => int, 'name' => string], ... ].
	 *
	 * @return array List of arrays each with 'site_id' (int) and 'name' (string).
	 */
	public static function website_options(): array {
		$map     = WCD_MainWP_Site_Map::all();
		$managed = WCD_MainWP_Site_Map::managed_sites();
		$out     = array();
		foreach ( $map as $site_id => $entry ) {
			if ( empty( $entry['enabled'] ) ) {
				continue;
			}
			$site_id = (int) $site_id;
			$out[]   = array(
				'site_id' => $site_id,
				'name'    => $managed[ $site_id ]['name'] ?? ( $entry['domain'] ?? ( 'Site #' . $site_id ) ),
			);
		}

		return $out;
	}

	/* ──────────────────────────── Filter mapping ───────────────────────── */

	/**
	 * Map raw request input to the API filter array used for /batches and /comparisons. Mirrors the
	 * webapp's mapping (difference_only -> above_threshold, selected sites -> group_ids). The source
	 * is always `manual`: this view only shows On-Demand Checks, never the account's monitoring or
	 * auto-update runs made elsewhere (e.g. in the webapp).
	 *
	 * @param array $input Raw request input (page, from, to, status, difference_only, site_ids).
	 * @return array API filter array for /batches and /comparisons.
	 */
	public static function build_api_filters( array $input ): array {
		// No orderBy here: /batches uses its own default (newest first), matching the webapp. The flat
		// (/comparisons) and drill-in paths set their own ordering.
		$filters = array(
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'per_page' => self::PER_PAGE,
			'source'   => 'manual',
		);

		$from_ts = strtotime( isset( $input['from'] ) ? trim( (string) $input['from'] ) : '' );
		$to_ts   = strtotime( isset( $input['to'] ) ? trim( (string) $input['to'] ) : '' );
		if ( false !== $from_ts ) {
			$filters['from'] = gmdate( 'Y-m-d', $from_ts );
		}
		if ( false !== $to_ts ) {
			$filters['to'] = gmdate( 'Y-m-d', $to_ts );
		}

		$status            = isset( $input['status'] ) ? (string) $input['status'] : '';
		$filters['status'] = '' !== $status ? $status : 'new,ok,to_fix,false_positive';

		if ( ! empty( $input['difference_only'] ) ) {
			$filters['above_threshold'] = true;
		}

		$group_ids = self::resolve_group_ids( $input['site_ids'] ?? array() );
		if ( ! empty( $group_ids ) ) {
			$filters['group_ids'] = implode( ',', $group_ids );
		}

		return $filters;
	}

	/**
	 * Resolve selected MainWP site ids to their WCD group UUIDs (manual + auto). Empty when nothing
	 * selected (the view then shows all of the account's runs).
	 *
	 * @param mixed $site_ids Selected MainWP site ids (array) or other input.
	 * @return array List of WCD group UUID strings.
	 */
	protected static function resolve_group_ids( $site_ids ): array {
		if ( ! is_array( $site_ids ) ) {
			return array();
		}
		$map = WCD_MainWP_Site_Map::all();
		$ids = array();
		foreach ( $site_ids as $sid ) {
			$entry = $map[ (int) $sid ] ?? array();
			if ( ! empty( $entry['manual_group_uuid'] ) ) {
				$ids[] = $entry['manual_group_uuid'];
			}
			if ( ! empty( $entry['auto_group_uuid'] ) ) {
				$ids[] = $entry['auto_group_uuid'];
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/* ─────────────────────────────── Rendering ─────────────────────────── */

	/**
	 * Render the batch (accordion) list for the given API filters as a native MainWP table: one
	 * tbody per run with a clickable title row and a lazy-loaded content row (the comparisons).
	 *
	 * @param array $api_filters API filter array from build_api_filters().
	 * @return array Array with 'html' (string) markup and 'pagination' (string) markup.
	 */
	public static function render_batch_list( array $api_filters ): array {
		$response = WCD_MainWP_API::list_batches( $api_filters );
		if ( ! $response['ok'] ) {
			return array(
				'html'       => self::message_box( 'wcd-error', $response['error'] ),
				'pagination' => '',
			);
		}

		$data    = is_array( $response['data'] ) ? $response['data'] : array();
		$batches = ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : array();
		$meta    = ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) ? $data['meta'] : array();

		if ( empty( $batches ) ) {
			return array(
				'html'       => self::empty_box(),
				'pagination' => '',
			);
		}

		ob_start();
		?>
		<table class="ui tablet stackable table mainwp-manage-updates-table wcd-runs-table">
			<thead>
				<tr>
					<th scope="col" class="collapsing no-sort"></th>
					<th scope="col"><?php esc_html_e( 'Websites', 'webchangedetector-for-mainwp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'webchangedetector-for-mainwp' ); ?></th>
					<th scope="col" class="collapsing"><?php esc_html_e( 'Created', 'webchangedetector-for-mainwp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'AI Summary', 'webchangedetector-for-mainwp' ); ?></th>
				</tr>
			</thead>
			<?php
			foreach ( $batches as $batch ) {
				self::render_batch_rows( (array) $batch );
			}
			?>
		</table>
		<?php

		return array(
			'html'       => ob_get_clean(),
			'pagination' => self::render_pagination( $meta ),
		);
	}

	/**
	 * Render the flat comparison list for the given API filters.
	 *
	 * @param array $api_filters API filter array from build_api_filters().
	 * @return array Array with 'html' (string) markup and 'pagination' (string) markup.
	 */
	public static function render_flat_list( array $api_filters ): array {
		// Newest comparisons first (matches the webapp's flat List view).
		$api_filters['orderBy']        = 'created_at';
		$api_filters['orderDirection'] = 'desc';
		// The /comparisons endpoint filters groups via `groups` (not /batches' `group_ids`).
		if ( isset( $api_filters['group_ids'] ) ) {
			$api_filters['groups'] = $api_filters['group_ids'];
			unset( $api_filters['group_ids'] );
		}
		$response = WCD_MainWP_API::get_comparisons( $api_filters );
		if ( ! $response['ok'] ) {
			return array(
				'html'       => self::message_box( 'wcd-error', $response['error'] ),
				'pagination' => '',
			);
		}

		$data        = is_array( $response['data'] ) ? $response['data'] : array();
		$comparisons = ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : array();
		$meta        = ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) ? $data['meta'] : array();

		if ( empty( $comparisons ) ) {
			return array(
				'html'       => self::empty_box(),
				'pagination' => '',
			);
		}

		return array(
			'html'       => self::render_comparisons_table( $comparisons, true ),
			'pagination' => self::render_pagination( $meta ),
		);
	}

	/**
	 * Render a comparison table (used by the batch drill-in and the flat view).
	 *
	 * @param array $comparisons List of comparison records (arrays).
	 * @param bool  $with_run    Whether to include the run/batch column.
	 * @return string Table markup.
	 */
	public static function render_comparisons_table( array $comparisons, bool $with_run = false ): string {
		if ( empty( $comparisons ) ) {
			return '<p class="wcd-muted">' . esc_html__( 'No comparisons in this run.', 'webchangedetector-for-mainwp' ) . '</p>';
		}

		// Flat list = a primary MainWP table; batch drill-in = MainWP's nested item-table style.
		$table_class = $with_run ? 'ui tablet stackable table wcd-runs-table' : 'ui table mainwp-manage-updates-item-table';

		ob_start();
		?>
		<table class="<?php echo esc_attr( $table_class ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Status', 'webchangedetector-for-mainwp' ); ?></th>
					<?php
					if ( $with_run ) :
						?>
						<th><?php esc_html_e( 'Run', 'webchangedetector-for-mainwp' ); ?></th><?php endif; ?>
					<th><?php esc_html_e( 'URL', 'webchangedetector-for-mainwp' ); ?></th>
					<th><?php esc_html_e( 'Compared', 'webchangedetector-for-mainwp' ); ?></th>
					<th><?php esc_html_e( 'Visual change', 'webchangedetector-for-mainwp' ); ?></th>
					<th><?php esc_html_e( 'AI summary', 'webchangedetector-for-mainwp' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $comparisons as $c ) {
					self::comparison_row( (array) $c, $with_run );
				}
				?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	/* ──────────────────────── Rendering internals ──────────────────────── */

	/**
	 * Echo one batch as a native accordion-table tbody: a clickable title row and a hidden content
	 * row whose comparisons are lazy-loaded on first open. No "On-Demand Check" label per row: this
	 * page only ever shows On-Demand Checks, so the websites identify the run.
	 *
	 * @param array $batch Batch record from the API.
	 * @return void
	 */
	protected static function render_batch_rows( array $batch ): void {
		$batch_id    = (string) ( $batch['id'] ?? '' );
		$failed      = (int) ( $batch['queues_count']['failed'] ?? 0 );
		$counts      = ( isset( $batch['comparisons_count'] ) && is_array( $batch['comparisons_count'] ) ) ? $batch['comparisons_count'] : array();
		$group_names = ( ! empty( $batch['group_names'] ) && is_array( $batch['group_names'] ) ) ? $batch['group_names'] : array();
		$finished_at = (string) ( $batch['finished_at'] ?? '' );
		$ai_summary  = (string) ( $batch['ai_summary']['summary'] ?? '' );
		$label       = ! empty( $group_names ) ? implode( ', ', $group_names ) : self::display_batch_name( $batch['name'] ?? '' );
		?>
		<tbody class="wcd-runs-batch" data-batch-id="<?php echo esc_attr( $batch_id ); ?>">
			<tr class="title wcd-runs-batch-head">
				<td class="accordion-trigger collapsing"><i class="caret right icon"></i></td>
				<td><strong><?php echo esc_html( $label ); ?></strong></td>
				<td>
					<div class="wcd-status-badges">
						<?php
						foreach ( $counts as $status => $amount ) {
							if ( (int) $amount > 0 && 'above_threshold' !== $status ) {
								self::status_badge( (string) $status, (int) $amount );
							}
						}
						if ( $failed > 0 ) {
							self::status_badge( 'failed', $failed );
						}
						?>
					</div>
				</td>
				<td class="collapsing">
					<?php if ( $finished_at ) : ?>
						<span data-tooltip="<?php echo esc_attr( self::short_date( $finished_at ) ); ?>" data-inverted="" data-position="left center">
							<?php echo esc_html( self::time_ago( $finished_at ) ); ?>
						</span>
					<?php else : ?>
						<span class="ui small text"><?php esc_html_e( 'Processing', 'webchangedetector-for-mainwp' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $finished_at ) : ?>
						<span class="ui small text wcd-ai-summary-text"><?php echo esc_html( $ai_summary ? $ai_summary : __( 'AI summary skipped', 'webchangedetector-for-mainwp' ) ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr class="wcd-runs-batch-body" hidden>
				<td colspan="5">
					<div class="wcd-runs-loading"><div class="ui active inline loader"></div></div>
				</td>
			</tr>
		</tbody>
		<?php
	}

	/**
	 * Echo one comparison table row.
	 *
	 * @param array $c        Comparison record from the API.
	 * @param bool  $with_run Whether to include the run/batch column.
	 * @return void
	 */
	protected static function comparison_row( array $c, bool $with_run ): void {
		$status = $c['status'] ?? 'none';
		if ( null === $status || '' === $status ) {
			$status = 'none';
		}
		$url     = (string) ( $c['url'] ?? '' );
		$title   = (string) ( $c['html_title'] ?? '' );
		$device  = (string) ( $c['device'] ?? '' );
		$percent = isset( $c['difference_percent'] ) ? (float) $c['difference_percent'] : 0.0;
		$public  = (string) ( $c['public_link'] ?? '' );
		$before  = (string) ( $c['screenshot_1_created_at'] ?? '' );
		$after   = (string) ( $c['screenshot_2_created_at'] ?? '' );
		$ai      = ( ! empty( $c['ai_verification_result']['summary'] ) && is_string( $c['ai_verification_result']['summary'] ) )
			? $c['ai_verification_result']['summary']
			: '';
		// Presentation-only colour hint (low/high). NOT the per-group "above threshold" decision,
		// which the API owns; this just tints the percentage.
		$sev = $percent <= 0 ? '' : ( $percent < 5 ? 'wcd-vc-sev-low' : 'wcd-vc-sev-high' );
		?>
		<tr>
			<td><?php self::status_badge( (string) $status ); ?></td>
			<?php if ( $with_run ) : ?>
				<td><?php echo esc_html( self::display_batch_name( $c['batch_name'] ?? '' ) ); ?></td>
			<?php endif; ?>
			<td>
				<span class="<?php echo esc_attr( self::device_icon_class( $device ) ); ?>"></span>
				<span class="wcd-url-link"><?php echo esc_html( $title ? $title : $url ); ?></span>
				<?php
				if ( $url ) :
					?>
					<div class="wcd-url-path"><?php echo esc_html( $url ); ?></div><?php endif; ?>
			</td>
			<td>
				<?php if ( $before || $after ) : ?>
					<div class="wcd-compared-row"><span class="wcd-ba-label"><?php esc_html_e( 'Before', 'webchangedetector-for-mainwp' ); ?></span><span class="screenshot-date"><?php echo esc_html( self::short_date( $before ) ); ?></span></div>
					<div class="wcd-compared-row"><span class="wcd-ba-label"><?php esc_html_e( 'After', 'webchangedetector-for-mainwp' ); ?></span><span class="screenshot-date"><?php echo esc_html( self::short_date( $after ) ); ?></span></div>
				<?php endif; ?>
			</td>
			<td class="wcd-visual-changes-column">
				<span class="wcd-visual-percentage <?php echo esc_attr( $sev ); ?>"><?php echo esc_html( self::format_percent( $percent ) ); ?>%</span>
			</td>
			<td><?php echo esc_html( $ai ); ?></td>
			<td>
			<?php
			if ( $public ) :
				?>
				<a class="ui mini button" href="<?php echo esc_url( $public ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'webchangedetector-for-mainwp' ); ?></a><?php endif; ?></td>
		</tr>
		<?php
	}

	/**
	 * Status badge as a native Fomantic `ui label` (themes for light/dark automatically). The colour
	 * variants are MainWP's own; built with esc_* so it is safe to echo directly.
	 *
	 * @param string   $status Status key (new, ok, to_fix, false_positive, failed, none).
	 * @param int|null $count  Optional count shown in the label's detail.
	 * @return void
	 */
	protected static function status_badge( string $status, $count = null ): void {
		$meta  = array(
			'new'            => array( 'red', __( 'New', 'webchangedetector-for-mainwp' ) ),
			'ok'             => array( 'green', __( 'OK', 'webchangedetector-for-mainwp' ) ),
			'to_fix'         => array( 'orange', __( 'To Fix', 'webchangedetector-for-mainwp' ) ),
			'false_positive' => array( 'purple', __( 'False positive', 'webchangedetector-for-mainwp' ) ),
			'failed'         => array( 'grey', __( 'Failed', 'webchangedetector-for-mainwp' ) ),
			'none'           => array( 'basic', __( 'No changes', 'webchangedetector-for-mainwp' ) ),
		);
		$color = $meta[ $status ][0] ?? 'basic';
		$label = $meta[ $status ][1] ?? ucfirst( $status );
		?>
		<span class="ui <?php echo esc_attr( $color ); ?> label">
			<?php echo esc_html( $label ); ?>
			<?php
			if ( null !== $count ) :
				?>
				<span class="detail"><?php echo esc_html( (string) $count ); ?></span><?php endif; ?>
		</span>
		<?php
	}

	/**
	 * Build the previous/next pagination control from API meta.
	 *
	 * @param array $meta Pagination meta (current_page, last_page, total, per_page).
	 * @return string Pagination markup, or empty string when only one page.
	 */
	protected static function render_pagination( array $meta ): string {
		$current = (int) ( $meta['current_page'] ?? 1 );
		$last    = (int) ( $meta['last_page'] ?? 0 );
		if ( $last < 1 && isset( $meta['total'], $meta['per_page'] ) && (int) $meta['per_page'] > 0 ) {
			$last = (int) ceil( (int) $meta['total'] / (int) $meta['per_page'] );
		}
		if ( $last <= 1 ) {
			return '';
		}

		ob_start();
		?>
		<div class="wcd-runs-pagination">
			<button type="button" class="ui mini basic button wcd-runs-page" data-page="<?php echo esc_attr( (string) max( 1, $current - 1 ) ); ?>" <?php disabled( $current <= 1 ); ?>><?php esc_html_e( 'Previous', 'webchangedetector-for-mainwp' ); ?></button>
			<span class="wcd-runs-page-info ui small text">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages. */
					esc_html__( 'Page %1$d of %2$d', 'webchangedetector-for-mainwp' ),
					(int) $current,
					(int) $last
				);
				?>
			</span>
			<button type="button" class="ui mini basic button wcd-runs-page" data-page="<?php echo esc_attr( (string) min( $last, $current + 1 ) ); ?>" <?php disabled( $current >= $last ); ?>><?php esc_html_e( 'Next', 'webchangedetector-for-mainwp' ); ?></button>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Empty-state message shown when no runs match the filters.
	 *
	 * @return string Message markup.
	 */
	protected static function empty_box(): string {
		return self::message_box( 'info', __( 'No change detections yet. Run an On-Demand Check or monitoring, or try different filters.', 'webchangedetector-for-mainwp' ) );
	}

	/**
	 * A native Fomantic `ui message` (themes for light/dark). $type: 'error' -> negative, else info.
	 *
	 * @param string $type Message type ('error'/'wcd-error' for negative, else info).
	 * @param string $text Message text (escaped before output).
	 * @return string Message markup.
	 */
	protected static function message_box( string $type, string $text ): string {
		$cls = 'wcd-error' === $type || 'error' === $type ? 'ui negative message' : 'ui message';

		return '<div class="' . $cls . '"><p>' . esc_html( $text ) . '</p></div>';
	}

	/* ───────────────────────────── Helpers ─────────────────────────────── */

	/**
	 * Display name for a batch, rewriting the legacy "Manual Checks" label.
	 *
	 * @param mixed $name Raw batch name.
	 * @return string Display name.
	 */
	protected static function display_batch_name( $name ): string {
		$name = (string) $name;

		return 'Manual Checks' === trim( $name ) ? __( 'On-Demand Checks', 'webchangedetector-for-mainwp' ) : $name;
	}

	/**
	 * Period pill label from a date range (mirrors the webapp's wcd_period_label, dash-free).
	 *
	 * @param string $from Range start date string (may be empty).
	 * @param string $to   Range end date string (may be empty).
	 * @return string Period label.
	 */
	public static function period_label( string $from, string $to ): string {
		if ( '' === $from && '' === $to ) {
			return __( 'All time', 'webchangedetector-for-mainwp' );
		}
		if ( '' === $from || '' === $to ) {
			return __( 'Custom range', 'webchangedetector-for-mainwp' );
		}

		if ( gmdate( 'Y-m-d' ) === $to ) {
			$diff_days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS );
			foreach ( array( 7, 30, 90 ) as $preset ) {
				if ( abs( $diff_days - $preset ) <= 1 ) {
					/* translators: %d: number of days. */
					return sprintf( __( 'Last %d days', 'webchangedetector-for-mainwp' ), $preset );
				}
			}
		}

		return gmdate( 'd.m.Y', strtotime( $from ) ) . ' to ' . gmdate( 'd.m.Y', strtotime( $to ) );
	}

	/**
	 * Format a difference percentage for display.
	 *
	 * @param mixed $percent Difference percentage value.
	 * @return string Formatted percentage (e.g. "1.23" or "< 0.01").
	 */
	protected static function format_percent( $percent ): string {
		$percent = (float) $percent;
		if ( $percent > 0 && $percent < 0.005 ) {
			return '< 0.01';
		}

		return (string) round( $percent, 2 );
	}

	/**
	 * Human-readable "x ago" label for a datetime string.
	 *
	 * @param string $datetime Datetime string parseable by strtotime().
	 * @return string Relative time label, or empty string when unparseable.
	 */
	protected static function time_ago( string $datetime ): string {
		$ts = strtotime( $datetime );
		if ( ! $ts ) {
			return '';
		}

		/* translators: %s: human-readable time difference, e.g. "2 hours". */
		return sprintf( __( '%s ago', 'webchangedetector-for-mainwp' ), human_time_diff( $ts, time() ) );
	}

	/**
	 * Localized short date/time label for a datetime string.
	 *
	 * @param string $datetime Datetime string parseable by strtotime().
	 * @return string Formatted date, or empty string when unparseable.
	 */
	protected static function short_date( string $datetime ): string {
		$ts = strtotime( $datetime );

		// wp_date converts the API's UTC timestamp into the dashboard's configured timezone.
		return $ts ? wp_date( 'd/m/Y H:i', $ts ) : '';
	}

	/**
	 * Dashicons CSS class for a device type.
	 *
	 * @param string $device Device key (desktop, mobile).
	 * @return string Dashicons class string.
	 */
	protected static function device_icon_class( string $device ): string {
		switch ( $device ) {
			case 'desktop':
				return 'dashicons dashicons-desktop';
			case 'mobile':
				return 'dashicons dashicons-smartphone';
			default:
				return 'dashicons dashicons-media-default';
		}
	}
}
