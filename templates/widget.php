<?php defined('ABSPATH') || exit; ?>

<div class="ui grid mainwp-widget-header">
    <div class="twelve wide column">
        <h2 class="ui header handle-drag">
            <?php esc_html_e('WebChange Detector', 'webchangedetector'); ?>
            <div class="sub header"><?php esc_html_e('Check Credits', 'webchangedetector'); ?></div>
        </h2>
    </div>
</div>

<div class="mainwp-scrolly-overflow">
<?php if ($error) : ?>
    <p style="color:#c00;"><?php echo esc_html($error); ?></p>
<?php else :
    $data  = $account['data'];
    $done  = (int) $data['checks_done'];
    $left  = (int) $data['checks_left'];
    $limit = (int) $data['checks_limit'];
    $pct   = $limit > 0 ? round(($done / $limit) * 100) : 0;
?>
    <div style="margin-bottom:8px;">
        <div style="background:#e0e0e0;border-radius:4px;overflow:hidden;height:12px;">
            <div style="background:#2271b1;width:<?php echo esc_attr($pct); ?>%;height:100%;border-radius:4px;transition:width .3s;"></div>
        </div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <tr>
            <td style="padding:3px 0;color:#555;"><?php esc_html_e('Used', 'webchangedetector'); ?></td>
            <td style="padding:3px 0;text-align:right;font-weight:600;"><?php echo esc_html(number_format_i18n($done)); ?></td>
        </tr>
        <tr>
            <td style="padding:3px 0;color:#555;"><?php esc_html_e('Remaining', 'webchangedetector'); ?></td>
            <td style="padding:3px 0;text-align:right;font-weight:600;"><?php echo esc_html(number_format_i18n($left)); ?></td>
        </tr>
        <tr>
            <td style="padding:3px 0;color:#555;"><?php esc_html_e('Total', 'webchangedetector'); ?></td>
            <td style="padding:3px 0;text-align:right;font-weight:600;"><?php echo esc_html(number_format_i18n($limit)); ?></td>
        </tr>
    </table>
    <p style="margin:8px 0 0;font-size:12px;color:#888;"><?php echo esc_html($pct); ?>% <?php esc_html_e('used', 'webchangedetector'); ?></p>
<?php endif; ?>
</div>
