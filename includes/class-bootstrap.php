<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Rmmigrate_Bootstrap', false)) {

/**
 * Edition bootstrap gates (Duplicator-style file + option checks).
 */
class Rmmigrate_Bootstrap
{
    const OWNER_FREE = 'free';

    /**
     * Whether this plugin main file should continue loading.
     */
    public static function can_run(string $boot_file): bool
    {
        if (!is_file($boot_file)) {
            return false;
        }

        $basename = plugin_basename($boot_file);
        if (self::is_plugin_listed_as_active($basename) && self::plugin_file_exists($basename)) {
            return true;
        }

        $edition_slug = self::edition_slug_for_boot_file($boot_file);
        if ($edition_slug === '' || !self::is_plugin_listed_as_active($edition_slug)) {
            return false;
        }

        return self::plugin_file_exists($edition_slug);
    }

    /**
     * Whether AI Agents (MCP) / Abilities API can run on this WordPress.
     * Requires WordPress 6.9+ (`wp_register_ability`). Backup/restore work from 6.2.
     */
    public static function mcp_supported(): bool
    {
        return function_exists('wp_register_ability');
    }

    /**
     * @param string $plugin Plugin basename relative to wp-content/plugins.
     */
    private static function plugin_file_exists(string $plugin): bool
    {
        if (!defined('WP_PLUGIN_DIR')) {
            return true;
        }

        return is_file(WP_PLUGIN_DIR . '/' . $plugin);
    }

    private static function edition_slug_for_boot_file(string $boot_file): string
    {
        $path = str_replace('\\', '/', $boot_file);
        if (strpos($path, '/plugins/rosenheinrich-multisite-migrate/') !== false || strpos($path, '/free/') !== false) {
            return 'rosenheinrich-multisite-migrate/multisite-migrate.php';
        }

        return '';
    }

    public static function mark_owner(string $owner): void
    {
        if (!defined('RMMIGRATE_BOOTSTRAP_OWNER')) {
            define('RMMIGRATE_BOOTSTRAP_OWNER', $owner);
        }
    }

    /**
     * @param string $plugin Plugin basename relative to wp-content/plugins.
     */
    public static function is_plugin_listed_as_active(string $plugin): bool
    {
        if (!function_exists('get_option')) {
            return true;
        }

        $active = get_option('active_plugins', array());
        if (!is_array($active)) {
            $active = array();
        }
        if (in_array($plugin, $active, true)) {
            return true;
        }

        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
            $sitewide = get_site_option('active_sitewide_plugins', array());
            if (is_array($sitewide) && isset($sitewide[$plugin])) {
                return true;
            }
        }

        return false;
    }

    /** Custom WP-Cron interval used by rmmigrate_tick. */
    const CRON_SCHEDULE_KEY = 'rmmigrate_5min';

    /**
     * Register the 5-minute schedule early so WP-Cron never encounters an unknown schedule.
     */
    public static function register_cron_schedules_filter(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        // Late priority: a later cron_schedules callback that returns a fresh
        // array (does not merge) would wipe keys added at the default priority.
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedules'), 99999);
    }

    /**
     * @param mixed $schedules Incoming cron_schedules filter value (may be non-array).
     * @return array<string,array{interval:int,display:string}>
     */
    public static function add_cron_schedules($schedules): array
    {
        if (!is_array($schedules)) {
            $schedules = array();
        }
        // Do not translate here — cron_schedules can run before init (WP 6.7 JIT notice).
        $schedules['rmmigrate_5min'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 Minutes',
        );
        $schedules['rmmigrate_pro_5min'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 Minutes',
        );
        return $schedules;
    }
}

}
