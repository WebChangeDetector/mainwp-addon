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
    $groups = WCD_MainWP_API::listGroups($apiKey)['data'] ?? [];
}
?>

<?php do_action('mainwp_pageheader_sites', 'WcdVisualRegressionTesting'); ?>

<?php if (isset($_GET['wcd_success'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
    <div class="ui positive message">
        <p><?php echo esc_html(sanitize_text_field($_GET['wcd_success'])) === 'post' // phpcs:ignore WordPress.Security.NonceVerification
            ? 'Post-update screenshots queued. Comparisons will run automatically.'
            : 'Pre-update screenshots queued successfully.'; ?></p>
    </div>
<?php elseif (isset($_GET['wcd_error'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
    <div class="ui negative message">
        <p>Could not queue screenshots. Please check the API key and try again.</p>
    </div>
<?php endif; ?>

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

    <div class="ui segment">
        <h3 class="ui header">Groups</h3>
        <?php if (empty($groups)) : ?>
            <p class="ui grey text">No groups found.</p>
        <?php else : ?>
            <table class="ui very basic celled table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>URLs</th>
                        <th>Enabled</th>
                        <th>Screenshots</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group) : ?>
                        <tr>
                            <td><?php echo esc_html($group['name']); ?></td>
                            <td><?php echo $group['monitoring'] ? 'Monitoring' : 'Manual'; ?></td>
                            <td><?php echo esc_html($group['selected_urls_count'] . ' / ' . $group['urls_count']); ?></td>
                            <td><?php echo $group['enabled'] ? '<i class="green check icon"></i>' : '<i class="grey times icon"></i>'; ?></td>
                            <td>
                                <?php foreach (['pre' => 'Pre', 'post' => 'Post'] as $type => $label) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('wcd_take_screenshot'); ?>
                                        <input type="hidden" name="action"   value="wcd_take_screenshot" />
                                        <input type="hidden" name="site_id"  value="<?php echo esc_attr($siteId); ?>" />
                                        <input type="hidden" name="group_id" value="<?php echo esc_attr($group['id']); ?>" />
                                        <input type="hidden" name="sc_type"  value="<?php echo esc_attr($type); ?>" />
                                        <button type="submit" class="ui mini <?php echo $type === 'pre' ? 'blue' : 'green'; ?> button">
                                            <i class="camera icon"></i> <?php echo esc_html($label); ?>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php do_action('mainwp_pagefooter_sites', 'WcdVisualRegressionTesting'); ?>
