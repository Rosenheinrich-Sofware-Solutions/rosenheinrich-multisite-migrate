<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Job_Cleanup
{
    const PURGE_HOOK = 'rmmigrate_purge_deletes';
    const PURGE_MAX_ATTEMPTS = 5;

    /** @var bool Request-local guard so archives page does not re-scan mid-request. */
    private static $reconcile_done = false;

    public static function register(): void
    {
        add_action(self::PURGE_HOOK, array(__CLASS__, 'purge_pending_deletes'));
    }

    public static function schedule_purge(int $delay_sec = 1): void
    {
        $when = time() + max(1, $delay_sec);
        $existing = wp_next_scheduled(self::PURGE_HOOK);
        if (!$existing) {
            wp_schedule_single_event($when, self::PURGE_HOOK);
        } elseif ($existing < $when) {
            wp_unschedule_event($existing, self::PURGE_HOOK);
            wp_schedule_single_event($when, self::PURGE_HOOK);
        }
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }

    /**
     * Align DB with disk for completed backups whose local archive is gone.
     * Runs at most once per request (archives page open).
     *
     * @return int Number of jobs whose local_path was cleared.
     */
    public static function reconcile_missing_local_archives(): int
    {
        if (self::$reconcile_done) {
            return 0;
        }
        self::$reconcile_done = true;

        $jobs = Rmmigrate_Job::list_jobs(array(
            'status_group' => 'completed',
            'job_type'     => Rmmigrate_Job::JOB_TYPE_BACKUP,
            'limit'        => 200,
        ));
        if ($jobs === array()) {
            return 0;
        }

        $changed = 0;

        foreach ($jobs as $job) {
            if ($job->get_status() !== Rmmigrate_Job::STATUS_COMPLETE) {
                continue;
            }
            $local_path = trim((string) ($job->data['local_path'] ?? ''));
            if ($local_path === '') {
                continue;
            }

            $full = Rmmigrate_Runner::resolve_local_path($job);
            if ($full !== '' && Rmmigrate_Filesystem::exists($full)) {
                continue;
            }

            // Free has no cloud remote_file_id column in normal installs; keep the check for parity.
            $has_remote = !empty($job->data['remote_file_id']);
            $job_id = $job->get_id();

            $job->save_fields(array('local_path' => ''));
            if ($has_remote) {
                $activity = sprintf(
                    /* translators: %d: job ID */
                    __('Local archive for backup #%1$d is missing; kept remote copy in the list.', 'rosenheinrich-multisite-migrate'),
                    $job_id
                );
            } else {
                $activity = sprintf(
                    /* translators: %d: job ID */
                    __('Local archive for backup #%1$d is missing; kept job history without local file.', 'rosenheinrich-multisite-migrate'),
                    $job_id
                );
            }
            Rmmigrate_Logger::log_activity(
                'backup',
                $activity,
                'info',
                array('job_id' => $job_id)
            );
            $changed++;
        }

        return $changed;
    }

    /**
     * Reset request guard between unit tests.
     */
    public static function reset_reconcile_guard_for_tests(): void
    {
        self::$reconcile_done = false;
    }

    /**
     * Finish soft-deleted jobs: purge local artifacts then drop the DB row.
     * Processes a small batch per invocation so large archives cannot starve the host.
     */
    public static function purge_pending_deletes(): void
    {
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Background purge of multi-GB archives.
            @set_time_limit(0);
        }
        @ignore_user_abort(true);

        $rows = Rmmigrate_Snap_DB::jobs_list_rows(array(
            'status_in' => array(Rmmigrate_Job::STATUS_DELETING),
            'limit'     => 3,
        ));
        if ($rows === array()) {
            return;
        }

        global $wpdb;
        foreach ($rows as $row) {
            $job_id = (int) ($row['id'] ?? 0);
            $job = Rmmigrate_Job::get($job_id);
            if ($job === null) {
                self::purge_orphan_references($job_id);
                continue;
            }
            if ($job->get_status() !== Rmmigrate_Job::STATUS_DELETING) {
                continue;
            }

            try {
                self::purge($job);
            } catch (Throwable $e) {
                $attempts = (int) ($job->data['purge_delete_attempts'] ?? 0) + 1;
                $err = sanitize_text_field($e->getMessage());
                self::handle_purge_delete_failure(
                    $job,
                    $job_id,
                    $attempts,
                    sprintf(
                        /* translators: 1: job ID, 2: max attempts, 3: error message */
                        __('Background delete failed for backup #%1$d after %2$d attempts: %3$s', 'rosenheinrich-multisite-migrate'),
                        $job_id,
                        self::PURGE_MAX_ATTEMPTS,
                        $err
                    ),
                    sprintf(
                        /* translators: 1: job ID, 2: error message */
                        __('Background delete failed for backup #%1$d: %2$s', 'rosenheinrich-multisite-migrate'),
                        $job_id,
                        $err
                    ),
                    sprintf(
                        /* translators: 1: job ID, 2: attempt number, 3: maximum attempts, 4: error message */
                        __('Background delete failed for backup #%1$d (attempt %2$d/%3$d): %4$s', 'rosenheinrich-multisite-migrate'),
                        $job_id,
                        $attempts,
                        self::PURGE_MAX_ATTEMPTS,
                        $err
                    )
                );
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
            $deleted = $wpdb->delete(Rmmigrate_Job::table_name(), array('id' => $job_id), array('%d'));
            if ($deleted !== 1) {
                $attempts = (int) ($job->data['purge_delete_attempts'] ?? 0) + 1;
                self::handle_purge_delete_failure(
                    $job,
                    $job_id,
                    $attempts,
                    sprintf(
                        /* translators: 1: job ID, 2: max attempts, 3: rows deleted */
                        __('Background delete row removal failed for backup #%1$d after %2$d attempts (deleted %3$d rows).', 'rosenheinrich-multisite-migrate'),
                        $job_id,
                        self::PURGE_MAX_ATTEMPTS,
                        (int) $deleted
                    ),
                    sprintf(
                        /* translators: 1: job ID, 2: rows deleted */
                        __('Background delete row removal failed for backup #%1$d (deleted %2$d rows).', 'rosenheinrich-multisite-migrate'),
                        $job_id,
                        (int) $deleted
                    ),
                    sprintf(
                        /* translators: 1: job ID, 2: rows deleted, 3: attempt number */
                        __('Background delete row removal failed for backup #%1$d (deleted %2$d rows, attempt %3$d).', 'rosenheinrich-multisite-migrate'),
                        $job_id,
                        (int) $deleted,
                        $attempts
                    )
                );
                continue;
            }
            $job->save_fields(array('purge_delete_attempts' => 0));
            Rmmigrate_Logger::log_activity(
                'backup',
                sprintf(
                    /* translators: %d: job ID */
                    __('Backup #%1$d deleted.', 'rosenheinrich-multisite-migrate'),
                    $job_id
                ),
                'info',
                array('job_id' => $job_id)
            );
        }

        $more = Rmmigrate_Snap_DB::jobs_list_rows(array(
            'status_in' => array(Rmmigrate_Job::STATUS_DELETING),
            'limit'     => 1,
        ));
        if ($more !== array()) {
            self::schedule_purge();
        }
    }

    /**
     * Remove local artifacts and cross-references for a job. Does not delete the DB row.
     */
    public static function purge(Rmmigrate_Job $job): void
    {
        $job_id = $job->get_id();
        if ($job_id <= 0) {
            return;
        }

        Rmmigrate_Local_Storage::delete_job_files($job);
        Rmmigrate_Activity_Log::delete_job_log($job_id);
        Rmmigrate_Activity_Log::delete_entries_for_job($job_id);
        self::delete_deploy_dirs($job_id);
        self::clear_site_options($job_id);
        Rmmigrate_Runner::force_release_lock($job_id);
        self::clear_transients($job_id);
        self::unschedule_worker($job_id);
    }

    /**
     * Clear stored references when the job row is already gone.
     */
    public static function purge_orphan_references(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }

        self::delete_deploy_dirs($job_id);
        self::clear_site_options($job_id);
        Rmmigrate_Runner::force_release_lock($job_id);
        self::clear_transients($job_id);
        self::unschedule_worker($job_id);
    }

    /**
     * Drop partial backup artifacts after cancel (backup jobs only).
     */
    public static function purge_cancelled_artifacts(Rmmigrate_Job $job): void
    {
        if ($job->get_job_type() !== Rmmigrate_Job::JOB_TYPE_BACKUP) {
            return;
        }
        if ($job->get_status() !== Rmmigrate_Job::STATUS_CANCELLED) {
            return;
        }

        Rmmigrate_Local_Storage::delete_job_files($job);
        Rmmigrate_Runner::force_release_lock($job->get_id());
        self::clear_transients($job->get_id());
        self::unschedule_worker($job->get_id());
    }

    public static function delete_deploy_dirs(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }

        $backups_dir = trailingslashit(Rmmigrate_Plugin::backups_dir());
        foreach (array('imports', 'recover') as $target) {
            $deploy_dir = $backups_dir . $target . '/mm-deploy-' . $job_id;
            if (is_dir($deploy_dir)) {
                Rmmigrate_Filesystem::delete_directory($deploy_dir);
            }
        }
    }

    public static function clear_site_options(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }

        $last_error = get_site_option('rmmigrate_last_error', array());
        if (is_array($last_error) && (int) ($last_error['job_id'] ?? 0) === $job_id) {
            delete_site_option('rmmigrate_last_error');
        }
    }

    public static function clear_transients(int $job_id): void
    {
        if ($job_id <= 0 || !function_exists('delete_transient')) {
            return;
        }

        $keys = array(
            'rmmigrate_browser_kick_' . $job_id,
            'rmmigrate_worker_rate_' . $job_id,
            'rmmigrate_admin_kick_' . $job_id,
        );
        foreach ($keys as $key) {
            delete_transient($key);
        }
    }

    public static function unschedule_worker(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }

        if (class_exists('Rmmigrate_Hosting_Detection', false)) {
            Rmmigrate_Hosting_Detection::unschedule_cron_worker($job_id);
            return;
        }

        if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
            return;
        }

        for ($i = 0; $i < 5; $i++) {
            $timestamp = wp_next_scheduled('rmmigrate_worker_cron', array($job_id));
            if (!$timestamp) {
                break;
            }
            $result = wp_unschedule_event((int) $timestamp, 'rmmigrate_worker_cron', array($job_id), true);
            if ($result instanceof WP_Error || $result === false) {
                break;
            }
        }
    }

    private static function handle_purge_delete_failure(
        Rmmigrate_Job $job,
        int $job_id,
        int $attempts,
        string $max_status_message,
        string $max_log_message,
        string $retry_log_message
    ): void {
        if ($attempts >= self::PURGE_MAX_ATTEMPTS) {
            $job->save_fields(array('purge_delete_attempts' => 0));
            $job->update_progress(array('purge_failed' => true));
            $job->set_status(Rmmigrate_Job::STATUS_ERROR, $max_status_message);
            Rmmigrate_Logger::log_activity(
                'backup',
                $max_log_message,
                'error',
                array('job_id' => $job_id)
            );
            return;
        }

        $job->save_fields(array('purge_delete_attempts' => $attempts));
        Rmmigrate_Logger::log_activity(
            'backup',
            $retry_log_message,
            'warning',
            array('job_id' => $job_id)
        );
        self::schedule_purge(min(300, 5 * $attempts));
    }
}
