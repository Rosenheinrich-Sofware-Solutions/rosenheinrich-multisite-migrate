<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Local_Storage
{
    /**
     * Ensure the on-disk job workspace exists and points at the current storage root.
     *
     * @throws RuntimeException When the directory cannot be created.
     */
    public static function ensure_job_work_dir(Rmmigrate_Job $job, bool $prepare_fresh = false): string
    {
        if (!Rmmigrate_Plugin::ensure_backup_root()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::WORK_DIR_FAILED),
                esc_html(__('Could not initialize backup storage directory.', 'rosenheinrich-multisite-migrate'))
            );
        }

        $job_id = $job->get_id();
        if ($job_id <= 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::JOB_CREATE_FAILED),
                esc_html(__('Invalid backup job.', 'rosenheinrich-multisite-migrate'))
            );
        }

        $work_rel = self::normalize_work_dir_rel($job);
        $work_dir = trailingslashit(Rmmigrate_Plugin::backups_dir()) . $work_rel;

        if ($prepare_fresh && Rmmigrate_Filesystem::is_dir($work_dir)) {
            Rmmigrate_DB_Dumper::clear_export_checkpoint_files($work_dir);
        }

        if (!Rmmigrate_Filesystem::ensure_directory($work_dir)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::WORK_DIR_FAILED),
                esc_html(__('Could not create backup working directory.', 'rosenheinrich-multisite-migrate'))
            );
        }

        $progress = $job->get_progress();
        if (($progress['init']['work_dir'] ?? '') !== $work_rel) {
            $init = is_array($progress['init'] ?? null) ? $progress['init'] : array();
            $init['work_dir'] = $work_rel;
            $job->update_progress(array('init' => $init));
        }

        return $work_dir;
    }

    public static function delete_job_files(Rmmigrate_Job $job): void
    {
        $local = Rmmigrate_Runner::resolve_local_path($job);
        if (Rmmigrate_Filesystem::exists($local)) {
            Rmmigrate_Filesystem::delete($local);
        }
        $work_dir = $job->get_work_dir();
        if (!Rmmigrate_Filesystem::exists($work_dir)) {
            return;
        }
        if (Rmmigrate_Filesystem::is_dir($work_dir)) {
            Rmmigrate_Filesystem::delete_directory($work_dir);
            return;
        }
        Rmmigrate_Filesystem::delete($work_dir);
    }

    /**
     * Human-readable size (B → EB). Large free-space values use TB/PB, not huge GB figures.
     */
    public static function format_bytes(int $bytes): string
    {
        if ($bytes < 0) {
            $bytes = 0;
        }

        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');
        $value = (float) $bytes;
        $unit  = 0;
        $max   = count($units) - 1;

        while ($value >= 1024 && $unit < $max) {
            $value /= 1024;
            ++$unit;
        }

        if (0 === $unit) {
            return (string) (int) $value . ' B';
        }

        $decimals = ($value >= 100) ? 0 : 1;
        $number   = round($value, $decimals);

        if (function_exists('number_format_i18n')) {
            return number_format_i18n($number, $decimals) . ' ' . $units[$unit];
        }

        return (string) $number . ' ' . $units[$unit];
    }

    private static function normalize_work_dir_rel(Rmmigrate_Job $job): string
    {
        $job_id = $job->get_id();
        $expected = 'jobs/' . $job_id . '/';
        $progress = $job->get_progress();
        $stored = isset($progress['init']['work_dir']) ? (string) $progress['init']['work_dir'] : '';
        $stored = str_replace('\\', '/', ltrim($stored, '/'));

        if ($stored === '' || $stored === 'jobs/' . $job_id || $stored === $expected) {
            return $expected;
        }

        if (preg_match('#^jobs/' . preg_quote((string) $job_id, '#') . '/?$#', $stored)) {
            return $expected;
        }

        return $expected;
    }
}
