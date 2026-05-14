<?php

class WCD_MainWP_API
{
    protected static function request(string $method, string $endpoint, array $body = [], string $apiToken = '')
    {
        if (empty($apiToken)) {
            $apiToken = (string) get_option('wcd_api_token');
        }

        $response = wp_remote_request(
            'https://api.webchangedetector.com/api/v2' . $endpoint,
            [
                'method' => $method,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiToken,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode($body),
            ]
        );

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
     * Trigger screenshots for the given groups.
     *
     * @param string[] $groupIds  List of group UUIDs.
     * @param string   $scType    'pre' (baseline) or 'post' (compare + diff).
     * @param string   $source    'manual' | 'auto_update' | 'monitoring'.
     * @return array{batch: string, amount_screenshots: int, groups: string[]}|null
     */
    public static function takeScreenshot(array $groupIds, string $scType = 'pre', string $source = 'manual'): ?array
    {
        return static::request('POST', '/screenshots/take', [
            'group_ids' => json_encode($groupIds),
            'sc_type'   => $scType,
            'source'    => $source,
        ]);
    }
}