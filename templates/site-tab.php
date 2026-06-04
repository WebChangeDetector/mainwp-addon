<?php defined('ABSPATH') || exit; ?>

<?php
$siteId = isset($_GET['id']) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$apiKey  = WCD_MainWP_Site_Settings::get($siteId);
$account = null;
$error   = null;

if (empty($apiKey)) {
    $error = 'No WebChange Detector API key configured. Please add one in the <a href="' . esc_url(admin_url('admin.php?page=Extensions-Mainwp-Addon')) . '">WebChange Detector Settings</a>.';
} else {
    $account = WCD_MainWP_API::getAccount($apiKey);
    if (empty($account['data'])) {
        $error = 'Could not retrieve account data. Please check the API key in the <a href="' . esc_url(admin_url('admin.php?page=Extensions-Mainwp-Addon')) . '">WebChange Detector Settings</a>.';
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
        <p><?php echo wp_kses_post($error); ?></p>
    </div>
<?php else : ?>
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
