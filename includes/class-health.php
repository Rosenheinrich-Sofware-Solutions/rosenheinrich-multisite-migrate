<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Health
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARN = 'warn';
    public const STATUS_BLOCKER = 'blocker';

    /**
     * @return array<string,mixed>
     */
    public static function get_status(): array
    {
        $dir = Rmmigrate_Plugin::backups_dir();
        Rmmigrate_Plugin::ensure_backup_root();
        $free = @disk_free_space($dir);
        $writable = Rmmigrate_Filesystem::is_writable($dir);
        $settings = Rmmigrate_Settings::get();
        $last_complete = Rmmigrate_Job::list_jobs(array(
            'job_type'     => Rmmigrate_Job::JOB_TYPE_BACKUP,
            'status_group' => 'completed',
            'limit'        => 1,
        ));
        $hosting = Rmmigrate_Hosting_Detection::get_cached();
        if (empty($hosting['detected_at'])) {
            // Never block Health page load with a live loopback probe on shared hosts.
            Rmmigrate_Hosting_Detection::schedule_deferred_detection();
            $hosting = array_merge(array(
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'memory_limit'       => (string) ini_get('memory_limit'),
                'loopback_ok'        => false,
                // Free cold default is browser; resolve without scheduling side effects.
                'effective_kickoff'  => Rmmigrate_Hosting_Detection::settings_kickoff_mode() === 'auto'
                    ? 'browser'
                    : Rmmigrate_Hosting_Detection::settings_kickoff_mode(),
                'effective_archive'  => Rmmigrate_Hosting_Detection::resolve_archive_mode(),
            ), $hosting);
        }

        $db_mode = $settings['db_mode'] ?? 'auto';
        $uses_mysqldump = Rmmigrate_DB_Dumper::can_use_mysqldump();
        $archive_mode = Rmmigrate_Hosting_Detection::effective_archive_mode();
        $max_exec = (int) ($hosting['max_execution_time'] ?? ini_get('max_execution_time'));
        $memory_limit = (string) ($hosting['memory_limit'] ?? ini_get('memory_limit'));
        $memory_bytes = self::memory_limit_bytes($memory_limit);

        if ($db_mode === 'mysqldump' && !$uses_mysqldump) {
            $db_status = self::STATUS_BLOCKER;
            $db_detail = __('mysqldump required but not found', 'rosenheinrich-multisite-migrate');
        } elseif ($db_mode === 'php') {
            $db_status = self::STATUS_OK;
            $db_detail = __('PHP export (by choice)', 'rosenheinrich-multisite-migrate');
        } elseif ($uses_mysqldump) {
            $db_status = self::STATUS_OK;
            $db_detail = __('mysqldump available', 'rosenheinrich-multisite-migrate');
        } else {
            $db_status = self::STATUS_WARN;
            $db_detail = __('PHP export (mysqldump not found)', 'rosenheinrich-multisite-migrate');
        }

        if ($archive_mode !== 'daf' && !class_exists('ZipArchive')) {
            $zip_status = self::STATUS_BLOCKER;
            $zip_detail = __('Missing — required for ZIP archives', 'rosenheinrich-multisite-migrate');
        } elseif (!class_exists('ZipArchive')) {
            $zip_status = self::STATUS_OK;
            $zip_detail = __('Not needed (DAF archive mode)', 'rosenheinrich-multisite-migrate');
        } else {
            $zip_status = self::STATUS_OK;
            $zip_detail = __('Available', 'rosenheinrich-multisite-migrate');
        }

        if ($free === false) {
            $disk_status = self::STATUS_WARN;
            $disk_detail = __('Unknown', 'rosenheinrich-multisite-migrate');
        } elseif ($free < 50 * 1024 * 1024) {
            $disk_status = self::STATUS_BLOCKER;
            $disk_detail = Rmmigrate_Local_Storage::format_bytes((int) $free) . ' ' . __('free — critically low', 'rosenheinrich-multisite-migrate');
        } elseif ($free < 200 * 1024 * 1024) {
            $disk_status = self::STATUS_WARN;
            $disk_detail = Rmmigrate_Local_Storage::format_bytes((int) $free) . ' ' . __('free — low', 'rosenheinrich-multisite-migrate');
        } else {
            $disk_status = self::STATUS_OK;
            $disk_detail = Rmmigrate_Local_Storage::format_bytes((int) $free) . ' ' . __('free', 'rosenheinrich-multisite-migrate');
        }

        if ($max_exec > 0 && $max_exec < 30) {
            $timeout_status = self::STATUS_WARN;
        } else {
            $timeout_status = self::STATUS_OK;
        }

        if ($memory_bytes > 0 && $memory_bytes < 128 * 1024 * 1024) {
            $memory_status = self::STATUS_WARN;
            $memory_detail = $memory_limit . ' ' . __('— may be tight for large sites', 'rosenheinrich-multisite-migrate');
        } else {
            $memory_status = self::STATUS_OK;
            $memory_detail = $memory_limit;
        }

        $checks = array(
            'disk_space' => self::make_check(
                $disk_status,
                __('Disk space', 'rosenheinrich-multisite-migrate'),
                $disk_detail
            ),
            'backup_storage' => self::make_check(
                $writable ? self::STATUS_OK : self::STATUS_BLOCKER,
                __('Local backup storage', 'rosenheinrich-multisite-migrate'),
                $writable
                    ? sprintf(
                        /* translators: %s: backup directory path. */
                        __('Writable on this server (%s)', 'rosenheinrich-multisite-migrate'),
                        wp_normalize_path($dir)
                    )
                    : __('Not writable — backups cannot be saved on this server', 'rosenheinrich-multisite-migrate')
            ),
            'zip_extension' => self::make_check(
                $zip_status,
                __('ZipArchive', 'rosenheinrich-multisite-migrate'),
                $zip_detail
            ),
            'database_export' => self::make_check(
                $db_status,
                __('Database dump method', 'rosenheinrich-multisite-migrate'),
                $db_detail
            ),
            'php_timeout' => self::make_check(
                $timeout_status,
                __('PHP max execution time', 'rosenheinrich-multisite-migrate'),
                $max_exec > 0
                    ? sprintf(
                        /* translators: %s: number of seconds */
                        __('%s seconds', 'rosenheinrich-multisite-migrate'),
                        number_format_i18n($max_exec)
                    )
                    : __('Unlimited', 'rosenheinrich-multisite-migrate')
            ),
            'memory_limit' => self::make_check(
                $memory_status,
                __('PHP memory limit', 'rosenheinrich-multisite-migrate'),
                $memory_detail
            ),
            'loopback' => self::make_check(
                !empty($hosting['loopback_ok']) ? self::STATUS_OK : self::STATUS_WARN,
                __('Background requests', 'rosenheinrich-multisite-migrate'),
                !empty($hosting['loopback_ok'])
                    ? __('Working', 'rosenheinrich-multisite-migrate')
                    : __('Failed — cron or browser fallback will be used', 'rosenheinrich-multisite-migrate')
            ),
            'kickoff_mode' => self::make_check(
                self::STATUS_OK,
                __('Backup engine start', 'rosenheinrich-multisite-migrate'),
                (string) ($hosting['effective_kickoff'] ?? Rmmigrate_Hosting_Detection::effective_kickoff_mode())
            ),
            'lock_mode' => self::make_check(
                self::STATUS_OK,
                __('Backup engine lock', 'rosenheinrich-multisite-migrate'),
                (string) ($hosting['lock_mode'] ?? Rmmigrate_Hosting_Detection::effective_lock_mode())
            ),
            'wp_options_auto_increment' => self::check_wp_options_auto_increment(),
        );

        $ok_count = 0;
        $warn_count = 0;
        $blocker_count = 0;
        foreach ($checks as $key => $c) {
            $next = self::next_action_for_check((string) $key, $c);
            if ($next !== '') {
                $checks[$key]['next_action'] = $next;
            }
            switch ($c['status']) {
                case self::STATUS_OK:
                    $ok_count++;
                    break;
                case self::STATUS_WARN:
                    $warn_count++;
                    break;
                case self::STATUS_BLOCKER:
                    $blocker_count++;
                    break;
            }
        }

        return array(
            'checks'         => $checks,
            'ok_count'       => $ok_count,
            'warn_count'     => $warn_count,
            'blocker_count'  => $blocker_count,
            'total'          => count($checks),
            'last_backup'    => !empty($last_complete[0]) ? $last_complete[0]->data['completed_at'] ?? '' : '',
            'php_version'    => PHP_VERSION,
            'wp_version'     => get_bloginfo('version'),
            'is_multisite'   => is_multisite(),
            'managed_host'   => self::detect_managed_host(),
            'hosting'        => $hosting,
        );
    }

    /**
     * @return array{status:string,ok:bool,label:string,detail:string}
     */
    private static function make_check(string $status, string $label, string $detail): array
    {
        return array(
            'status' => $status,
            'ok'     => $status === self::STATUS_OK,
            'label'  => $label,
            'detail' => $detail,
        );
    }

    /**
     * One recommended next step for a failing/warning health check (Ads pain: timeouts, storage).
     *
     * @param array{status?:string,ok?:bool,detail?:string} $check
     */
    public static function next_action_for_check(string $key, array $check): string
    {
        $status = (string) ($check['status'] ?? self::STATUS_OK);
        if ($status === self::STATUS_OK) {
            return '';
        }

        switch ($key) {
            case 'disk_space':
                return __('Free disk space or prune old archives before the next backup.', 'rosenheinrich-multisite-migrate');
            case 'backup_storage':
                return __('Fix the local storage path permissions under Settings → Storage.', 'rosenheinrich-multisite-migrate');
            case 'zip_extension':
                return __('Enable PHP ZipArchive, or use DAF archive format for chunked backups.', 'rosenheinrich-multisite-migrate');
            case 'database_export':
                return __('Install mysqldump or raise PHP limits so the built-in exporter can finish.', 'rosenheinrich-multisite-migrate');
            case 'php_timeout':
                return __('Use DAF/chunked backups — jobs resume after PHP timeouts on shared hosting.', 'rosenheinrich-multisite-migrate');
            case 'memory_limit':
                return __('Raise memory_limit or exclude large media paths so backups stay within host limits.', 'rosenheinrich-multisite-migrate');
            case 'loopback':
                return __('Leave the page open (browser kickoff) or ensure WP-Cron can run background jobs.', 'rosenheinrich-multisite-migrate');
            case 'wp_options_auto_increment':
                return __('Repair wp_options AUTO_INCREMENT before restore — incomplete DB exports can break restores.', 'rosenheinrich-multisite-migrate');
            default:
                return __('Open Health for details, then retry the backup.', 'rosenheinrich-multisite-migrate');
        }
    }

    /**
     * @param array{status?:string,ok?:bool} $check
     */
    public static function check_css_class(array $check, bool $pill = false): string
    {
        $status = $check['status'] ?? (!empty($check['ok']) ? self::STATUS_OK : self::STATUS_WARN);
        $css = self::status_css_suffix($status);
        return $pill ? 'mm-status-pill mm-status-' . $css : 'mm-status-' . $css;
    }

    /**
     * @param array{status?:string,ok?:bool} $check
     */
    public static function check_status_label(array $check): string
    {
        $status = $check['status'] ?? (!empty($check['ok']) ? self::STATUS_OK : self::STATUS_WARN);
        switch ($status) {
            case self::STATUS_OK:
                return __('OK', 'rosenheinrich-multisite-migrate');
            case self::STATUS_BLOCKER:
                return __('Blocked', 'rosenheinrich-multisite-migrate');
            default:
                return __('Warn', 'rosenheinrich-multisite-migrate');
        }
    }

    public static function summary_text(array $health): string
    {
        $blockers = (int) ($health['blocker_count'] ?? 0);
        $warns = (int) ($health['warn_count'] ?? 0);
        $ok = (int) ($health['ok_count'] ?? 0);
        $total = (int) ($health['total'] ?? 0);

        if ($blockers > 0) {
            return sprintf(
                /* translators: %1$d: number of blocking health checks. */
                _n(
                    '%1$d blocker — backups cannot run until fixed',
                    '%1$d blockers — backups cannot run until fixed',
                    $blockers,
                    'rosenheinrich-multisite-migrate'
                ),
                $blockers
            );
        }
        if ($warns > 0) {
            return sprintf(
                /* translators: 1: passed check count, 2: total check count, 3: warning count. */
                _n(
                    '%1$d of %2$d checks passed (%3$d warning)',
                    '%1$d of %2$d checks passed (%3$d warnings)',
                    $warns,
                    'rosenheinrich-multisite-migrate'
                ),
                $ok,
                $total,
                $warns
            );
        }

        return sprintf(
            /* translators: 1: passed check count, 2: total check count. */
            __('All %1$d of %2$d checks passed', 'rosenheinrich-multisite-migrate'),
            $ok,
            $total
        );
    }

    private static function status_css_suffix(string $status): string
    {
        if ($status === self::STATUS_BLOCKER) {
            return 'error';
        }
        if ($status === self::STATUS_WARN) {
            return 'warn';
        }

        return 'ok';
    }

    private static function memory_limit_bytes(string $limit): int
    {
        if ($limit === '' || $limit === '-1') {
            return -1;
        }
        if (function_exists('wp_convert_hr_to_bytes')) {
            return (int) wp_convert_hr_to_bytes($limit);
        }

        $limit = trim($limit);
        $last = strtolower(substr($limit, -1));
        $value = (int) $limit;
        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * @return array{status:string,label:string,detail:string}
     */
    private static function check_wp_options_auto_increment(): array
    {
        global $wpdb;
        $label = __('wp_options AUTO_INCREMENT', 'rosenheinrich-multisite-migrate');
        if (!is_object($wpdb)) {
            return self::make_check(
                self::STATUS_WARN,
                $label,
                __('Could not verify AUTO_INCREMENT on option_id — imports may fail to insert options', 'rosenheinrich-multisite-migrate')
            );
        }
        $table = isset($wpdb->options) && is_string($wpdb->options) && $wpdb->options !== ''
            ? $wpdb->options
            : ((isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_') . 'options');
        $row = null;
        if (method_exists($wpdb, 'get_row') && method_exists($wpdb, 'prepare')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Health check: one-shot information_schema probe; table from $wpdb->options.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    $table,
                    'option_id'
                ),
                ARRAY_A
            );
        }
        if (!is_array($row)) {
            // Fallback for hosts that block information_schema. %i requires WP 6.2+ (plugin Requires at least).
            $create = null;
            if (method_exists($wpdb, 'get_row') && method_exists($wpdb, 'prepare')) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- SHOW CREATE is read-only; identifier via prepare %i from $wpdb->options.
                $create_row = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N);
                if (is_array($create_row) && isset($create_row[1])) {
                    $create = (string) $create_row[1];
                }
            }
            if (is_string($create) && stripos($create, 'AUTO_INCREMENT') !== false) {
                return self::make_check(self::STATUS_OK, $label, __('Present on option_id', 'rosenheinrich-multisite-migrate'));
            }
            return self::make_check(
                self::STATUS_WARN,
                $label,
                __('Could not verify AUTO_INCREMENT on option_id — imports may fail to insert options', 'rosenheinrich-multisite-migrate')
            );
        }
        $extra = strtolower((string) ($row['EXTRA'] ?? $row['extra'] ?? ''));
        if (strpos($extra, 'auto_increment') !== false) {
            return self::make_check(self::STATUS_OK, $label, __('Present on option_id', 'rosenheinrich-multisite-migrate'));
        }

        return self::make_check(
            self::STATUS_WARN,
            $label,
            __('Missing on option_id — WordPress may fail to insert new options after restore', 'rosenheinrich-multisite-migrate')
        );
    }

    private static function detect_managed_host(): string
    {
        if (defined('WPE_APIKEY') || defined('PWP_NAME')) {
            return __('WP Engine detected — cache directories are auto-excluded.', 'rosenheinrich-multisite-migrate');
        }
        if (defined('KINSTAMU_VERSION') || defined('KINSTA_CACHE_ZONE')) {
            return __('Kinsta detected — object cache paths may be excluded.', 'rosenheinrich-multisite-migrate');
        }
        if (defined('GD_SYSTEM_PLUGIN_DIR') || defined('GD_VIP')) {
            return __('GoDaddy Managed WordPress detected.', 'rosenheinrich-multisite-migrate');
        }
        return __('Standard hosting', 'rosenheinrich-multisite-migrate');
    }
}
