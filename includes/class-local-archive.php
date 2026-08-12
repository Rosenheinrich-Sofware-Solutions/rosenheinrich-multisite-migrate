<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Local_Archive
{
    /**
     * Ensure the backup archive exists locally.
     *
     * @return string|WP_Error Local absolute path
     */
    public static function ensure_local_archive(Rmmigrate_Job $job, ?callable $progress = null)
    {
        if (!empty($job->data['local_path'])) {
            $path = Rmmigrate_Runner::resolve_local_path($job);
            if (Rmmigrate_Filesystem::exists($path)) {
                if ($progress !== null) {
                    $progress(__('Backup ready', 'rosenheinrich-multisite-migrate'), 100);
                }
                return $path;
            }
        }

        return self::local_error(
            $job,
            'mm_no_archive',
            __('Backup file not found on this server. Run a new backup or import a backup archive.', 'rosenheinrich-multisite-migrate')
        );
    }

    private static function local_error(Rmmigrate_Job $job, string $code, string $message): WP_Error
    {
        Rmmigrate_Logger::log_activity(
            'restore',
            sprintf(
                /* translators: 1: job ID, 2: error message */
                __('Could not locate backup #%1$d: %2$s', 'rosenheinrich-multisite-migrate'),
                $job->get_id(),
                $message
            ),
            'error',
            array(
                'job_id'  => $job->get_id(),
                'context' => array('error_code' => $code),
            )
        );

        return new WP_Error($code, $message);
    }
}
