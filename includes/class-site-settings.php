<?php

class WCD_MainWP_Site_Settings
{
    private const OPTION_KEY = 'wcd_api_key';

    public static function init(): void
    {
        add_filter('mainwp_getsubpages_sites', [self::class, 'registerSiteTab']);
        add_action('admin_post_wcd_save_settings', [self::class, 'handleSaveSettings']);
        add_action('admin_post_wcd_take_screenshot', [self::class, 'handleTakeScreenshot']);
    }

    public static function registerSiteTab(array $subPages): array
    {
        $subPages[] = [
            'title'       => 'Webchange Detector',
            'slug'        => 'WcdVisualRegressionTesting',
            'sitetab'     => true,
            'menu_hidden' => true,
            'callback'    => [self::class, 'renderSiteTab'],
        ];

        return $subPages;
    }

    public static function renderSiteTab(): void
    {
        include WCD_MAINWP_PLUGIN_PATH . 'templates/site-tab.php';
    }

    public static function handleTakeScreenshot(): void
    {
        check_admin_referer('wcd_take_screenshot');

        $siteId  = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        $groupId = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';
        $scType  = isset($_POST['sc_type']) && $_POST['sc_type'] === 'post' ? 'post' : 'pre';
        $apiKey  = self::get($siteId);

        $returnUrl = add_query_arg(
            ['page' => 'ManageSitesWcdVisualRegressionTesting', 'id' => $siteId],
            admin_url('admin.php')
        );

        if (empty($apiKey) || empty($groupId)) {
            wp_safe_redirect(add_query_arg('wcd_error', '1', $returnUrl));
            exit;
        }

        $result = WCD_MainWP_API::takeScreenshot([$groupId], $scType, 'manual', $apiKey);

        if (empty($result['batch'])) {
            wp_safe_redirect(add_query_arg('wcd_error', '1', $returnUrl));
            exit;
        }

        wp_safe_redirect(add_query_arg('wcd_success', $scType, $returnUrl));
        exit;
    }

    public static function handleSaveSettings(): void
    {
        check_admin_referer('wcd_save_settings');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'webchangedetector'));
        }

        $apiKey = isset($_POST[self::OPTION_KEY]) ? sanitize_text_field($_POST[self::OPTION_KEY]) : '';
        update_option(self::OPTION_KEY, $apiKey);

        wp_safe_redirect(add_query_arg(
            ['page' => 'Extensions-Mainwp-Addon', 'wcd_settings_saved' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    public static function renderSettingsForm(): void
    {
        $apiKey  = self::getGlobal();
        $account = !empty($apiKey) ? WCD_MainWP_API::getAccount($apiKey) : null;
        ?>
        <?php if (isset($_GET['wcd_settings_saved'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
            <div class="ui positive message">
                <p><?php esc_html_e('Settings saved.', 'webchangedetector'); ?></p>
            </div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wcd_save_settings'); ?>
            <input type="hidden" name="action" value="wcd_save_settings" />
            <div class="ui form">
                <div class="field">
                    <label><?php esc_html_e('WebChange Detector API Key', 'webchangedetector'); ?></label>
                    <input type="text"
                           name="<?php echo esc_attr(self::OPTION_KEY); ?>"
                           value="<?php echo esc_attr($apiKey); ?>"
                           placeholder="<?php esc_attr_e('Enter your WCD API key', 'webchangedetector'); ?>" />
                </div>
                <button type="submit" class="ui primary button">
                    <?php esc_html_e('Save Settings', 'webchangedetector'); ?>
                </button>
            </div>
        </form>

        <?php if (!empty($account['data'])) :
            $data  = $account['data'];
            $done  = (int) $data['checks_done'];
            $left  = (int) $data['checks_left'];
            $limit = (int) $data['checks_limit'];
            $pct   = $limit > 0 ? round(($done / $limit) * 100) : 0;
        ?>
        <h3 class="ui header" style="margin-top:1.5em;"><?php esc_html_e('Account', 'webchangedetector'); ?></h3>
        <table class="ui very basic celled table">
            <tbody>
                <tr><td><strong><?php esc_html_e('Name', 'webchangedetector'); ?></strong></td><td><?php echo esc_html($data['name_first'] . ' ' . $data['name_last']); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Email', 'webchangedetector'); ?></strong></td><td><?php echo esc_html($data['email']); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Plan', 'webchangedetector'); ?></strong></td><td><?php echo esc_html($data['plan_name'] ?? '—'); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Status', 'webchangedetector'); ?></strong></td><td><?php echo esc_html(ucfirst($data['status'])); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Renewal', 'webchangedetector'); ?></strong></td><td><?php echo esc_html($data['renewal_at'] ?? '—'); ?></td></tr>
            </tbody>
        </table>

        <h3 class="ui header" style="margin-top:1.5em;"><?php esc_html_e('Check Credits', 'webchangedetector'); ?></h3>
        <div class="ui indicating progress" data-percent="<?php echo esc_attr($pct); ?>">
            <div class="bar" style="width:<?php echo esc_attr($pct); ?>%;"></div>
        </div>
        <p><?php echo esc_html($done); ?> used &nbsp;·&nbsp; <?php echo esc_html($left); ?> remaining &nbsp;·&nbsp; <?php echo esc_html($limit); ?> total</p>
        <?php elseif (!empty($apiKey)) : ?>
            <div class="ui warning message">
                <p><?php esc_html_e('Could not retrieve account data. Please check your API key.', 'webchangedetector'); ?></p>
            </div>
        <?php endif; ?>
        <?php
    }

    public static function get(int $siteId): string
    {
        return self::getGlobal();
    }

    public static function getGlobal(): string
    {
        return (string) get_option(self::OPTION_KEY, '');
    }
}
