<?php
/**
 * Dashboard widget body: plan, check credits, renewal countdown, active sites + quick links.
 *
 * Provided by WCD_MainWP_Widget::render_metabox():
 *
 * @var array    $account           Unwrapped account details (empty on error).
 * @var string   $error             Error message (empty on success).
 * @var int      $active_sites      Number of MainWP sites enabled for visual checks.
 * @var int|null $renewal_days      Days until the plan renews (null when unknown).
 * @var string   $visual_checks_url URL of the Visual Checks page.
 * @var string   $settings_url      URL of the Visual Checks Settings tab (sites & URLs).
 * @var string   $account_url       URL of the extension settings page (API token, account).
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="ui grid mainwp-widget-header">
	<div class="twelve wide column">
		<h2 class="ui header handle-drag">
			<?php esc_html_e( 'WebChange Detector', 'webchangedetector-for-mainwp' ); ?>
			<div class="sub header"><?php esc_html_e( 'Visual checks for your updates', 'webchangedetector-for-mainwp' ); ?></div>
		</h2>
	</div>
</div>

<div class="mainwp-scrolly-overflow wcd-widget">
<?php if ( $error ) : ?>
	<p class="wcd-error"><?php echo esc_html( $error ); ?></p>
	<a href="<?php echo esc_url( $account_url ); ?>" class="ui mini basic button"><?php esc_html_e( 'Open settings', 'webchangedetector-for-mainwp' ); ?></a>
	<?php
else :
	$wcd_mainwp_done  = (int) ( $account['checks_done'] ?? 0 );
	$wcd_mainwp_left  = (int) ( $account['checks_left'] ?? 0 );
	$wcd_mainwp_limit = (int) ( $account['checks_limit'] ?? 0 );
	$wcd_mainwp_pct   = $wcd_mainwp_limit > 0 ? (int) min( 100, round( ( $wcd_mainwp_done / $wcd_mainwp_limit ) * 100 ) ) : 0;
	$wcd_mainwp_step  = (int) ( round( $wcd_mainwp_pct / 5 ) * 5 );
	$wcd_mainwp_plan  = (string) ( $account['plan_name'] ?? '' );
	?>
	<div class="wcd-widget-planrow">
		<?php if ( '' !== $wcd_mainwp_plan ) : ?>
			<span class="ui small text"><strong><?php echo esc_html( $wcd_mainwp_plan ); ?></strong></span>
		<?php endif; ?>
		<?php if ( null !== $renewal_days ) : ?>
			<span class="ui small text wcd-muted">
				<?php
				if ( 0 === (int) $renewal_days ) {
					esc_html_e( 'renews today', 'webchangedetector-for-mainwp' );
				} else {
					printf(
						/* translators: %d: number of days until the plan renews. */
						esc_html( _n( 'renews in %d day', 'renews in %d days', $renewal_days, 'webchangedetector-for-mainwp' ) ),
						(int) $renewal_days
					);
				}
				?>
			</span>
		<?php endif; ?>
	</div>
	<div class="wcd-progress"><div class="wcd-progress-bar wcd-w-<?php echo esc_attr( (string) $wcd_mainwp_step ); ?>"></div></div>
	<p class="wcd-muted wcd-widget-usage">
		<?php
		printf(
			/* translators: 1: used checks, 2: total checks, 3: percentage used. */
			esc_html__( '%1$s of %2$s checks used (%3$s%%)', 'webchangedetector-for-mainwp' ),
			esc_html( number_format_i18n( $wcd_mainwp_done ) ),
			esc_html( number_format_i18n( $wcd_mainwp_limit ) ),
			esc_html( (string) $wcd_mainwp_pct )
		);
		?>
	</p>
	<table class="wcd-widget-table">
		<tr><td><?php esc_html_e( 'Checks remaining', 'webchangedetector-for-mainwp' ); ?></td><td><?php echo esc_html( number_format_i18n( $wcd_mainwp_left ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Active sites', 'webchangedetector-for-mainwp' ); ?></td><td><?php echo esc_html( number_format_i18n( $active_sites ) ); ?></td></tr>
	</table>
	<div class="wcd-widget-actions">
		<a href="<?php echo esc_url( $visual_checks_url ); ?>" class="ui mini basic button"><?php esc_html_e( 'WebChange Detector', 'webchangedetector-for-mainwp' ); ?></a>
		<a href="<?php echo esc_url( $settings_url ); ?>" class="ui mini basic button"><?php esc_html_e( 'Settings', 'webchangedetector-for-mainwp' ); ?></a>
	</div>
<?php endif; ?>
</div>
