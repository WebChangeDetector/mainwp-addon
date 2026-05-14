<?php

class WCD_MainWP_Site_Settings
{
    private const META_KEY = 'wcd_api_key';

    public static function init(): void
    {
        add_action('mainwp_manage_sites_edit', [self::class, 'renderField']);
        add_action('mainwp_site_updated', [self::class, 'saveOnUpdate'], 10, 2);
        add_action('mainwp_added_new_site', [self::class, 'saveOnAdd'], 10, 2);
        add_filter('mainwp_getsubpages_sites', [self::class, 'registerSiteTab']);
        add_action('admin_post_wcd_take_screenshot', [self::class, 'handleTakeScreenshot']);
    }

    public static function registerSiteTab(array $subPages): array
    {
        $subPages[] = [
            'title'       => 'Visual Regression Testing',
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

    public static function renderField(): void
    {
        $websiteId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $apiKey    = $websiteId ? self::get($websiteId) : '';
        ?>
        <div class="ui grid field">
            <label class="six wide column middle aligned">
                <?php esc_html_e('WebChange Detector API Key', 'webchangedetector'); ?>
            </label>
            <div class="ten wide column">
                <input type="text"
                       name="<?php echo esc_attr(self::META_KEY); ?>"
                       value="<?php echo esc_attr($apiKey); ?>"
                       placeholder="<?php esc_attr_e('Enter WCD API key for this site', 'webchangedetector'); ?>" />
            </div>
        </div>
        <?php
    }

    public static function saveOnUpdate(object $website, array $post): void
    {
        if (!isset($post[self::META_KEY])) {
            return;
        }

        self::save($website->id, sanitize_text_field($post[self::META_KEY]));
    }

    public static function saveOnAdd(int $siteId, object $website): void
    {
        if (!isset($_POST[self::META_KEY])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        self::save($siteId, sanitize_text_field($_POST[self::META_KEY])); // phpcs:ignore WordPress.Security.NonceVerification
    }

    public static function get(int $siteId): string
    {
        return (string) get_option('wcd_site_' . self::META_KEY . '_' . $siteId, '');
    }

    private static function save(int $siteId, string $value): void
    {
        update_option('wcd_site_' . self::META_KEY . '_' . $siteId, $value);
    }
}
