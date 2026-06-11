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

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the settings-page and safe-update AJAX endpoints.
 */
class WCD_MainWP_Ajax {

	const NONCE = 'wcd_mainwp_ajax';

	/**
	 * Register every AJAX action handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		$actions = array(
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
			'runs_render',
			'runs_comparisons',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_wcd_mainwp_' . $action, array( self::class, $action ) );
		}
	}

	/* ──────────────────────────── Guards/helpers ───────────────────────── */

	/**
	 * Reject the request unless the AJAX nonce is valid and the user may manage options.
	 *
	 * The nonce is verified inline in each handler (via check_ajax_referer) so static analysis
	 * can see it in the handler scope; this helper centralises the capability check and the
	 * shared 403 response.
	 *
	 * @param bool $nonce_valid Result of the in-handler check_ajax_referer() call.
	 * @return void
	 */
	protected static function verify( bool $nonce_valid ): void {
		if ( ! $nonce_valid || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webchangedetector' ) ), 403 );
		}
	}

	/**
	 * Return the global WCD API token.
	 *
	 * @return string
	 */
	protected static function token(): string {
		return WCD_MainWP_Site_Settings::get_global();
	}

	/**
	 * Resolve the requested site id from the POST payload after verifying the nonce.
	 *
	 * @return int
	 */
	protected static function site_id(): int {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			return 0;
		}

