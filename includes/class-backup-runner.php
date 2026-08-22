<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Runner
{
    /** @var int|null Job ID holding the SQL lock in this request. */
    private static $sql_lock_owner = null;

    /** @var int|null Job ID with an acquired worker lock in this request. */
    private static $locked_job_id = null;

    /** @var bool True when this request authenticated via HMAC worker_token. */
    private static $worker_token_auth = false;

    const DB_CHUNK_SEC      = 5;
    const JOB_LOCK_PREFIX   = 'rmmigrate_job_lock_';
    const SQL_LOCK_PREFIX   = 'rmmigrate_sql_worker_lock_';
    const LEASE_TTL         = 90;
    const LEASE_OPTION_PREFIX = 'rmmigrate_worker_lease_';
    const WAIT_BACKOFF_PREFIX = 'rmmigrate_worker_wait_backoff_';

    /** @var array<int,Rmmigrate_Filesystem_Stream> */
    private static $job_lock_handles = array();

    private static $reserved_memory = null;

    public static function worker_budget_sec(): int
    {
        return Rmmigrate_Hosting_Detection::worker_budget_sec();
    }

    /**
     * @return array{done:bool,status:int,percent:int,message:string,error?:string}
     */
    public static function process(int $job_id): array
    {
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            return array('done' => true, 'status' => -1, 'percent' => 0, 'message' => 'Job not found', 'error' => 'not_found');
        }

        if (in_array($job->get_status(), array(
            Rmmigrate_Job::STATUS_COMPLETE,
            Rmmigrate_Job::STATUS_CANCELLED,
            Rmmigrate_Job::STATUS_ERROR,
            Rmmigrate_Job::STATUS_DELETING,
        ), true)) {
            $out = array(
                'done'    => true,
                'status'  => $job->get_status(),
                'percent' => $job->get_percent(),
                'message' => $job->get_progress_message(),
            );
            if ($job->get_status() === Rmmigrate_Job::STATUS_ERROR) {
                $out['error'] = $job->data['error_message'] ?? $job->get_status_label();
            }
            return $out;
        }

        if (!self::acquire_job_lock($job_id)) {
            self::log_lock_wait($job_id);
            self::schedule_continuation_backoff($job_id);
            return array(
                'done'        => false,
                'status'      => $job->get_status(),
                'percent'     => $job->get_percent(),
                'message'     => $job->get_progress_message() !== ''
                    ? $job->get_progress_message()
                    : __('Waiting for backup worker…', 'rosenheinrich-multisite-migrate'),
                'waiting'     => true,
                'lease_fresh' => self::lease_is_fresh($job_id),
            );
        }

        delete_transient(self::WAIT_BACKOFF_PREFIX . $job_id);

        // Rate-limit only real slice starts (after lock), not lock-wait responses.
        if (self::$worker_token_auth) {
            self::$worker_token_auth = false;
            if (!self::increment_worker_rate($job_id)) {
                self::release_job_lock($job_id);
                self::log_worker_auth_failure($job_id, 'rate_limited');
                self::schedule_continuation_backoff($job_id);
                return array(
                    'done'        => false,
                    'status'      => $job->get_status(),
                    'percent'     => $job->get_percent(),
                    'message'     => $job->get_progress_message() !== ''
                        ? $job->get_progress_message()
                        : __('Waiting for backup worker…', 'rosenheinrich-multisite-migrate'),
                    'waiting'     => true,
                    'lease_fresh' => self::lease_is_fresh($job_id),
                );
            }
        }

        self::$locked_job_id = $job_id;
        self::register_process_shutdown();

        @ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            $is_cli_or_cron = (defined('WP_CLI') && WP_CLI)
                || (function_exists('wp_doing_cron') && wp_doing_cron());
            if ($is_cli_or_cron) {
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- CLI/cron workers may run long; ajax path uses a hard ceiling below.
                @set_time_limit(0);
            } else {
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Cap admin-ajax workers under typical proxy kill windows.
                @set_time_limit(max(10, Rmmigrate_Hosting_Detection::worker_budget_sec() + 5));
            }
        }

        Rmmigrate_Logger::for_job($job_id);
        Rmmigrate_Logger::log_job_milestone(
            $job_id,
            'lock_acquired',
            sprintf(
                /* translators: %s: lock mode (file or sql) */
                __('Worker lock acquired (%s).', 'rosenheinrich-multisite-migrate'),
                Rmmigrate_Hosting_Detection::effective_lock_mode()
            ),
            $job->is_restore() ? 'restore' : 'backup',
            'info',
            array(
                'lock_mode' => Rmmigrate_Hosting_Detection::effective_lock_mode(),
                'job_type'  => $job->get_job_type(),
            ),
            5 * MINUTE_IN_SECONDS
        );

        try {
            if ($job->get_status() > Rmmigrate_Job::STATUS_PENDING) {
                Rmmigrate_Local_Storage::ensure_job_work_dir($job, false);
            }

            $result = $job->is_restore()
                ? Rmmigrate_Restore_Runner::process_slice($job)
                : self::process_slice($job);
        } catch (Throwable $e) {
            $job = Rmmigrate_Job::get($job_id) ?? $job;
            if (!in_array($job->get_status(), array(
                Rmmigrate_Job::STATUS_COMPLETE,
                Rmmigrate_Job::STATUS_ERROR,
                Rmmigrate_Job::STATUS_CANCELLED,
            ), true)) {
                Rmmigrate_Logger::log('Error: ' . sanitize_text_field($e->getMessage()));
                $job->set_status(
                    Rmmigrate_Job::STATUS_ERROR,
                    sanitize_text_field($e->getMessage()),
                    Rmmigrate_Error_Codes::from_throwable($e)
                );
            }
            if ($job->is_restore()) {
                Rmmigrate_Restore_Runner::disable_maintenance();
            }
            self::release_process_lock($job_id);
            return array('done' => true, 'status' => -1, 'percent' => 0, 'message' => sanitize_text_field($e->getMessage()), 'error' => sanitize_text_field($e->getMessage()));
        }

        self::release_process_lock($job_id);

        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            return array('done' => true, 'status' => -1, 'percent' => 0, 'message' => 'Job lost');
        }

        if ($job->get_status() === Rmmigrate_Job::STATUS_CANCELLED) {
            Rmmigrate_Job_Cleanup::purge_cancelled_artifacts($job);
        }

        $done = $job->get_status() === Rmmigrate_Job::STATUS_COMPLETE
            || $job->get_status() === Rmmigrate_Job::STATUS_ERROR
            || $job->get_status() === Rmmigrate_Job::STATUS_CANCELLED;

        if ($job->get_status() === Rmmigrate_Job::STATUS_COMPLETE && !$job->is_restore()) {
            Rmmigrate_Retention::schedule_deferred_prune();
        }

        if (!$done) {
            self::schedule_continuation($job_id);
        }

        return array(
            'done'    => $done,
            'status'  => $job->get_status(),
            'percent' => $job->get_percent(),
            'message' => $job->get_progress_message(),
        );
    }

    /**
     * Keep background workers moving without relying on the admin browser tab.
     * Browser kickoff mode stays UI-driven; loopback/auto re-dispatch a non-blocking request.
     */
    private static function schedule_continuation(int $job_id): void
    {
        $mode = Rmmigrate_Hosting_Detection::effective_kickoff_mode();
        if ($mode === 'browser') {
            Rmmigrate_Hosting_Detection::schedule_cron_worker($job_id);
            return;
        }

        if ($mode === 'cron') {
            Rmmigrate_Hosting_Detection::schedule_cron_worker($job_id);
            return;
        }

        self::kick_loopback($job_id);
        Rmmigrate_Hosting_Detection::schedule_cron_worker($job_id);
    }

    /**
     * @return array{message:string}
     */
    private static function process_slice(Rmmigrate_Job $job): array
    {
        if ($job->get_status() === Rmmigrate_Job::STATUS_CANCELLED) {
            Rmmigrate_Job_Cleanup::purge_cancelled_artifacts($job);
            return array('message' => $job->get_status_label());
        }

        self::assert_time_limits($job);

        $steps = new Rmmigrate_Build_Steps($job);
        $step = $steps->current();
        $scope = Rmmigrate_Multisite_Scope::from_job($job);

        if ($job->get_status() === Rmmigrate_Job::STATUS_PENDING) {
            return self::step_init($job, $scope, $steps);
        }

        switch ($step) {
            case Rmmigrate_Build_Steps::STEP_INIT:
                return self::finish_slice($job, self::step_init($job, $scope, $steps));
            case Rmmigrate_Build_Steps::STEP_DATABASE:
                return self::finish_slice($job, self::step_database($job, $scope, $steps));
            case Rmmigrate_Build_Steps::STEP_ARCHIVE:
                return self::finish_slice($job, self::step_archive($job, $scope, $steps));
            case Rmmigrate_Build_Steps::STEP_FINALIZE:
                return self::finish_slice($job, self::step_finalize($job, $steps));
        }

        return self::finish_slice($job, array('message' => $job->get_status_label()));
    }

    /**
     * @param array{message:string} $result
     * @return array{message:string}
     */
    private static function finish_slice(Rmmigrate_Job $job, array $result): array
    {
        Rmmigrate_Engine_Config::apply_throttle();
        return $result;
    }

    private static function assert_time_limits(Rmmigrate_Job $job): void
    {
        $progress = $job->get_progress();
        $started = (int) ($progress['started_at'] ?? 0);
        if ($started <= 0) {
            $started = strtotime((string) ($job->data['created_at'] ?? ''));
        }
        if ($started <= 0) {
            return;
        }

        $elapsed = time() - $started;
        $progress = $job->get_progress();
        $scanning = ($job->get_status() === Rmmigrate_Job::STATUS_ARCHIVING)
            && empty($progress['archive']['file_list_built']);
        $max = Rmmigrate_Engine_Config::max_build_seconds();
        if ($scanning) {
            $max = (int) ($max * 1.5);
        }

        if ($elapsed > $max) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::TIME_LIMIT),
                esc_html(__('Backup build exceeded maximum time limit.', 'rosenheinrich-multisite-migrate'))
            );
        }
    }

    /**
     * @return array{message:string}
     */
    private static function step_init(Rmmigrate_Job $job, Rmmigrate_Multisite_Scope $scope, Rmmigrate_Build_Steps $steps): array
    {
        if ($job->get_status() !== Rmmigrate_Job::STATUS_PENDING) {
            return array('message' => $job->get_progress_message());
        }

        Rmmigrate_Plugin::ensure_backup_root();

        if (!self::check_disk_space()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DISK_SPACE),
                esc_html(__('Insufficient disk space for backup.', 'rosenheinrich-multisite-migrate'))
            );
        }

        $work_dir = Rmmigrate_Local_Storage::ensure_job_work_dir($job, true);
        $work_rel = 'jobs/' . $job->get_id() . '/';

        $slug = Rmmigrate_Multisite_Scope::archive_name_slug($job);

        $job->update_progress(array(
            'step'       => Rmmigrate_Build_Steps::STEP_DATABASE,
            'started_at' => time(),
            'init'       => array(
                'work_dir'        => $work_rel,
                'slug'            => $slug,
                'backup_intent'   => (string) ($job->get_progress()['backup_intent'] ?? 'manual'),
                'include_wp_core' => !empty($job->get_progress()['include_wp_core']),
                'include_wp_core_explicit' => !empty($job->get_progress()['include_wp_core_explicit']),
            ),
        ));
        $job->replace_progress_section('database', array(
            'tables' => $scope->get_tables(),
        ));
        $job->set_status(Rmmigrate_Job::STATUS_INIT);
        $job->set_status(Rmmigrate_Job::STATUS_DUMPING_DB);
        /**
         * Fires when a backup job has finished init and enters the database phase.
         *
         * @since 1.0.6
         * @param Rmmigrate_Job $job Backup job.
         */
        do_action('rmmigrate_backup_init', $job);

        return array('message' => __('Initializing backup', 'rosenheinrich-multisite-migrate'));
    }

    /**
     * @return array{message:string}
     */
    private static function step_database(Rmmigrate_Job $job, Rmmigrate_Multisite_Scope $scope, Rmmigrate_Build_Steps $steps): array
    {
        if ($job->skips_database()) {
            if ($job->get_status() < Rmmigrate_Job::STATUS_ARCHIVING) {
                $work_dir = $job->get_work_dir();
                Rmmigrate_Filesystem::put_contents(trailingslashit($work_dir) . 'database.sql', "-- Skipped (files-only profile)\n-- " . RMMIGRATE_DB_EOF . "\n");
                $job->set_status(Rmmigrate_Job::STATUS_DB_DONE);
                $steps->advance_to(Rmmigrate_Build_Steps::STEP_ARCHIVE);
                $job->set_status(Rmmigrate_Job::STATUS_ARCHIVING);
            }
            return array('message' => $job->get_progress_message());
        }

        $dumper = new Rmmigrate_DB_Dumper($job, $scope);
        $budget = Rmmigrate_Hosting_Detection::worker_budget_sec();
        $done = $dumper->run_slice($budget);

        if ($done) {
            if ($job->claim_status_at_least(Rmmigrate_Job::STATUS_DB_DONE)) {
                $steps->advance_to(Rmmigrate_Build_Steps::STEP_ARCHIVE);
                $job->set_status(Rmmigrate_Job::STATUS_ARCHIVING);
                Rmmigrate_Logger::log('Database dump complete');
            }
            return array('message' => $job->get_progress_message());
        }

        return array('message' => $job->get_progress_message());
    }

    /**
     * @return array{message:string}
     */
    private static function step_archive(Rmmigrate_Job $job, Rmmigrate_Multisite_Scope $scope, Rmmigrate_Build_Steps $steps): array
    {
        if ($job->skips_files()) {
            if ($job->get_status() < Rmmigrate_Job::STATUS_ARCHIVE_DONE) {
                $work_dir = $job->get_work_dir();
                $manifest = Rmmigrate_Manifest::with_core_detection(
                    Rmmigrate_Manifest::build($job, $scope, 0),
                    $job
                );
                $slug = $job->get_progress()['init']['slug'] ?? 'site';
                $ext = Rmmigrate_Hosting_Detection::effective_archive_mode_for_job($job) === 'daf' ? 'daf' : 'zip';
                $name = Rmmigrate_Multisite_Scope::build_archive_file_name($job, $ext);
                Rmmigrate_Manifest::write(trailingslashit($work_dir) . 'manifest.json', $manifest, $name);
                $path = trailingslashit($work_dir) . $name;
                if ($ext === 'zip' && class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                    $zip->addFile(trailingslashit($work_dir) . 'database.sql', 'database.sql');
                    $zip->addFile(trailingslashit($work_dir) . 'manifest.json', 'manifest.json');
                    $zip->close();
                } else {
                    Rmmigrate_Filesystem::copy(trailingslashit($work_dir) . 'database.sql', $path);
                }
                $job->save_fields(array('local_path' => 'jobs/' . $job->get_id() . '/' . $name, 'file_size' => (int) @Rmmigrate_Filesystem::filesize($path)));
                $job->set_status(Rmmigrate_Job::STATUS_ARCHIVE_DONE);
                $steps->advance_to(Rmmigrate_Build_Steps::STEP_FINALIZE);
            }
            return array('message' => $job->get_progress_message());
        }

        $archiver = Rmmigrate_Archive_Factory::create_archiver($job, $scope);
        $done = $archiver->run_slice(self::worker_budget_sec());

        $progress = $job->get_progress();
        $idx = (int) ($progress['archive']['file_index'] ?? 0);
        $total = (int) ($progress['archive']['total_files'] ?? 0);

        if ($done) {
            if ($job->claim_status_at_least(Rmmigrate_Job::STATUS_ARCHIVE_DONE)) {
                $steps->advance_to(Rmmigrate_Build_Steps::STEP_FINALIZE);
                Rmmigrate_Logger::log('Archive complete');
            }
            return array('message' => $job->get_progress_message());
        }

        return array('message' => $job->get_progress_message());
    }

    /**
     * Finalize is sliced across worker requests so hashing / encrypt cannot monopolize PHP-FPM.
     *
     * @return array{message:string}
     */
    private static function step_finalize(Rmmigrate_Job $job, Rmmigrate_Build_Steps $steps): array
    {
        $fin = self::finalize_progress($job);
        $phase = (string) ($fin['phase'] ?? 'hashes');
        $budget = self::worker_budget_sec();

        if ($phase === 'hashes') {
            if (!self::store_file_hashes_slice($job, $budget)) {
                return array('message' => $job->get_progress_message());
            }
            $fin = self::finalize_progress($job);
            $job->update_progress(array(
                'finalize' => array_merge($fin, array('phase' => 'encrypt')),
            ));
            return array('message' => $job->get_progress_message());
        }

        if ($phase === 'encrypt') {
            if (!self::maybe_encrypt_archive_slice($job, $budget)) {
                return array('message' => $job->get_progress_message());
            }
            $fin = self::finalize_progress($job);
            $job->update_progress(array(
                'finalize' => array_merge($fin, array('phase' => 'cleanup')),
            ));
            return array('message' => $job->get_progress_message());
        }

        self::cleanup_work_dir($job);
        $fin = self::finalize_progress($job);
        $job->update_progress(array(
            'finalize' => array_merge($fin, array('phase' => 'done')),
        ));

        if ($job->get_status() !== Rmmigrate_Job::STATUS_COMPLETE) {
            $archive_path = Rmmigrate_Runner::resolve_local_path($job);
            if ($archive_path !== '' && Rmmigrate_Filesystem::exists($archive_path)) {
                $ext = strtolower(pathinfo($archive_path, PATHINFO_EXTENSION));
                if ($ext === 'zip') {
                    $readable = Rmmigrate_Validator::validate_archive_readable($archive_path);
                    if (is_wp_error($readable)) {
                        $wp_code = sanitize_key($readable->get_error_code());
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
                        throw new Rmmigrate_Service_Exception(
                            esc_html($readable->get_error_message()),
                            array(),
                            sanitize_key($wp_code !== '' ? $wp_code : Rmmigrate_Service_Exception::CODE_VALIDATION)
                        );
                    }
                }
            }
            $job->set_status(Rmmigrate_Job::STATUS_COMPLETE);
            $steps->advance_to(Rmmigrate_Build_Steps::STEP_FINALIZE);
            Rmmigrate_Logger::log('Backup complete');
            /**
             * Fires after a backup job completes successfully.
             *
             * @since 1.0.6
             * @param Rmmigrate_Job $job Backup job.
             */
            do_action('rmmigrate_backup_done', $job);
        }
        return array('message' => $job->get_progress_message());
    }

    private static function cleanup_work_dir(Rmmigrate_Job $job): void
    {
        $work_dir = trailingslashit($job->get_work_dir());
        if (!Rmmigrate_Filesystem::is_dir($work_dir)) {
            return;
        }

        $keep_files = array('.worker.lock');
        $progress = $job->get_progress();
        $archive_path = $progress['archive']['zip_path'] ?? $progress['archive']['archive_path'] ?? '';
        if ($archive_path !== '') {
            $keep_files[] = basename($archive_path);
        }
        $rel = $job->data['local_path'] ?? '';
        if ($rel !== '') {
            $keep_files[] = basename($rel);
        }

        $keep_dirs = array();

        try {
            $iterator = new DirectoryIterator($work_dir);
            foreach ($iterator as $file) {
                if ($file->isDot()) {
                    continue;
                }
                $filename = $file->getFilename();
                if ($file->isDir()) {
                    if (in_array($filename, $keep_dirs, true)) {
                        continue;
                    }
                    Rmmigrate_Filesystem::delete_directory($file->getPathname());
                    continue;
                }
                if (in_array($filename, $keep_files, true)) {
                    continue;
                }
                Rmmigrate_Filesystem::delete($file->getPathname());
            }
        } catch (Exception $e) {
            // Ignore directory iteration errors, cleanup is best-effort.
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function finalize_progress(Rmmigrate_Job $job): array
    {
        $progress = $job->get_progress();
        return is_array($progress['finalize'] ?? null) ? $progress['finalize'] : array();
    }

    /**
     * @return bool True when encryption is finished or skipped.
     */
    private static function maybe_encrypt_archive_slice(Rmmigrate_Job $job, int $budget_sec): bool
    {
        if (!Rmmigrate_Archive_Encryption::is_enabled()) {
            return true;
        }
        $local = self::resolve_local_path($job);
        if (!Rmmigrate_Filesystem::exists($local)) {
            return true;
        }
        if (substr($local, -strlen(Rmmigrate_Archive_Encryption::EXT)) === Rmmigrate_Archive_Encryption::EXT) {
            return true;
        }

        $encrypted = Rmmigrate_Archive_Encryption::encrypted_path($local);
        $fin = self::finalize_progress($job);
        $offset = (int) ($fin['encrypt_offset'] ?? 0);
        $result = Rmmigrate_Archive_Encryption::encrypt_slice($local, $encrypted, $offset, max(1, $budget_sec));
        if ($result === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::ENCRYPT_FAILED),
                esc_html__('Could not encrypt backup archive.', 'rosenheinrich-multisite-migrate')
            );
        }

        $job->update_progress(array(
            'finalize' => array_merge($fin, array(
                'phase'          => 'encrypt',
                'encrypt_offset' => (int) $result['plain_offset'],
            )),
        ));

        if (empty($result['done'])) {
            return false;
        }

        Rmmigrate_Filesystem::delete($local);
        $job->save_fields(array(
            'local_path' => 'jobs/' . $job->get_id() . '/' . basename($encrypted),
            'file_size'  => Rmmigrate_Filesystem::filesize($encrypted),
        ));
        return true;
    }

    /**
     * Hash files in budget-sized batches. Partial hashes live in work-dir file_hashes.json.
     *
     * @return bool True when hashes are written into the manifest (or nothing to hash).
     */
    private static function store_file_hashes_slice(Rmmigrate_Job $job, int $budget_sec): bool
    {
        $manifest_path = trailingslashit($job->get_work_dir()) . 'manifest.json';
        if (!Rmmigrate_Filesystem::exists($manifest_path)) {
            return true;
        }
        $raw = Rmmigrate_Filesystem::get_contents($manifest_path);
        $manifest = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($manifest)) {
            return true;
        }

        $files = Rmmigrate_File_List::load($job->get_work_dir());
        $total = count($files);
        $fin = self::finalize_progress($job);
        $index = (int) ($fin['hash_index'] ?? 0);
        $hash_path = trailingslashit($job->get_work_dir()) . 'file_hashes.json';
        $hashes = array();
        if ($index > 0 && Rmmigrate_Filesystem::exists($hash_path)) {
            $hash_raw = Rmmigrate_Filesystem::get_contents($hash_path);
            $decoded = $hash_raw !== false ? json_decode($hash_raw, true) : null;
            if (is_array($decoded)) {
                $hashes = $decoded;
            }
        }

        $start = microtime(true);
        while ($index < $total && (microtime(true) - $start) < $budget_sec) {
            $f = $files[$index];
            $hashes[$f['archive']] = Rmmigrate_Filesystem::file_md5($f['path']);
            $index++;
        }

        $job->update_progress(array(
            'finalize' => array_merge($fin, array(
                'phase'      => 'hashes',
                'hash_index' => $index,
                'hash_total' => $total,
            )),
        ));

        if ($index < $total) {
            Rmmigrate_Filesystem::put_contents($hash_path, wp_json_encode($hashes));
            return false;
        }

        if ($total === 0) {
            $hashes = self::build_file_hashes($job);
        }

        $manifest['file_hashes'] = $hashes;
        $manifest['backup_type'] = $job->get_backup_type();
        $manifest['backup_profile'] = $job->get_backup_profile();
        $manifest['parent_job_id'] = $job->get_parent_job_id();
        $archive_name = self::archive_file_name($job);
        Rmmigrate_Manifest::write($manifest_path, $manifest, $archive_name);
        if (Rmmigrate_Filesystem::exists($hash_path)) {
            Rmmigrate_Filesystem::delete($hash_path);
        }
        return true;
    }

    /**
     * Build md5 hashes for every file in the archive file list.
     *
     * @return array<string,string>
     */
    private static function build_file_hashes(Rmmigrate_Job $job): array
    {
        $files = Rmmigrate_File_List::load($job->get_work_dir());
        $hashes = array();
        foreach ($files as $f) {
            $hashes[$f['archive']] = Rmmigrate_Filesystem::file_md5($f['path']);
        }
        return $hashes;
    }

    private static function archive_file_name(Rmmigrate_Job $job): string
    {
        $progress = $job->get_progress();
        $archive_name = basename($progress['archive']['zip_path'] ?? $progress['archive']['archive_path'] ?? '');
        if ($archive_name !== '' && $archive_name !== '.') {
            return $archive_name;
        }

        $rel = $job->data['local_path'] ?? '';
        if ($rel !== '') {
            return basename($rel);
        }

        return 'backup.zip';
    }

    public static function resolve_local_path(Rmmigrate_Job $job): string
    {
        $rel = $job->data['local_path'] ?? '';
        if ($rel === '') {
            $progress = $job->get_progress();
            $rel = 'jobs/' . $job->get_id() . '/' . basename($progress['archive']['zip_path'] ?? '');
        }
        $rel = ltrim((string) $rel, '/');
        $primary = trailingslashit(Rmmigrate_Plugin::backups_dir()) . $rel;
        if ($rel === '' || Rmmigrate_Filesystem::exists($primary)) {
            return $primary;
        }
        foreach (Rmmigrate_Engine_Config::legacy_local_storage_paths() as $root) {
            if ($root === '') {
                continue;
            }
            $candidate = trailingslashit($root) . $rel;
            if (Rmmigrate_Filesystem::exists($candidate)) {
                return $candidate;
            }
        }

        return $primary;
    }

    public static function kick_worker(int $job_id): void
    {
        $mode = Rmmigrate_Hosting_Detection::effective_kickoff_mode();
        if ($mode === 'browser') {
            set_transient('rmmigrate_browser_kick_' . $job_id, 1, 300);
            Rmmigrate_Hosting_Detection::schedule_cron_worker($job_id);
            self::log_kickoff($job_id, $mode, 'browser_transient');
            return;
        }

        if ($mode === 'loopback') {
            self::log_kickoff($job_id, $mode, 'loopback');
            self::kick_loopback($job_id);
            Rmmigrate_Hosting_Detection::schedule_cron_worker($job_id);
            return;
        }

        if ($mode === 'cron') {
            self::log_kickoff($job_id, $mode, 'cron');
            Rmmigrate_Hosting_Detection::schedule_cron_worker($job_id);
            return;
        }

        // Unknown / legacy "auto": never block on live loopback during kickoff.
        Rmmigrate_Hosting_Detection::schedule_deferred_detection();
        Rmmigrate_Hosting_Detection::schedule_cron_worker($job_id);
        self::log_kickoff($job_id, 'auto', 'cron_fallback');
    }

    public static function note_worker_request(int $job_id, string $source): void
    {
        if ($job_id <= 0) {
            return;
        }

        $job = Rmmigrate_Job::get($job_id);
        $type = ($job !== null && $job->is_restore()) ? 'restore' : 'backup';

        Rmmigrate_Logger::log_job_milestone(
            $job_id,
            'worker_request_' . $source,
            sprintf(
                /* translators: %s: worker source (browser, loopback, cron) */
                __('Worker request received (%s).', 'rosenheinrich-multisite-migrate'),
                $source
            ),
            $type,
            'info',
            array('worker_source' => $source),
            2 * MINUTE_IN_SECONDS
        );
    }

    private static function log_kickoff(int $job_id, string $mode, string $action): void
    {
        Rmmigrate_Logger::log_job_milestone(
            $job_id,
            'kickoff_' . $mode . '_' . $action,
            sprintf(
                /* translators: 1: kickoff mode, 2: action detail */
                __('Worker kickoff: %1$s (%2$s).', 'rosenheinrich-multisite-migrate'),
                $mode,
                $action
            ),
            'backup',
            'info',
            array(
                'kickoff_mode'   => $mode,
                'kickoff_action' => $action,
            ),
            30
        );
    }

    private static function log_lock_wait(int $job_id): void
    {
        $job = Rmmigrate_Job::get($job_id);
        $type = ($job !== null && $job->is_restore()) ? 'restore' : 'backup';

        Rmmigrate_Logger::log_job_milestone(
            $job_id,
            'lock_wait',
            sprintf(
                /* translators: 1: job ID, 2: lock mode */
                __('Worker waiting for lock on job #%1$d (%2$s).', 'rosenheinrich-multisite-migrate'),
                $job_id,
                Rmmigrate_Hosting_Detection::effective_lock_mode()
            ),
            $type,
            'warning',
            array('lock_mode' => Rmmigrate_Hosting_Detection::effective_lock_mode()),
            60
        );
    }

    private static function log_lock_acquire_failure(int $job_id, string $reason): void
    {
        $job = Rmmigrate_Job::get($job_id);
        $type = ($job !== null && $job->is_restore()) ? 'restore' : 'backup';

        Rmmigrate_Logger::log_job_milestone(
            $job_id,
            'lock_acquire_failed_' . $reason,
            sprintf(
                /* translators: 1: job ID, 2: lock mode, 3: failure reason */
                __('Worker could not acquire lock on job #%1$d (%2$s): %3$s.', 'rosenheinrich-multisite-migrate'),
                $job_id,
                Rmmigrate_Hosting_Detection::effective_lock_mode(),
                $reason
            ),
            $type,
            'warning',
            array(
                'lock_mode'   => Rmmigrate_Hosting_Detection::effective_lock_mode(),
                'lock_reason' => $reason,
            ),
            120
        );
    }

    private static function kick_loopback(int $job_id): void
    {
        $url = admin_url('admin-ajax.php');
        $response = wp_remote_post($url, array(
            'timeout'   => 0.01,
            'blocking'  => false,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Must filter core https_local_ssl_verify for local restore SSL.
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body'      => array(
                'action'       => 'rmmigrate_worker',
                'job_id'       => $job_id,
                'worker_token' => self::worker_token($job_id),
            ),
        ));
        if (is_wp_error($response)) {
            Rmmigrate_Logger::log_job_milestone(
                $job_id,
                'loopback_error',
                sprintf(
                    /* translators: %s: transport error message */
                    __('Loopback worker dispatch failed: %s', 'rosenheinrich-multisite-migrate'),
                    $response->get_error_message()
                ),
                'backup',
                'warning',
                array('transport_error' => $response->get_error_message()),
                120
            );
            return;
        }
    }

    public static function worker_token(int $job_id, int $ttl = 300): string
    {
        $expires = time() + max(60, $ttl);
        $site = function_exists('site_url') ? (string) site_url() : '';
        $sig = substr(hash_hmac('sha256', $job_id . '|' . $expires . '|' . $site, wp_salt('auth')), 0, 32);
        return $expires . '.' . $sig;
    }

    /**
     * Unauthenticated nopriv worker is intentional for loopback/cron kickoffs.
     * Mitigations: HMAC worker_token (job_id + expiry + site_url), per-job rate limit, job existence check.
     */
    public static function verify_worker_token(int $job_id, string $token): bool
    {
        if ($job_id <= 0 || $token === '') {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            self::log_worker_auth_failure($job_id, 'malformed_token');
            return false;
        }

        $expires = (int) $parts[0];
        if ($expires < time()) {
            self::log_worker_auth_failure($job_id, 'expired_token');
            return false;
        }

        $site = function_exists('site_url') ? (string) site_url() : '';
        $expected = substr(hash_hmac('sha256', $job_id . '|' . $expires . '|' . $site, wp_salt('auth')), 0, 32);
        if (!hash_equals($expected, $parts[1])) {
            // Legacy tokens (pre site_url binding) remain valid until they expire.
            $legacy = substr(hash_hmac('sha256', $job_id . '|' . $expires, wp_salt('auth')), 0, 32);
            if (!hash_equals($legacy, $parts[1])) {
                self::log_worker_auth_failure($job_id, 'invalid_token_signature');
                return false;
            }
        }

        // Rate limit is applied after lock acquire in process() so waiters do not burn budget.
        self::$worker_token_auth = true;
        return true;
    }

    private static function log_worker_auth_failure(int $job_id, string $reason): void
    {
        if ($job_id <= 0) {
            Rmmigrate_Logger::log_system(
                'Worker authentication failed: ' . $reason,
                array('reason' => $reason),
                'warning'
            );
            return;
        }

        $job = Rmmigrate_Job::get($job_id);
        $type = ($job !== null && $job->is_restore()) ? 'restore' : 'backup';

        Rmmigrate_Logger::log_job_milestone(
            $job_id,
            'worker_auth_' . $reason,
            sprintf(
                /* translators: 1: job ID, 2: failure reason */
                __('Worker authentication rejected for job #%1$d (%2$s).', 'rosenheinrich-multisite-migrate'),
                $job_id,
                $reason
            ),
            $type,
            'warning',
            array('worker_auth_reason' => $reason),
            300
        );
    }

    private static function increment_worker_rate(int $job_id): bool
    {
        $rate_key = 'rmmigrate_worker_rate_' . $job_id;
        $lock_key = 'rmmigrate_worker_rate_lock_' . $job_id;

        if (function_exists('wp_cache_add') && !wp_cache_add($lock_key, 1, 'rmmigrate', 5)) {
            return false;
        }

        $count = (int) get_transient($rate_key);
        if ($count >= 120) {
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($lock_key, 'rmmigrate');
            }
            return false;
        }

        set_transient($rate_key, $count + 1, MINUTE_IN_SECONDS);

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($lock_key, 'rmmigrate');
        }

        return true;
    }

    public static function force_release_lock(int $job_id): void
    {
        self::release_process_lock($job_id);
        self::release_sql_job_lock($job_id);
        self::clear_worker_lease($job_id);
        // Drop the lock file inode so a new worker can proceed after stale recovery
        // even if a dead NFS/peer flock left the path contested.
        if ($job_id > 0 && Rmmigrate_Hosting_Detection::effective_lock_mode() !== 'sql') {
            $path = Rmmigrate_Plugin::backups_dir() . '/jobs/' . $job_id . '/.worker.lock';
            if (Rmmigrate_Filesystem::exists($path)) {
                Rmmigrate_Filesystem::delete($path);
            }
        }
        delete_transient('rmmigrate_milestone_lock_acquired_' . $job_id);
        delete_transient(self::WAIT_BACKOFF_PREFIX . $job_id);
    }

    public static function job_worker_is_idle(int $job_id): bool
    {
        if ($job_id <= 0) {
            return true;
        }
        if (isset(self::$job_lock_handles[$job_id]) || self::$locked_job_id === $job_id) {
            return false;
        }
        // Expired lease is stealable even if flock/SQL claim looks stuck.
        if (!self::lease_is_fresh($job_id)) {
            return true;
        }
        if (!self::acquire_job_lock($job_id)) {
            return false;
        }
        self::release_job_lock($job_id);
        return true;
    }

    public static function lease_option(int $job_id): string
    {
        return self::LEASE_OPTION_PREFIX . $job_id;
    }

    /**
     * Persist a fresh worker lease for the lock holder.
     */
    public static function write_worker_lease(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }
        $now = time();
        update_site_option(
            self::lease_option($job_id),
            array(
                'token'      => wp_generate_password(32, false, false),
                'hb_at'      => $now,
                'expires_at' => $now + self::LEASE_TTL,
            )
        );
    }

    public static function touch_worker_lease(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }
        $option = self::lease_option($job_id);
        $lease  = get_site_option($option, null);
        $now    = time();
        if (!is_array($lease)) {
            self::write_worker_lease($job_id);
        } else {
            $lease['hb_at']      = $now;
            $lease['expires_at'] = $now + self::LEASE_TTL;
            update_site_option($option, $lease);
        }
        if (self::$sql_lock_owner === $job_id || self::$locked_job_id === $job_id) {
            self::refresh_sql_lock($job_id);
        }
    }

    public static function clear_worker_lease(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }
        delete_site_option(self::lease_option($job_id));
    }

    public static function lease_is_fresh(int $job_id): bool
    {
        if ($job_id <= 0) {
            return false;
        }
        $lease = get_site_option(self::lease_option($job_id), null);
        if (!is_array($lease)) {
            return false;
        }
        $now        = time();
        $hb_at      = (int) ($lease['hb_at'] ?? 0);
        $expires_at = (int) ($lease['expires_at'] ?? 0);
        if ($hb_at > 0 && ($now - $hb_at) < self::LEASE_TTL) {
            return true;
        }
        return $expires_at > $now;
    }

    /**
     * Cron continuation with escalating delay while waiting on another worker.
     */
    public static function schedule_continuation_backoff(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }
        $key   = self::WAIT_BACKOFF_PREFIX . $job_id;
        $prev  = (int) get_transient($key);
        $delay = 15;
        if ($prev >= 60) {
            $delay = 60;
        } elseif ($prev >= 30) {
            $delay = 60;
        } elseif ($prev >= 15) {
            $delay = 30;
        }
        set_transient($key, $delay, 5 * MINUTE_IN_SECONDS);
        Rmmigrate_Hosting_Detection::schedule_cron_worker($job_id, $delay);
    }

    private static function register_process_shutdown(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        self::$reserved_memory = str_repeat(' ', 2 * 1024 * 1024); // Reserve 2MB for fatal error handling
        
        register_shutdown_function(static function () {
            self::$reserved_memory = null; // Free the reserved memory immediately

            $job_id = self::$locked_job_id;
            if ($job_id === null) {
                return;
            }

            $error = error_get_last();
            $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
            if (is_array($error) && in_array($error['type'], $fatal_types, true)) {
                $job = Rmmigrate_Job::get($job_id);
                if ($job !== null && !in_array($job->get_status(), array(
                    Rmmigrate_Job::STATUS_COMPLETE,
                    Rmmigrate_Job::STATUS_CANCELLED,
                    Rmmigrate_Job::STATUS_ERROR,
                    Rmmigrate_Job::STATUS_DELETING,
                ), true)) {
                    Rmmigrate_Logger::for_job($job_id);
                    $fatal_message = (string) ($error['message'] ?? 'unknown');
                    $fatal_file = (string) ($error['file'] ?? 'unknown');
                    $fatal_line = (string) ($error['line'] ?? '0');
                    
                    Rmmigrate_Logger::log("Fatal Error: {$fatal_message}");
                    Rmmigrate_Logger::log("Location: {$fatal_file} on line {$fatal_line}");
                    
                    $progress = $job->get_progress();
                    $db = $progress['database'] ?? array();
                    $phase = $db['phase'] ?? 'unknown';
                    $table_index = (int) ($db['table_index'] ?? 0);
                    $tables = $db['tables'] ?? array();
                    $current_table = $tables[$table_index] ?? 'unknown';
                    Rmmigrate_Logger::log("Job State at crash -> Phase: {$phase}, DB Table: {$current_table} (Index: {$table_index})");

                    if (stripos($fatal_message, 'allowed memory size') !== false) {
                        $exception = new RuntimeException(
                            __('PHP ran out of memory during the backup. Use Settings → Database → mysqldump or raise memory_limit.', 'rosenheinrich-multisite-migrate')
                        );
                        $service_code = 'memory_limit';
                    } else {
                        $exception = new RuntimeException(__('Worker stopped unexpectedly. Check the activity log or try again.', 'rosenheinrich-multisite-migrate'));
                        $service_code = 'worker_stopped';
                    }
                    $message = Rmmigrate_User_Error_Messages::format($exception);
                    $job->set_status(Rmmigrate_Job::STATUS_ERROR, $message, $service_code);
                    if ($job->is_restore()) {
                        Rmmigrate_Restore_Runner::disable_maintenance();
                    }
                }
            }

            self::release_process_lock($job_id);
        });
    }

    private static function release_process_lock(int $job_id): void
    {
        self::release_job_lock($job_id);
        if (self::$locked_job_id === $job_id) {
            self::$locked_job_id = null;
        }
    }

    /** @deprecated Use worker_token() */
    public static function cron_secret(): string
    {
        return substr(hash_hmac('sha256', 'rmmigrate_cron', wp_salt('auth')), 0, 32);
    }

    private static function check_disk_space(): bool
    {
        $result = Rmmigrate_Validator::validate_disk_space_for_bytes(100 * 1024 * 1024);
        return !is_wp_error($result);
    }

    private static function acquire_job_lock(int $job_id): bool
    {
        if (isset(self::$job_lock_handles[$job_id]) || self::$sql_lock_owner === $job_id) {
            self::write_worker_lease($job_id);
            return true;
        }

        if (Rmmigrate_Hosting_Detection::effective_lock_mode() === 'sql') {
            if (!self::acquire_sql_job_lock($job_id)) {
                return false;
            }
            self::write_worker_lease($job_id);
            return true;
        }

        $path = Rmmigrate_Plugin::backups_dir() . '/jobs/' . $job_id . '/.worker.lock';
        $dir = dirname($path);
        if (!Rmmigrate_Filesystem::is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $fh = Rmmigrate_Filesystem::open_lock($path, 'c+');
        if ($fh === false) {
            self::log_lock_acquire_failure($job_id, 'lock_file_open_failed');
            return false;
        }

        if (!Rmmigrate_Filesystem::try_exclusive_lock($fh)) {
            Rmmigrate_Filesystem::release_lock($fh);
            return false;
        }

        self::$job_lock_handles[$job_id] = $fh;
        self::write_worker_lease($job_id);
        return true;
    }

    private static function release_job_lock(int $job_id): void
    {
        if (Rmmigrate_Hosting_Detection::effective_lock_mode() === 'sql') {
            self::release_sql_job_lock($job_id);
            self::clear_worker_lease($job_id);
            return;
        }

        if (!isset(self::$job_lock_handles[$job_id])) {
            return;
        }

        Rmmigrate_Filesystem::release_lock(self::$job_lock_handles[$job_id]);
        unset(self::$job_lock_handles[$job_id]);
        self::clear_worker_lease($job_id);
    }

    private static function acquire_sql_job_lock(int $job_id): bool
    {
        if (self::$sql_lock_owner === $job_id) {
            return true;
        }

        $claim_key = 'rmmigrate_sql_lock_claim_' . $job_id;
        if (function_exists('wp_cache_add') && !wp_cache_add($claim_key, 1, 'rmmigrate', 15)) {
            return false;
        }

        $option  = self::SQL_LOCK_PREFIX . $job_id;
        $expires = time() + 120;
        $acquired = false;

        if (add_site_option($option, $expires)) {
            $acquired = true;
        } else {
            $existing = (int) get_site_option($option, 0);
            if ($existing > 0 && $existing < time()) {
                delete_site_option($option);
                $acquired = add_site_option($option, $expires);
            }
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($claim_key, 'rmmigrate');
        }

        if ($acquired) {
            self::$sql_lock_owner = $job_id;
            return true;
        }

        self::log_lock_acquire_failure($job_id, 'sql_lock_unavailable');
        return false;
    }

    private static function release_sql_job_lock(int $job_id): void
    {
        delete_site_option(self::sql_lock_option($job_id));
        if (self::$sql_lock_owner === $job_id) {
            self::$sql_lock_owner = null;
        }
    }

    public static function refresh_sql_lock(int $job_id): void
    {
        if (Rmmigrate_Hosting_Detection::effective_lock_mode() === 'sql') {
            update_site_option(self::sql_lock_option($job_id), time() + 120);
        }
    }

    private static function sql_lock_option(int $job_id): string
    {
        return self::SQL_LOCK_PREFIX . $job_id;
    }

}
