<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional pre-update backup hook (opt-in, default off) with dedicated retention cap.
 */
final class Rmmigrate_Pre_Update_Backup
{
    public const LOCK_TRANSIENT = 'rmmigrate_pre_update_lock';
    public const LOCK_TTL_SECONDS = 300;
    public const SYNC_BUDGET_SECONDS = 15;

    public static function register(): void
    {
        add_filter('upgrader_pre_install', array(__CLASS__, 'maybe_backup_before_update'), 10, 2);
        // Core_Upgrader does not call install_package(), so upgrader_pre_install never fires for
        // WordPress core updates. upgrader_pre_download runs for core (and other) downloads.
        add_filter('upgrader_pre_download', array(__CLASS__, 'maybe_backup_before_core_download'), 10, 3);
    }

    public static function is_enabled(): bool
    {
        $settings = Rmmigrate_Settings::get();
        return !empty($settings['auto_backup_before_update']);
    }

    public static function retention_limit(): int
    {
        $settings = Rmmigrate_Settings::get();
        return max(1, min(20, (int) ($settings['retention_pre_update'] ?? 3)));
    }

    public static function profile(): string
    {
        $settings = Rmmigrate_Settings::get();
        $profile = (string) ($settings['pre_update_profile'] ?? 'db');
        return in_array($profile, array('db', 'full'), true) ? $profile : 'db';
    }

    /**
     * Core updates download via Core_Upgrader::upgrade() without install_package().
     *
     * @param mixed                $reply    Short-circuit download value.
     * @param string               $package  Package URL.
     * @param mixed                $upgrader Upgrader instance.
     * @return mixed
     */
    public static function maybe_backup_before_core_download($reply, $package = '', $upgrader = null)
    {
        if (function_exists('is_wp_error') && is_wp_error($reply)) {
            return $reply;
        }
        if (!is_object($upgrader) || !self::is_core_upgrader($upgrader)) {
            return $reply;
        }
        self::maybe_backup_before_update(true, array('type' => 'core'));
        return $reply;
    }

    /**
     * Trigger a pre-update backup before WordPress installs a plugin/theme update
     * (and via maybe_backup_before_core_download for core).
     *
     * @param mixed $return     Upgrader filter return value.
     * @param mixed $hook_extra Upgrader hook_extra array.
     * @return mixed
     */
    public static function maybe_backup_before_update($return, $hook_extra = array())
    {
        if (function_exists('is_wp_error') && is_wp_error($return)) {
            return $return;
        }
        if (!self::is_enabled()) {
            return $return;
        }
        if (!self::current_user_can_trigger()) {
            return $return;
        }
        if (!self::should_backup_for_hook_extra(is_array($hook_extra) ? $hook_extra : array())) {
            return $return;
        }

        try {
            if (function_exists('get_transient') && get_transient(self::LOCK_TRANSIENT)) {
                return $return;
            }
            if (class_exists('Rmmigrate_Job') && Rmmigrate_Job::get_active() !== null) {
                return $return;
            }

            if (!self::background_worker_available()) {
                if (class_exists('Rmmigrate_Logger')) {
                    Rmmigrate_Logger::log('Pre-update backup skipped: no background worker (WP-Cron disabled and browser-only kickoff).');
                }
                return $return;
            }

            if (function_exists('set_transient')) {
                set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL_SECONDS);
            }

            $profile = self::profile();
            $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
            $scope = (class_exists('Rmmigrate_Multisite_Scope'))
                ? Rmmigrate_Multisite_Scope::SCOPE_NETWORK
                : 'network';

            $job = Rmmigrate_Job::create(array(
                'job_type'       => Rmmigrate_Job::JOB_TYPE_BACKUP,
                'scope'          => $scope,
                'blog_id'        => $blog_id,
                'destination'    => 'local',
                'triggered_by'   => 'pre_update',
                'backup_profile' => $profile,
            ));


            // Drive a short synchronous budget so the DB dump (and early file steps) preferably
            // finish before the upgrader overwrites files; then kick the background worker.
            self::run_sync_budget($job->get_id());

            if (class_exists('Rmmigrate_Runner') && method_exists('Rmmigrate_Runner', 'kick_worker')) {
                Rmmigrate_Runner::kick_worker($job->get_id());
            }

            self::enforce_retention();
        } catch (Throwable $e) {
            if (class_exists('Rmmigrate_Logger')) {
                Rmmigrate_Logger::log('Pre-update backup skipped: ' . $e->getMessage());
            }
        }

