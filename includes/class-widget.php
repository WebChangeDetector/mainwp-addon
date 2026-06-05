<?php
/**
 * Dashboard widget showing the connected account's plan + check credits.
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

class WCD_MainWP_Widget
{
    public static function renderMetabox(): void
    {
        $token   = WCD_MainWP_Site_Settings::getGlobal();
        $account = [];
        $error   = '';

        if ('' === $token) {
            $error = __('No API token configured. Add one in the WebChange Detector settings.', 'webchangedetector');
        } else {
            $account = WCD_MainWP_Site_Settings::getAccount();
            if (empty($account)) {
                $error = __('Could not retrieve account data. Please check the API token.', 'webchangedetector');
            }
        }

        include WCD_MAINWP_PLUGIN_PATH . 'templates/widget.php';
    }
}
