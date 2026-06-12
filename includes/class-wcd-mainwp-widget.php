<?php
/**
 * Dashboard widget showing the connected account's plan, check credits, renewal and active sites.
 *
 * Server-rendered from the cached account (5 min transient) + the local site map: no extra WCD
 * API calls beyond the shared account cache, so the MainWP dashboard never blocks on us.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the MainWP dashboard widget with the connected account's plan and check credits.
 */
class WCD_MainWP_Widget {

	/**
	 * Render the dashboard widget metabox body.
	 *
	 * @return void
	 */
	public static function render_metabox(): void {
		// Self-contained capability gate: the widget only exposes account data to admins, matching
		// the manage_options gate on every settings/AJAX path (MainWP also gates the dashboard).
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$token   = WCD_MainWP_Site_Settings::get_global();
		$account = array();
		$error   = '';

		if ( '' === $token ) {
			$error = __( 'No API token configured. Add one in the WebChange Detector settings.', 'webchangedetector-for-mainwp' );
		} else {
			$account = WCD_MainWP_Site_Settings::get_account();
			if ( empty( $account ) ) {
				$error = __( 'Could not retrieve account data. Please check the API token.', 'webchangedetector-for-mainwp' );
			}
		}

		$active_sites      = count(
			array_filter(
				WCD_MainWP_Site_Map::all(),
				static function ( $entry ) {
					return ! empty( $entry['enabled'] );
				}
			)
		);
		$renewal_days      = self::renewal_days( $account );
		$visual_checks_url = admin_url( 'admin.php?page=ManageSites' . WCD_MainWP_Runs_View::PAGE_SLUG );
		$settings_url      = admin_url( 'admin.php?page=ManageSites' . WCD_MainWP_Site_Settings::SUBPAGE_SLUG );
		$account_url       = admin_url( 'admin.php?page=' . WCD_MainWP_Bootstrap::settings_page_slug() );

		include WCD_MAINWP_PLUGIN_PATH . 'templates/widget.php';
	}

	/**
	 * Days until the plan renews, from the account's renewal_at field.
	 *
	 * @param array $account Unwrapped account details.
	 * @return int|null Days left (>= 0), or null when unknown.
	 */
	protected static function renewal_days( array $account ): ?int {
		if ( empty( $account['renewal_at'] ) ) {
			return null;
		}
		$ts = strtotime( (string) $account['renewal_at'] );
		if ( ! $ts ) {
			return null;
		}

		return max( 0, (int) floor( ( $ts - time() ) / DAY_IN_SECONDS ) );
	}
}
