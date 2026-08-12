<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_File_List
{
    /**
     * @return array<int,array{path:string,size:int,archive:string}>
     */
    public static function build(Rmmigrate_Multisite_Scope $scope, ?Rmmigrate_Job $job = null): array
    {
        $files = array();
        $content_root = wp_normalize_path(WP_CONTENT_DIR);
        $settings = Rmmigrate_Settings::get();

        if (self::should_include_wp_core($job, $settings)) {
            $files = array_merge($files, self::build_wp_core_files());
        }

        foreach ($scope->get_content_paths() as $base_path) {
            $files = array_merge($files, self::scan_content_path($scope, $base_path, $content_root));
        }

        return self::finalize_list($files, $scope, $job);
    }

    /**
     * Resumable file scan within a time budget. Persists partial results to file-list.json.
     */
    public static function build_slice(Rmmigrate_Multisite_Scope $scope, Rmmigrate_Job $job, int $budget_sec): bool
    {
        $work_dir = $job->get_work_dir();
        $progress = $job->get_progress();
        $scan = $progress['file_scan'] ?? array();

        if (!empty($scan['done'])) {
            return true;
        }

        $files = self::load($work_dir);
        $settings = Rmmigrate_Settings::get();
        $include_core = self::should_include_wp_core($job, $settings);
        $start = microtime(true);

        if (empty($scan['initialized'])) {
            $paths = array();
            foreach ($scope->get_content_paths() as $base_path) {
                $paths[] = wp_normalize_path($base_path);
            }
            $scan = array(
                'initialized'     => true,
                'wp_core_pending' => $include_core,
                'wp_core_done'    => false,
                'wp_core_cursor'  => '',
                'path_index'      => 0,
                'paths'           => $paths,
                'cursor'          => '',
            );
        }

        if (!empty($scan['wp_core_pending']) && empty($scan['wp_core_done'])) {
            // Walk WP core (wp-admin, wp-includes, root files) in a SINGLE pass.
            // The resumable cursor compares path strings (`$path <= $cursor`) and
            // therefore only works if traversal is sorted — but
            // RecursiveDirectoryIterator gives no ordering guarantee. Splitting the
            // core walk across budget slices could silently skip unprocessed files,
            // which is exactly why "Include WordPress core" added little/no size.
            // Core is a bounded set and we are only building a file LIST (no copy),
            // so finishing it in one slice is cheap and correct.
            $result = self::scan_wp_core_slice('', PHP_INT_MAX, microtime(true), $job);
            $files = array_merge($files, $result['files']);
            $scan['wp_core_cursor'] = '';
            $scan['wp_core_done'] = true;
            self::save($work_dir, $files);
            $job->update_progress(array('file_scan' => $scan));

            $core_count = count($result['files']);
            if ($core_count === 0) {
                Rmmigrate_Logger::log('Include WordPress core is ON but the core scan found 0 files under ' . wp_normalize_path(ABSPATH) . ' — check directory permissions.');
            } else {
                Rmmigrate_Logger::log(sprintf('Include WordPress core: added %d core file(s) to the backup file list.', $core_count));
            }
        } elseif (empty($scan['wp_core_pending']) && empty($scan['wp_core_logged'])) {
            $scan['wp_core_logged'] = true;
            $job->update_progress(array('file_scan' => $scan));
            Rmmigrate_Logger::log('Include WordPress core is OFF for this backup — wp-admin/wp-includes/root PHP excluded.');
        }

        $path_index = (int) ($scan['path_index'] ?? 0);
        $paths = is_array($scan['paths'] ?? null) ? $scan['paths'] : array();

        while ($path_index < count($paths) && (microtime(true) - $start) < $budget_sec) {
            $base_path = $paths[$path_index];
            $content_root = wp_normalize_path(WP_CONTENT_DIR);
            $result = self::scan_tree_slice(
                $scope,
                $base_path,
                $content_root,
                (string) ($scan['cursor'] ?? ''),
                $budget_sec,
                $start,
                $job
            );
            $files = array_merge($files, $result['files']);
            $scan['cursor'] = $result['cursor'];

            if ($result['done']) {
                $path_index++;
                $scan['cursor'] = '';
            } else {
                $scan['path_index'] = $path_index;
                self::save($work_dir, $files);
                $job->update_progress(array('file_scan' => $scan));
                return false;
            }
        }

        if ($path_index < count($paths)) {
            $scan['path_index'] = $path_index;
            self::save($work_dir, $files);
            $job->update_progress(array('file_scan' => $scan));
            return false;
        }

        $scan['path_index'] = $path_index;
        $scan['done'] = true;
        $files = self::finalize_list($files, $scope, $job);
        self::save($work_dir, $files);
        $job->update_progress(array('file_scan' => $scan));

        return true;
    }

    /**
     * @param array<int,array{path:string,size:int,archive:string}> $files
     * @return array<int,array{path:string,size:int,archive:string}>
     */
    private static function finalize_list(array $files, Rmmigrate_Multisite_Scope $scope, ?Rmmigrate_Job $job): array
    {
        if ($job !== null) {
            $files = self::filter_by_profile($files, $job);
        }

        $files = self::skip_oversized_files($files, $job);

        usort($files, function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return $files;
    }

    /**
     * Drop files larger than max_archive_file_bytes (default 512 MiB).
     *
     * @param array<int,array{path:string,size:int,archive:string}> $files
     * @return array<int,array{path:string,size:int,archive:string}>
     */
    private static function skip_oversized_files(array $files, ?Rmmigrate_Job $job): array
    {
        $max = Rmmigrate_Settings::get_max_archive_file_bytes();
        if ($max <= 0) {
            return $files;
        }

        $kept = array();
        $skipped = 0;
        foreach ($files as $file) {
            $size = (int) ($file['size'] ?? 0);
            if ($size > $max) {
                $skipped++;
                Rmmigrate_Logger::log(sprintf(
                    'Skipped large file: %s (%s)',
                    (string) ($file['path'] ?? ''),
                    size_format($size)
                ));
                continue;
            }
            $kept[] = $file;
        }

        if ($skipped > 0 && $job !== null) {
            $job->update_progress(array(
                'archive' => array(
                    'skipped_large' => $skipped,
                ),
            ));
        }

        return $kept;
    }

    /**
     * Refresh worker lease + light progress heartbeat about every 30s.
     */
    private static function maybe_scan_heartbeat(?Rmmigrate_Job $job, float &$last_hb): void
    {
        if ($job === null) {
            return;
        }
        $now = microtime(true);
        if (($now - $last_hb) < 30.0) {
            return;
        }
        $last_hb = $now;
        Rmmigrate_Runner::touch_worker_lease($job->get_id());
        $job->update_progress(array(
            'file_scan' => array(
                'heartbeat_at' => time(),
            ),
        ));
    }

    /**
     * @return array<int,array{path:string,size:int,archive:string}>
     */
    private static function scan_content_path(Rmmigrate_Multisite_Scope $scope, string $base_path, string $content_root): array
    {
        $files = array();
        $base_path = wp_normalize_path($base_path);
        if (!Rmmigrate_Filesystem::is_dir($base_path) && !Rmmigrate_Filesystem::is_file($base_path)) {
            return $files;
        }
        if (Rmmigrate_Filesystem::is_file($base_path)) {
            if (!$scope->is_path_excluded($base_path)) {
                $files[] = self::entry($base_path, $content_root);
            }
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $path = wp_normalize_path($file->getPathname());
            if ($scope->is_path_excluded($path)) {
                continue;
            }
            if ($file->isFile()) {
                $files[] = self::entry($path, $content_root);
            }
        }

        return $files;
    }

    /**
     * Absolute paths skipped during ABSPATH (core) walks.
     *
     * @return string[]
     */
    private static function core_scan_exclude_paths(string $abspath = ''): array
    {
        $abspath = wp_normalize_path($abspath !== '' ? $abspath : ABSPATH);
        $exclude = array(
            wp_normalize_path(WP_CONTENT_DIR),
            // Installer state + .mm-snapshot-* leftovers live under ABSPATH, not wp-content.
            // Must match Rmmigrate_Install_Cleanup::STATE_DIR.
            untrailingslashit($abspath) . '/.multisite-migrate-installer',
        );
        if (Rmmigrate_Engine_Config::storage_filter_self()) {
            foreach (Rmmigrate_Engine_Config::self_storage_exclude_paths() as $root) {
                $exclude[] = untrailingslashit(wp_normalize_path($root));
            }
        }

        return $exclude;
    }

    /**
     * @return array{files:array<int,array{path:string,size:int,archive:string}>,cursor:string,done:bool}
     */
    private static function scan_wp_core_slice(string $cursor, int $budget_sec, float $start, ?Rmmigrate_Job $job = null): array
    {
        $files = array();
        $abspath = wp_normalize_path(ABSPATH);
        $exclude = self::core_scan_exclude_paths($abspath);

        if (!Rmmigrate_Filesystem::is_dir($abspath)) {
            return array('files' => $files, 'cursor' => '', 'done' => true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abspath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            // Skip directories that cannot be opened (permissions) instead of
            // throwing UnexpectedValueException and aborting the whole core walk.
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        $last_cursor = $cursor;
        $skipping = $cursor !== '';
        $last_hb = $start;

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            self::maybe_scan_heartbeat($job, $last_hb);
            $path = wp_normalize_path($file->getPathname());
            if ($skipping) {
                if ($path <= $cursor) {
                    continue;
                }
                $skipping = false;
            }

            if ((microtime(true) - $start) >= $budget_sec) {
                return array('files' => $files, 'cursor' => $last_cursor, 'done' => false);
            }

            $skip = false;
            if (Rmmigrate_Engine_Config::storage_filter_self()
                && Rmmigrate_Engine_Config::path_looks_like_archive_storage($path)
            ) {
                $skip = true;
            }
            if (!$skip) {
                foreach ($exclude as $ex) {
                    $ex = untrailingslashit($ex);
                    if ($path === $ex || strpos($path, $ex . '/') === 0) {
                        $skip = true;
                        break;
                    }
                }
            }
            if ($skip || !$file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace($abspath, '', $path), '/');
            $files[] = array(
                'path'    => $path,
                'size'    => (int) Rmmigrate_Filesystem::filesize($path),
                'archive' => 'files/' . $relative,
            );
            $last_cursor = $path;
        }

        return array('files' => $files, 'cursor' => '', 'done' => true);
    }

    /**
     * @return array{files:array<int,array{path:string,size:int,archive:string}>,cursor:string,done:bool}
     */
    private static function scan_tree_slice(
        Rmmigrate_Multisite_Scope $scope,
        string $base_path,
        string $content_root,
        string $cursor,
        int $budget_sec,
        float $start,
        ?Rmmigrate_Job $job = null
    ): array {
        $files = array();
        $base_path = wp_normalize_path($base_path);
        if (!Rmmigrate_Filesystem::is_dir($base_path) && !Rmmigrate_Filesystem::is_file($base_path)) {
            return array('files' => $files, 'cursor' => '', 'done' => true);
        }

        if (Rmmigrate_Filesystem::is_file($base_path)) {
            if (!$scope->is_path_excluded($base_path) && ($cursor === '' || $base_path > $cursor)) {
                $files[] = self::entry($base_path, $content_root);
            }
            return array('files' => $files, 'cursor' => '', 'done' => true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $last_cursor = $cursor;
        $skipping = $cursor !== '';
        $last_hb = $start;

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            self::maybe_scan_heartbeat($job, $last_hb);
            $path = wp_normalize_path($file->getPathname());
            if ($skipping) {
                if ($path <= $cursor) {
                    continue;
                }
                $skipping = false;
            }

            if ((microtime(true) - $start) >= $budget_sec) {
                return array('files' => $files, 'cursor' => $last_cursor, 'done' => false);
            }

            if ($scope->is_path_excluded($path)) {
                continue;
            }
            if ($file->isFile()) {
                $files[] = self::entry($path, $content_root);
                $last_cursor = $path;
            }
        }

        return array('files' => $files, 'cursor' => '', 'done' => true);
    }

    /**
     * @return array<int,array{path:string,size:int,archive:string}>
     */
    public static function build_wp_core_files(): array
    {
        $result = self::scan_wp_core_slice('', PHP_INT_MAX, microtime(true));
        return $result['files'];
    }

    /**
     * @return array{path:string,size:int,archive:string}
     */
    private static function entry(string $absolute, string $content_root): array
    {
        $relative = ltrim(str_replace($content_root, '', wp_normalize_path($absolute)), '/');
        return array(
            'path'    => $absolute,
            'size'    => (int) Rmmigrate_Filesystem::filesize($absolute),
            'archive' => 'files/wp-content/' . $relative,
        );
    }

    /**
     * @param array<int,array{path:string,size:int,archive:string}> $files
     * @return array<int,array{path:string,size:int,archive:string}>
     */
    private static function filter_by_profile(array $files, Rmmigrate_Job $job): array
    {
        $profile = $job->get_backup_profile();
        $content = wp_normalize_path(WP_CONTENT_DIR);
        $roots = array();

        switch ($profile) {
            case 'media':
                $roots[] = Rmmigrate_Engine_Config::uploads_basedir();
                break;
            case 'plugins':
                $roots[] = $content . '/plugins';
                break;
            case 'themes':
                $roots[] = $content . '/themes';
                break;
            case 'custom':
                $progress = $job->get_progress();
                $raw = $progress['custom_paths'] ?? ($job->data['custom_paths'] ?? '[]');
                $paths = json_decode(is_string($raw) ? $raw : '[]', true);
                if (is_array($paths)) {
                    foreach ($paths as $p) {
                        $roots[] = wp_normalize_path((string) $p);
                    }
                }
                break;
            default:
                return $files;
        }

        if ($roots === array()) {
            return $files;
        }

        // When "Include WordPress core" is on (or this is a migration backup),
        // keep core files (wp-admin/, wp-includes/, root PHP — archived under
        // files/ but NOT files/wp-content/) even for a content-subset profile.
        // Otherwise the explicit core flag would be silently dropped here.
        $keep_core = self::should_include_wp_core($job, Rmmigrate_Settings::get());

        return array_values(array_filter($files, static function ($file) use ($roots, $keep_core) {
            $path = wp_normalize_path($file['path']);
            foreach ($roots as $root) {
                if (strpos($path, $root) === 0) {
                    return true;
                }
            }
            if ($keep_core) {
                $archive = wp_normalize_path((string) ($file['archive'] ?? ''));
                if (strpos($archive, 'files/') === 0 && strpos($archive, 'files/wp-content/') !== 0) {
                    return true;
                }
            }
            return false;
        }));
    }

    public static function save(string $work_dir, array $files): void
    {
        Rmmigrate_Filesystem::put_contents(
            trailingslashit($work_dir) . 'file-list.json',
            wp_json_encode($files)
        );
    }

    /**
     * @return array<int,array{path:string,size:int,archive:string}>
     */
    public static function load(string $work_dir): array
    {
        $path = trailingslashit($work_dir) . 'file-list.json';
        if (!Rmmigrate_Filesystem::exists($path)) {
            return array();
        }
        $raw = Rmmigrate_Filesystem::get_contents($path);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function should_include_wp_core(?Rmmigrate_Job $job, array $settings): bool
    {
        if ($job !== null) {
            $progress = $job->get_progress();
            // Explicit per-backup wizard override wins over everything else.
            if (!empty($progress['include_wp_core_explicit']) || !empty($progress['init']['include_wp_core_explicit'])) {
                return !empty($progress['include_wp_core']) || !empty($progress['init']['include_wp_core']);
            }
            $intent = (string) ($progress['backup_intent'] ?? $progress['init']['backup_intent'] ?? '');
            if ($intent === 'migration') {
                return true;
            }
            if (!empty($progress['include_wp_core']) || !empty($progress['init']['include_wp_core'])) {
                return true;
            }
        }
        return !empty($settings['include_wp_core']);
    }
}