		return isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
	}

	/**
	 * Unwrap a { data: ... } envelope from an API payload.
	 *
	 * @param mixed $data The API payload, possibly wrapped in a data envelope.
	 * @return mixed
	 */
	protected static function unwrap( $data ) {
		if ( is_array( $data ) && isset( $data['data'] ) ) {
			return $data['data'];
		}

		return $data;
	}

	/* ─────────────────────────── Settings actions ──────────────────────── */

	/**
	 * Enable or disable a managed site and auto-sync its URLs on enable.
	 *
	 * @return void
	 */
	public static function toggle_site(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id = self::site_id();
		$enabled = ! empty( $_POST['enabled'] ) && 'false' !== $_POST['enabled'];

		if ( ! $enabled ) {
			WCD_MainWP_Site_Map::disable_site( $site_id );
			wp_send_json_success( array( 'enabled' => false ) );
		}

		$result = WCD_MainWP_Site_Map::enable_site( $site_id, self::token() );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		// Auto-sync the site's URLs so the group is populated immediately (best effort; do not
		// fail the enable if there is nothing to sync yet).
		$sync = WCD_MainWP_Url_Sync::sync_site( $site_id, self::token() );

		wp_send_json_success(
			array(
				'enabled'      => true,
				'synced'       => $sync['ok'],
				'sync_message' => $sync['ok'] ? '' : $sync['error'],
			)
		);
	}

	/**
	 * Sync a single site's URLs into its WCD group.
	 *
	 * @return void
	 */
	public static function sync_urls(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$result = WCD_MainWP_Url_Sync::sync_site( self::site_id(), self::token() );

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'URL sync started.', 'webchangedetector' ),
				'count'   => $result['count'],
			)
		);
	}

	/**
	 * Return a site's group URLs with desktop/mobile selection state and active counts.
	 *
	 * @return void
	 */
	public static function get_site_urls(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id  = self::site_id();
		$group_id = WCD_MainWP_Site_Map::get_manual_group( $site_id );
		if ( '' === $group_id ) {
			wp_send_json_error( array( 'message' => __( 'Site is not enabled.', 'webchangedetector' ) ) );
		}

		$response = WCD_MainWP_API::get_group_urls( $group_id, self::token(), array( 'per_page' => 1000 ) );
		if ( ! $response['ok'] ) {
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		$urls   = WCD_MainWP_Update_Flow::extract_urls( $response['data'] );
		$active = 0;
		$clean  = array();
		foreach ( $urls as $url ) {
			$desktop = ! empty( $url['desktop'] );
			$mobile  = ! empty( $url['mobile'] );
			if ( $desktop || $mobile ) {
				++$active;
			}
			$clean[] = array(
				'id'      => $url['id'] ?? '',
				'url'     => $url['url'] ?? '',
				'title'   => $url['html_title'] ?? '',
				'desktop' => $desktop,
				'mobile'  => $mobile,
			);
		}

		wp_send_json_success(
			array(
				'urls'   => $clean,
				'active' => $active,
				'total'  => count( $clean ),
			)
		);
	}

	/**
	 * Update the desktop/mobile selection for a single URL in a site's group.
	 *
	 * @return void
	 */
	public static function update_url(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$site_id  = self::site_id();
		$url_id   = isset( $_POST['url_id'] ) ? sanitize_text_field( wp_unslash( $_POST['url_id'] ) ) : '';
		$desktop  = ! empty( $_POST['desktop'] ) && 'false' !== $_POST['desktop'];
		$mobile   = ! empty( $_POST['mobile'] ) && 'false' !== $_POST['mobile'];
		$group_id = WCD_MainWP_Site_Map::get_manual_group( $site_id );

		if ( '' === $group_id || '' === $url_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing group or URL.', 'webchangedetector' ) ) );
		}

		$response = WCD_MainWP_API::update_url_in_group(
			$group_id,
			$url_id,
			array(
				'desktop' => $desktop,
				'mobile'  => $mobile,
			),
			self::token()
		);
		if ( ! $response['ok'] ) {
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		wp_send_json_success(
			array(
				'desktop' => $desktop,
				'mobile'  => $mobile,
			)
		);
	}

	/**
	 * Progressive stats for the hero banner: total Pages (URLs) and Checks (selected viewports)
	 * across the scope. Loaded after the dashboard renders so WCD API calls never block it.
	 *
	 * @return void
	 */
	public static function banner_stats(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		$scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'bulk';
		if ( 'site' === $scope ) {
			$site_id = self::site_id();
			$ids     = ( $site_id && WCD_MainWP_Site_Map::is_enabled( $site_id ) ) ? array( $site_id ) : array();
		} else {
			$ids = self::scope_site_ids();
		}

		$token  = self::token();
		$pages  = 0;
		$checks = 0;
		foreach ( $ids as $sid ) {
			$group_id = WCD_MainWP_Site_Map::get_manual_group( (int) $sid );
			if ( '' === $group_id ) {
				continue;
			}
			// The API aggregates the SELECTED (active) counts group-wide in `meta`, independent of
			// pagination: `selected_urls_count` = URLs with desktop or mobile enabled (= Pages),
			// `selected_checks_count` = total selected viewports (= Checks). So we only need the meta,
			// not the URL list: per_page=1 keeps the payload tiny instead of pulling every URL.
			$response = WCD_MainWP_API::get_group_urls( $group_id, $token, array( 'per_page' => 1 ) );
			if ( ! $response['ok'] || ! is_array( $response['data'] ) ) {
				continue;
			}
			$meta    = isset( $response['data']['meta'] ) && is_array( $response['data']['meta'] ) ? $response['data']['meta'] : array();
			$pages  += isset( $meta['selected_urls_count'] ) ? (int) $meta['selected_urls_count'] : 0;
			$checks += isset( $meta['selected_checks_count'] ) ? (int) $meta['selected_checks_count'] : 0;
		}

		wp_send_json_success(
			array(
				'sites'  => count( $ids ),
				'pages'  => $pages,
				'checks' => $checks,
			)
		);
	}

	/* ───────────────────────── Orchestration actions ───────────────────── */

	/**
	 * Build the preflight summary (sites, pages, checks, pending updates, credits) for a run.
	 *
	 * @return void
	 */
	public static function preflight(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$scope = self::scope_site_ids();
		$token = self::token();

		$sites         = array();
		$checks        = 0;
		$pages         = 0;
		$total_updates = 0;
		$managed       = WCD_MainWP_Site_Map::managed_sites();
		foreach ( $scope as $site_id ) {
			$group_id = WCD_MainWP_Site_Map::get_manual_group( $site_id );
			if ( '' === $group_id ) {
				continue;
			}

			$response = WCD_MainWP_API::get_group_urls( $group_id, $token, array( 'per_page' => 1000 ) );
			$urls     = $response['ok'] ? WCD_MainWP_Update_Flow::extract_urls( $response['data'] ) : array();

			$site_checks = 0;
			$site_pages  = 0;
			$clean_urls  = array();
			foreach ( $urls as $url ) {
				$desktop = ! empty( $url['desktop'] );
				$mobile  = ! empty( $url['mobile'] );
				if ( ! $desktop && ! $mobile ) {
					continue;
				}
				++$site_pages;
				$site_checks += ( $desktop ? 1 : 0 ) + ( $mobile ? 1 : 0 );
				$clean_urls[] = array(
					'title'   => $url['html_title'] ?? '',
					'url'     => $url['url'] ?? '',
					'desktop' => $desktop,
					'mobile'  => $mobile,
				);
			}

			$items          = WCD_MainWP_Update_Flow::update_items_for_site( $site_id );
			$sites[]        = array(
				'site_id' => $site_id,
				'name'    => $managed[ $site_id ]['name'] ?? '',
				'host'    => $managed[ $site_id ]['domain'] ?? '',
				'checks'  => $site_checks,
				'pages'   => $site_pages,
				'urls'    => $clean_urls,
				'updates' => array(
					'total' => count( $items ),
					'items' => $items,
				),
			);
			$checks        += $site_checks;
			$pages         += $site_pages;
			$total_updates += count( $items );
		}

		$account      = WCD_MainWP_Site_Settings::get_account();
		$checks_left  = isset( $account['checks_left'] ) ? (int) $account['checks_left'] : null;
		$checks_limit = isset( $account['checks_limit'] ) ? (int) $account['checks_limit'] : null;
		$checks_done  = isset( $account['checks_done'] ) ? (int) $account['checks_done'] : null;
		$enough       = null === $checks_left ? true : ( $checks_left >= $checks );

		wp_send_json_success(
			array(
				'sites'         => $sites,
				'checks'        => $checks,
				'pages'         => $pages,
				'screenshots'   => $checks * 2,
				'total_updates' => $total_updates,
				'enough'        => $enough,
				// checks_left/enough kept top-level for backward compatibility.
				'checks_left'   => $checks_left,
				'credits'       => array(
					'plan_name'    => $account['plan_name'] ?? '',
					'checks_left'  => $checks_left,
					'checks_limit' => $checks_limit,
					'checks_done'  => $checks_done,
					'enough'       => $enough,
				),
			)
		);
	}

	/**
	 * Trigger the pre-update screenshots for the requested site.
	 *
	 * @return void
	 */
	public static function take_pre(): void {
		self::take_screenshot( 'pre' );
	}

	/**
	 * Trigger the post-update screenshots for the requested site.
	 *
	 * @return void
	 */
	public static function take_post(): void {
		self::take_screenshot( 'post' );
	}

	/**
	 * Take screenshots of the requested site's group for the given screenshot type.
	 *
	 * @param string $sc_type The screenshot type ('pre' or 'post').
	 * @return void
	 */
	protected static function take_screenshot( string $sc_type ): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$group_id = WCD_MainWP_Site_Map::get_manual_group( self::site_id() );
		if ( '' === $group_id ) {
			wp_send_json_error( array( 'message' => __( 'Site is not enabled.', 'webchangedetector' ) ) );
		}

		$response = WCD_MainWP_API::take_screenshot( array( $group_id ), $sc_type, 'manual', self::token() );
		if ( ! $response['ok'] ) {
			$message = 402 === $response['status'] ? __( 'Not enough check credits.', 'webchangedetector' ) : $response['error'];
			wp_send_json_error(
				array(
					'message' => $message,
					'status'  => $response['status'],
				)
			);
		}

		$data  = self::unwrap( $response['data'] );
		$batch = is_array( $data ) && ! empty( $data['batch'] ) ? $data['batch'] : '';

		wp_send_json_success( array( 'batch' => $batch ) );
	}

	/**
	 * Trigger the WordPress update on the requested site.
	 *
	 * @return void
	 */
	public static function run_update(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$result = WCD_MainWP_Update_Flow::trigger_site_update( self::site_id() );

		if ( ! $result['ok'] ) {
			wp_send_json_error(
				array(
					'message' => $result['error'],
					'offline' => $result['offline'],
				)
			);
		}

		wp_send_json_success(
			array(
				'updated' => $result['updated'],
				'message' => $result['error'],
			)
		);
	}

	/**
	 * Poll one or more batches and aggregate their queue status counts.
	 *
	 * @return void
	 */
	public static function poll(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		// The unified run polls all of a phase's batches at once: accept batches[] and aggregate, with
		// single `batch` kept for backward compatibility.
		$batches = array();
		if ( isset( $_POST['batches'] ) && is_array( $_POST['batches'] ) ) {
			$batches = array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['batches'] ) ) );
		} elseif ( isset( $_POST['batch'] ) && '' !== $_POST['batch'] ) {
			$batches = array( sanitize_text_field( wp_unslash( $_POST['batch'] ) ) );
		}
		$batches = array_values( array_unique( $batches ) );
		if ( empty( $batches ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing batch id.', 'webchangedetector' ) ) );
		}

		$bucket = static function ( $counts ): array {
			$counts = is_array( $counts ) ? $counts : array();

			return array(
				'queue'      => (int) ( $counts['open'] ?? 0 ),
				'processing' => (int) ( $counts['processing'] ?? 0 ),
				'done'       => (int) ( $counts['done'] ?? 0 ),
				'failed'     => (int) ( $counts['failed'] ?? 0 ),
			);
		};

		$aggregate = array(
			'queue'      => 0,
			'processing' => 0,
			'done'       => 0,
			'failed'     => 0,
		);
		$per_batch = array();
		foreach ( $batches as $batch ) {
			// The queues endpoint pre-aggregates per-batch status counts in `meta`, so we only need the
			// meta, not the items: per_page=1 keeps the payload tiny.
			$response = WCD_MainWP_API::get_queues( $batch, '', self::token(), 1 );
			if ( ! $response['ok'] ) {
				wp_send_json_error( array( 'message' => $response['error'] ) );
			}

			$meta = ( is_array( $response['data'] ) && isset( $response['data']['meta'] ) && is_array( $response['data']['meta'] ) ) ? $response['data']['meta'] : array();
			if ( isset( $meta['status_counts_by_batch'][ $batch ] ) && is_array( $meta['status_counts_by_batch'][ $batch ] ) ) {
				$by_batch = $meta['status_counts_by_batch'][ $batch ];
			} elseif ( 1 === count( $batches ) && isset( $meta['status_counts'] ) && is_array( $meta['status_counts'] ) ) {
				// Single-batch back-compat only: the global status_counts is for this one batch. Never
				// reuse it across multiple batches (it would multiply the aggregate).
				$by_batch = $meta['status_counts'];
			} else {
				$by_batch = array();
			}

			$b                   = $bucket( $by_batch );
			$per_batch[ $batch ] = $b;
			foreach ( $aggregate as $key => $val ) {
				$aggregate[ $key ] = $val + $b[ $key ];
			}
		}

		$remaining = $aggregate['queue'] + $aggregate['processing'];
		$finished  = $aggregate['done'] + $aggregate['failed'];

		wp_send_json_success(
			array_merge(
				$aggregate,
				array(
					'remaining' => $remaining,
					// Per-batch breakdown lets the unified run card show each site's done/total counter.
					'by_batch'  => $per_batch,
					// Only "complete" once the queue is empty AND something finished, so we never stop on a
					// batch whose queue has not been populated yet.
					'complete'  => 0 === $remaining && $finished > 0,
				)
			)
		);
	}

	/**
	 * Return the shaped comparison results for a batch.
	 *
	 * @return void
	 */
	public static function results(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$batch = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
		if ( '' === $batch ) {
			wp_send_json_error( array( 'message' => __( 'Missing batch id.', 'webchangedetector' ) ) );
		}

		$response = WCD_MainWP_API::get_comparisons(
			array(
				'batches'  => $batch,
				'per_page' => 100,
			),
			self::token()
		);
		if ( ! $response['ok'] ) {
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		$comparisons = WCD_MainWP_Update_Flow::extract_urls( $response['data'] );
		wp_send_json_success( array( 'comparisons' => array_map( array( self::class, 'shape_comparison' ), $comparisons ) ) );
	}

	/**
	 * Update a comparison's review status (ok, to_fix or false_positive).
	 *
	 * @return void
	 */
	public static function mark_comparison(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );
		$id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$valid  = array( 'ok', 'to_fix', 'false_positive' );

		if ( '' === $id || ! in_array( $status, $valid, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid comparison or status.', 'webchangedetector' ) ) );
		}

		$response = WCD_MainWP_API::update_comparison( $id, $status, self::token() );
		if ( ! $response['ok'] ) {
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		wp_send_json_success( array( 'status' => $status ) );
	}

	/* ────────────────────── Change Detections overview ─────────────────── */

	/**
	 * Render the runs list (batch or flat view) for the given filters. Returns rendered HTML
	 * fragments ({ html, pagination }) which the JS swaps in.
	 */
	public static function runs_render(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		$view  = ( isset( $_POST['view'] ) && 'flat' === $_POST['view'] ) ? 'flat' : 'batch';
		$input = array(
			'page'            => isset( $_POST['page'] ) ? (int) $_POST['page'] : 1,
			'from'            => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '',
			'to'              => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '',
			'source'          => isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '',
			'status'          => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
			'difference_only' => ! empty( $_POST['difference_only'] ) && 'false' !== $_POST['difference_only'],
			'site_ids'        => ( isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ) ? array_map( 'intval', wp_unslash( $_POST['site_ids'] ) ) : array(),
		);

		$filters = WCD_MainWP_Runs_View::build_api_filters( $input );
		$result  = 'flat' === $view
			? WCD_MainWP_Runs_View::render_flat_list( $filters )
			: WCD_MainWP_Runs_View::render_batch_list( $filters );

		wp_send_json_success( $result );
	}

	/**
	 * Render the comparison table for a single batch (the accordion drill-in).
	 */
	public static function runs_comparisons(): void {
		self::verify( check_ajax_referer( self::NONCE, 'nonce', false ) );

		$batch = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
		if ( '' === $batch ) {
			wp_send_json_error( array( 'message' => __( 'Missing batch id.', 'webchangedetector' ) ) );
		}

		$response = WCD_MainWP_API::get_comparisons(
			array(
				'batches'        => $batch,
				'per_page'       => 100,
				'orderBy'        => 'difference_percent',
				'orderDirection' => 'desc',
			),
			self::token()
		);
		if ( ! $response['ok'] ) {
			wp_send_json_error( array( 'message' => $response['error'] ) );
		}

		$comparisons = WCD_MainWP_Update_Flow::extract_urls( $response['data'] );
		wp_send_json_success( array( 'html' => WCD_MainWP_Runs_View::render_comparisons_table( $comparisons, false ) ) );
	}

	/* ────────────────────────────── Internals ──────────────────────────── */

	/**
	 * Resolve the in-scope, enabled site ids from the request: an explicit site_ids[] list, a
	 * single site_id, or (for a bulk run) all enabled sites.
	 *
	 * @return int[]
	 */
	protected static function scope_site_ids(): array {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			return array();
		}

		$ids = array();
		if ( ! empty( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ) {
			$ids = array_map( 'intval', wp_unslash( $_POST['site_ids'] ) );
		} elseif ( ! empty( $_POST['site_id'] ) ) {
			$ids = array( (int) $_POST['site_id'] );
		} else {
			$ids = array_map( 'intval', array_keys( WCD_MainWP_Site_Map::all() ) );
		}

		return array_values( array_filter( $ids, array( WCD_MainWP_Site_Map::class, 'is_enabled' ) ) );
	}

	/**
	 * Reduce a comparison resource to the fields the results view needs.
	 *
	 * @param mixed $c The comparison resource (array or object).
	 * @return array
	 */
	protected static function shape_comparison( $c ): array {
		$c       = (array) $c;
		$percent = isset( $c['difference_percent'] ) ? (float) $c['difference_percent'] : 0.0;
		$status  = $c['status'] ?? 'new';

		return array(
			'id'         => $c['id'] ?? '',
			'url'        => $c['url'] ?? '',
			'device'     => $c['device'] ?? '',
			'percent'    => $percent,
			'status'     => $status,
			'public'     => $c['public_link'] ?? '',
			'before'     => $c['screenshot_1_link'] ?? ( $c['screenshot_1'] ?? '' ),
			'after'      => $c['screenshot_2_link'] ?? ( $c['screenshot_2'] ?? '' ),
			'ai_summary' => self::ai_summary( $c ),
		);
	}

	/**
	 * Best-effort AI summary text (only present when the account has the feature). Never exposes
	 * model names or crop URLs (the API already strips those server-side).
	 *
	 * @param array $c The comparison resource.
	 * @return string
	 */
	protected static function ai_summary( array $c ): string {
		if ( ! empty( $c['ai_verification_result'] ) && is_array( $c['ai_verification_result'] ) ) {
			$r = $c['ai_verification_result'];
			if ( ! empty( $r['summary'] ) && is_string( $r['summary'] ) ) {
				return sanitize_text_field( $r['summary'] );
			}
			if ( ! empty( $r['reason'] ) && is_string( $r['reason'] ) ) {
				return sanitize_text_field( $r['reason'] );
			}
		}

		return '';
	}
}
