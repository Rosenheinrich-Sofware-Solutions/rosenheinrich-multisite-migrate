<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Zip_Archiver
{
    private const QUEUE_FILE = 'zip-queue.json';
    private const MAX_ADD_FILES_PER_SLICE = 1000;

    /** Uncompressed bytes added per slice; close() rewrites and deflates them in one go. */
    private const MAX_ADD_BYTES_PER_SLICE = 134217728;

    /** Entries at least this large are stored, not deflated, so close() stays short. */
    private const STORE_UNCOMPRESSED_MIN_BYTES = 33554432;

    /** A slice that died this many times at the same file is retried with a single file. */
    private const SLICE_REPEAT_SHRINK = 2;

    /** A slice that died this many times at the same file switches the backup to DAF. */
    private const SLICE_REPEAT_SWITCH_DAF = 3;

    /** @var string|null Unit tests: force ZipArchive::close failure with this status string. */
    public static $unit_test_simulate_zip_close_status = null;

    /** @var Rmmigrate_Job */
    private $job;

    /** @var Rmmigrate_Multisite_Scope */
    private $scope;

    public function __construct(Rmmigrate_Job $job, Rmmigrate_Multisite_Scope $scope)
    {
        $this->job = $job;
        $this->scope = $scope;
    }

    public function build_file_list(int $budget_sec): bool
    {
        if (!Rmmigrate_File_List::build_slice($this->scope, $this->job, $budget_sec)) {
            return false;
        }

        $work_dir = $this->job->get_work_dir();
        $files = Rmmigrate_File_List::load($work_dir);

        if ((int) ($this->job->get_progress()['archive']['file_index'] ?? 0) === 0) {
            $total_bytes = 0;
            foreach ($files as $f) {
                $total_bytes += (int) ($f['size'] ?? 0);
            }
            $sql_path = trailingslashit($work_dir) . 'database.sql';
            if (Rmmigrate_Filesystem::exists($sql_path)) {
                $total_bytes += (int) Rmmigrate_Filesystem::filesize($sql_path);
            }

            $manifest = Rmmigrate_Manifest::build($this->job, $this->scope, count($files), $total_bytes);
            $manifest = Rmmigrate_Manifest::with_core_detection($manifest, $this->job);
            $manifest_path = trailingslashit($work_dir) . 'manifest.json';
            $zip_name = Rmmigrate_Multisite_Scope::build_archive_file_name($this->job, 'zip');
            Rmmigrate_Manifest::write($manifest_path, $manifest, $zip_name);
            $this->job->update_progress(array('manifest' => $manifest));
        }

        $queue = $this->build_queue($work_dir, $files);
        RMMIGRATE_IO::write_json_atomic(
            trailingslashit($work_dir) . self::QUEUE_FILE,
            $queue
        );

        $archive = $this->job->get_progress()['archive'] ?? array();
        $zip_path = $archive['zip_path'] ?? '';
        $zip_name = $archive['zip_name'] ?? '';
        if ($zip_path === '') {
            $zip_name = Rmmigrate_Multisite_Scope::build_archive_file_name($this->job, 'zip');
            $zip_path = trailingslashit($work_dir) . $zip_name;
        }

        $this->job->update_progress(array(
            'archive' => array(
                'file_list_built' => true,
                'total_files'     => count($queue),
                'file_index'      => (int) ($archive['file_index'] ?? 0),
                'zip_path'        => $zip_path,
                'zip_name'        => $zip_name,
            ),
        ));

        return true;
    }

    public function run_slice(int $budget_sec): bool
    {
        if (!class_exists('ZipArchive')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::ZIP_EXTENSION),
                esc_html__('ZipArchive PHP extension is required.', 'rosenheinrich-multisite-migrate')
            );
        }

        $progress = $this->job->get_progress();
        $archive = $progress['archive'] ?? array();

        if (empty($archive['file_list_built'])) {
            if (!$this->build_file_list($budget_sec)) {
                return false;
            }
            $archive = $this->job->get_progress()['archive'] ?? array();
        }

        $work_dir = $this->job->get_work_dir();
        $zip_path = $archive['zip_path'] ?? '';
        if ($zip_path === '') {
            $zip_name = Rmmigrate_Multisite_Scope::build_archive_file_name($this->job, 'zip');
            $zip_path = trailingslashit($work_dir) . $zip_name;
            $this->job->update_progress(array('archive' => array('zip_path' => $zip_path)));
        }
        $file_index = (int) ($archive['file_index'] ?? 0);
        $queue_path = trailingslashit($work_dir) . self::QUEUE_FILE;
        $queue_missing = !Rmmigrate_Filesystem::exists($queue_path);
        $queue = $this->load_queue($work_dir);
        if ($queue_missing && $file_index > 0) {
            $file_index = 0;
            if ($zip_path !== '' && Rmmigrate_Filesystem::exists($zip_path)) {
                Rmmigrate_Filesystem::delete($zip_path);
            }
            $this->job->update_progress(array('archive' => array('file_index' => 0)));
            Rmmigrate_Logger::log('ZIP queue missing — archive reset, restarting from file 0.');
        }
        $total = count($queue);

        if ($total === 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::ARCHIVE_VALIDATION_FAILED),
                esc_html__('Backup archive queue is empty.', 'rosenheinrich-multisite-migrate')
            );
        }

        $resume = false;
        if ($file_index > 0) {
            if (!Rmmigrate_Filesystem::exists($zip_path)) {
                $file_index = 0;
                $this->job->update_progress(array('archive' => array('file_index' => 0)));
                Rmmigrate_Logger::log('ZIP resume rejected — archive missing, restarting from file 0.');
            } else {
                $probe = new ZipArchive();
                if ($probe->open($zip_path) === true) {
                    $probe->close();
                    $resume = true;
                } else {
                    Rmmigrate_Filesystem::delete($zip_path);
                    $file_index = 0;
                    $this->job->update_progress(array('archive' => array('file_index' => 0)));
                    Rmmigrate_Logger::log('ZIP resume rejected — archive unreadable, restarting from file 0.');
                }
            }
        }

        $repeats = $this->register_slice_attempt($file_index);
        $this->cleanup_libzip_temp_files($zip_path);
        if ($repeats >= self::SLICE_REPEAT_SWITCH_DAF) {
            $this->switch_to_daf($zip_path, $work_dir, $file_index, $repeats);
            return false;
        }

        $zip = new ZipArchive();
        $open_flags = $resume ? ZipArchive::CREATE : (ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $open_res = $zip->open($zip_path, $open_flags);

        if ($open_res !== true) {
            $code = Rmmigrate_Error_Codes::ARCHIVE_VALIDATION_FAILED;
            if (defined('ZipArchive::ER_MEMORY') && $open_res === ZipArchive::ER_MEMORY) {
                $code = Rmmigrate_Error_Codes::MEMORY_LIMIT;
            } elseif (defined('ZipArchive::ER_OPEN') && ($open_res === ZipArchive::ER_OPEN || $open_res === ZipArchive::ER_READ)) {
                $code = Rmmigrate_Error_Codes::PERMISSION_DENIED;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key($code),
                esc_html(
                    sprintf(
                        /* translators: %s: ZipArchive error code */
                        __('Cannot open backup archive for writing (code: %s).', 'rosenheinrich-multisite-migrate'),
                        (string) $open_res
                    )
                )
            );
        }

        $start = microtime(true);
        $max_file_bytes = Rmmigrate_Settings::get_max_archive_file_bytes();
        $skipped_large = (int) ($archive['skipped_large'] ?? 0);
        $zip_size = Rmmigrate_Filesystem::exists($zip_path) ? (int) Rmmigrate_Filesystem::filesize($zip_path) : 0;
        $close_budget_sec = min(10.0, max(5.0, 5.0 + ($zip_size / (100 * 1024 * 1024))));
        $add_budget_sec = max(1.0, (float) $budget_sec - $close_budget_sec);
        $max_add = self::MAX_ADD_FILES_PER_SLICE;
        if ($zip_size > 500 * 1024 * 1024) {
            $max_add = 250;
        } elseif ($zip_size > 100 * 1024 * 1024) {
            $max_add = 500;
        }
        $max_slice_bytes = self::slice_max_bytes();
        if ($repeats >= self::SLICE_REPEAT_SHRINK) {
            // The previous attempts at this file never finished; retry with a single file.
            $max_add = 1;
            $max_slice_bytes = 0;
            Rmmigrate_Logger::log(sprintf('ZIP slice at file %d was interrupted %d times - retrying with a single file.', $file_index, $repeats));
        }
        $store_min_bytes = self::store_min_bytes();
        $can_store = method_exists($zip, 'setCompressionName') && defined('ZipArchive::CM_STORE');
        $processed = 0;
        $added = 0;
        $added_bytes = 0;
        while ($file_index < $total
            && ((microtime(true) - $start) < $add_budget_sec || $processed === 0)
            && $added < $max_add
            && ($added_bytes < $max_slice_bytes || $added === 0)
        ) {
            Rmmigrate_Runner::touch_worker_lease($this->job->get_id());
            $item = $queue[$file_index];
            if (Rmmigrate_Filesystem::exists($item['path'])) {
                $size = (int) Rmmigrate_Filesystem::filesize($item['path']);
                $archive_name = (string) ($item['archive'] ?? '');
                if ($max_file_bytes > 0
                    && $size > $max_file_bytes
                    && $archive_name !== 'database.sql'
                    && $archive_name !== 'manifest.json'
                ) {
                    Rmmigrate_Logger::log(sprintf(
                        'Skipped large file: %s (%s)',
                        $item['path'],
                        size_format($size)
                    ));
                    $skipped_large++;
                } else {
                    if (!$zip->addFile($item['path'], $item['archive'])) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        throw Rmmigrate_Job_Exception::raise(
                            sanitize_key(Rmmigrate_Error_Codes::ARCHIVE_VALIDATION_FAILED),
                            esc_html(
                                sprintf(
                                    /* translators: %s: absolute file path */
                                    __('Cannot add a file to the backup archive: %s', 'rosenheinrich-multisite-migrate'),
                                    $item['path']
                                )
                            )
                        );
                    }
                    if ($can_store && $size >= $store_min_bytes) {
                        // Deflating a large entry happens inside close() and can outlast the request.
                        $zip->setCompressionName($item['archive'], ZipArchive::CM_STORE);
                    }
                    $added++;
                    $added_bytes += max(0, $size);
                }
            }
            $file_index++;
            $processed++;
        }

        Rmmigrate_Runner::touch_worker_lease($this->job->get_id());
        $close_ok = true;
        $simulated_status = null;
        if ((defined('MULTISITE_MIGRATE_UNIT_TEST') || defined('RMMIGRATE_UNIT_TEST'))
            && self::$unit_test_simulate_zip_close_status !== null
        ) {
            $simulated_status = self::$unit_test_simulate_zip_close_status;
            self::$unit_test_simulate_zip_close_status = null;
            $close_ok = false;
        } else {
            $close_ok = $zip->close();
        }
        if ($close_ok !== true) {
            $status = $simulated_status !== null
                ? $simulated_status
                : Rmmigrate_Zip_Archive_Errors::describe_close_failure(
                    $zip,
                    $zip_path,
                    $open_res,
                    array(
                        'file_index'  => $file_index,
                        'total_files' => $total,
                        'added'       => $added,
                    )
                );
            $error_code = (stripos($status, 'quota') !== false || stripos($status, 'space') !== false || stripos($status, 'disk_free') !== false)
                ? Rmmigrate_Error_Codes::DISK_SPACE
                : Rmmigrate_Error_Codes::ARCHIVE_VALIDATION_FAILED;
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key($error_code),
                esc_html(
                    sprintf(
                        /* translators: %s: ZipArchive error status string */
                        __('Cannot finalize backup archive. Reason: %s', 'rosenheinrich-multisite-migrate'),
                        $status
                    )
                )
            );
        }

        $zip_bytes = Rmmigrate_Filesystem::exists($zip_path) ? (int) Rmmigrate_Filesystem::filesize($zip_path) : 0;
        $this->job->update_progress(array('archive' => array(
            'file_index'    => $file_index,
            'skipped_large' => $skipped_large,
            'bytes_written' => $zip_bytes,
        ),));

        if ($file_index >= $total) {
            $this->cleanup_libzip_temp_files($zip_path);
            $rel = Rmmigrate_Local_Storage::work_dir_rel($this->job) . basename($zip_path);
            $this->job->save_fields(array(
                'local_path' => $rel,
                'file_size'  => (int) Rmmigrate_Filesystem::filesize($zip_path),
            ));
            return true;
        }

        return false;
    }

    /**
     * Count attempts that start at the same queue position. A slice that finishes always
     * advances file_index, so a repeat means the previous request died (timeout, kill)
     * before it could save progress.
     *
     * @return int Number of earlier attempts at this position.
     */
    private function register_slice_attempt(int $file_index): int
    {
        $archive = $this->job->get_progress()['archive'] ?? array();
        $attempt = is_array($archive['slice_attempt'] ?? null) ? $archive['slice_attempt'] : array();
        $same = isset($attempt['file_index']) && (int) $attempt['file_index'] === $file_index;
        $count = $same ? max(0, (int) ($attempt['count'] ?? 0)) + 1 : 1;
        $this->job->update_progress(array(
            'archive' => array(
                'slice_attempt' => array(
                    'file_index' => $file_index,
                    'count'      => $count,
                ),
            ),
        ));

        return $count - 1;
    }

    /**
     * Remove libzip temp files (<archive>.XXXXXX) left behind when a request died inside close().
     * Only this job's worker touches its archive, so any match is stale.
     */
    private function cleanup_libzip_temp_files(string $zip_path): int
    {
        if ($zip_path === '') {
            return 0;
        }
        $dir = dirname($zip_path);
        $base = basename($zip_path);
        $candidates = glob(trailingslashit($dir) . '*', GLOB_NOSORT);
        if (!is_array($candidates)) {
            return 0;
        }
        $removed = 0;
        $pattern = '/^' . preg_quote($base, '/') . '\\.[A-Za-z0-9]{6}$/';
        foreach ($candidates as $candidate) {
            if (preg_match($pattern, basename($candidate)) !== 1 || !is_file($candidate)) {
                continue;
            }
            if (Rmmigrate_Filesystem::delete($candidate)) {
                $removed++;
            }
        }
        if ($removed > 0) {
            Rmmigrate_Logger::log(sprintf('Removed %d leftover ZIP temp file(s).', $removed));
        }

        return $removed;
    }

    /**
     * Give up on ZIP for this backup and restart the archive step in the resumable DAF format.
     */
    private function switch_to_daf(string $zip_path, string $work_dir, int $file_index, int $repeats): void
    {
        $archive = $this->job->get_progress()['archive'] ?? array();
        if ($zip_path !== '' && Rmmigrate_Filesystem::exists($zip_path)) {
            Rmmigrate_Filesystem::delete($zip_path);
        }
        $queue_path = trailingslashit($work_dir) . self::QUEUE_FILE;
        if (Rmmigrate_Filesystem::exists($queue_path)) {
            Rmmigrate_Filesystem::delete($queue_path);
        }
        $this->job->replace_progress_section('archive', array(
            'skipped_large' => (int) ($archive['skipped_large'] ?? 0),
            'zip_fallback'  => array(
                'file_index' => $file_index,
                'repeats'    => $repeats,
                'at'         => time(),
            ),
        ));
        $this->job->update_progress(array('archive_mode' => 'daf'));
        Rmmigrate_Logger::log(sprintf('ZIP slice at file %d was interrupted %d times - switching this backup to the DAF format.', $file_index, $repeats));
    }

    private static function slice_max_bytes(): int
    {
        return self::MAX_ADD_BYTES_PER_SLICE;
    }

    private static function store_min_bytes(): int
    {
        return self::STORE_UNCOMPRESSED_MIN_BYTES;
    }

    /**
     * @param array<int,array{path:string,size:int,archive:string}> $files
     * @return array<int,array{path:string,archive:string}>
     */
    private function build_queue(string $work_dir, array $files): array
    {
        $queue = array();
        $sql_path = trailingslashit($work_dir) . 'database.sql';
        if (Rmmigrate_Filesystem::exists($sql_path)) {
            $queue[] = array('path' => $sql_path, 'archive' => 'database.sql');
        }
        $manifest_path = trailingslashit($work_dir) . 'manifest.json';
        if (Rmmigrate_Filesystem::exists($manifest_path)) {
            $queue[] = array('path' => $manifest_path, 'archive' => 'manifest.json');
        }

        $wp_config = ABSPATH . 'wp-config.php';
        if (Rmmigrate_Filesystem::exists($wp_config)) {
            $queue[] = array('path' => $wp_config, 'archive' => 'files/wp-config.php');
        }
        $htaccess = ABSPATH . '.htaccess';
        if (Rmmigrate_Filesystem::exists($htaccess)) {
            $queue[] = array('path' => $htaccess, 'archive' => 'files/.htaccess');
        }
        foreach ($files as $f) {
            $queue[] = array('path' => $f['path'], 'archive' => $f['archive']);
        }

        return $queue;
    }

    /**
     * @return array<int,array{path:string,archive:string}>
     */
    private function load_queue(string $work_dir): array
    {
        $path = trailingslashit($work_dir) . self::QUEUE_FILE;
        if (!Rmmigrate_Filesystem::exists($path)) {
            $files = Rmmigrate_File_List::load($work_dir);
            $queue = $this->build_queue($work_dir, $files);
            RMMIGRATE_IO::write_json_atomic($path, $queue);
            return $queue;
        }
        $raw = Rmmigrate_Filesystem::get_contents($path);
        if ($raw === false) {
            return array();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        $queue = array();
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entry_path = isset($entry['path']) ? (string) $entry['path'] : '';
            $entry_archive = isset($entry['archive']) ? (string) $entry['archive'] : '';
            if ($entry_path === '' || $entry_archive === '') {
                continue;
            }
            $queue[] = array(
                'path'    => $entry_path,
                'archive' => $entry_archive,
            );
        }

        return $queue;
    }
}
