<?php
/**
 * Interaction Flows (read-only + On-Demand toggle) for the per-site tab.
 *
 * Renders the HTML fragments the flow AJAX actions return: the flow list (accordion), the
 * read-only step list, the runs list and the per-step run detail. Flows are recorded with the
 * WebChange Detector browser extension and managed in the WebChange Detector account; the add-on
 * only lists them, shows their runs and flips the On-Demand flag (enabled_manual), which is what
 * safe-update pre/post checks honor. Monitoring (enabled_monitoring) is display-only here: the
 * add-on hides monitoring settings everywhere.
 *
 * Naming: "Interaction Flows" (WCD_MainWP_Interaction_Flows) is this customer-facing feature;
 * WCD_MainWP_Update_Flow is the unrelated safe-update orchestration. Keep the names apart.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Interaction Flows fragments for the per-site tab.
 */
class WCD_MainWP_Interaction_Flows {

	// Public comparison page (same base the API's ComparisonResource public_link uses). The
	// flow-run checkpoint payload carries the comparison `token`, not a ready-made public_link,
	// so the add-on builds the link here. Single constant so a base-URL change is a one-line fix
	// (same exposure class as WCD_MainWP_Bootstrap::UPGRADE_URL).
	const PUBLIC_COMPARISON_URL = 'https://www.webchangedetector.com/show-change-detection?token=';

	/**
	 * Whether the account's plan includes Interaction Flows (gates the toggle, not the read view).
	 * Reads the 5-minute account cache, so this costs no extra API call.
	 *
	 * @return bool True when flow toggles may be used.
	 */
	public static function can_toggle(): bool {
		$account = WCD_MainWP_Site_Settings::get_account();

		return ! empty( $account['plan_features']['interaction_flows'] );
	}

