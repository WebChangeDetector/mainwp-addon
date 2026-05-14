<?php

class WCD_MainWP_Bootstrap
{
    public static function init()
    {
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-api.php';
        require_once WCD_MAINWP_PLUGIN_PATH . 'includes/class-site-settings.php';

        add_action('plugins_loaded', [self::class, 'setup']);
    }

    public static function setup()
    {
        if (defined('MAINWP_VERSION')) {
            add_filter(
                'mainwp_getextensions',
                [self::class, 'register_extension']
            );

            WCD_MainWP_Site_Settings::init();
        }
    }

    public static function register_extension($extensions)
    {
        $extensions[] = [
            'plugin'   => WCD_MAINWP_PLUGIN_PATH . 'webchangedetector-mainwp.php',
            'api'      => 'WebChangeDetector MainWP Extension',
            'mainwp'   => true,
            'callback' => [self::class, 'render_admin_page'],
            'icon'     => WCD_MAINWP_PLUGIN_URL . 'assets/images/logo.png',
        ];

        return $extensions;
    }

    public static function render_admin_page()
    {
        include WCD_MAINWP_PLUGIN_PATH . 'templates/admin-page.php';
    }
}
