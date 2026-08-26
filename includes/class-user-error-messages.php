<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_User_Error_Messages
{
    /**
     * @return array{message:string,action:string}
     */
    public static function map(string $raw): array
    {
        $text = wp_strip_all_tags($raw);
        $lower = strtolower($text);

        if (strpos($lower, 'insufficient disk space') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Free disk space on the server or choose a smaller backup.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'decrypt') !== false || strpos($lower, 'passphrase') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Enter the archive passphrase in Restore or import settings.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'incremental restore requires') !== false
            || strpos($lower, 'incremental backups can only be restored') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Incremental restores require Multisite Migrate Pro.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'ziparchive') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Ask your host to enable the PHP Zip extension, or use a .daf backup.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'install token') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Re-export the backup from the source site so a new install token is embedded.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'archive not found') !== false || strpos($lower, 'no backup archives') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Import a backup archive to this server first.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'connection failed') !== false || strpos($lower, 'mysqli') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Verify database host, username, password, and that the database exists with your hosting provider.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'memory') !== false || strpos($lower, 'allowed memory size') !== false) {
            return array(
                'message' => __('PHP ran out of memory during the database export.', 'rosenheinrich-multisite-migrate'),
                'action'  => __('Use Settings → Database → mysqldump, raise memory_limit, or exclude large tables.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'maximum execution time') !== false
            || strpos($lower, 'max_execution_time') !== false
            || strpos($lower, 'exceeded maximum time limit') !== false) {
            return array(
                'message' => __('PHP time limit hit mid-slice.', 'rosenheinrich-multisite-migrate'),
                'action'  => __('Raise max_execution_time or open Health, then retry.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'row too large for php export') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Use mysqldump mode or exclude the table from this backup.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'worker stopped unexpectedly') !== false
            || strpos($lower, 'worker stopped before the job finished') !== false) {
            return array(
                'message' => __('Worker stopped unexpectedly.', 'rosenheinrich-multisite-migrate'),
                'action'  => __('Open Activity for details, then retry.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'backup database table is missing') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Deactivate and reactivate the plugin in Network Admin → Plugins, then retry the backup.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'failed to create backup job') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Check that the database user can create rows in wp_rmmigrate_jobs, or deactivate and reactivate the plugin.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'failed to restore file') !== false
            || strpos($lower, 'cannot restore file') !== false
            || strpos($lower, 'too many missing files') !== false
            || strpos($lower, 'datei konnte nicht wiederhergestellt') !== false) {
            return array(
                'message' => __('A file could not be restored.', 'rosenheinrich-multisite-migrate'),
                'action'  => __('Open the activity log for the file name, check write permissions, then retry.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'cannot write extracted file') !== false
            || strpos($lower, 'cannot extract') !== false
            || strpos($lower, 'extract failed') !== false) {
            return array(
                'message' => __('Archive extraction failed.', 'rosenheinrich-multisite-migrate'),
                'action'  => __('Check disk space and write permissions, then retry.', 'rosenheinrich-multisite-migrate'),
            );
        }

        return array(
            'message' => $text,
            'action'  => __('Check the activity log or recovery guide for next steps.', 'rosenheinrich-multisite-migrate'),
        );
    }

    /**
     * Short admin-banner copy: prefer error code, never surface paths/basenames.
     *
     * @param array{message?:string,code?:string,error_code?:string,job_type?:string} $last_error
     */
    public static function for_admin_banner(array $last_error): string
    {
        $code = sanitize_key((string) ($last_error['code'] ?? $last_error['error_code'] ?? ''));
        if ($code === Rmmigrate_Error_Codes::FILE_RESTORE_FAILED) {
            return __('A file could not be restored.', 'rosenheinrich-multisite-migrate');
        }
        if ($code === Rmmigrate_Error_Codes::EXTRACT_FAILED) {
            return __('Archive extraction failed.', 'rosenheinrich-multisite-migrate');
        }
        if ($code === Rmmigrate_Error_Codes::TIME_LIMIT) {
            return __('PHP time limit hit mid-slice.', 'rosenheinrich-multisite-migrate');
        }
        if ($code === Rmmigrate_Error_Codes::WORKER_STOPPED || $code === Rmmigrate_Error_Codes::STALE_WORKER) {
            return __('Worker stopped unexpectedly.', 'rosenheinrich-multisite-migrate');
        }
        if ($code === Rmmigrate_Error_Codes::MEMORY_LIMIT) {
            return __('PHP ran out of memory during the job.', 'rosenheinrich-multisite-migrate');
        }
        if ($code === Rmmigrate_Error_Codes::PERMISSION_DENIED) {
            return __('Permission denied while writing files.', 'rosenheinrich-multisite-migrate');
        }

        $raw = (string) ($last_error['message'] ?? '');
        // Never surface paths/basenames in admin toasts or banners.
        if (preg_match('/failed to restore file|cannot restore file|datei konnte nicht wiederhergestellt|too many missing files/i', $raw)
            || preg_match('/:\s*[^\s\\/:*?"<>|]+\.(?:php|py|js|css|htaccess|txt|json|xml|html|htm|mo|po|inc|sql)\s*$/i', $raw)
        ) {
            return __('A file could not be restored.', 'rosenheinrich-multisite-migrate');
        }

        $mapped = self::map($raw);
        $message = $mapped['message'];
        $message = preg_replace('/:\s*(\.?[\w.\-]+|\/[^\s]+|\\\\[^\s]+)\s*$/u', '', $message) ?? $message;
        $message = trim($message);
        if ($message === '') {
            $job_type = sanitize_key((string) ($last_error['job_type'] ?? ''));
            if ($job_type === 'restore') {
                return __('The restore did not complete.', 'rosenheinrich-multisite-migrate');
            }
            if ($job_type === 'backup') {
                return __('The backup did not complete.', 'rosenheinrich-multisite-migrate');
            }
            if ($job_type === 'import') {
                return __('The import did not complete.', 'rosenheinrich-multisite-migrate');
            }
            return __('The job did not complete.', 'rosenheinrich-multisite-migrate');
        }

        if (function_exists('mb_substr')) {
            return (string) mb_substr($message, 0, 200);
        }
        return substr($message, 0, 200);
    }

    public static function format(Throwable $e): string
    {
        if (!$e instanceof RuntimeException) {
            return __('An unexpected error occurred.', 'rosenheinrich-multisite-migrate');
        }
        // Message only — actions belong in UI CTAs / map(), not activity rows or job error_message.
        return self::map($e->getMessage())['message'];
    }
}