	/**
	 * Render the flow list as a MainWP accordion table (one tbody per flow with a lazy drill-in
	 * body row, same pattern as the Checks tab).
	 *
	 * @param array $flows      Flow records from the API (lean list shape).
	 * @param bool  $can_toggle Whether the plan allows flipping the On-Demand toggle.
	 * @return string List markup.
	 */
	public static function render_flows_list( array $flows, bool $can_toggle ): string {
		if ( empty( $flows ) ) {
			return '<div class="ui message"><p>'
				. esc_html__( 'No flows for this site yet. Record flows with the WebChange Detector browser extension and manage them in your WebChange Detector account.', 'webchangedetector-for-mainwp' )
				. '</p></div>';
		}

		ob_start();
		if ( ! $can_toggle ) {
			self::upsell_message();
		}
		?>
		<table class="ui tablet stackable table wcd-flows-table">
			<thead>
				<tr>
					<th scope="col" class="collapsing no-sort"></th>
					<th scope="col"><?php esc_html_e( 'Flow', 'webchangedetector-for-mainwp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Steps', 'webchangedetector-for-mainwp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last run', 'webchangedetector-for-mainwp' ); ?></th>
					<th scope="col" class="collapsing"><?php esc_html_e( 'On-Demand Checks', 'webchangedetector-for-mainwp' ); ?></th>
					<th scope="col" class="collapsing"><?php esc_html_e( 'Monitoring', 'webchangedetector-for-mainwp' ); ?></th>
				</tr>
			</thead>
			<?php
			foreach ( $flows as $flow ) {
				self::flow_rows( (array) $flow, $can_toggle );
			}
			?>
		</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * Echo one flow as an accordion tbody: a clickable title row plus a hidden body row whose
	 * steps and runs lazy-load on first open.
	 *
	 * @param array $flow       Flow record from the API.
	 * @param bool  $can_toggle Whether the On-Demand toggle is usable.
	 * @return void
	 */
	protected static function flow_rows( array $flow, bool $can_toggle ): void {
		$flow_id  = (string) ( $flow['id'] ?? '' );
		$last_run = ( isset( $flow['last_run'] ) && is_array( $flow['last_run'] ) ) ? $flow['last_run'] : array();
		?>
		<tbody class="wcd-flow" data-flow-id="<?php echo esc_attr( $flow_id ); ?>">
			<tr class="title wcd-flow-head">
				<td class="accordion-trigger collapsing"><i class="caret right icon"></i></td>
				<td class="wcd-col-url">
					<span class="wcd-url-link"><?php echo esc_html( (string) ( $flow['name'] ?? '' ) ); ?></span>
					<?php if ( ! empty( $flow['url'] ) ) : ?>
						<div class="wcd-url-path">
							<?php
							/* translators: %s: the flow's start URL. */
							printf( esc_html__( 'starts at %s', 'webchangedetector-for-mainwp' ), esc_html( (string) $flow['url'] ) );
							?>
						</div>
					<?php endif; ?>
				</td>
				<td><?php self::count_badges( $flow ); ?></td>
				<td>
					<?php if ( ! empty( $last_run['status'] ) ) : ?>
						<?php self::run_status_badge( (string) $last_run['status'] ); ?>
						<?php $wcd_mainwp_last_time = (string) ( $last_run['finished_at'] ? $last_run['finished_at'] : ( $last_run['created_at'] ?? '' ) ); ?>
						<?php if ( '' !== $wcd_mainwp_last_time ) : ?>
							<span class="wcd-muted" data-tooltip="<?php echo esc_attr( WCD_MainWP_Runs_View::short_date( $wcd_mainwp_last_time ) ); ?>" data-inverted="" data-position="left center">
								<?php echo esc_html( WCD_MainWP_Runs_View::time_ago( $wcd_mainwp_last_time ) ); ?>
							</span>
						<?php endif; ?>
					<?php else : ?>
						<span class="wcd-muted"><?php esc_html_e( 'Never', 'webchangedetector-for-mainwp' ); ?></span>
					<?php endif; ?>
				</td>
				<td class="collapsing wcd-flow-toggle-cell">
					<div class="ui toggle checkbox<?php echo $can_toggle ? '' : ' disabled'; ?>">
						<input type="checkbox" class="wcd-flow-toggle" data-flow-id="<?php echo esc_attr( $flow_id ); ?>" <?php checked( ! empty( $flow['enabled_manual'] ) ); ?> <?php disabled( ! $can_toggle ); ?> />
						<label></label>
					</div>
				</td>
				<td class="collapsing">
					<?php if ( ! empty( $flow['enabled_monitoring'] ) ) : ?>
						<span class="ui green basic label"><?php esc_html_e( 'On', 'webchangedetector-for-mainwp' ); ?></span>
					<?php else : ?>
						<span class="ui basic label"><?php esc_html_e( 'Off', 'webchangedetector-for-mainwp' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr class="wcd-flow-body" hidden>
				<td colspan="6">
					<div class="wcd-flow-drillin" data-role="flow-steps"></div>
					<div class="wcd-flow-drillin" data-role="flow-runs"></div>
				</td>
			</tr>
		</tbody>
		<?php
	}

	/**
	 * Echo the steps/checkpoints/asserts count badges for a flow list row.
	 *
	 * @param array $flow Flow record from the API.
	 * @return void
	 */
	protected static function count_badges( array $flow ): void {
		$steps       = (int) ( $flow['steps_count'] ?? 0 );
		$checkpoints = (int) ( $flow['checkpoints_count'] ?? 0 );
		$asserts     = (int) ( $flow['asserts_count'] ?? 0 );
		?>
		<div class="wcd-status-badges">
			<span class="ui basic label"><?php echo esc_html( (string) $steps ); ?> <?php echo esc_html( _n( 'step', 'steps', $steps, 'webchangedetector-for-mainwp' ) ); ?></span>
			<span class="ui blue basic label"><?php echo esc_html( (string) $checkpoints ); ?> <?php echo esc_html( _n( 'checkpoint', 'checkpoints', $checkpoints, 'webchangedetector-for-mainwp' ) ); ?></span>
			<?php if ( $asserts > 0 ) : ?>
				<span class="ui basic label"><?php echo esc_html( (string) $asserts ); ?> <?php echo esc_html( _n( 'assert', 'asserts', $asserts, 'webchangedetector-for-mainwp' ) ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the read-only step list of one flow (from the flow detail payload; sensitive values
	 * arrive redacted: value = null + value_set = true).
	 *
	 * @param array $flow Flow record from the API (detail shape with steps).
	 * @return string Steps markup.
	 */
	public static function render_steps( array $flow ): string {
		$steps = ( isset( $flow['steps'] ) && is_array( $flow['steps'] ) ) ? $flow['steps'] : array();

		ob_start();
		?>
		<h4 class="ui header wcd-flow-subhead"><?php esc_html_e( 'Steps', 'webchangedetector-for-mainwp' ); ?></h4>
		<?php if ( empty( $steps ) ) : ?>
			<p class="wcd-muted"><?php esc_html_e( 'This flow has no steps.', 'webchangedetector-for-mainwp' ); ?></p>
			<?php
		else :
			foreach ( array_values( $steps ) as $index => $step ) {
				self::step_row( (array) $step, $index + 1 );
			}
		endif;
		return ob_get_clean();
	}

	/**
	 * Echo one read-only step row (list + run detail share this base; the run detail appends the
	 * per-step results next to it).
	 *
	 * @param array $step  Step record.
	 * @param int   $index 1-based step position.
	 * @return void
	 */
	protected static function step_row( array $step, int $index ): void {
		?>
		<div class="wcd-flow-step">
			<span class="wcd-flow-step-num"><?php echo esc_html( (string) $index ); ?></span>
			<span class="ui basic label wcd-flow-step-type"><?php echo esc_html( self::step_type_label( (string) ( $step['type'] ?? '' ) ) ); ?></span>
			<span class="wcd-flow-step-main">
				<?php echo esc_html( (string) ( $step['label'] ?? '' ) ); ?>
				<?php if ( ! empty( $step['selector'] ) ) : ?>
					<span class="wcd-url-path"><?php echo esc_html( (string) $step['selector'] ); ?></span>
				<?php endif; ?>
			</span>
			<?php self::step_value( $step ); ?>
			<?php if ( ! empty( $step['assertion'] ) && is_array( $step['assertion'] ) ) : ?>
				<span class="wcd-muted"><?php echo esc_html( self::assertion_summary( $step['assertion'] ) ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Echo a step's value: plaintext for non-sensitive steps, a neutral "value stored" hint for
	 * sensitive ones (never render a redacted sensitive step as if it were empty).
	 *
	 * @param array $step Step record.
	 * @return void
	 */
	protected static function step_value( array $step ): void {
		if ( ! empty( $step['sensitive'] ) ) {
			if ( ! empty( $step['value_set'] ) ) {
				echo '<span class="ui basic label"><i class="lock icon"></i>' . esc_html__( 'Value stored', 'webchangedetector-for-mainwp' ) . '</span>';
			}
			return;
		}

		if ( isset( $step['value'] ) && '' !== $step['value'] && null !== $step['value'] ) {
			echo '<span class="wcd-flow-step-value">' . esc_html( (string) $step['value'] ) . '</span>';
		}
	}

	/**
	 * Render a flow's runs list with prev/next pagination.
	 *
	 * @param array $runs Run records from the API (lean list shape).
	 * @param array $meta Pagination meta (current_page, last_page, total, per_page).
	 * @return string Runs markup.
	 */
	public static function render_runs( array $runs, array $meta ): string {
		ob_start();
		?>
		<h4 class="ui header wcd-flow-subhead"><?php esc_html_e( 'Recent runs', 'webchangedetector-for-mainwp' ); ?></h4>
		<?php if ( empty( $runs ) ) : ?>
			<p class="wcd-muted"><?php esc_html_e( 'No runs yet. Flows run with the checks of this site.', 'webchangedetector-for-mainwp' ); ?></p>
			<?php
		else :
			?>
			<table class="ui table mainwp-manage-updates-item-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Type', 'webchangedetector-for-mainwp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'webchangedetector-for-mainwp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Finished', 'webchangedetector-for-mainwp' ); ?></th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $runs as $run ) {
						self::run_row( (array) $run );
					}
					?>
				</tbody>
			</table>
			<?php
			self::runs_pagination( $meta );
		endif;
		?>
		<div class="wcd-flow-rundetail-host" data-role="run-detail" hidden></div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Echo one run list row.
	 *
	 * @param array $run Run record from the API.
	 * @return void
	 */
	protected static function run_row( array $run ): void {
		$finished = (string) ( $run['finished_at'] ?? '' );
		$created  = (string) ( $run['created_at'] ?? '' );
		$time     = '' !== $finished ? $finished : $created;
		?>
		<tr>
			<td>
				<span class="<?php echo esc_attr( 'mobile' === ( $run['device'] ?? '' ) ? 'dashicons dashicons-smartphone' : 'dashicons dashicons-desktop' ); ?>"></span>
				<?php echo esc_html( self::run_type_label( (string) ( $run['sc_type'] ?? '' ) ) ); ?>
			</td>
			<td><?php self::run_status_badge( (string) ( $run['status'] ?? '' ) ); ?></td>
			<td>
				<?php if ( '' !== $time ) : ?>
					<span data-tooltip="<?php echo esc_attr( WCD_MainWP_Runs_View::short_date( $time ) ); ?>" data-inverted="" data-position="left center">
						<?php echo esc_html( WCD_MainWP_Runs_View::time_ago( $time ) ); ?>
					</span>
				<?php endif; ?>
			</td>
			<td class="collapsing">
				<button type="button" class="ui mini button wcd-flow-run-view" data-run-id="<?php echo esc_attr( (string) ( $run['id'] ?? '' ) ); ?>">
					<?php esc_html_e( 'Details', 'webchangedetector-for-mainwp' ); ?>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the per-step results of one flow run. The wrapper carries data-run-status so the JS
	 * knows whether to keep polling (only while the run is still processing).
	 *
	 * @param array $run Run record from the API (detail shape with steps).
	 * @return string Run detail markup.
	 */
	public static function render_run_detail( array $run ): string {
		$status = (string) ( $run['status'] ?? '' );
		$steps  = ( isset( $run['steps'] ) && is_array( $run['steps'] ) ) ? $run['steps'] : array();
		$time   = (string) ( ! empty( $run['finished_at'] ) ? $run['finished_at'] : ( $run['created_at'] ?? '' ) );

		ob_start();
		?>
		<div class="wcd-flow-rundetail" data-run-status="<?php echo esc_attr( $status ); ?>">
			<div class="wcd-flow-rundetail-head">
				<?php self::run_status_badge( $status ); ?>
				<span><?php echo esc_html( self::run_type_label( (string) ( $run['sc_type'] ?? '' ) ) ); ?></span>
				<?php if ( '' !== $time ) : ?>
					<span class="wcd-muted"><?php echo esc_html( WCD_MainWP_Runs_View::short_date( $time ) ); ?></span>
				<?php endif; ?>
				<?php if ( 'processing' === $status ) : ?>
					<span class="wcd-muted"><i class="sync loading icon"></i><?php esc_html_e( 'Updating automatically…', 'webchangedetector-for-mainwp' ); ?></span>
				<?php endif; ?>
			</div>
			<?php
			if ( empty( $steps ) ) {
				echo '<p class="wcd-muted">' . esc_html__( 'No step results for this run yet.', 'webchangedetector-for-mainwp' ) . '</p>';
			} else {
				foreach ( array_values( $steps ) as $index => $step ) {
					self::run_step_row( (array) $step, $index + 1 );
				}
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Echo one run detail step: the read-only step base plus its result (status, error, duration,
	 * assertion outcome, checkpoint block).
	 *
	 * @param array $step  Step record with results.
	 * @param int   $index 1-based step position.
	 * @return void
	 */
	protected static function run_step_row( array $step, int $index ): void {
		?>
		<div class="wcd-flow-runstep">
			<?php self::step_row( $step, $index ); ?>
			<div class="wcd-flow-runstep-result">
				<?php self::step_status_badge( $step['status'] ?? null ); ?>
				<?php if ( isset( $step['duration_ms'] ) && null !== $step['duration_ms'] ) : ?>
					<span class="wcd-muted"><?php echo esc_html( self::format_duration( (int) $step['duration_ms'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $step['error'] ) ) : ?>
					<span class="wcd-error"><?php echo esc_html( (string) $step['error'] ); ?></span>
				<?php endif; ?>
				<?php self::assertion_result( $step ); ?>
			</div>
			<?php
			if ( ! empty( $step['checkpoint'] ) && is_array( $step['checkpoint'] ) ) {
				self::checkpoint_block( $step['checkpoint'] );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Echo the assertion outcome of a run step (expected vs actual), when the step has one.
	 *
	 * @param array $step Step record with results.
	 * @return void
	 */
	protected static function assertion_result( array $step ): void {
		$assertion = ( isset( $step['assertion'] ) && is_array( $step['assertion'] ) ) ? $step['assertion'] : array();
		if ( empty( $assertion ) || ! isset( $assertion['passed'] ) || null === $assertion['passed'] ) {
			return;
		}

		if ( $assertion['passed'] ) {
			echo '<span class="ui green basic label"><i class="check icon"></i>' . esc_html__( 'Assertion passed', 'webchangedetector-for-mainwp' ) . '</span>';
			return;
		}
		?>
		<span class="ui red basic label"><i class="times icon"></i><?php esc_html_e( 'Assertion failed', 'webchangedetector-for-mainwp' ); ?></span>
		<span class="wcd-muted">
			<?php
			printf(
				/* translators: 1: expected value, 2: actual value. */
				esc_html__( 'expected %1$s, got %2$s', 'webchangedetector-for-mainwp' ),
				esc_html( (string) ( $assertion['expected'] ?? '' ) ),
				esc_html( (string) ( $assertion['actual'] ?? '' ) )
			);
			?>
		</span>
		<?php
	}

	/**
	 * Echo a checkpoint's result block: screenshot link, comparison (difference percent + public
	 * View link) or the baseline marker, plus a capture error when the queue failed.
	 *
	 * @param array $checkpoint Checkpoint record of a run step.
	 * @return void
	 */
	protected static function checkpoint_block( array $checkpoint ): void {
		$screenshot = ( isset( $checkpoint['screenshot'] ) && is_array( $checkpoint['screenshot'] ) ) ? $checkpoint['screenshot'] : array();
		$comparison = ( isset( $checkpoint['comparison'] ) && is_array( $checkpoint['comparison'] ) ) ? $checkpoint['comparison'] : array();
		?>
		<div class="wcd-flow-checkpoint">
			<?php if ( ! empty( $comparison ) ) : ?>
				<?php
				$wcd_mainwp_percent = isset( $comparison['difference_percent'] ) ? (float) $comparison['difference_percent'] : 0.0;
				$wcd_mainwp_sev     = $wcd_mainwp_percent <= 0 ? '' : ( $wcd_mainwp_percent < 5 ? 'wcd-vc-sev-low' : 'wcd-vc-sev-high' );
				?>
				<span class="wcd-visual-percentage <?php echo esc_attr( $wcd_mainwp_sev ); ?>"><?php echo esc_html( WCD_MainWP_Runs_View::format_percent( $wcd_mainwp_percent ) ); ?>%</span>
				<?php if ( ! empty( $comparison['token'] ) ) : ?>
					<a class="ui mini button" href="<?php echo esc_url( self::PUBLIC_COMPARISON_URL . $comparison['token'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'webchangedetector-for-mainwp' ); ?></a>
				<?php endif; ?>
			<?php elseif ( ! empty( $checkpoint['baseline'] ) ) : ?>
				<span class="ui basic label"><i class="flag outline icon"></i><?php esc_html_e( 'Baseline captured, nothing to compare yet', 'webchangedetector-for-mainwp' ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $screenshot['link'] ) ) : ?>
				<a class="ui mini basic button" href="<?php echo esc_url( (string) $screenshot['link'] ); ?>" target="_blank" rel="noopener"><i class="camera icon"></i><?php esc_html_e( 'Screenshot', 'webchangedetector-for-mainwp' ); ?></a>
			<?php endif; ?>
			<?php if ( 'failed' === ( $checkpoint['queue_status'] ?? '' ) && ! empty( $checkpoint['error_msg'] ) ) : ?>
				<span class="wcd-error"><?php echo esc_html( (string) $checkpoint['error_msg'] ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ───────────────────────────── Helpers ─────────────────────────────── */

	/**
	 * Echo the upsell notice shown when the plan lacks Interaction Flows (toggles are disabled).
	 *
	 * @return void
	 */
	protected static function upsell_message(): void {
		?>
		<div class="ui info message">
			<p>
				<?php esc_html_e( 'Interaction Flows are not included in your current plan, so they are shown read-only here.', 'webchangedetector-for-mainwp' ); ?>
				<a href="<?php echo esc_url( WCD_MainWP_Bootstrap::UPGRADE_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade plan', 'webchangedetector-for-mainwp' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Prev/next pagination for the runs list (mirrors the Checks tab control, own trigger class).
	 *
	 * @param array $meta Pagination meta (current_page, last_page).
	 * @return void
	 */
	protected static function runs_pagination( array $meta ): void {
		$current = (int) ( $meta['current_page'] ?? 1 );
		$last    = (int) ( $meta['last_page'] ?? 1 );
		if ( $last <= 1 ) {
			return;
		}
		?>
		<div class="wcd-runs-pagination">
			<button type="button" class="ui mini basic button wcd-flow-runs-page" data-page="<?php echo esc_attr( (string) max( 1, $current - 1 ) ); ?>" <?php disabled( $current <= 1 ); ?>><?php esc_html_e( 'Previous', 'webchangedetector-for-mainwp' ); ?></button>
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
			<button type="button" class="ui mini basic button wcd-flow-runs-page" data-page="<?php echo esc_attr( (string) min( $last, $current + 1 ) ); ?>" <?php disabled( $current >= $last ); ?>><?php esc_html_e( 'Next', 'webchangedetector-for-mainwp' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Translated label for a step type.
	 *
	 * @param string $type Step type key.
	 * @return string Display label.
	 */
	protected static function step_type_label( string $type ): string {
		$labels = array(
			'navigate'      => __( 'Navigate', 'webchangedetector-for-mainwp' ),
			'click'         => __( 'Click', 'webchangedetector-for-mainwp' ),
			'fill'          => __( 'Fill', 'webchangedetector-for-mainwp' ),
			'wait'          => __( 'Wait', 'webchangedetector-for-mainwp' ),
			'wait_for_load' => __( 'Wait for load', 'webchangedetector-for-mainwp' ),
			'checkpoint'    => __( 'Checkpoint', 'webchangedetector-for-mainwp' ),
			'assert'        => __( 'Assert', 'webchangedetector-for-mainwp' ),
		);

		return $labels[ $type ] ?? ucfirst( $type );
	}

	/**
	 * Translated label for a run's sc_type (data-model vocabulary in, UI terminology out).
	 *
	 * @param string $sc_type Run type key ('pre', 'post' or 'auto').
	 * @return string Display label.
	 */
	protected static function run_type_label( string $sc_type ): string {
		$labels = array(
			'pre'  => __( 'Pre-update check', 'webchangedetector-for-mainwp' ),
			'post' => __( 'Post-update check', 'webchangedetector-for-mainwp' ),
			'auto' => __( 'Monitoring check', 'webchangedetector-for-mainwp' ),
		);

		return $labels[ $sc_type ] ?? ucfirst( $sc_type );
	}

	/**
	 * Echo a run status badge (processing, done, failed) as a native Fomantic label.
	 *
	 * @param string $status Run status key.
	 * @return void
	 */
	protected static function run_status_badge( string $status ): void {
		$meta  = array(
			'processing' => array( 'blue', __( 'Processing', 'webchangedetector-for-mainwp' ) ),
			'done'       => array( 'green', __( 'Done', 'webchangedetector-for-mainwp' ) ),
			'failed'     => array( 'red', __( 'Failed', 'webchangedetector-for-mainwp' ) ),
		);
		$color = $meta[ $status ][0] ?? 'basic';
		$label = $meta[ $status ][1] ?? ucfirst( $status );

		echo '<span class="ui ' . esc_attr( $color ) . ' label">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Echo a step result badge (passed, failed, skipped; pending while null).
	 *
	 * @param mixed $status Step result status, or null while the run is still processing.
	 * @return void
	 */
	protected static function step_status_badge( $status ): void {
		$meta = array(
			'passed'  => array( 'green', __( 'Passed', 'webchangedetector-for-mainwp' ) ),
			'failed'  => array( 'red', __( 'Failed', 'webchangedetector-for-mainwp' ) ),
			'skipped' => array( 'grey', __( 'Skipped', 'webchangedetector-for-mainwp' ) ),
		);

		if ( null === $status || '' === $status ) {
			echo '<span class="ui basic label">' . esc_html__( 'Pending', 'webchangedetector-for-mainwp' ) . '</span>';
			return;
		}

		$color = $meta[ $status ][0] ?? 'basic';
		$label = $meta[ $status ][1] ?? ucfirst( (string) $status );

		echo '<span class="ui ' . esc_attr( $color ) . ' basic label">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Human-readable summary of a step's assertion definition (read-only step list).
	 *
	 * @param array $assertion Assertion definition (kind, operator, expected).
	 * @return string Summary text.
	 */
	protected static function assertion_summary( array $assertion ): string {
		return sprintf(
			/* translators: 1: assertion kind, 2: operator, 3: expected value. */
			__( 'Assert %1$s %2$s "%3$s"', 'webchangedetector-for-mainwp' ),
			str_replace( '_', ' ', (string) ( $assertion['kind'] ?? '' ) ),
			(string) ( $assertion['operator'] ?? '' ),
			(string) ( $assertion['expected'] ?? '' )
		);
	}

	/**
	 * Format a step duration for display.
	 *
	 * @param int $ms Duration in milliseconds.
	 * @return string Formatted duration (e.g. "230 ms" or "1.4 s").
	 */
	protected static function format_duration( int $ms ): string {
		if ( $ms >= 1000 ) {
			/* translators: %s: duration in seconds. */
			return sprintf( __( '%s s', 'webchangedetector-for-mainwp' ), (string) round( $ms / 1000, 1 ) );
		}

		/* translators: %d: duration in milliseconds. */
		return sprintf( __( '%d ms', 'webchangedetector-for-mainwp' ), $ms );
	}
}
