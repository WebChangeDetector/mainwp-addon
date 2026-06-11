<?php
/**
 * Dashboard widget body: plan + check credits.
 *
 * @var array  $account Unwrapped account details (empty on error).
 * @var string $error   Error message (empty on success).
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="ui grid mainwp-widget-header">
	<div class="twelve wide column">
		<h2 class="ui header handle-drag">
			<?php esc_html_e( 'WebChange Detector', 'webchangedetector' ); ?>
			<div class="sub header"><?php esc_html_e( 'Check Credits', 'webchangedetector' ); ?></div>
		</h2>
	</div>
</div>

<div class="mainwp-scrolly-overflow wcd-widget">
<?php if ( $error ) : ?>
	<p class="wcd-error"><?php echo esc_html( $error ); ?></p>
	<?php
else :
	$done  = (int) ( $account['checks_done'] ?? 0 );
	$left  = (int) ( $account['checks_left'] ?? 0 );
	$limit = (int) ( $account['checks_limit'] ?? 0 );
	$pct   = $limit > 0 ? (int) round( ( $done / $limit ) * 100 ) : 0;
	$step  = (int) ( round( $pct / 5 ) * 5 );
	?>
	<div class="wcd-progress"><div class="wcd-progress-bar wcd-w-<?php echo esc_attr( (string) $step ); ?>"></div></div>
	<table class="wcd-widget-table">
		<tr><td><?php esc_html_e( 'Used', 'webchangedetector' ); ?></td><td><?php echo esc_html( number_format_i18n( $done ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Remaining', 'webchangedetector' ); ?></td><td><?php echo esc_html( number_format_i18n( $left ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Total', 'webchangedetector' ); ?></td><td><?php echo esc_html( number_format_i18n( $limit ) ); ?></td></tr>
	</table>
	<p class="wcd-muted"><?php echo esc_html( (string) $pct ); ?>% <?php esc_html_e( 'used', 'webchangedetector' ); ?></p>
<?php endif; ?>
</div>
