<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Validator
{
    /**
     * @param array<string,mixed> $args
     * @return true|WP_Error
     */
    public static function validate_backup_start(array $args)
    {
        $issues = array();

        $settings = Rmmigrate_Settings::get();
        $archive_mode = !empty($args['archive_mode']) && in_array($args['archive_mode'], array('zip', 'daf'), true)
            ? $args['archive_mode']
            : ($settings['archive_mode'] ?? 'zip');
        if ($archive_mode !== 'daf' && !class_exists('ZipArchive')) {
            $issues[] = __('ZipArchive PHP extension is required (or switch archive mode to DAF in settings).', 'rosenheinrich-multisite-migrate');
        }

        $dir = Rmmigrate_Plugin::backups_dir();
        $free = @disk_free_space($dir);
        if ($free === false) {
            Rmmigrate_Logger::log('disk_free_space() returned false for ' . $dir . '; skipping disk-space pre-check.');
        }
        $estimate = self::estimate_size($args);
        $buffer = 100 * 1024 * 1024;
        if ($free !== false && $free < ($estimate + $buffer)) {
            $issues[] = sprintf(
                /* translators: 1: required disk space, 2: available disk space. */
                __('Insufficient disk space. Need ~%1$s, have %2$s.', 'rosenheinrich-multisite-migrate'),
                Rmmigrate_Local_Storage::format_bytes($estimate + $buffer),
                Rmmigrate_Local_Storage::format_bytes((int) $free)
            );
        }

        $db_mode = $settings['db_mode'] ?? 'auto';
        if ($db_mode === 'mysqldump' && !Rmmigrate_DB_Dumper::can_use_mysqldump()) {
            $issues[] = __('mysqldump is not available.', 'rosenheinrich-multisite-migrate');
        }

        if ($issues !== array()) {
            return new WP_Error('mm_validation', implode(' ', $issues), array('issues' => $issues));
        }

        return true;
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function scope_from_args(array $args): Rmmigrate_Multisite_Scope
    {
        $resolved = Rmmigrate_Multisite_Scope::resolve_backup_scope(
            $args['scope'] ?? Rmmigrate_Multisite_Scope::SCOPE_NETWORK,
            $args['excluded_blogs'] ?? array(),
            $args['included_blogs'] ?? array()
        );
        if (is_wp_error($resolved)) {
            return new Rmmigrate_Multisite_Scope(
                Rmmigrate_Multisite_Scope::SCOPE_NETWORK,
                (int) ($args['blog_id'] ?? 0),
                array()
            );
        }

        $scope = $resolved['scope'];
        $included = array();
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED) {
            $included = array_values(array_diff(
                Rmmigrate_Multisite_Scope::get_all_site_ids(),
                $resolved['excluded_blogs']
            ));
        }

        return new Rmmigrate_Multisite_Scope(
            $scope,
            (int) ($args['blog_id'] ?? 0),
            $resolved['excluded_blogs'],
            $included,
            self::resolve_table_patterns($args)
        );
    }

    /**
     * @param array<string,mixed> $args
     * @return string[]
     */
    public static function resolve_table_patterns(array $args): array
    {
        $patterns = Rmmigrate_Multisite_Scope::plugin_runtime_table_patterns();
        if (!empty($args['exclude_log_tables'])) {
            $patterns = array_merge($patterns, Rmmigrate_Multisite_Scope::log_table_patterns());
        }
        $raw = $args['excluded_tables'] ?? array();
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode("\n", $raw)));
        }
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                $entry = trim((string) $entry);
                if ($entry !== '') {
                    $patterns[] = $entry;
                }
            }
        }
        return array_values(array_unique($patterns));
    }

    /**
     * @param array<string,mixed> $args
     */
    public static function estimate_size(array $args): int
    {
        $scope = self::scope_from_args($args);
        $db_size = self::estimate_db_size($scope);
        $file_size = self::estimate_files_size($scope);
        return $db_size + $file_size;
    }

    private static function estimate_db_size(Rmmigrate_Multisite_Scope $scope): int
    {
        $tables = $scope->get_tables();
        if ($tables === array()) {
            return 0;
        }

        return Rmmigrate_Snap_DB::sum_table_bytes($tables);
    }

    private static function estimate_files_size(Rmmigrate_Multisite_Scope $scope): int
    {
        $paths = $scope->get_content_paths();
        $total = 0;
        $deadline = microtime(true) + 1.5;
        foreach ($paths as $path) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $total += self::estimate_path_size($path, min(0.25, $remaining));
        }
        return $total;
    }

    /**
     * Fast, cacheable path estimate for backup-start disk preflight.
     * Never walks the whole tree unbounded — that froze admin-ajax on large sites.
     */
    private static function estimate_path_size(string $path, float $budget_sec = 0.25): int
    {
        $normalized = wp_normalize_path($path);
        $cache_key = 'rmmigrate_dir_sz_' . md5($normalized);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['size']) && !empty($cached['complete'])) {
            return max(0, (int) $cached['size']);
        }

        $result = Rmmigrate_Filesystem::directory_size_bounded($normalized, max(0.05, $budget_sec));
        $size = max(0, (int) $result['size']);
        if (!empty($result['complete'])) {
            set_transient($cache_key, array('size' => $size, 'complete' => true), 15 * MINUTE_IN_SECONDS);
            return $size;
        }

        // Incomplete walk: over-estimate so the free-space check stays conservative,
        // but do not cache — next start can finish/refine the walk.
        return max($size * 4, $size + (256 * 1024 * 1024));
    }

    /**
     * Validate backup archive extension and magic bytes.
     *
     * @return array{type:string}|null Null when invalid.
     */
    public static function probe_archive(string $path): ?array
    {
        if (!Rmmigrate_Filesystem::is_readable($path) || Rmmigrate_Filesystem::filesize($path) < 4) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, array('zip', 'daf', 'venc'), true)) {
            return null;
        }

        if ($ext === 'venc') {
            return array('type' => 'venc');
        }

        $head = Rmmigrate_Filesystem::get_contents($path, 0, 8);
        if ($head === false) {
            return null;
        }

        if ($ext === 'daf' && $head === Rmmigrate_Daf_Archiver::MAGIC) {
            return array('type' => 'daf');
        }

        if ($ext === 'zip' && strlen($head) >= 2 && $head[0] === 'P' && $head[1] === 'K') {
            return array('type' => 'zip');
        }

        return null;
    }

    /**
     * @return true|WP_Error
     */
    public static function validate_import_disk_space(string $target_path, int $incoming_bytes = 0)
    {
        $needed = $incoming_bytes > 0 ? $incoming_bytes : (int) @Rmmigrate_Filesystem::filesize($target_path);
        return self::validate_disk_space_for_bytes($needed);
    }

    /**
     * @return true|WP_Error
     */
    public static function validate_restore_disk_space(string $archive_path)
    {
        $needed = self::estimate_extract_size($archive_path);
        return self::validate_disk_space_for_bytes($needed);
    }

    public static function estimate_extract_size(string $archive_path): int
    {
        if (!Rmmigrate_Filesystem::is_readable($archive_path)) {
            return 0;
        }
        $size = (int) Rmmigrate_Filesystem::filesize($archive_path);
        $probe = self::probe_archive($archive_path);
        if ($probe === null) {
            return $size;
        }
        if (($probe['type'] ?? '') === 'zip' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($archive_path) === true) {
                $total = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if (is_array($stat)) {
                        $total += (int) ($stat['size'] ?? 0);
                    }
                }
                $zip->close();
                if ($total > 0) {
                    return $total;
                }
            }
        }
        return $size * 2;
    }

    /**
     * @return true|WP_Error
     */
    public static function validate_disk_space_for_bytes(int $needed_bytes)
    {
        $dir = Rmmigrate_Plugin::backups_dir();
        $free = @disk_free_space($dir);
        if ($free === false) {
            Rmmigrate_Logger::log('disk_free_space() returned false for ' . $dir . '; skipping disk-space check.');
        }
        $buffer = 100 * 1024 * 1024;
        if ($free !== false && $free < ($needed_bytes + $buffer)) {
            return new WP_Error(
                'mm_disk_space',
                sprintf(
                    /* translators: 1: required disk space, 2: available disk space. */
                    __('Insufficient disk space. Need ~%1$s, have %2$s.', 'rosenheinrich-multisite-migrate'),
                    Rmmigrate_Local_Storage::format_bytes($needed_bytes + $buffer),
                    Rmmigrate_Local_Storage::format_bytes((int) $free)
                )
            );
        }
        return true;
    }

    /**
     * Deeper archive validation beyond magic bytes.
     *
     * @return true|WP_Error
     */
    public static function validate_archive_readable(string $path)
    {
        $probe = self::probe_archive($path);
        if ($probe === null) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'daf') {
                $head = Rmmigrate_Filesystem::get_contents($path, 0, 8);
                $legacy = $head === false ? '' : Rmmigrate_Daf_Archiver::detect_legacy_magic((string) $head);
                if ($legacy !== '') {
                    return new WP_Error(
                        'mm_daf_legacy',
                        Rmmigrate_Daf_Archiver::legacy_error_message($legacy)
                    );
                }
            }
            return new WP_Error('mm_invalid_archive', __('Invalid backup archive.', 'rosenheinrich-multisite-migrate'));
        }
        $type = $probe['type'] ?? '';
        if ($type === 'zip') {
            if (!class_exists('ZipArchive')) {
                return new WP_Error('mm_zip', __('ZipArchive PHP extension is required.', 'rosenheinrich-multisite-migrate'));
            }
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return new WP_Error('mm_zip_open', __('Cannot open backup archive — file may be corrupt or incomplete.', 'rosenheinrich-multisite-migrate'));
            }
            if ($zip->locateName('manifest.json') === false && $zip->locateName('database.sql') === false) {
                $zip->close();
                return new WP_Error('mm_zip_contents', __('Backup archive is missing required files.', 'rosenheinrich-multisite-migrate'));
            }
            $zip->close();
        }
        if ($type === 'daf') {
            $head = Rmmigrate_Filesystem::get_contents($path, 0, 8);
            if ($head === false) {
                return new WP_Error('mm_daf_open', __('Cannot open backup archive.', 'rosenheinrich-multisite-migrate'));
            }
            if ((string) $head !== Rmmigrate_Daf_Archiver::MAGIC) {
                $legacy = Rmmigrate_Daf_Archiver::detect_legacy_magic((string) $head);
                if ($legacy !== '') {
                    return new WP_Error(
                        'mm_daf_legacy',
                        Rmmigrate_Daf_Archiver::legacy_error_message($legacy)
                    );
                }
                return new WP_Error('mm_daf', __('Invalid DAF archive — file may be corrupt or incomplete.', 'rosenheinrich-multisite-migrate'));
            }
        }
        return true;
    }
}
