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
        if (strpos($lower, 'row too large for php export') !== false) {
            return array(
                'message' => $text,
                'action'  => __('Use mysqldump mode or exclude the table from this backup.', 'rosenheinrich-multisite-migrate'),
            );
        }
        if (strpos($lower, 'worker stopped unexpectedly') !== false) {
            return array(
                'message' => __('The backup worker stopped before the job finished.', 'rosenheinrich-multisite-migrate'),
                'action'  => __('Open the activity log for details, then retry with mysqldump mode if memory was exhausted.', 'rosenheinrich-multisite-migrate'),
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

        return array(
            'message' => $text,
            'action'  => __('Check the activity log or recovery guide for next steps.', 'rosenheinrich-multisite-migrate'),
        );
    }

    public static function format(Throwable $e): string
    {
        if (!$e instanceof RuntimeException) {
            return __('An unexpected error occurred. Check the activity log for details.', 'rosenheinrich-multisite-migrate');
        }
        $mapped = self::map($e->getMessage());
        if ($mapped['action'] === '') {
            return $mapped['message'];
        }
        return $mapped['message'] . ' ' . $mapped['action'];
    }
}
