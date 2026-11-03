<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Deactivator
{
    public const LIFECYCLE_OPTION = 'rmmigrate_lifecycle';

    /**
     * @param string $plugin_basename Plugin basename being deactivated (plugin_basename(__FILE__)).
     */
    public static function deactivate(string $plugin_basename = ''): void
    {
        unset($plugin_basename);

        wp_clear_scheduled_hook('rmmigrate_worker_cron');
        wp_clear_scheduled_hook('rmmigrate_purge_deletes');
        wp_clear_scheduled_hook('rmmigrate_deferred_hosting_detect');
        wp_clear_scheduled_hook('rmmigrate_storage_self_check');
        wp_clear_scheduled_hook('rmmigrate_deferred_retention_prune');
        wp_clear_scheduled_hook('rmmigrate_tick');
        wp_clear_scheduled_hook('rmmigrate_oauth_purge_expired');
        // Telemetry jobs belong to this edition only (Pro uses its own prefixes).
        wp_clear_scheduled_hook('rmmigrate_telemetry_flush');
        wp_clear_scheduled_hook('rmmigrate_daily_telemetry_snapshot');
        wp_clear_scheduled_hook('rmmigrate_telemetry_snapshot_oneshot');
        wp_clear_scheduled_hook('rmmigrate_telemetry_consent_remote');
        delete_transient('rmmigrate_lock');
        delete_transient('rmmigrate_preflight');

        delete_site_option('rmmigrate_restore_maintenance');
        delete_site_option('rmmigrate_restore_maintenance_job');
        delete_site_option('rmmigrate_maintenance');
        delete_option('rmmigrate_maintenance');
        self::remove_owned_maintenance_file();
        self::record_lifecycle();
        self::maybe_send_deactivate_beacon();
    }

    /**
     * Local, never transmitted on its own: when and how often the plugin was deactivated.
     */
    private static function record_lifecycle(): void
    {
        $lifecycle = get_site_option(self::LIFECYCLE_OPTION, array());
        if (!is_array($lifecycle)) {
            $lifecycle = array();
        }
        $lifecycle['deactivated_at'] = time();
        $lifecycle['deactivations']  = (int) ($lifecycle['deactivations'] ?? 0) + 1;
        update_site_option(self::LIFECYCLE_OPTION, $lifecycle);
    }

    private static function maybe_send_deactivate_beacon(): void
    {
        if (self::sibling_commercial_remains_active()) {
            return;
        }

        if (class_exists('Rmmigrate_Telemetry', false) && method_exists('Rmmigrate_Telemetry', 'send_deactivate_beacon')) {
            Rmmigrate_Telemetry::send_deactivate_beacon();
            return;
        }

        if (!defined('RMMIGRATE_PATH')) {
            return;
        }

        $telemetry_file = RMMIGRATE_PATH . 'includes/admin/class-telemetry.php';
        if (!is_file($telemetry_file)) {
            return;
        }
        require_once $telemetry_file;
        if (class_exists('Rmmigrate_Telemetry', false) && method_exists('Rmmigrate_Telemetry', 'send_deactivate_beacon')) {
            Rmmigrate_Telemetry::send_deactivate_beacon();
        }
    }

    private static function sibling_commercial_remains_active(): bool
    {
        $free_basenames = array(
            'rosenheinrich-multisite-migrate/multisite-migrate.php',
            'multisite-migrate/multisite-migrate.php',
        );

        $listed = get_option('active_plugins', array());
        if (!is_array($listed)) {
            $listed = array();
        }
        foreach ($listed as $plugin) {
            if (!is_string($plugin) || in_array($plugin, $free_basenames, true)) {
                continue;
            }
            if (preg_match('#/multisite-migrate\.php$#', $plugin) === 1) {
                return true;
            }
        }

        $sitewide = get_site_option('active_sitewide_plugins', array());
        if (!is_array($sitewide)) {
            return false;
        }
        foreach (array_keys($sitewide) as $plugin) {
            if (!is_string($plugin) || in_array($plugin, $free_basenames, true)) {
                continue;
            }
            if (preg_match('#/multisite-migrate\.php$#', $plugin) === 1) {
                return true;
            }
        }

        return false;
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
