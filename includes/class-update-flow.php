<?php
/**
 * Update orchestration helpers.
 *
 * Two responsibilities:
 *  1. Trigger a single site's MainWP update synchronously (sanctioned internal call, guarded).
 *  2. The after-update hook safety-net (post recovery for the card flow + coverage for updates
 *     started outside our card). Suppressed while the card flow drives the update itself.
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

class WCD_MainWP_Update_Flow
{
    const ABILITIES_CLASS = '\\MainWP\\Dashboard\\MainWP_Abilities_Updates';

    /** Request-scoped suppression: true while our own card run owns the update. */
    protected static $suppress = false;

    /** How long a "post already triggered" marker lives, to dedupe multiple after-hooks per run. */
    const POST_DEDUPE_TTL = 180;

    public static function init(): void
    {
        add_action('mainwp_after_wp_update', [self::class, 'onAfterCoreUpdate'], 10, 2);
        add_action('mainwp_after_plugin_theme_translation_update', [self::class, 'onAfterPluginThemeUpdate'], 10, 4);
    }

    /* ─────────────────────────── Update trigger ────────────────────────── */

    /**
     * Whether the sanctioned MainWP update API is available.
     */
    public static function canTriggerUpdates(): bool
    {
        return class_exists(self::ABILITIES_CLASS)
            && method_exists(self::ABILITIES_CLASS, 'execute_update_site_plugins');
    }

    /**
     * Trigger all available updates for one site, synchronously. Suppresses the after-update
     * hooks for the duration so we do not double-fire post screenshots.
     *
     * @return array{ok: bool, updated: int, offline: bool, error: string}
     */
    public static function triggerSiteUpdate(int $siteId): array
    {
        if (! self::canTriggerUpdates()) {
            return ['ok' => false, 'updated' => 0, 'offline' => false, 'error' => __('MainWP update API is unavailable. Run the native update instead.', 'webchangedetector')];
        }

        self::$suppress = true;
        $updated  = 0;
        $offline  = false;
        $errors   = [];

        $methods = [
            'execute_update_site_core',
            'execute_update_site_plugins',
            'execute_update_site_themes',
            'execute_update_site_translations',
        ];

        try {
            foreach ($methods as $method) {
                if (! method_exists(self::ABILITIES_CLASS, $method)) {
                    continue;
                }

                $result = call_user_func([self::ABILITIES_CLASS, $method], ['site_id_or_domain' => $siteId]);

                if (is_wp_error($result)) {
                    $code = $result->get_error_code();
                    if ('mainwp_site_offline' === $code) {
                        $offline = true;
                    } elseif ('mainwp_no_updates' !== $code) {
                        // "no updates available" is a normal, non-error outcome.
                        $errors[] = $result->get_error_message();
                    }
                    continue;
                }

                if (is_array($result) && ! empty($result['updated'])) {
                    // Core returns a single associative array; plugins/themes return a list.
                    $updated += array_keys($result['updated']) === range(0, count($result['updated']) - 1)
                        ? count($result['updated'])
                        : 1;
                }
                if (is_array($result) && ! empty($result['errors'])) {
                    foreach ($result['errors'] as $err) {
                        $errors[] = is_string($err) ? $err : wp_json_encode($err);
                    }
                }
            }
        } finally {
            self::$suppress = false;
        }

        // Offline is a hard failure; other per-type errors are tolerated as long as something updated.
        if ($offline) {
            return ['ok' => false, 'updated' => $updated, 'offline' => true, 'error' => __('Site is offline.', 'webchangedetector')];
        }

        return ['ok' => true, 'updated' => $updated, 'offline' => false, 'error' => $errors ? implode('; ', $errors) : ''];
    }

    /* ───────────────────────── After-update hooks ──────────────────────── */

    /**
     * Core update finished for a site.
     *
     * @param mixed  $information Update response.
     * @param object $site        Site object.
     */
    public static function onAfterCoreUpdate($information, $site): void
    {
        self::maybeTakePost($site);
    }

    /**
     * Plugin/theme/translation update finished for a site.
     *
     * @param mixed  $information Update response.
     * @param string $type        plugin|theme|translation.
     * @param string $slugs       Comma-separated slugs.
     * @param object $site        Site object.
     */
    public static function onAfterPluginThemeUpdate($information, $type, $slugs, $site): void
    {
        self::maybeTakePost($site);
    }

    /**
     * Enqueue post screenshots for the site's manual group, unless our card flow already owns the
     * run or we already did it for this site within the dedupe window.
     */
    protected static function maybeTakePost($site): void
    {
        if (self::$suppress) {
            return;
        }

        $siteId = is_object($site) && isset($site->id) ? (int) $site->id : 0;
        if (! $siteId || ! WCD_MainWP_Site_Map::isEnabled($siteId)) {
            return;
        }

        $dedupeKey = 'wcd_post_done_' . $siteId;
        if (WCD_MainWP_Options::getTransient($dedupeKey)) {
            return;
        }
        WCD_MainWP_Options::setTransient($dedupeKey, 1, self::POST_DEDUPE_TTL);

        $groupId = WCD_MainWP_Site_Map::getManualGroup($siteId);
        if ('' !== $groupId) {
            // Fire-and-forget: never block MainWP's own update response on our screenshot call.
            WCD_MainWP_API::takeScreenshot([$groupId], 'post', 'manual', '', false);
        }
    }

    /* ─────────────────────────────── Preflight ─────────────────────────── */

    /**
     * Count the checks one run over the given groups will consume:
     * sum over URLs of (desktop?1:0)+(mobile?1:0).
     */
    public static function checksForGroup(string $groupId, string $apiToken = ''): int
    {
        $response = WCD_MainWP_API::getGroupUrls($groupId, $apiToken, ['per_page' => 1000]);
        if (! $response['ok']) {
            return 0;
        }

        $urls  = self::extractUrls($response['data']);
        $count = 0;
        foreach ($urls as $url) {
            $count += ! empty($url['desktop']) ? 1 : 0;
            $count += ! empty($url['mobile']) ? 1 : 0;
        }

        return $count;
    }

    /**
     * Normalize a group-urls response into a flat list of url arrays.
     */
    public static function extractUrls($data): array
    {
        if (! is_array($data)) {
            return [];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return $data;
    }

    /* ─────────────────────────── Pending updates ───────────────────────── */

    /**
     * Best-effort count of pending MainWP updates across the given sites, read from MainWP's own
     * per-site upgrade columns. Used only for the banner copy ("install the N pending updates"), so
     * it is approximate (it does not subtract ignored/dismissed updates) and degrades to null when
     * MainWP's DB layer is unavailable, in which case the banner drops the number.
     *
     * @param int[] $siteIds
     */
    public static function pendingUpdatesCount(array $siteIds): ?int
    {
        $db = '\\MainWP\\Dashboard\\MainWP_DB';
        if (! class_exists($db) || ! method_exists($db, 'instance')) {
            return null;
        }

        $total = 0;
        $known = false;
        foreach ($siteIds as $siteId) {
            $website = $db::instance()->get_website_by_id((int) $siteId);
            if (empty($website)) {
                continue;
            }
            $known  = true;
            $total += self::countSiteUpgrades($website);
        }

        return $known ? $total : null;
    }

    /**
     * Count one site's pending updates: WordPress core (0/1) + plugins + themes + translations.
     *
     * @param object $website MainWP website row.
     */
    protected static function countSiteUpgrades($website): int
    {
        $count = 0;

        $core = ! empty($website->wp_upgrades) ? json_decode($website->wp_upgrades, true) : [];
        if (is_array($core) && ! empty($core)) {
            $count++;
        }

        foreach (['plugin_upgrades', 'theme_upgrades', 'translation_upgrades'] as $field) {
            $raw     = isset($website->$field) ? $website->$field : '';
            $decoded = ! empty($raw) ? json_decode($raw, true) : [];
            if (is_array($decoded)) {
                $count += count($decoded);
            }
        }

        return $count;
    }
}
