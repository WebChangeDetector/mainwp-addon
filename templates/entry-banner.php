<?php
/**
 * WebChange Detector hero banner.
 *
 * Rendered at the top of the MainWP dashboard body (hook `mainwp_before_overview_widgets`) on both
 * the Operations dashboard (bulk: all enabled sites) and an individual child-site overview (single
 * site). It is the single, prominent entry point into the safe-update flow (decision -> preflight ->
 * pre -> update -> post -> results), driven by the shared `.wcd-safe-update` handler.
 *
 * Sites + the pending-update count are rendered server-side (cheap). Pages/Checks load progressively
 * via the `banner_stats` AJAX call so the dashboard never blocks on WCD API calls.
 *
 * @var string   $scope         'bulk' (all enabled sites) or 'site' (one site).
 * @var int      $site_id       Site id when scope is 'site' (0 otherwise).
 * @var int      $sites_count   Number of sites in scope.
 * @var int|null $updates_count Pending MainWP updates in scope (null when unknown).
 * @var bool     $force_enabled Keep the CTA enabled even when $updates_count is 0 (caller knows
 *                              updates exist, e.g. the Updates-page entry point). Optional.
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

$headline = ('site' === $scope)
    ? __('Update this site with a visual safety net', 'webchangedetector')
    : __('Update all sites with a visual safety net', 'webchangedetector');

if (null !== $updates_count && $updates_count > 0) {
    $description = sprintf(
        /* translators: %d: number of pending updates. */
        _n(
            'Capture before/after screenshots, install the %d pending update, then compare so you instantly see what changed.',
            'Capture before/after screenshots, install the %d pending updates, then compare so you instantly see what changed.',
            $updates_count,
            'webchangedetector'
        ),
        $updates_count
    );
} else {
    $description = __('Capture before/after screenshots around your updates, then compare so you instantly see what changed.', 'webchangedetector');
}
?>
<div class="wcd-hero" data-stats-scope="<?php echo esc_attr($scope); ?>" data-site-id="<?php echo esc_attr((string) $site_id); ?>">
    <div class="wcd-hero__icon"><i class="eye icon"></i></div>
    <div class="wcd-hero__body">
        <span class="wcd-hero__badge"><?php esc_html_e('WebChange Detector', 'webchangedetector'); ?></span>
        <div class="wcd-hero__title"><?php echo esc_html($headline); ?></div>
        <div class="wcd-hero__desc"><?php echo esc_html($description); ?></div>
    </div>
    <div class="wcd-hero__stats">
        <div class="wcd-hero__stat">
            <div class="wcd-hero__num"><?php echo esc_html((string) $sites_count); ?></div>
            <div class="wcd-hero__lbl"><?php echo esc_html(_n('Site', 'Sites', $sites_count, 'webchangedetector')); ?></div>
        </div>
        <div class="wcd-hero__stat">
            <div class="wcd-hero__num" data-role="pages">&hellip;</div>
            <div class="wcd-hero__lbl"><?php esc_html_e('Pages', 'webchangedetector'); ?></div>
        </div>
        <div class="wcd-hero__stat">
            <div class="wcd-hero__num" data-role="checks">&hellip;</div>
            <div class="wcd-hero__lbl"><?php esc_html_e('Checks', 'webchangedetector'); ?></div>
        </div>
    </div>
    <?php $no_updates = empty($force_enabled) && null !== $updates_count && 0 === (int) $updates_count; ?>
    <button type="button" class="wcd-hero__cta wcd-safe-update<?php echo $no_updates ? ' is-disabled' : ''; ?>" data-scope="<?php echo esc_attr($scope); ?>" data-site-id="<?php echo esc_attr((string) $site_id); ?>" <?php disabled($no_updates); ?>>
        <i class="<?php echo $no_updates ? 'ban' : 'play'; ?> icon"></i>
        <?php echo esc_html($no_updates ? __('No updates available', 'webchangedetector') : __('Run visual check & update', 'webchangedetector')); ?>
    </button>
</div>
<?php // The unified in-card run renders here, right below the launch band (no popup). ?>
<div class="wcd-run-host" data-scope="<?php echo esc_attr($scope); ?>" data-site-id="<?php echo esc_attr((string) $site_id); ?>"></div>
