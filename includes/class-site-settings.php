<?php
/**
 * Settings storage, token verification and the extension settings page.
 *
 * Owns the single dashboard-level API token (`wcd_api_token`) and the cached account details.
 * The settings page renders the design's layout: account/credits card + per-site cards with
 * URL configuration. All site/URL mutations happen via AJAX (see WCD_MainWP_Ajax).
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

class WCD_MainWP_Site_Settings
{
    const OPTION_KEY     = 'wcd_api_token';
    const ACCOUNT_CACHE  = 'wcd_account_details';
    const ERROR_CACHE    = 'wcd_token_error';
    const ACCOUNT_TTL    = 300; // 5 minutes.

    public static function init(): void
    {
        add_filter('mainwp_getsubpages_sites', [self::class, 'registerSiteTab']);
        add_action('admin_post_wcd_save_settings', [self::class, 'handleSaveSettings']);
    }

    public static function registerSiteTab(array $subPages): array
    {
        $subPages[] = [
            'title'       => 'WebChange Detector',
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

    /* ─────────────────────────── Token storage ─────────────────────────── */

    public static function getGlobal(): string
    {
        return (string) WCD_MainWP_Options::get(self::OPTION_KEY, '');
    }

    /**
     * Verify a token by calling /account. On success, caches the account; returns the result.
     *
     * @return array{ok: bool, account: array, error: string}
     */
    public static function verifyToken(string $token): array
    {
        $response = WCD_MainWP_API::getAccount($token);

        if (! $response['ok'] || empty($response['data'])) {
            $error = $response['error'] ?: __('Could not retrieve account data.', 'webchangedetector');
            if (! empty($response['status'])) {
                $error .= ' (HTTP ' . (int) $response['status'] . ')';
            }

            return ['ok' => false, 'account' => [], 'error' => $error];
        }

        $account = self::unwrap($response['data']);
        WCD_MainWP_Options::setTransient(self::ACCOUNT_CACHE, $account, self::ACCOUNT_TTL);

        return ['ok' => true, 'account' => $account, 'error' => ''];
    }

    /**
     * Return the cached account, fetching + verifying once if needed.
     */
    public static function getAccount(bool $force = false): array
    {
        if (! $force) {
            $cached = WCD_MainWP_Options::getTransient(self::ACCOUNT_CACHE);
            if (is_array($cached) && ! empty($cached)) {
                return $cached;
            }
        }

        $token = self::getGlobal();
        if ('' === $token) {
            return [];
        }

        $result = self::verifyToken($token);

        return $result['ok'] ? $result['account'] : [];
    }

    /**
     * Unwrap a { data: {...} } envelope.
     */
    protected static function unwrap($data): array
    {
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return is_array($data) ? $data : [];
    }

    /* ──────────────────────────── Save handler ─────────────────────────── */

    public static function handleSaveSettings(): void
    {
        check_admin_referer('wcd_save_settings');

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'webchangedetector'));
        }

        $token = isset($_POST[self::OPTION_KEY]) ? sanitize_text_field(wp_unslash($_POST[self::OPTION_KEY])) : '';
        WCD_MainWP_Options::set(self::OPTION_KEY, $token);
        WCD_MainWP_Options::deleteTransient(self::ACCOUNT_CACHE);

        $flag = '1';
        if ('' !== $token) {
            $verify = self::verifyToken($token);
            $flag   = $verify['ok'] ? '1' : '0';
            if (! $verify['ok']) {
                // Surface the exact reason (HTTP status / SSL / API message) on the settings page.
                WCD_MainWP_Options::setTransient(self::ERROR_CACHE, $verify['error'], 120);
            } else {
                WCD_MainWP_Options::deleteTransient(self::ERROR_CACHE);
            }
        }

        wp_safe_redirect(add_query_arg(
            ['page' => WCD_MainWP_Bootstrap::settingsPageSlug(), 'wcd_token_verified' => $flag],
            admin_url('admin.php')
        ));
        exit;
    }

    /* ──────────────────────────── Settings page ────────────────────────── */

    public static function renderSettingsForm(): void
    {
        $token   = self::getGlobal();
        $account = '' !== $token ? self::getAccount() : [];
        $sites   = WCD_MainWP_Site_Map::managedSites();
        $map     = WCD_MainWP_Site_Map::all();

        include WCD_MAINWP_PLUGIN_PATH . 'templates/settings-page.php';
    }
}
