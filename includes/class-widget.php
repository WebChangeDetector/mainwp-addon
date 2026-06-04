<?php

class WCD_MainWP_Widget
{
    public static function renderMetabox(): void
    {
        $siteId = isset($_GET['dashboard']) ? (int) $_GET['dashboard'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

        if (empty($siteId)) {
            self::renderGlobalWidget();
            return;
        }

        self::renderSiteWidget($siteId);
    }

    private static function renderGlobalWidget(): void
    {
        ?>
        <div class="ui grid mainwp-widget-header">
            <div class="twelve wide column">
                <h2 class="ui header handle-drag">
                    <?php esc_html_e('WebChange Detector', 'webchangedetector'); ?>
                    <div class="sub header"><?php esc_html_e('Check Credits', 'webchangedetector'); ?></div>
                </h2>
            </div>
        </div>
        <div class="mainwp-scrolly-overflow">
            <p style="color:#888;"><?php esc_html_e('Open a site\'s Overview page to see its WebChange Detector check credits.', 'webchangedetector'); ?></p>
        </div>
        <?php
    }

    private static function renderSiteWidget(int $siteId): void
    {
        $apiKey  = WCD_MainWP_Site_Settings::get($siteId);
        $account = null;
        $error   = null;

        if (empty($apiKey)) {
            $error = __('No WebChange Detector API key set for this site.', 'webchangedetector');
        } else {
            $account = WCD_MainWP_API::getAccount($apiKey);
            if (empty($account['data'])) {
                $error = __('Could not retrieve account data. Please check the API key.', 'webchangedetector');
            }
        }

        include WCD_MAINWP_PLUGIN_PATH . 'templates/widget.php';
    }
}
