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
        delete_transient('rmmigrate_lock');
        delete_transient('rmmigrate_preflight');
    }
}
