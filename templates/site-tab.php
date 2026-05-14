<?php defined('ABSPATH') || exit; ?>

<?php
$siteId = isset($_GET['id']) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$apiKey  = WCD_MainWP_Site_Settings::get($siteId);
$account = null;
$error   = null;

if (empty($apiKey)) {
    $error = 'No WebChange Detector API key set for this site. Please add one in the site settings.';
} else {
    $account = WCD_MainWP_API::getAccount($apiKey);
    if (empty($account['data'])) {
        $error = 'Could not retrieve account data. Please check the API key in the site settings.';
    }
}
?>

<?php do_action('mainwp_pageheader_sites', 'WcdVisualRegressionTesting'); ?>

<?php if ($error) : ?>
    <div class="ui negative message">
        <p><?php echo esc_html($error); ?></p>
    </div>
<?php else :
    $data = $account['data'];
?>
    <div class="ui segment">
        <h3 class="ui header">Account</h3>
        <table class="ui very basic celled table">
            <tbody>
                <tr><td><strong>Name</strong></td><td><?php echo esc_html($data['name_first'] . ' ' . $data['name_last']); ?></td></tr>
                <tr><td><strong>Email</strong></td><td><?php echo esc_html($data['email']); ?></td></tr>
                <tr><td><strong>Plan</strong></td><td><?php echo esc_html($data['plan_name'] ?? '—'); ?></td></tr>
                <tr><td><strong>Status</strong></td><td><?php echo esc_html(ucfirst($data['status'])); ?></td></tr>
                <tr><td><strong>Renewal</strong></td><td><?php echo esc_html($data['renewal_at'] ?? '—'); ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="ui segment">
        <h3 class="ui header">Check Credits</h3>
        <?php
        $done  = (int) $data['checks_done'];
        $left  = (int) $data['checks_left'];
        $limit = (int) $data['checks_limit'];
        $pct   = $limit > 0 ? round(($done / $limit) * 100) : 0;
        ?>
        <div class="ui indicating progress" data-percent="<?php echo esc_attr($pct); ?>">
            <div class="bar" style="width: <?php echo esc_attr($pct); ?>%;"></div>
        </div>
        <p><?php echo esc_html($done); ?> used &nbsp;·&nbsp; <?php echo esc_html($left); ?> remaining &nbsp;·&nbsp; <?php echo esc_html($limit); ?> total</p>
    </div>
<?php endif; ?>

<?php do_action('mainwp_pagefooter_sites', 'WcdVisualRegressionTesting'); ?>
