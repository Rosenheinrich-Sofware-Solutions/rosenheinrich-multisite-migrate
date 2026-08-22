<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Free and Pro editions ship this class under the same name. During the
// Free<->Pro switch (or when WP loads active plugins while running the other
// edition's uninstall.php) both copies can be required in one request, so bail
// if it is already declared to avoid a fatal "Cannot declare class" redeclare.
if ( class_exists( 'Rmmigrate_Uninstaller', false ) ) {
    return;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup on plugin-owned tables and options.

class Rmmigrate_Uninstaller
{
    public const PLAN_OPTION = 'rmmigrate_uninstall_plan';

    /**
     * @return array{delete_backups: bool, delete_logs: bool, delete_settings: bool}
     */
    public static function get_plan(): array
    {
        $saved = function_exists('get_site_option') ? get_site_option(self::PLAN_OPTION) : false;
        if (!is_array($saved)) {
            return array(
                'delete_backups'  => true,
                'delete_logs'     => true,
                'delete_settings' => true,
            );
        }

        return array(
            'delete_backups'  => !empty($saved['delete_backups']),
            'delete_logs'     => !empty($saved['delete_logs']),
            'delete_settings' => !empty($saved['delete_settings']),
        );
    }

    /**
     * @param array{delete_backups?: bool, delete_logs?: bool, delete_settings?: bool} $plan
     */
    public static function save_plan(array $plan): void
    {
        update_site_option(
            self::PLAN_OPTION,
            array(
                'delete_backups'  => !empty($plan['delete_backups']),
                'delete_logs'     => !empty($plan['delete_logs']),
                'delete_settings' => !empty($plan['delete_settings']),
            )
        );
    }

    /**
     * @param array{delete_backups?: bool, delete_logs?: bool, delete_settings?: bool} $plan
     */
    public static function apply_cleanup(array $plan): void
    {
        $storage_dir = self::resolve_storage_dir();

        self::clear_maintenance();
        self::clear_crons();

        if (!empty($plan['delete_settings'])) {
            self::delete_settings();
        }

        if (!empty($plan['delete_backups']) || !empty($plan['delete_logs'])) {
            self::delete_storage($storage_dir, $plan);
        }
    }

    public static function run(): void
    {
        self::apply_cleanup(self::get_plan());
        delete_site_option(self::PLAN_OPTION);
    }

    public static function resolve_storage_dir(): string
    {
        $settings = function_exists('get_site_option') ? get_site_option('rmmigrate_settings') : false;
        $custom = '';
        if (is_array($settings)) {
            $custom = trim((string) ($settings['local_storage_path'] ?? ''));
        }

        if ($custom === '') {
            $dir_name = defined('RMMIGRATE_DIR_NAME') ? RMMIGRATE_DIR_NAME : 'multisite-migrate-archives';
            $basedir = self::uploads_basedir();
            if ($basedir === '') {
                return '';
            }
            return wp_normalize_path(trailingslashit($basedir) . $dir_name);
        }

        if ($custom[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $custom)) {
            return wp_normalize_path($custom);
        }

        $basedir = self::uploads_basedir();
        if ($basedir === '') {
            return '';
        }
        return wp_normalize_path(trailingslashit($basedir) . ltrim($custom, '/'));
    }

    /**
     * Verify that a storage directory is safe to delete and not a WordPress or system root folder.
     */
    public static function is_safe_to_delete_storage_dir(string $storage_dir): bool
    {
        $dir = function_exists('wp_normalize_path') ? wp_normalize_path(rtrim($storage_dir, '/\\')) : rtrim(str_replace('\\', '/', $storage_dir), '/');
        if ($dir === '' || $dir === '/' || $dir === '.') {
            return false;
        }

        require_once dirname(__DIR__) . '/includes/class-filesystem.php';
        if (Rmmigrate_Filesystem::is_forbidden_root_directory($dir)) {
            return false;
        }

        // Must not be identical to uploads basedir itself
        $basedir = self::uploads_basedir();
        if ($basedir !== '') {
            $norm_basedir = function_exists('wp_normalize_path') ? wp_normalize_path(rtrim($basedir, '/\\')) : rtrim(str_replace('\\', '/', $basedir), '/');
            if ($dir === $norm_basedir) {
                return false;
            }
        }

        return true;
    }

    /**
     * Uploads base directory, resolved via wp_upload_dir() to match the backup engine.
     */
    private static function uploads_basedir(): string
    {
        if (class_exists('Rmmigrate_Engine_Config', false)) {
            $basedir = Rmmigrate_Engine_Config::uploads_basedir();
            if ($basedir !== '') {
                return $basedir;
            }
        }

        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : false;
        if (is_array($uploads) && !empty($uploads['basedir'])) {
            return wp_normalize_path($uploads['basedir']);
        }

        return '';
    }

    private static function clear_maintenance(): void
    {
        delete_site_option('rmmigrate_maintenance');
        delete_option('rmmigrate_maintenance');
        $maintenance_file = (defined('ABSPATH') ? ABSPATH : '') . '.maintenance';
        if ($maintenance_file !== '.maintenance' && file_exists($maintenance_file)) {
            wp_delete_file($maintenance_file);
        }
    }

    private static function clear_crons(): void
    {
        wp_clear_scheduled_hook('rmmigrate_worker_cron');
        wp_clear_scheduled_hook('rmmigrate_purge_deletes');
        wp_clear_scheduled_hook('rmmigrate_deferred_hosting_detect');
        wp_clear_scheduled_hook('rmmigrate_deferred_retention_prune');
        wp_clear_scheduled_hook('rmmigrate_tick');
        wp_clear_scheduled_hook('rmmigrate_telemetry_flush');
        wp_clear_scheduled_hook('rmmigrate_daily_telemetry_snapshot');
        wp_clear_scheduled_hook('rmmigrate_telemetry_snapshot_oneshot');
    }

    private static function delete_settings(): void
    {
        $site_options = array(
            'rmmigrate_settings',
            'rmmigrate_setup_wizard',
            'rmmigrate_pending_setup',
            'rmmigrate_pending_welcome',
            'rmmigrate_free_welcome_version',
            'rmmigrate_hosting_status',
            'rmmigrate_last_error',
            'rmmigrate_migration_notice',
            'rmmigrate_recover_point_job_id',
            'rmmigrate_recover_point_deployed_at',
            'rmmigrate_upgrade_notice',
            'rmmigrate_dismiss_multisite_upgrade',
            'rmmigrate_first_activated_at',
            'rmmigrate_maintenance',
            'rmmigrate_telemetry',
            'rmmigrate_telemetry_queue',
        );

        foreach ($site_options as $option_name) {
            delete_site_option($option_name);
            delete_option($option_name);
        }

        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
                'rmmigrate_review_nudge'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
                'rmmigrate_review_nudge_cooldown_until'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
                'rmmigrate_feedback'
            )
        );

        $transients = array(
            'rmmigrate_lock',
            'rmmigrate_migration_notice',
            'rmmigrate_preflight',
        );

        foreach ($transients as $transient_name) {
            delete_transient($transient_name);
            delete_site_transient($transient_name);
        }

        $snap_db_file = dirname(__DIR__) . '/includes/class-snap-db.php';
        if (!class_exists('Rmmigrate_Snap_DB', false) && is_file($snap_db_file)) {
            require_once $snap_db_file;
        }

        if (class_exists('Rmmigrate_Snap_DB', false)) {
            Rmmigrate_Snap_DB::drop_table_if_exists($wpdb->base_prefix . 'rmmigrate_jobs');
            Rmmigrate_Snap_DB::drop_table_if_exists($wpdb->base_prefix . 'mm_oauth_clients');
            Rmmigrate_Snap_DB::drop_table_if_exists($wpdb->base_prefix . 'mm_oauth_tokens');
        }

        if (is_multisite()) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                    $wpdb->esc_like('rmmigrate_sql_worker_lock_') . '%'
                )
            );
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like('rmmigrate_sql_worker_lock_') . '%'
                )
            );
        }
    }

    /**
     * @param array{delete_backups: bool, delete_logs: bool} $plan
     */
    private static function delete_storage(string $storage_dir, array $plan): void
    {
        if ($storage_dir === '' || !is_dir($storage_dir) || !self::is_safe_to_delete_storage_dir($storage_dir)) {
            return;
        }

        if (!empty($plan['delete_backups']) && !empty($plan['delete_logs'])) {
            require_once dirname(__DIR__) . '/includes/class-filesystem.php';
            Rmmigrate_Filesystem::delete_directory($storage_dir);
            return;
        }

        require_once dirname(__DIR__) . '/includes/class-filesystem.php';

        if (!empty($plan['delete_logs'])) {
            $logs_dir = $storage_dir . '/logs';
            if (is_dir($logs_dir)) {
                Rmmigrate_Filesystem::delete_directory($logs_dir);
            }
        }

        if (!empty($plan['delete_backups'])) {
            self::delete_backup_archives($storage_dir);
            foreach (array('jobs', 'imports', '.cache') as $subdir) {
                $path = $storage_dir . '/' . $subdir;
                if (is_dir($path)) {
                    Rmmigrate_Filesystem::delete_directory($path);
                }
            }
        }
    }

    private static function delete_backup_archives(string $storage_dir): void
    {
        $items = scandir($storage_dir);
        if ($items === false) {
            return;
        }

        $extensions = array('zip', 'daf', 'venc');
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $storage_dir . '/' . $item;
            if (is_dir($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $extensions, true)) {
                Rmmigrate_Filesystem::delete($path);
            }
        }
    }
}