        return $return;
    }

    /**
     * Skip new installs and translation/language-pack updates.
     *
     * @param array<string,mixed> $hook_extra
     */
    public static function should_backup_for_hook_extra(array $hook_extra): bool
    {
        $action = strtolower((string) ($hook_extra['action'] ?? ''));
        if ($action === 'install') {
            return false;
        }

        $type = strtolower((string) ($hook_extra['type'] ?? ''));
        if (in_array($type, array('translation', 'language', 'translations'), true)) {
            return false;
        }
        if (!empty($hook_extra['language_update_list']) || !empty($hook_extra['language_update'])) {
            return false;
        }

        // Language_Pack_Upgrader passes destination / WP_LANG_DIR style extras without type=plugin.
        if (isset($hook_extra['api']) && is_object($hook_extra['api']) && isset($hook_extra['api']->type)
            && strtolower((string) $hook_extra['api']->type) === 'translation') {
            return false;
        }

        return true;
    }

    /**
     * @param mixed $upgrader
     */
    private static function is_core_upgrader($upgrader): bool
    {
        if (!is_object($upgrader)) {
            return false;
        }
        $class = get_class($upgrader);
        if ($class === 'Core_Upgrader' || is_a($upgrader, 'Core_Upgrader')) {
            return true;
        }
        return substr($class, -13) === 'Core_Upgrader';
    }

    private static function run_sync_budget(int $job_id): void
    {
        if ($job_id <= 0 || !class_exists('Rmmigrate_Runner') || !method_exists('Rmmigrate_Runner', 'process')) {
            return;
        }
        $deadline = time() + self::SYNC_BUDGET_SECONDS;
        while (time() < $deadline) {
            $result = Rmmigrate_Runner::process($job_id);
            if (!is_array($result)) {
                break;
            }
            if (!empty($result['done'])) {
                break;
            }
            // Avoid a tight loop if the worker is waiting on a lock.
            if (!empty($result['waiting'])) {
                break;
            }
        }
    }

    /**
     * Prune completed pre-update backups exceeding the configured retention limit.
     */
    public static function enforce_retention(?int $limit = null): int
    {
        $max_keep = $limit !== null ? max(1, min(20, $limit)) : self::retention_limit();
        if (!class_exists('Rmmigrate_Job')) {
            return 0;
        }

        $completed = Rmmigrate_Job::list_jobs(array(
            'status_group' => 'completed',
            'job_type'     => Rmmigrate_Job::JOB_TYPE_BACKUP,
            'triggered_by' => 'pre_update',
            'limit'        => 200,
        ));

        $pre_update_jobs = array();
        foreach ($completed as $job) {
            if (!($job instanceof Rmmigrate_Job)) {
                continue;
            }
            $triggered_by = method_exists($job, 'get_triggered_by')
                ? (string) $job->get_triggered_by()
                : (string) ($job->data['triggered_by'] ?? '');
            if ($triggered_by === 'pre_update') {
                $pre_update_jobs[] = $job;
            }
        }

        if (count($pre_update_jobs) <= $max_keep) {
            return 0;
        }

        $to_prune = array_slice($pre_update_jobs, $max_keep);
        $pruned = 0;

        foreach ($to_prune as $old_job) {
            if (class_exists('Rmmigrate_Job_Cleanup') && method_exists('Rmmigrate_Job_Cleanup', 'delete_local_files')) {
                Rmmigrate_Job_Cleanup::delete_local_files($old_job);
            } elseif (class_exists('Rmmigrate_Job_Cleanup') && method_exists('Rmmigrate_Job_Cleanup', 'purge')) {
                Rmmigrate_Job_Cleanup::purge($old_job);
            } elseif (class_exists('Rmmigrate_Local_Storage')) {
                Rmmigrate_Local_Storage::delete_job_files($old_job);
            }

            if (method_exists($old_job, 'delete')) {
                $old_job->delete();
            } else {
                global $wpdb;
                if (is_object($wpdb) && method_exists($wpdb, 'delete')) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention cleanup of expired pre-update job row.
                    $wpdb->delete(Rmmigrate_Job::table_name(), array('id' => $old_job->get_id()), array('%d'));
                }
            }
            $pruned++;
        }

        return $pruned;
    }

    /**
     * A pending job that only a browser tab can drive would block manual jobs until someone
     * opens the plugin, so skip when neither WP-Cron nor a loopback worker is available.
     */
    private static function background_worker_available(): bool
    {
        if (!class_exists('Rmmigrate_Hosting_Detection') || !method_exists('Rmmigrate_Hosting_Detection', 'effective_kickoff_mode')) {
            return true;
        }
        if (Rmmigrate_Hosting_Detection::effective_kickoff_mode() !== 'browser') {
            return true;
        }
        return !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
    }

    private static function current_user_can_trigger(): bool
    {
        // Background auto-updates run from WP-Cron without a logged-in user; the opt-in
        // setting itself is admin-controlled.
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }
        if (!function_exists('current_user_can')) {
            return false;
        }
        return current_user_can('update_plugins')
            || current_user_can('update_themes')
            || current_user_can('update_core')
            || current_user_can('manage_options')
            || current_user_can('manage_network');
    }
}
