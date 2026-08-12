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
        if (strpos($path, '/plugins/rosenheinrich-multisite-migrate/') !== false) {
            return 'rosenheinrich-multisite-migrate/multisite-migrate.php';
        }
        if (strpos($path, '/free/') !== false) {
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
}

}
