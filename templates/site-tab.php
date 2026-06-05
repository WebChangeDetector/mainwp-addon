<?php
/**
 * Per-site tab (slug WcdVisualRegressionTesting): status + safe-update entry for one site.
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

$site_id   = isset($_GET['id']) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$token     = WCD_MainWP_Site_Settings::getGlobal();
$enabled   = $site_id && WCD_MainWP_Site_Map::isEnabled($site_id);
$settings  = esc_url(admin_url('admin.php?page=' . WCD_MainWP_Bootstrap::settingsPageSlug()));
?>

<?php do_action('mainwp_pageheader_sites', 'WcdVisualRegressionTesting'); ?>

<div class="ui segment">
    <h3 class="ui header"><?php esc_html_e('WebChange Detector', 'webchangedetector'); ?></h3>

    <?php if ('' === $token) : ?>
        <div class="ui negative message">
            <p>
                <?php
                printf(
                    /* translators: %s: settings page URL. */
                    wp_kses_post(__('No API token configured. Add one in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector')),
                    $settings // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above.
                );
                ?>
            </p>
        </div>
    <?php elseif (! $enabled) : ?>
        <div class="ui info message">
            <p>
                <?php
                printf(
                    /* translators: %s: settings page URL. */
                    wp_kses_post(__('This site is not enabled for visual checks yet. Enable it in the <a href="%s">WebChange Detector Settings</a>.', 'webchangedetector')),
                    $settings // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above.
                );
                ?>
            </p>
        </div>
    <?php else : ?>
        <p class="wcd-muted"><?php esc_html_e('Run a safe update for this site: capture before/after screenshots around the update and review the change detections.', 'webchangedetector'); ?></p>
        <button type="button" class="ui green button wcd-safe-update" data-scope="site" data-site-id="<?php echo esc_attr((string) $site_id); ?>">
            <i class="eye icon"></i> <?php esc_html_e('On-Demand Safe Update', 'webchangedetector'); ?>
        </button>
        <a href="<?php echo $settings; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above. ?>" class="ui button"><?php esc_html_e('Configure URLs', 'webchangedetector'); ?></a>
    <?php endif; ?>
</div>

<?php do_action('mainwp_pagefooter_sites', 'WcdVisualRegressionTesting'); ?>
