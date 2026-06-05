<?php
/**
 * Network-aware option + transient storage.
 *
 * MainWP runs in the network admin on multisite, so the add-on's settings live at the
 * dashboard/network level. These helpers transparently pick the site-wide store on multisite
 * and the regular store on single-site installs, so callers never have to branch.
 *
 * @package WebChangeDetector_MainWP
 */

defined('ABSPATH') || exit;

class WCD_MainWP_Options
{
    public static function get(string $key, $default = false)
    {
        return is_multisite() ? get_site_option($key, $default) : get_option($key, $default);
    }

    public static function set(string $key, $value): bool
    {
        return is_multisite() ? update_site_option($key, $value) : update_option($key, $value);
    }

    public static function delete(string $key): bool
    {
        return is_multisite() ? delete_site_option($key) : delete_option($key);
    }

    public static function getTransient(string $key)
    {
        return is_multisite() ? get_site_transient($key) : get_transient($key);
    }

    public static function setTransient(string $key, $value, int $ttl): bool
    {
        return is_multisite() ? set_site_transient($key, $value, $ttl) : set_transient($key, $value, $ttl);
    }

    public static function deleteTransient(string $key): bool
    {
        return is_multisite() ? delete_site_transient($key) : delete_transient($key);
    }
}
