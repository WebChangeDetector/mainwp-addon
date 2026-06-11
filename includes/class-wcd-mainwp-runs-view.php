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

defined( 'ABSPATH' ) || exit;

/**
 * Renders the account-wide "Change Detections" overview page and its filter/run helpers.
 */
class WCD_MainWP_Runs_View {

	const PAGE_SLUG = 'WcdChangeDetections';
	const PER_PAGE  = 20;

	/**
	 * Hook the page registration into MainWP's Sites submenu filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'mainwp_getsubpages_sites', array( self::class, 'register_page' ) );
	}

	/**
	 * Register the page as a Sites submenu entry (not a per-site tab, so it shows in the left menu).
	 *
	 * @param array $sub_pages Existing MainWP Sites subpages.
	 * @return array Subpages with the Change Detections entry appended.
	 */
	public static function register_page( array $sub_pages ): array {
		$sub_pages[] = array(
			'title'    => 'Change Detections',
			'slug'     => self::PAGE_SLUG,
			'sitetab'  => false,
			'callback' => array( self::class, 'render_page' ),
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

	/* ─────────────────────────── Filter options ────────────────────────── */

	/**
	 * Status filter options keyed by API status value.
	 *
	 * @return array Status value => translated label.
	 */
	public static function status_options(): array {
		return array(
			'new'            => __( 'New', 'webchangedetector' ),
			'ok'             => __( 'OK', 'webchangedetector' ),
			'to_fix'         => __( 'To Fix', 'webchangedetector' ),
			'false_positive' => __( 'False positive', 'webchangedetector' ),
		);
	}

	/**
	 * Source (check type) filter options keyed by API source value.
	 *
	 * @return array Source value => translated label.
	 */
	public static function source_options(): array {
		return array(
			''            => __( 'All types', 'webchangedetector' ),
			'manual'      => __( 'On-Demand Checks', 'webchangedetector' ),
			'monitoring'  => __( 'Monitoring', 'webchangedetector' ),
			'auto_update' => __( 'Auto-Update Checks', 'webchangedetector' ),
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
	 * webapp's mapping (difference_only -> above_threshold, selected sites -> group_ids).
	 *
	 * @param array $input Raw request input (page, from, to, source, status, difference_only, site_ids).
	 * @return array API filter array for /batches and /comparisons.
	 */
	public static function build_api_filters( array $input ): array {
		// No orderBy here: /batches uses its own default (newest first), matching the webapp. The flat
		// (/comparisons) and drill-in paths set their own ordering.
		$filters = array(
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'per_page' => self::PER_PAGE,
		);

		$from = isset( $input['from'] ) ? trim( (string) $input['from'] ) : '';
		$to   = isset( $input['to'] ) ? trim( (string) $input['to'] ) : '';
		if ( '' !== $from ) {
			$filters['from'] = gmdate( 'Y-m-d', strtotime( $from ) );
		}
		if ( '' !== $to ) {
			$filters['to'] = gmdate( 'Y-m-d', strtotime( $to ) );
		}

		$source = isset( $input['source'] ) ? (string) $input['source'] : '';
		if ( '' !== $source ) {
			$filters['source'] = $source;
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
	 * Render the batch (accordion) list for the given API filters.
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
		foreach ( $batches as $batch ) {
			self::render_batch_card( (array) $batch );
		}

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
		$response                      = WCD_MainWP_API::get_comparisons( $api_filters );
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
			return '<p class="wcd-muted">' . esc_html__( 'No comparisons in this run.', 'webchangedetector' ) . '</p>';
		}

		ob_start();
		?>
		<table class="ui celled striped compact table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Status', 'webchangedetector' ); ?></th>
					<?php
					if ( $with_run ) :
						?>
						<th><?php esc_html_e( 'Run', 'webchangedetector' ); ?></th><?php endif; ?>
					<th><?php esc_html_e( 'URL', 'webchangedetector' ); ?></th>
					<th><?php esc_html_e( 'Compared', 'webchangedetector' ); ?></th>
					<th><?php esc_html_e( 'Visual change', 'webchangedetector' ); ?></th>
					<th><?php esc_html_e( 'AI summary', 'webchangedetector' ); ?></th>
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
	 * Echo one batch accordion card.
	 *
	 * @param array $batch Batch record from the API.
	 * @return void
	 */
	protected static function render_batch_card( array $batch ): void {
		$batch_id     = (string) ( $batch['id'] ?? '' );
		$failed       = (int) ( $batch['queues_count']['failed'] ?? 0 );
		$counts       = ( isset( $batch['comparisons_count'] ) && is_array( $batch['comparisons_count'] ) ) ? $batch['comparisons_count'] : array();
		$display_name = self::display_batch_name( $batch['name'] ?? '' );
		$source_label = self::source_label( $batch['source'] ?? '' );
		$group_names  = ( ! empty( $batch['group_names'] ) && is_array( $batch['group_names'] ) ) ? $batch['group_names'] : array();
		$finished_at  = $batch['finished_at'] ?? '';
		$ai_summary   = $batch['ai_summary']['summary'] ?? '';
		?>
		<div class="ui segment wcd-runs-batch" data-batch-id="<?php echo esc_attr( $batch_id ); ?>">
			<div class="wcd-runs-batch-head">
				<span class="wcd-runs-caret dashicons dashicons-arrow-right-alt2"></span>
				<div class="wcd-runs-col wcd-runs-col-status">
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
				</div>
				<div class="wcd-runs-col wcd-runs-col-name">
					<strong><?php esc_html_e( 'Change Detection', 'webchangedetector' ); ?></strong>
					<span class="wcd-cd-name"><?php echo esc_html( $display_name ); ?></span>
					<?php if ( $source_label && $source_label !== $display_name ) : ?>
						<span class="wcd-cd-subtitle"><?php echo esc_html( $source_label ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $group_names ) ) : ?>
						<span class="wcd-cd-websites"><?php echo esc_html( implode( ', ', $group_names ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="wcd-runs-col wcd-runs-col-date">
					<strong><?php esc_html_e( 'Created', 'webchangedetector' ); ?></strong>
					<span>
						<?php
						if ( $finished_at ) {
							echo esc_html( self::time_ago( $finished_at ) );
							echo '<br>';
							echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $finished_at ) ) );
						} else {
							esc_html_e( 'Processing', 'webchangedetector' );
						}
						?>
					</span>
				</div>
				<div class="wcd-runs-col wcd-runs-col-summary">
					<strong><?php esc_html_e( 'AI Summary', 'webchangedetector' ); ?></strong>
					<?php if ( $finished_at ) : ?>
						<span class="wcd-ai-summary-text"><?php echo esc_html( $ai_summary ? $ai_summary : __( 'AI summary skipped', 'webchangedetector' ) ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="wcd-runs-batch-body" hidden>
				<div class="wcd-runs-loading"><div class="ui active inline loader"></div></div>
			</div>
		</div>
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
					<div class="wcd-compared-row"><span class="wcd-ba-label"><?php esc_html_e( 'Before', 'webchangedetector' ); ?></span><span class="screenshot-date"><?php echo esc_html( self::short_date( $before ) ); ?></span></div>
					<div class="wcd-compared-row"><span class="wcd-ba-label"><?php esc_html_e( 'After', 'webchangedetector' ); ?></span><span class="screenshot-date"><?php echo esc_html( self::short_date( $after ) ); ?></span></div>
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
				<a class="ui mini button" href="<?php echo esc_url( $public ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'webchangedetector' ); ?></a><?php endif; ?></td>
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
			'new'            => array( 'red', __( 'New', 'webchangedetector' ) ),
			'ok'             => array( 'green', __( 'OK', 'webchangedetector' ) ),
			'to_fix'         => array( 'orange', __( 'To Fix', 'webchangedetector' ) ),
			'false_positive' => array( 'purple', __( 'False positive', 'webchangedetector' ) ),
			'failed'         => array( 'grey', __( 'Failed', 'webchangedetector' ) ),
			'none'           => array( 'basic', __( 'No changes', 'webchangedetector' ) ),
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
			<button type="button" class="ui button wcd-runs-page" data-page="<?php echo esc_attr( (string) max( 1, $current - 1 ) ); ?>" <?php disabled( $current <= 1 ); ?>><?php esc_html_e( 'Previous', 'webchangedetector' ); ?></button>
			<span class="wcd-runs-page-info">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages. */
					esc_html__( 'Page %1$d of %2$d', 'webchangedetector' ),
					(int) $current,
					(int) $last
				);
				?>
			</span>
			<button type="button" class="ui button wcd-runs-page" data-page="<?php echo esc_attr( (string) min( $last, $current + 1 ) ); ?>" <?php disabled( $current >= $last ); ?>><?php esc_html_e( 'Next', 'webchangedetector' ); ?></button>
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
		return self::message_box( 'info', __( 'No change detections yet. Run an On-Demand Check or monitoring, or try different filters.', 'webchangedetector' ) );
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
	 * Human-readable label for a batch source value.
	 *
	 * @param string $source Source key (manual, monitoring, auto_update).
	 * @return string Translated label, or empty string when unknown.
	 */
	protected static function source_label( string $source ): string {
		$map = array(
			'manual'      => __( 'On-Demand Checks', 'webchangedetector' ),
			'monitoring'  => __( 'Monitoring', 'webchangedetector' ),
			'auto_update' => __( 'Auto-Update Checks', 'webchangedetector' ),
		);

		return $map[ $source ] ?? '';
	}

	/**
	 * Display name for a batch, rewriting the legacy "Manual Checks" label.
	 *
	 * @param mixed $name Raw batch name.
	 * @return string Display name.
	 */
	protected static function display_batch_name( $name ): string {
		$name = (string) $name;

		return 'Manual Checks' === trim( $name ) ? __( 'On-Demand Checks', 'webchangedetector' ) : $name;
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
			return __( 'All time', 'webchangedetector' );
		}
		if ( '' === $from || '' === $to ) {
			return __( 'Custom range', 'webchangedetector' );
		}

		if ( gmdate( 'Y-m-d' ) === $to ) {
			$diff_days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS );
			foreach ( array( 7, 30, 90 ) as $preset ) {
				if ( abs( $diff_days - $preset ) <= 1 ) {
					/* translators: %d: number of days. */
					return sprintf( __( 'Last %d days', 'webchangedetector' ), $preset );
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
		return sprintf( __( '%s ago', 'webchangedetector' ), human_time_diff( $ts, time() ) );
	}

	/**
	 * Localized short date/time label for a datetime string.
	 *
	 * @param string $datetime Datetime string parseable by strtotime().
	 * @return string Formatted date, or empty string when unparseable.
	 */
	protected static function short_date( string $datetime ): string {
		$ts = strtotime( $datetime );

		return $ts ? date_i18n( 'd/m/Y H:i', $ts ) : '';
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
