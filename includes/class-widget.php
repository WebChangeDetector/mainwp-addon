<?php

class WCD_MainWP_Widget
{
    public static function renderMetabox(): void
    {
        self::renderGlobalWidget();
    }

    private static function renderGlobalWidget(): void
    {
        $apiKey  = WCD_MainWP_Site_Settings::getGlobal();
        $account = null;
        $error   = null;

        if (empty($apiKey)) {
            $error = __('No WebChange Detector API key configured. Please add one in the extension settings.', 'webchangedetector');
        } else {
            $account = WCD_MainWP_API::getAccount($apiKey);
            if (empty($account['data'])) {
                $error = __('Could not retrieve account data. Please check the API key.', 'webchangedetector');
            }
        }

        include WCD_MAINWP_PLUGIN_PATH . 'templates/widget.php';
    }

}
