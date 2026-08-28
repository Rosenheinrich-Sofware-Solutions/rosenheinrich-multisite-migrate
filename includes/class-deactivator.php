<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Deactivator
{
    /**
     * @param string $plugin_basename Plugin basename being deactivated (plugin_basename(__FILE__)).
     */
    public static function deactivate(string $plugin_basename = ''): void
    {
        unset($plugin_basename);

        wp_clear_scheduled_hook('rmmigrate_worker_cron');
        wp_clear_scheduled_hook('rmmigrate_purge_deletes');
        wp_clear_scheduled_hook('rmmigrate_deferred_hosting_detect');
        wp_clear_scheduled_hook('rmmigrate_deferred_retention_prune');
        wp_clear_scheduled_hook('rmmigrate_tick');
        delete_transient('rmmigrate_lock');
        delete_transient('rmmigrate_preflight');

        delete_site_option('rmmigrate_restore_maintenance');
        delete_site_option('rmmigrate_restore_maintenance_job');
        delete_site_option('rmmigrate_maintenance');
        delete_option('rmmigrate_maintenance');
        self::remove_owned_maintenance_file();
    }

    private static function remove_owned_maintenance_file(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }
        $maintenance_file = ABSPATH . '.maintenance';
        if ($maintenance_file === '.maintenance' || !file_exists($maintenance_file)) {
            return;
        }
        $contents = file_get_contents($maintenance_file);
        if ($contents === false || strpos($contents, 'multisite-migrate-restore') === false) {
            return;
        }
        wp_delete_file($maintenance_file);
    }
}
