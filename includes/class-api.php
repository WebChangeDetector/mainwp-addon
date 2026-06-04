<?php

class WCD_MainWP_API
{
    const DEFAULT_API_URL = 'https://api.webchangedetector.com/api/v2';

    protected static function getApiUrl(): string
    {
        if (defined('WCD_API_URL')) {
            return rtrim(WCD_API_URL, '/');
        }

        return self::DEFAULT_API_URL;
    }

    protected static function request(string $method, string $endpoint, array $body = [], string $apiToken = '', array $query = [])
    {
        if (empty($apiToken)) {
            $apiToken = (string) get_option('wcd_api_token');
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiToken,
                'Content-Type'  => 'application/json',
            ],
        ];

        if ($method !== 'GET' && !empty($body)) {
            $args['body'] = json_encode($body);
        }

        $url = self::getApiUrl() . $endpoint;
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_request($url, $args);

        return json_decode(
            wp_remote_retrieve_body($response),
            true
        );
    }

    /**
     * Retrieve the account associated with the current API token.
     *
     * @return array{data: array{id: string, name_first: string, name_last: string, email: string, checks_done: int, checks_left: int, checks_limit: int, plan: string, plan_name: string, status: string}}|null
     */
    public static function getAccount(string $apiToken = ''): ?array
    {
        return static::request('GET', '/account', [], $apiToken);
    }

    /**
     * List all groups.
     *
     * @param int|null $perPage  Results per page (default: all via high number).
     * @return array{data: array<int, array{id: string, name: string, monitoring: bool, enabled: bool, urls_count: int, selected_urls_count: int}>}|null
     */
    public static function listGroups(string $apiToken = '', int $perPage = 100): ?array
    {
        return static::request('GET', '/groups', [], $apiToken, ['per_page' => $perPage]);
    }

    /**
     * Trigger screenshots for the given groups.
     *
     * @param string[] $groupIds  List of group UUIDs.
     * @param string   $scType    'pre' (baseline) or 'post' (compare + diff).
     * @param string   $source    'manual' | 'auto_update' | 'monitoring'.
     * @return array{batch: string, amount_screenshots: int, groups: string[]}|null
     */
    public static function takeScreenshot(array $groupIds, string $scType = 'pre', string $source = 'manual', string $apiToken = ''): ?array
    {
        return static::request('POST', '/screenshots/take', [
            'group_ids' => json_encode($groupIds),
            'sc_type'   => $scType,
            'source'    => $source,
        ], $apiToken);
    }
}