<?php
/**
 * Extension settings page: API token, account/credits card, per-site URL configuration.
 *
 * Provided by WCD_MainWP_Site_Settings::renderSettingsForm():
 *
 * @var string $token   Stored API token.
 * @var array  $account Account details (plan_name, checks_left, checks_limit, ...).
 * @var array  $sites   Managed MainWP sites [ id => [ id, url, name, domain ] ].
 * @var array  $map     Stored site map [ id => [ enabled, ... ] ].
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

$verified_flag = isset($_GET['wcd_token_verified']) ? sanitize_text_field(wp_unslash($_GET['wcd_token_verified'])) : null; // phpcs:ignore WordPress.Security.NonceVerification
$active_sites  = 0;
foreach ($map as $entry) {
    if (! empty($entry['enabled'])) {
        $active_sites++;
    }
}

$checks_left  = isset($account['checks_left']) ? (int) $account['checks_left'] : null;
$checks_limit = isset($account['checks_limit']) ? (int) $account['checks_limit'] : null;
$checks_done  = isset($account['checks_done']) ? (int) $account['checks_done'] : null;
$used_pct     = ($checks_limit && $checks_limit > 0) ? min(100, round(($checks_done / $checks_limit) * 100)) : 0;
?>

<?php if ('1' === $verified_flag) : ?>
    <div class="ui positive message"><p><?php esc_html_e('Settings saved and API token verified.', 'webchangedetector'); ?></p></div>
<?php elseif ('0' === $verified_flag) :
    $token_error = WCD_MainWP_Options::getTransient(WCD_MainWP_Site_Settings::ERROR_CACHE);
    ?>
    <div class="ui negative message">
        <p><?php esc_html_e('Settings saved, but the API token could not be verified.', 'webchangedetector'); ?></p>
        <?php if (is_string($token_error) && '' !== $token_error) : ?>
            <p><strong><?php esc_html_e('Reason:', 'webchangedetector'); ?></strong> <?php echo esc_html($token_error); ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcd-token-form">
    <?php wp_nonce_field('wcd_save_settings'); ?>
    <input type="hidden" name="action" value="wcd_save_settings" />
    <div class="ui form">
        <div class="field">
            <label for="wcd_api_token"><?php esc_html_e('WebChange Detector API Token', 'webchangedetector'); ?></label>
            <input type="text" id="wcd_api_token" name="<?php echo esc_attr(WCD_MainWP_Site_Settings::OPTION_KEY); ?>"
                   value="<?php echo esc_attr($token); ?>"
                   placeholder="<?php esc_attr_e('Enter your WCD API token', 'webchangedetector'); ?>" />
        </div>
        <div class="field">
            <div class="ui checkbox">
                <input type="checkbox" id="wcd_auto_enable_sites" name="<?php echo esc_attr(WCD_MainWP_Site_Settings::AUTO_ENABLE_KEY); ?>" value="1" <?php checked(WCD_MainWP_Site_Settings::autoEnableNewSites()); ?> />
                <label for="wcd_auto_enable_sites"><?php esc_html_e('Auto-enable new sites', 'webchangedetector'); ?></label>
            </div>
            <p class="wcd-muted"><?php esc_html_e('When you add a site in MainWP, enable it for visual checks and sync its URLs automatically. Each enabled site provisions WebChange Detector resources that count against your plan.', 'webchangedetector'); ?></p>
        </div>
        <button type="submit" class="ui primary button"><?php esc_html_e('Save & verify', 'webchangedetector'); ?></button>
    </div>
</form>

<?php if ('' === $token) : ?>
    <div class="ui info message wcd-mt">
        <p><?php esc_html_e('Add your API token to connect your verified WebChange Detector account.', 'webchangedetector'); ?></p>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php if (empty($account)) : ?>
    <div class="ui warning message wcd-mt">
        <p><?php esc_html_e('Could not retrieve account data. Please check your API token.', 'webchangedetector'); ?></p>
    </div>
    <?php return; ?>
<?php endif; ?>

<div class="ui segment wcd-mt">
    <div class="ui stackable grid">
        <div class="four wide column">
            <div class="wcd-account-label"><?php esc_html_e('Plan', 'webchangedetector'); ?></div>
            <div class="wcd-account-value"><?php echo esc_html($account['plan_name'] ?? '-'); ?></div>
        </div>
        <div class="eight wide middle aligned column">
            <div class="wcd-progress"><div class="wcd-progress-bar wcd-w-<?php echo esc_attr((string) ((int) (round($used_pct / 5) * 5))); ?>"></div></div>
            <div class="wcd-account-label">
                <?php
                if (null !== $checks_left && null !== $checks_limit) {
                    printf(
                        /* translators: 1: remaining checks, 2: total checks. */
                        esc_html__('%1$s of %2$s checks available', 'webchangedetector'),
                        '<b>' . esc_html((string) $checks_left) . '</b>',
                        esc_html((string) $checks_limit)
                    );
                }
                ?>
            </div>
        </div>
        <div class="four wide right aligned column">
            <div class="wcd-account-value"><?php echo esc_html((string) $active_sites); ?></div>
            <div class="wcd-account-label"><?php esc_html_e('Active sites', 'webchangedetector'); ?></div>
        </div>
    </div>
</div>

<h3 class="ui header wcd-mt"><?php esc_html_e('Sites & pages', 'webchangedetector'); ?></h3>
<p class="wcd-muted"><?php esc_html_e('Enable a site and pick which URLs are checked. Checks run when you update from the Updates page.', 'webchangedetector'); ?></p>

<?php if (empty($sites)) : ?>
    <div class="ui message"><p><?php esc_html_e('No managed sites found in MainWP.', 'webchangedetector'); ?></p></div>
<?php else : ?>
    <div class="ui segments">
        <?php foreach ($sites as $site) :
            $sid     = (int) $site['id'];
            $enabled = ! empty($map[$sid]['enabled']);
            ?>
            <div class="ui segment wcd-site" data-site-id="<?php echo esc_attr((string) $sid); ?>">
                <div class="wcd-site-head">
                    <div class="ui toggle checkbox">
                        <input type="checkbox" class="wcd-site-toggle" <?php checked($enabled); ?> />
                        <label></label>
                    </div>
                    <div class="wcd-site-meta">
                        <div class="wcd-site-name"><?php echo esc_html($site['name']); ?></div>
                        <div class="wcd-site-domain"><?php echo esc_html($site['domain']); ?></div>
                    </div>
                    <div class="wcd-site-urlcount" data-role="urlcount"><?php echo $enabled ? '' : esc_html__('Disabled', 'webchangedetector'); ?></div>
                    <div class="wcd-site-actions">
                        <button type="button" class="ui mini button wcd-sync-urls" <?php disabled(! $enabled); ?>>
                            <?php esc_html_e('Sync WP URLs', 'webchangedetector'); ?>
                        </button>
                        <button type="button" class="ui mini button wcd-configure-urls" <?php disabled(! $enabled); ?>>
                            <?php esc_html_e('Configure URLs', 'webchangedetector'); ?>
                        </button>
                    </div>
                </div>
                <div class="wcd-url-config" data-role="urlconfig" hidden></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
