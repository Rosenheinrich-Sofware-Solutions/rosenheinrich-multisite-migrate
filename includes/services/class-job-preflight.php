<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Rmmigrate_Job_Preflight
{
    public static function assert_can_view_job(Rmmigrate_Job $job): void
    {
        if (!Rmmigrate_Access::can_view_job($job)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw Rmmigrate_Service_Exception::forbidden(esc_html(__('Permission denied.', 'rosenheinrich-multisite-migrate')));
        }
    }

    public static function ensure_local_archive(Rmmigrate_Job $job): void
    {
        if (Rmmigrate_Recovery_Points::is_restorable($job)) {
            return;
        }
        if (is_multisite() && !current_user_can('manage_network')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('This backup file is not available on this server.', 'rosenheinrich-multisite-migrate')),
                array('phase' => 'archive_fetch'), sanitize_key(Rmmigrate_Error_Codes::ARCHIVE_MISSING));
        }
        $fetched = Rmmigrate_Local_Archive::ensure_local_archive($job);
        if (is_wp_error($fetched)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($fetched->get_error_message()),
                array('phase' => 'archive_fetch'), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($fetched)));
        }
    }

    /**
     * Perform a fast, non-blocking preflight check before backup, restore, or migration
     * and determine the optimal automatic execution mode.
     *
     * @param string              $operation 'backup', 'restore', or 'migration'.
     * @param array<string,mixed> $context   Optional context overrides (e.g. required_bytes, archive_format).
     * @return array{
     *     operation: string,
     *     status: string,
     *     can_proceed: bool,
     *     summary: string,
     *     recommended_mode: array{archive_mode: string, db_mode: string, kickoff_mode: string, reason: string},
     *     checks: array<int, array{id: string, label: string, status: string, detail: string, hint: string}>
     * }
     */
    public static function evaluate($operation = 'backup', array $context = array()): array
    {
        if (is_array($operation)) {
            $context   = $operation;
            $operation = isset($context['operation']) && is_string($context['operation']) ? $context['operation'] : 'backup';
        }
        $operation = is_string($operation) && in_array($operation, array('backup', 'restore', 'migration'), true) ? $operation : 'backup';

        $backup_dir = class_exists('Rmmigrate_Plugin') ? Rmmigrate_Plugin::backups_dir() : '';
        if ($backup_dir !== '' && !is_dir($backup_dir) && !array_key_exists('storage_writable', $context) && class_exists('Rmmigrate_Plugin')) {
            @Rmmigrate_Plugin::ensure_backup_root();
        }

        // 1. Storage check.
        $storage_writable = array_key_exists('storage_writable', $context)
            ? (bool) $context['storage_writable']
            : ($backup_dir !== '' && class_exists('Rmmigrate_Filesystem') && Rmmigrate_Filesystem::is_writable($backup_dir));

        $checks = array();
        if ($storage_writable) {
            $checks[] = array(
                'id'     => 'storage',
                'label'  => __('Backup storage directory', 'rosenheinrich-multisite-migrate'),
                'status' => 'green',
                'detail' => __('Backup storage directory is writable and ready.', 'rosenheinrich-multisite-migrate'),
                'hint'   => '',
            );
        } else {
            $checks[] = array(
                'id'     => 'storage',
                'label'  => __('Backup storage directory', 'rosenheinrich-multisite-migrate'),
                'status' => 'red',
                'detail' => __('Backup storage directory is not writable.', 'rosenheinrich-multisite-migrate'),
                'hint'   => sprintf(
                    /* translators: %s: relative storage directory under uploads, e.g. uploads/rosenheinrich-multisite-migrate */
                    __('Grant write permissions (0755 or 0775) to %s or configure a writable storage path in Settings.', 'rosenheinrich-multisite-migrate'),
                    'uploads/' . (defined('RMMIGRATE_DIR_NAME') ? RMMIGRATE_DIR_NAME : 'rosenheinrich-multisite-migrate')
                ),
            );
        }

        // 2. Free disk space check.
        $free_bytes = false;
        if (array_key_exists('free_bytes', $context)) {
            $free_bytes = $context['free_bytes'];
        } elseif ($backup_dir !== '' && function_exists('disk_free_space')) {
            $probe_dir = is_dir($backup_dir) ? $backup_dir : dirname($backup_dir);
            if (is_dir($probe_dir)) {
                $free_bytes = @disk_free_space($probe_dir);
            }
        }

        $min_red_bytes = 50 * 1024 * 1024;
        $min_yellow_bytes = 150 * 1024 * 1024;
        $required_bytes = isset($context['required_bytes']) ? max($min_red_bytes, (int) $context['required_bytes']) : $min_red_bytes;

        if ($free_bytes === false || $free_bytes === null || !is_numeric($free_bytes) || (float) $free_bytes < 0) {
            $checks[] = array(
                'id'     => 'disk',
                'label'  => __('Available disk space', 'rosenheinrich-multisite-migrate'),
                'status' => 'green',
                'detail' => __('Disk quota managed by host.', 'rosenheinrich-multisite-migrate'),
                'hint'   => '',
            );
        } else {
            $free_int = (int) $free_bytes;
            $free_mb = round($free_int / (1024 * 1024), 1);
            if ($free_int < $required_bytes) {
                $req_mb = round($required_bytes / (1024 * 1024), 1);
                $checks[] = array(
                    'id'     => 'disk',
                    'label'  => __('Available disk space', 'rosenheinrich-multisite-migrate'),
                    'status' => 'red',
                    'detail' => sprintf(
                        /* translators: 1: free space in MB, 2: required space in MB */
                        __('Insufficient free disk space (%1$s MB available, at least %2$s MB required).', 'rosenheinrich-multisite-migrate'),
                        (string) $free_mb,
                        (string) $req_mb
                    ),
                    'hint'   => __('Delete old archives or free up server disk space before starting.', 'rosenheinrich-multisite-migrate'),
                );
            } elseif ($free_int < $min_yellow_bytes) {
                $checks[] = array(
                    'id'     => 'disk',
                    'label'  => __('Available disk space', 'rosenheinrich-multisite-migrate'),
                    'status' => 'yellow',
                    'detail' => sprintf(
                        /* translators: %s: free space in MB */
                        __('Low free disk space (%s MB available).', 'rosenheinrich-multisite-migrate'),
                        (string) $free_mb
                    ),
                    'hint'   => __('Consider pruning older local backups if your site has a large media library.', 'rosenheinrich-multisite-migrate'),
                );
            } else {
                $checks[] = array(
                    'id'     => 'disk',
                    'label'  => __('Available disk space', 'rosenheinrich-multisite-migrate'),
                    'status' => 'green',
                    'detail' => sprintf(
                        /* translators: %s: free space in MB */
                        __('%s MB free disk space available.', 'rosenheinrich-multisite-migrate'),
                        (string) $free_mb
                    ),
                    'hint'   => '',
                );
            }
        }

        // 3. Archive engine check.
        $has_zip = array_key_exists('has_zip_archive', $context)
            ? (bool) $context['has_zip_archive']
            : (array_key_exists('zip_available', $context)
                ? (bool) $context['zip_available']
                : class_exists('ZipArchive'));
        $has_pclzip = array_key_exists('has_pclzip', $context)
            ? (bool) $context['has_pclzip']
            : (class_exists('PclZip') || (defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/class-pclzip.php')));
        $archive_format = strtolower((string) ($context['archive_format'] ?? ''));

        if (($operation === 'restore' || $operation === 'migration') && $archive_format === 'zip' && !$has_zip && !$has_pclzip) {
            $checks[] = array(
                'id'     => 'archive_engine',
                'label'  => __('Archive engine', 'rosenheinrich-multisite-migrate'),
                'status' => 'red',
                'detail' => __('Neither PHP ZipArchive nor PclZip is available to extract this .zip archive.', 'rosenheinrich-multisite-migrate'),
                'hint'   => __('Enable the PHP zip extension on this server or use a .daf backup archive.', 'rosenheinrich-multisite-migrate'),
            );
        } elseif (!$has_zip) {
            $checks[] = array(
                'id'     => 'archive_engine',
                'label'  => __('Archive engine', 'rosenheinrich-multisite-migrate'),
                'status' => 'green',
                'detail' => __('PHP ZipArchive is unavailable; using the built-in resumable DAF archive engine automatically.', 'rosenheinrich-multisite-migrate'),
                'hint'   => '',
            );
        } else {
            $checks[] = array(
                'id'     => 'archive_engine',
                'label'  => __('Archive engine', 'rosenheinrich-multisite-migrate'),
                'status' => 'green',
                'detail' => __('Resumable DAF and ZipArchive engines are both available.', 'rosenheinrich-multisite-migrate'),
                'hint'   => '',
            );
        }

        // 4. Runtime limits & WP-Cron check.
        $max_exec = array_key_exists('max_execution_time', $context)
            ? (int) $context['max_execution_time']
            : (int) @ini_get('max_execution_time');
        $raw_mem = array_key_exists('memory_limit', $context)
            ? (string) $context['memory_limit']
            : (string) @ini_get('memory_limit');
        $mem_bytes = self::parse_ini_bytes($raw_mem);
        $disable_wp_cron = array_key_exists('disable_wp_cron', $context)
            ? (bool) $context['disable_wp_cron']
            : (array_key_exists('wp_cron_disabled', $context)
                ? (bool) $context['wp_cron_disabled']
                : (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON));

        $low_exec = ($max_exec > 0 && $max_exec < 120);
        $low_mem = ($mem_bytes > 0 && $mem_bytes < 128 * 1024 * 1024);
        // DISABLE_WP_CRON with a working system cron is a healthy setup, not a constraint.
        $system_cron = array_key_exists('system_cron', $context)
            ? (bool) $context['system_cron']
            : (class_exists('Rmmigrate_Hosting_Detection') && Rmmigrate_Hosting_Detection::system_cron_detected($disable_wp_cron));
        $system_cron = $disable_wp_cron && $system_cron;
        $system_cron_hint = __('You can close this tab: the job continues through the system cron and may pause until its next run. Keep the tab open to follow the progress live.', 'rosenheinrich-multisite-migrate');

        if ($system_cron && !$low_exec && !$low_mem) {
            $checks[] = array(
                'id'     => 'runtime',
                'label'  => __('PHP runtime & background worker', 'rosenheinrich-multisite-migrate'),
                'status' => 'green',
                'detail' => __('WP-Cron is triggered by a system cron (DISABLE_WP_CRON is set), so the job keeps running in the background.', 'rosenheinrich-multisite-migrate'),
                'hint'   => $system_cron_hint,
            );
        } elseif ($low_exec || $low_mem || $disable_wp_cron) {
            $runtime_notes = array();
            if ($low_exec) {
                $runtime_notes[] = sprintf(
                    /* translators: %d: max_execution_time in seconds */
                    __('max_execution_time is %ds', 'rosenheinrich-multisite-migrate'),
                    $max_exec
                );
            }
            if ($low_mem) {
                $runtime_notes[] = sprintf(
                    /* translators: %s: memory_limit string */
                    __('memory_limit is %s', 'rosenheinrich-multisite-migrate'),
                    $raw_mem
                );
            }
            if ($disable_wp_cron && !$system_cron) {
                $runtime_notes[] = __('DISABLE_WP_CRON is active', 'rosenheinrich-multisite-migrate');
            }
            $checks[] = array(
                'id'     => 'runtime',
                'label'  => __('PHP runtime & background worker', 'rosenheinrich-multisite-migrate'),
                'status' => 'yellow',
                'detail' => sprintf(
                    /* translators: %s: comma-separated list of constrained runtime settings */
                    __('This server has tight limits (%s). The job runs in small steps that can resume after an interruption.', 'rosenheinrich-multisite-migrate'),
                    implode(', ', $runtime_notes)
                ),
                'hint'   => $system_cron ? $system_cron_hint : __('Keep this browser tab open until the job finishes.', 'rosenheinrich-multisite-migrate'),
            );
        } else {
            $checks[] = array(
                'id'     => 'runtime',
                'label'  => __('PHP runtime & background worker', 'rosenheinrich-multisite-migrate'),
                'status' => 'green',
                'detail' => __('PHP memory, execution time, and worker scheduling meet recommended thresholds.', 'rosenheinrich-multisite-migrate'),
                'hint'   => '',
            );
        }

        // Automatic mode selection.
        $is_multisite = array_key_exists('is_multisite', $context)
            ? (bool) $context['is_multisite']
            : (function_exists('is_multisite') && is_multisite());

        $reasons = array();
        if (!$has_zip) {
            $archive_mode = 'daf';
            $reasons[] = __('The PHP ZIP extension is missing, so the built-in resumable archive format is used', 'rosenheinrich-multisite-migrate');
        } else {
            $archive_mode = class_exists('Rmmigrate_Hosting_Detection')
                ? Rmmigrate_Hosting_Detection::effective_archive_mode()
                : 'daf';
            if (!in_array($archive_mode, array('daf', 'zip'), true)) {
                $archive_mode = 'daf';
            }
            if ($archive_mode === 'zip' && ($low_exec || $low_mem || $is_multisite)) {
                $reasons[] = __('ZIP archives are set in Settings. The resumable archive format copes better with tight limits and large networks', 'rosenheinrich-multisite-migrate');
            }
        }

        $proc_open_ok = array_key_exists('proc_open_available', $context)
            ? (bool) $context['proc_open_available']
            : (class_exists('Rmmigrate_Process') && method_exists('Rmmigrate_Process', 'is_proc_open_available')
                ? Rmmigrate_Process::is_proc_open_available()
                : function_exists('proc_open'));
        $cached_hosting = class_exists('Rmmigrate_Hosting_Detection')
            ? Rmmigrate_Hosting_Detection::get_cached()
            : array();
        $can_mysqldump = array_key_exists('can_use_mysqldump', $context)
            ? (bool) $context['can_use_mysqldump']
            : (array_key_exists('mysqldump_available', $context)
                ? (bool) $context['mysqldump_available']
                : (array_key_exists('mysqldump_ok', $context)
                    ? (bool) $context['mysqldump_ok']
                    : ($proc_open_ok && (
                        (class_exists('Rmmigrate_DB_Dumper') && method_exists('Rmmigrate_DB_Dumper', 'can_use_mysqldump'))
                            ? Rmmigrate_DB_Dumper::can_use_mysqldump()
                            : !empty($cached_hosting['mysqldump_available'])
                    ))));

        if (!$proc_open_ok || !$can_mysqldump) {
            $db_mode = 'pdo';
            $reasons[] = __('The database is exported in small batches through PHP because the mysqldump tool is not available', 'rosenheinrich-multisite-migrate');
        } else {
            $db_mode = 'mysqldump';
        }

        $loopback_ok = array_key_exists('loopback_ok', $context)
            ? (bool) $context['loopback_ok']
            : !empty($cached_hosting['loopback_ok']);

        if ($disable_wp_cron && !$loopback_ok) {
            $kickoff_mode = 'browser';
            $reasons[] = __('This browser tab drives the job because WP-Cron is off and background requests could not be confirmed', 'rosenheinrich-multisite-migrate');
        } else {
            $kickoff_mode = class_exists('Rmmigrate_Hosting_Detection')
                ? Rmmigrate_Hosting_Detection::effective_kickoff_mode()
                : 'browser';
            if (!in_array($kickoff_mode, array('loopback', 'cron', 'browser'), true)) {
                $kickoff_mode = 'browser';
            }
        }

        if (empty($reasons)) {
            $reasons[] = __('Settings match what this server supports', 'rosenheinrich-multisite-migrate');
        }

        if (class_exists('Rmmigrate_Access') && Rmmigrate_Access::is_subsite_scoped_operation()) {
            foreach ($checks as $i => $check) {
                if ($check['status'] === 'red') {
                    $checks[$i]['hint'] = __('This is a server setting. Ask your network administrator to fix it.', 'rosenheinrich-multisite-migrate');
                }
            }
        }

        // Overall status.
        $status = 'green';
        foreach ($checks as $check) {
            if ($check['status'] === 'red') {
                $status = 'red';
                break;
            }
            if ($check['status'] === 'yellow') {
                $status = 'yellow';
            }
        }

        if ($status === 'red') {
            $summary = __('Fix the item marked below before you start.', 'rosenheinrich-multisite-migrate');
        } elseif ($status === 'yellow') {
            $summary = __('You can start. The plugin adapts to this server’s limits automatically.', 'rosenheinrich-multisite-migrate');
        } else {
            $summary = __('All checks passed. The server is ready.', 'rosenheinrich-multisite-migrate');
        }

        return array(
            'operation'        => $operation,
            'status'           => $status,
            'can_proceed'      => ($status !== 'red'),
            'summary'          => $summary,
            'recommended_mode' => array(
                'archive_mode' => $archive_mode,
                'db_mode'      => $db_mode,
                'kickoff_mode' => $kickoff_mode,
                'reason'       => implode('. ', $reasons) . '.',
            ),
            'checks'           => $checks,
        );
    }

    /**
     * Parse shorthand PHP ini byte values (e.g. 128M, 1G) into bytes.
     */
    private static function parse_ini_bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;
        switch ($unit) {
            case 'g':
                return $num * 1024 * 1024 * 1024;
            case 'm':
                return $num * 1024 * 1024;
            case 'k':
                return $num * 1024;
            default:
                return $num;
        }
    }
}
