<?php
/**
 * "Checks" tab of the WebChange Detector extension page: a dashboard-wide list of On-Demand Check
 * runs (batches).
 *
 * Rendered as a tab on the add-on's extension page (Extensions -> WebChange Detector); the page
 * shell (templates/admin-page.php) supplies the MainWP chrome + tab switcher. Filter bar (period /
 * status / website / visual), batch + list views, pagination, and an inline comparison table per
 * run. The source is always `manual` (On-Demand): runs created elsewhere on the account (monitoring,
 * auto-update via the webapp) are out of scope here. Data comes from the WCD API (/batches +
 * /comparisons); the markup/AJAX live here, the styles (scoped to .wcd-runs) in assets/css/wcd-mainwp.css.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the account-wide "Checks" tab, the "Run" tab and the shared tab switcher + filter helpers.
 */
class WCD_MainWP_Runs_View {

	const PER_PAGE = 20;

	/**
	 * Render the "Run" tab body (the safe-update entry point). The page shell renders the chrome +
	 * tab switcher around it.
	 *
	 * @return void
	 */
	public static function render_run_page(): void {
		include WCD_MAINWP_PLUGIN_PATH . 'templates/run-view.php';
	}

	/**
	 * Render the "Checks" tab body (the runs list). The page shell renders the chrome + tab switcher
	 * around it.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		include WCD_MAINWP_PLUGIN_PATH . 'templates/runs-view.php';
	}

	/**
	 * Echo the extension page's tab switcher: MainWP's native sub-navigation bar as a Fomantic
	 * "ui labeled icon inverted menu mainwp-sub-submenu" (the exact class list the SeoPress MainWP
	 * add-on uses), so each item shows an icon above its label (Run, Checks, Settings, Account). The
	 * `inverted` class is mandatory: the MainWP theme scopes the readable white labels/icons and the
	 * accent-colored active-tab highlight to `.ui.inverted.menu.mainwp-sub-submenu`; the bare class
	 * only paints the dark background. The four tabs all live on the single extension page and switch
	 * via a ?tab= reload (WCD_MainWP_Bootstrap::tab_url()). Because the bar is free-standing (not
	 * "top attached"), the tab body below uses a plain "ui padded segment" (not "bottom attached").
	 *
	 * @param string $active Active tab: 'run', 'checks', 'settings' or 'account'.
	 * @return void
	 */
	public static function render_tabs( string $active ): void {
		$tabs = array(
			'run'      => array(
				'icon'  => 'play',
				'label' => __( 'Run', 'webchangedetector-for-mainwp' ),
			),
			'checks'   => array(
				'icon'  => 'history',
				'label' => __( 'Checks', 'webchangedetector-for-mainwp' ),
			),
			'settings' => array(
				'icon'  => 'cog',
				'label' => __( 'Settings', 'webchangedetector-for-mainwp' ),
			),
			'account'  => array(
				'icon'  => 'user',
				'label' => __( 'Account', 'webchangedetector-for-mainwp' ),
			),
		);
		?>
		<div class="ui labeled icon inverted menu mainwp-sub-submenu">
			<?php foreach ( $tabs as $wcd_mainwp_key => $wcd_mainwp_tab ) : ?>
				<a class="item<?php echo $wcd_mainwp_key === $active ? ' active' : ''; ?>" href="<?php echo esc_url( WCD_MainWP_Bootstrap::tab_url( $wcd_mainwp_key ) ); ?>">
					<i class="<?php echo esc_attr( $wcd_mainwp_tab['icon'] ); ?> icon"></i>
					<?php echo esc_html( $wcd_mainwp_tab['label'] ); ?>
				</a>
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
					<th scope="col" class="wcd-col-ai"><?php esc_html_e( 'AI Summary', 'webchangedetector-for-mainwp' ); ?></th>
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
					<th class="wcd-col-ai"><?php esc_html_e( 'AI summary', 'webchangedetector-for-mainwp' ); ?></th>
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
				<td class="wcd-col-ai">
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
		// Flow checkpoint comparisons carry the flow identity (additive API fields, null on regular
		// rows): show "flow name : checkpoint label" so several checkpoints of one start URL stay
		// distinguishable in the drill-in. Markup-only addition; no key or shape changes.
		$flow_label = '';
		if ( ! empty( $c['flow_checkpoint_label'] ) && is_string( $c['flow_checkpoint_label'] ) ) {
			$flow_name  = ( ! empty( $c['flow_name'] ) && is_string( $c['flow_name'] ) ) ? $c['flow_name'] : __( 'Flow', 'webchangedetector-for-mainwp' );
			$flow_label = $flow_name . ' : ' . $c['flow_checkpoint_label'];
		}
		// Presentation-only colour hint (low/high). NOT the per-group "above threshold" decision,
		// which the API owns; this just tints the percentage.
		$sev = $percent <= 0 ? '' : ( $percent < 5 ? 'wcd-vc-sev-low' : 'wcd-vc-sev-high' );
		?>
		<tr>
			<td><?php self::status_badge( (string) $status ); ?></td>
			<?php if ( $with_run ) : ?>
				<td><?php echo esc_html( self::display_batch_name( $c['batch_name'] ?? '' ) ); ?></td>
			<?php endif; ?>
			<td class="wcd-col-url">
				<span class="<?php echo esc_attr( self::device_icon_class( $device ) ); ?>"></span>
				<span class="wcd-url-link"><?php echo esc_html( $title ? $title : $url ); ?></span>
				<?php if ( '' !== $flow_label ) : ?>
					<div class="wcd-flow-row-label"><i class="route icon"></i><?php echo esc_html( $flow_label ); ?></div>
				<?php endif; ?>
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
			<td class="wcd-col-ai"><?php echo esc_html( $ai ); ?></td>
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
	 * Format a difference percentage for display. Public: the Interaction Flows renderer reuses it
	 * so percentages read identically everywhere.
	 *
	 * @param mixed $percent Difference percentage value.
	 * @return string Formatted percentage (e.g. "1.23" or "< 0.01").
	 */
	public static function format_percent( $percent ): string {
		$percent = (float) $percent;
		if ( $percent > 0 && $percent < 0.005 ) {
			return '< 0.01';
		}

		return (string) round( $percent, 2 );
	}

	/**
	 * Human-readable "x ago" label for a datetime string. Public: reused by the Interaction Flows
	 * renderer.
	 *
	 * @param string $datetime Datetime string parseable by strtotime().
	 * @return string Relative time label, or empty string when unparseable.
	 */
	public static function time_ago( string $datetime ): string {
		$ts = strtotime( $datetime );
		if ( ! $ts ) {
			return '';
		}

		/* translators: %s: human-readable time difference, e.g. "2 hours". */
		return sprintf( __( '%s ago', 'webchangedetector-for-mainwp' ), human_time_diff( $ts, time() ) );
	}

	/**
	 * Localized short date/time label for a datetime string. Public: reused by the Interaction
	 * Flows renderer.
	 *
	 * @param string $datetime Datetime string parseable by strtotime().
	 * @return string Formatted date, or empty string when unparseable.
	 */
	public static function short_date( string $datetime ): string {
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
