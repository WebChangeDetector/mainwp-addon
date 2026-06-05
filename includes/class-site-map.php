<?php
/**
 * Maps MainWP child sites to WebChange Detector websites + groups.
 *
 * MainWP owns the list of managed sites (via the mainwp_getdbsites filter). WCD owns the
 * websites/groups. This class is the bridge: enabling a site provisions a WCD website (with a
 * manual + auto detection group) and stores the resulting UUIDs, keyed by the MainWP site id.
 *
 * The domain stored here is normalized once (scheme + trailing slash stripped) and reused
 * byte-for-byte for every WCD call, because the API resolves websites by exact domain match.
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

class WCD_MainWP_Site_Map
{
    const OPTION_KEY = 'wcd_site_map';

    /**
     * Normalize a site URL the same way the WCD API expects: strip the scheme and any trailing
     * slash, keep www + path. Mirrors the webapp's mm_normalize_domain.
     */
    public static function normalizeDomain(string $raw): string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return '';
        }

        $candidate = preg_match('#^https?://#i', $raw) ? $raw : 'https://' . $raw;
        if (! filter_var($candidate, FILTER_VALIDATE_URL)) {
            return '';
        }

        $bare = preg_replace('#^https?://#i', '', $candidate);

        return rtrim($bare, '/');
    }

    /**
     * The whole map: [ mainwp_site_id => [ website_uuid, manual_group_uuid, auto_group_uuid, domain, enabled ] ].
     */
    public static function all(): array
    {
        $map = WCD_MainWP_Options::get(self::OPTION_KEY, []);

        return is_array($map) ? $map : [];
    }

    public static function forSite(int $siteId): array
    {
        $map = self::all();

        return $map[$siteId] ?? [];
    }

    public static function isEnabled(int $siteId): bool
    {
        $entry = self::forSite($siteId);

        return ! empty($entry['enabled']) && ! empty($entry['manual_group_uuid']);
    }

    public static function getManualGroup(int $siteId): string
    {
        return (string) (self::forSite($siteId)['manual_group_uuid'] ?? '');
    }

    public static function getDomain(int $siteId): string
    {
        return (string) (self::forSite($siteId)['domain'] ?? '');
    }

    /**
     * Persist a single site entry.
     */
    protected static function saveSite(int $siteId, array $entry): void
    {
        $map           = self::all();
        $map[$siteId]  = array_merge($map[$siteId] ?? [], $entry);
        WCD_MainWP_Options::set(self::OPTION_KEY, $map);
    }

    /**
     * Managed MainWP sites as [ id => [ id, url, name, domain ] ], via the public filter.
     */
    public static function managedSites(): array
    {
        // mainwp_getsites returns ALL managed sites when $websiteid is null. (mainwp_getdbsites
        // only returns rows when an explicit sites/groups/clients filter is passed, so it cannot
        // enumerate "all sites".) The filtered value IS the plugin file, then the child key.
        $sites = apply_filters('mainwp_getsites', WCD_MAINWP_PLUGIN_FILE, self::extensionKey(), null);

        $result = [];
        if (is_array($sites)) {
            foreach ($sites as $site) {
                $site = (array) $site;
                if (empty($site['id'])) {
                    continue;
                }
                $id          = (int) $site['id'];
                $result[$id] = [
                    'id'     => $id,
                    'url'    => $site['url'] ?? '',
                    'name'   => $site['name'] ?? ($site['url'] ?? ''),
                    'domain' => self::normalizeDomain($site['url'] ?? ''),
                ];
            }
        }

        return $result;
    }

    /**
     * The security key MainWP hands registered extensions. Fetched live via the public filter.
     */
    protected static function extensionKey(): string
    {
        $info = apply_filters('mainwp_extension_enabled_check', WCD_MAINWP_PLUGIN_FILE);

        return is_array($info) && ! empty($info['key']) ? (string) $info['key'] : '';
    }

    /**
     * Enable a site: provision (or reuse) its WCD website + groups, then mark it enabled.
     *
     * @return array{ok: bool, error: string}
     */
    public static function enableSite(int $siteId, string $apiToken = ''): array
    {
        $managed = self::managedSites();
        if (empty($managed[$siteId])) {
            return ['ok' => false, 'error' => __('Unknown MainWP site.', 'webchangedetector')];
        }

        $domain = $managed[$siteId]['domain'];
        if ('' === $domain) {
            return ['ok' => false, 'error' => __('Could not determine the site domain.', 'webchangedetector')];
        }

        $existing = self::forSite($siteId);

        // Already provisioned: keep the group names consistent (pure domain) and flip the flag on.
        if (! empty($existing['manual_group_uuid']) && ! empty($existing['website_uuid'])) {
            WCD_MainWP_API::updateGroup($existing['manual_group_uuid'], ['name' => $domain], $apiToken);
            if (! empty($existing['auto_group_uuid'])) {
                WCD_MainWP_API::updateGroup($existing['auto_group_uuid'], ['name' => $domain], $apiToken);
            }
            self::saveSite($siteId, ['enabled' => true]);

            return ['ok' => true, 'error' => ''];
        }

        // Create the manual + auto detection groups. Both named the bare domain (matches the
        // customer plugin); the monitoring flag distinguishes them.
        $manual = WCD_MainWP_API::createGroup(
            ['name' => $domain, 'monitoring' => false, 'enabled' => true, 'cms' => 'wordpress'],
            $apiToken
        );
        $manualId = self::extractUuid($manual);
        if (! $manualId) {
            return ['ok' => false, 'error' => $manual['error'] ?: __('Could not create the on-demand group.', 'webchangedetector')];
        }

        $auto = WCD_MainWP_API::createGroup(
            ['name' => $domain, 'monitoring' => true, 'enabled' => true, 'cms' => 'wordpress'],
            $apiToken
        );
        $autoId = self::extractUuid($auto);
        if (! $autoId) {
            return ['ok' => false, 'error' => $auto['error'] ?: __('Could not create the monitoring group.', 'webchangedetector')];
        }

        // Create the website that links both groups.
        $website   = WCD_MainWP_API::createWebsite($domain, $manualId, $autoId, $apiToken);
        $websiteId = self::extractUuid($website);
        $wdata     = (is_array($website['data'] ?? null) && isset($website['data']['data']) && is_array($website['data']['data']))
            ? $website['data']['data']
            : ($website['data'] ?? []);
        $linkOk    = is_array($wdata) && ! empty($wdata['manual_detection_group']) && ! empty($wdata['auto_detection_group']);
        if (! $website['ok'] || ! $websiteId || ! $linkOk) {
            return ['ok' => false, 'error' => $website['error'] ?: __('Could not create the WebChange Detector website (groups not linked).', 'webchangedetector')];
        }

        self::saveSite($siteId, [
            'website_uuid'      => $websiteId,
            'manual_group_uuid' => $manualId,
            'auto_group_uuid'   => $autoId,
            'domain'            => $domain,
            'enabled'           => true,
        ]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Disable a site (keeps the mapping so re-enabling does not re-provision).
     */
    public static function disableSite(int $siteId): void
    {
        if (self::forSite($siteId)) {
            self::saveSite($siteId, ['enabled' => false]);
        }
    }

    /**
     * Pull a UUID out of a create response (group or website resource).
     */
    protected static function extractUuid(array $response): string
    {
        if (empty($response['ok']) || empty($response['data'])) {
            return '';
        }
        $data = $response['data'];
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return isset($data['id']) ? (string) $data['id'] : '';
    }
}
