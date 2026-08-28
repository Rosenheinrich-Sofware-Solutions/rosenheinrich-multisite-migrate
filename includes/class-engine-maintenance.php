<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Engine_Maintenance
{
    public static function run_scheduled(): void
    {
        self::prune_logs();
    }

    public static function prune_logs(): void
    {
        $months = Rmmigrate_Engine_Config::log_retention_months();
        $cutoff = strtotime('-' . $months . ' months');
        if ($cutoff === false) {
            return;
        }

        self::prune_activity_log($cutoff);
        self::prune_job_logs($cutoff);
        self::prune_system_logs($cutoff);
    }

    /**
     * Delete orphaned job work dirs and partial import uploads. Does not delete finished backups.
     *
     * @return array{removed:int,bytes:int,message:string}
     */
    public static function clean_storage(): array
    {
        Rmmigrate_Plugin::ensure_backup_root();
        $root = trailingslashit(Rmmigrate_Plugin::backups_dir());
        $removed = 0;
        $bytes = 0;

        $active_ids = array();
        global $wpdb;
        // Non-terminal: pending or in-progress (0..99). %i requires WP 6.2+ (plugin Requires at least).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom jobs table; one-shot status scan for storage cleanup.
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE status >= %d AND status < %d',
                Rmmigrate_Job::table_name(),
                Rmmigrate_Job::STATUS_PENDING,
                Rmmigrate_Job::STATUS_COMPLETE
            )
        );
        if (is_string($wpdb->last_error) && trim($wpdb->last_error) !== '') {
            return array(
                'removed' => 0,
                'bytes'   => 0,
                'message' => __('Storage cleanup skipped: could not read active jobs.', 'rosenheinrich-multisite-migrate'),
            );
        }
        foreach ((array) $rows as $id) {
            $active_ids[(int) $id] = true;
        }

        $jobs_dir = $root . 'jobs';
        if (Rmmigrate_Filesystem::is_dir($jobs_dir)) {
            foreach (Rmmigrate_Filesystem::list_dir($jobs_dir) as $entry) {
                $job_id = (int) $entry;
                if ($job_id <= 0 || isset($active_ids[$job_id])) {
                    continue;
                }
                $path = $jobs_dir . '/' . $entry;
                if (!Rmmigrate_Filesystem::is_dir($path)) {
                    continue;
                }
                $job = Rmmigrate_Job::get($job_id);
                if ($job !== null) {
                    $status = $job->get_status();
                    if ($status >= Rmmigrate_Job::STATUS_PENDING && $status < Rmmigrate_Job::STATUS_COMPLETE) {
                        continue;
                    }
                }
                $size = self::directory_size($path);
                if (Rmmigrate_Filesystem::delete_directory($path)) {
                    $removed++;
                    $bytes += $size;
                }
            }
        }

        $imports_dir = $root . 'imports';
        if (Rmmigrate_Filesystem::is_dir($imports_dir)) {
            foreach (Rmmigrate_Filesystem::list_dir($imports_dir) as $entry) {
                $path = $imports_dir . '/' . $entry;
                if (!Rmmigrate_Filesystem::is_file($path)) {
                    continue;
                }
                // Keep only incomplete/temp-looking names; leave registered archives alone.
                $lower = strtolower($entry);
                if (strpos($lower, '.part') === false && strpos($lower, '.tmp') === false && strpos($lower, '.upload') === false) {
                    continue;
                }
                $mtime = filemtime($path);
                if ($mtime === false || (int) $mtime >= (time() - HOUR_IN_SECONDS)) {
                    continue;
                }
                $size = (int) Rmmigrate_Filesystem::filesize($path);
                if (Rmmigrate_Filesystem::delete($path)) {
                    $removed++;
                    $bytes += $size;
                }
            }
        }

        $message = sprintf(
            /* translators: 1: number of paths removed, 2: bytes freed (human readable) */
            __('Cleaned %1$d temporary path(s); freed %2$s.', 'rosenheinrich-multisite-migrate'),
            $removed,
            Rmmigrate_Local_Storage::format_bytes($bytes)
        );

        return array(
            'removed' => $removed,
            'bytes'   => $bytes,
            'message' => $message,
        );
    }

    private static function directory_size(string $dir): int
    {
        if (!Rmmigrate_Filesystem::is_dir($dir)) {
            return 0;
        }

        return (int) (Rmmigrate_Filesystem::directory_size_bounded($dir, 0.0)['size'] ?? 0);
    }

    private static function prune_activity_log(int $cutoff): void
    {
        $dir = Rmmigrate_Activity_Log::logs_dir();
        $paths = Rmmigrate_Log_Rotator::glob_activity_log_files($dir);
        if ($paths === array()) {
            return;
        }

        $max_bytes = Rmmigrate_Engine_Config::log_max_bytes() * 4;
        foreach ($paths as $path) {
            self::prune_activity_log_file($path, $cutoff, $max_bytes);
        }
    }

    private static function prune_activity_log_file(string $path, int $cutoff, int $max_bytes): void
    {
        if (!Rmmigrate_Filesystem::is_readable($path)) {
            return;
        }

        $file_size = (int) Rmmigrate_Filesystem::filesize($path);
        if ($file_size <= 0 || $file_size > $max_bytes) {
            return;
        }

        $lock_path = $path . '.lock';
        $lock = Rmmigrate_Filesystem::open_lock($lock_path, 'c+');
        if ($lock === false) {
            return;
        }
        if (!Rmmigrate_Filesystem::try_exclusive_lock($lock)) {
            Rmmigrate_Filesystem::fclose_raw($lock);
            return;
        }

        try {
            $raw = Rmmigrate_Filesystem::get_contents($path);
            if ($raw === false || $raw === '') {
                return;
            }
            $lines = explode("\n", $raw);

            $kept = array();
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    $kept[] = $line;
                    continue;
                }
                $ts = strtotime((string) ($decoded['time'] ?? ''));
                if ($ts === false || $ts >= $cutoff) {
                    $kept[] = $line;
                }
            }

            if (count($kept) === count(array_filter($lines, static function ($line) {
                return $line !== '';
            }))) {
                return;
            }

            if ($kept === array()) {
                Rmmigrate_Filesystem::delete($path);
            } else {
                Rmmigrate_Filesystem::write_exclusive_with_retry($path, implode("\n", $kept) . "\n");
            }
        } finally {
            Rmmigrate_Filesystem::release_lock($lock);
            Rmmigrate_Filesystem::fclose_raw($lock);
        }
    }

    private static function prune_job_logs(int $cutoff): void
    {
        $dir = Rmmigrate_Activity_Log::logs_dir();
        if (!Rmmigrate_Filesystem::is_dir($dir)) {
            return;
        }

        foreach (Rmmigrate_Log_Rotator::glob_job_log_files($dir) as $file) {
            $mtime = (int) filemtime($file);
            if ($mtime > 0 && $mtime < $cutoff && Rmmigrate_Filesystem::is_writable($file)) {
                Rmmigrate_Filesystem::delete($file);
            }
        }
    }

    private static function prune_system_logs(int $cutoff): void
    {
        $dir = Rmmigrate_Activity_Log::logs_dir();
        if (!Rmmigrate_Filesystem::is_dir($dir)) {
            return;
        }

        foreach (Rmmigrate_Log_Rotator::glob_system_log_files($dir) as $file) {
            $mtime = (int) filemtime($file);
            if ($mtime > 0 && $mtime < $cutoff && Rmmigrate_Filesystem::is_writable($file)) {
                Rmmigrate_Filesystem::delete($file);
            }
        }
    }
}
