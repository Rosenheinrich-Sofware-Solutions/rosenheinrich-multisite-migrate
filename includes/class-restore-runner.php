<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/shared/class-topology-db-connection.php';

class Rmmigrate_Restore_Runner
{
    /**
     * @return array{message:string}
     */
    public static function process_slice(Rmmigrate_Job $job): array
    {
        if (Rmmigrate_Job_Cleanup::cancel_flag_requested($job->get_id())
            && $job->get_status() !== Rmmigrate_Job::STATUS_CANCELLED) {
            $job->cancel();
            self::disable_maintenance();
            return array('message' => $job->get_status_label());
        }
        if ($job->get_status() === Rmmigrate_Job::STATUS_CANCELLED) {
            self::disable_maintenance();
            return array('message' => $job->get_status_label());
        }

        $steps = new Rmmigrate_Restore_Build_Steps($job);
        $step = $steps->current();

        if ($job->get_status() === Rmmigrate_Job::STATUS_PENDING) {
            return self::step_init($job);
        }

        switch ($step) {
            case Rmmigrate_Restore_Build_Steps::STEP_EXTRACT:
                return self::step_extract($job, $steps);
            case Rmmigrate_Restore_Build_Steps::STEP_DATABASE:
                return self::step_database($job, $steps);
            case Rmmigrate_Restore_Build_Steps::STEP_POST_IMPORT_SR:
                return self::step_post_import_search_replace($job, $steps);
            case Rmmigrate_Restore_Build_Steps::STEP_FILES:
                return self::step_files($job, $steps);
            case Rmmigrate_Restore_Build_Steps::STEP_CLEANUP:
                return self::step_cleanup($job, $steps);
            case Rmmigrate_Restore_Build_Steps::STEP_MIGRATION_FINALIZE:
                return self::step_migration_finalize($job);
        }

        return array('message' => $job->get_status_label());
    }

    /**
     * @return array{message:string}
     */
    private static function step_init(Rmmigrate_Job $job): array
    {
        if (!defined('WP_IMPORTING')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core import flag required during restore/import.
            define('WP_IMPORTING', true);
        }

        Rmmigrate_Plugin::ensure_backup_root();
        $work_dir = Rmmigrate_Local_Storage::ensure_job_work_dir($job, false);
        $work_rel = Rmmigrate_Local_Storage::work_dir_rel($job);

        self::enable_maintenance($job->get_id());

        $source_job = Rmmigrate_Job::get($job->get_source_job_id()) ?? $job;
        if ($source_job->get_backup_type() === 'incremental') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::INCREMENTAL_PRO_ONLY),
                esc_html(__('Incremental backups can only be restored in Multisite Migrate Pro.', 'rosenheinrich-multisite-migrate'))
            );
        }

        $progress = $job->get_progress();
        $source_path = $progress['source']['zip_path'] ?? Rmmigrate_Runner::resolve_local_path($source_job);
        $decrypt = is_array($progress['decrypt'] ?? null) ? $progress['decrypt'] : array();
        if (!empty($decrypt['plain_path']) && empty($decrypt['pending']) && Rmmigrate_Filesystem::exists((string) $decrypt['plain_path'])) {
            $source_path = (string) $decrypt['plain_path'];
        } elseif (substr($source_path, -strlen(Rmmigrate_Archive_Encryption::EXT)) === Rmmigrate_Archive_Encryption::EXT
            || (!empty($decrypt['pending']) && !empty($decrypt['enc_path']))) {
            $enc_path = !empty($decrypt['enc_path']) ? (string) $decrypt['enc_path'] : $source_path;
            $enc_ext = preg_quote(Rmmigrate_Archive_Encryption::EXT, '/');
            $plain_name = preg_replace('/' . $enc_ext . '$/', '', basename($enc_path));
            if ($plain_name === basename($enc_path)) {
                $plain_name = 'archive.zip';
            }
            $plain_path = !empty($decrypt['plain_path'])
                ? (string) $decrypt['plain_path']
                : trailingslashit($work_dir) . $plain_name;
            $passphrase = (string) ($progress['archive_passphrase'] ?? ($job->data['archive_passphrase'] ?? ''));
            if ($passphrase !== '') {
                Rmmigrate_Archive_Encryption::set_runtime_passphrase($passphrase);
            }
            try {
                $offset = (int) ($progress['decrypt_offset'] ?? 0);
                $plain_bytes = (int) ($progress['decrypt_plain_bytes'] ?? -1);
                if ($offset === 0) {
                    $plain_bytes = -1;
                }
                $result = Rmmigrate_Archive_Encryption::decrypt_slice(
                    $enc_path,
                    $plain_path,
                    $offset,
                    Rmmigrate_Runner::remaining_budget_sec(),
                    $plain_bytes
                );
                if ($result === null) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DECRYPT_PASSPHRASE),
                        esc_html(__('Failed to decrypt backup archive. Enter the correct passphrase in Restore.', 'rosenheinrich-multisite-migrate'))
                    );
                }
                $job->update_progress(array(
                    'decrypt_offset'      => (int) $result['byte_offset'],
                    'decrypt_plain_bytes' => (int) ($result['plain_bytes'] ?? 0),
                    'decrypt'             => array(
                        'enc_path'   => $enc_path,
                        'plain_path' => $plain_path,
                        'pending'    => empty($result['done']),
                    ),
                ));
                if (empty($result['done'])) {
                    return array('message' => __('Decrypting backup archive…', 'rosenheinrich-multisite-migrate'));
                }
                $source_path = $plain_path;
            } finally {
                Rmmigrate_Archive_Encryption::clear_runtime_passphrase();
            }
        }

        self::maybe_warn_php_version_mismatch($job, $source_path);

        $extract_plan = self::build_extract_plan($source_job, $source_path);
        if ($extract_plan === array()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::ARCHIVE_MISSING),
                esc_html(__('No backup archives found for restore.', 'rosenheinrich-multisite-migrate'))
            );
        }

        $init_progress = array(
            'init'           => array('work_dir' => $work_rel),
            'source'         => array(
                'zip_path'      => $source_path,
                'source_job_id' => $job->get_source_job_id(),
            ),
            'extract_plan'   => $extract_plan,
            'plan_index'     => 0,
            'extract_engine' => Rmmigrate_Extract_Engine::ENGINE_AUTO,
        );
        if (!empty($decrypt) || isset($progress['decrypt_offset'])) {
            $init_progress['decrypt'] = array(
                'plain_path' => $source_path,
                'pending'    => false,
            );
            $init_progress['decrypt_offset'] = (int) ($job->get_progress()['decrypt_offset'] ?? 0);
        }
        $job->update_progress($init_progress);

        self::assert_topology_allows_restore($job, $source_path);

        $job->set_status(Rmmigrate_Job::STATUS_INIT);
        Rmmigrate_Logger::log('Restore initialized');
        /**
         * Fires after restore init completes (WP_IMPORTING may already be defined).
         *
         * @since 1.0.6
         * @param Rmmigrate_Job $job Restore job.
         */
        do_action('rmmigrate_restore_init', $job);

        return array('message' => __('Initializing restore', 'rosenheinrich-multisite-migrate'));
    }

    /**
     * @return array{message:string}
     */
    private static function step_extract(Rmmigrate_Job $job, Rmmigrate_Restore_Build_Steps $steps): array
    {
        $job->set_status(Rmmigrate_Job::STATUS_ARCHIVING);
        $extractor = new Rmmigrate_Archive_Extractor($job);
        $engine_state = array(
            'extract_throttle' => Rmmigrate_Extract_Engine::throttle_enabled(array()),
        );
        $budget = Rmmigrate_Extract_Engine::budget_sec($engine_state, Rmmigrate_Runner::remaining_budget_sec());
        $done = $extractor->run_slice($budget);

        if ($done) {
            $steps->advance_next();
            Rmmigrate_Logger::log('Extract complete');
            return array('message' => __('Extraction complete', 'rosenheinrich-multisite-migrate'));
        }

        $progress = $job->get_progress();
        $extract = is_array($progress['extract'] ?? null) ? $progress['extract'] : array();
        $archive_size = self::job_archive_size($job);
        $pct = Rmmigrate_Progress::extract_percent($extract, $archive_size);
        if ($pct > 0) {
            /* translators: %d: percent complete */
            return array('message' => sprintf(__('Extracting (%d%%)', 'rosenheinrich-multisite-migrate'), $pct));
        }

        $idx = (int) ($extract['entry_index'] ?? $extract['zip_index'] ?? 0);
        $total = (int) ($extract['zip_total'] ?? count($extract['entries'] ?? array()));
        /* translators: 1: current entry index, 2: total entries */
        return array('message' => sprintf(__('Extracting (%1$d/%2$d)', 'rosenheinrich-multisite-migrate'), $idx, $total));
    }

    /**
     * @return array{message:string}
     */
    private static function step_database(Rmmigrate_Job $job, Rmmigrate_Restore_Build_Steps $steps): array
    {
        $progress = $job->get_progress();
        if (empty($progress['db_prepared'])) {
            self::prepare_database_for_restore($job);
            $job->update_progress(array('db_prepared' => true));
            // Import must continue in this worker tick: quarantine renames live tables and
            // the next HTTP request cannot boot WordPress against missing wp_* tables.
        }

        $job->set_status(Rmmigrate_Job::STATUS_DUMPING_DB);
        $importer = new Rmmigrate_SQL_Importer($job);
        $done = $importer->run_slice(Rmmigrate_Runner::remaining_budget_sec());

        if ($done) {
            self::finish_database_import($job);
            $job->set_status(Rmmigrate_Job::STATUS_DB_DONE);
            self::enable_maintenance($job->get_id());
            $steps->advance_next();
            Rmmigrate_Logger::log('Database import complete');
            return array('message' => __('Database restored', 'rosenheinrich-multisite-migrate'));
        }

        $progress = $job->get_progress();
        $import = is_array($progress['import'] ?? null) ? $progress['import'] : array();
        $import_warnings = is_array($import['warnings'] ?? null) ? $import['warnings'] : array();
        if ($import_warnings !== array()) {
            $first = (string) $import_warnings[0];
            if (count($import_warnings) > 1) {
                return array(
                    'message' => sprintf(
                        /* translators: 1: first warning, 2: additional warning count */
                        __('Importing database (%1$s; +%2$d more warnings)', 'rosenheinrich-multisite-migrate'),
                        $first,
                        count($import_warnings) - 1,
                    ),
                );
            }

            return array(
                'message' => sprintf(
                    /* translators: %s: import warning message */
                    __('Importing database (%s)', 'rosenheinrich-multisite-migrate'),
                    $first,
                ),
            );
        }
        $extract_dir = trailingslashit($job->get_work_dir()) . 'extracted/';
        $sql_path = $extract_dir . 'database.sql';
        $fallback_total = Rmmigrate_Filesystem::exists($sql_path)
            ? (int) Rmmigrate_Filesystem::filesize($sql_path)
            : 0;
        $pct = Rmmigrate_Progress::import_percent($import, $fallback_total);
        if ($pct > 0) {
            /* translators: 1: percent complete */
            return array('message' => sprintf(__('Importing database (%1$d%%)', 'rosenheinrich-multisite-migrate'), $pct));
        }

        return array('message' => __('Importing database', 'rosenheinrich-multisite-migrate'));
    }

    /**
     * @return array{message:string}
     */
    private static function step_post_import_search_replace(Rmmigrate_Job $job, Rmmigrate_Restore_Build_Steps $steps): array
    {
        $scanner = new Rmmigrate_Post_Import_Search_Replace($job);
        $done = $scanner->run_slice(Rmmigrate_Runner::remaining_budget_sec());

        if ($done) {
            $steps->advance_next();
            Rmmigrate_Logger::log('Post-import search-replace complete');
            return array('message' => __('URL search-replace complete', 'rosenheinrich-multisite-migrate'));
        }

        $progress = $job->get_progress()['post_import_sr'] ?? array();
        $updated = (int) ($progress['updated'] ?? 0);
        /* translators: %d: number of updated rows */
        return array('message' => sprintf(__('Scanning tables for URLs (%1$d updated)', 'rosenheinrich-multisite-migrate'), $updated));
    }

    /**
     * @return array{message:string}
     */
    private static function step_files(Rmmigrate_Job $job, Rmmigrate_Restore_Build_Steps $steps): array
    {
        $job->set_status(Rmmigrate_Job::STATUS_ARCHIVING);
        $restorer = new Rmmigrate_File_Restorer($job);
        $done = $restorer->run_slice(Rmmigrate_Runner::remaining_budget_sec());

        if ($done) {
            $job->set_status(Rmmigrate_Job::STATUS_ARCHIVE_DONE);
            $steps->advance_next();
            Rmmigrate_Logger::log('File restore complete');
            return array('message' => __('Files restored', 'rosenheinrich-multisite-migrate'));
        }

        $progress = $job->get_progress();
        $file_restore = is_array($progress['file_restore'] ?? null) ? $progress['file_restore'] : array();
        $pct = Rmmigrate_Progress::files_percent($file_restore);
        if ($pct > 0) {
            /* translators: %d: percent complete */
            return array('message' => sprintf(__('Restoring files (%d%%)', 'rosenheinrich-multisite-migrate'), $pct));
        }

        $idx = (int) ($file_restore['index'] ?? 0);
        $total = count($file_restore['queue'] ?? array());
        /* translators: 1: current file index, 2: total files */
        return array('message' => sprintf(__('Restoring files (%1$d/%2$d)', 'rosenheinrich-multisite-migrate'), $idx, $total));
    }

    /**
     * @return array{message:string}
     */
    private static function step_cleanup(Rmmigrate_Job $job, Rmmigrate_Restore_Build_Steps $steps): array
    {
        if ($job->get_restore_type() !== Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            $extract_dir = trailingslashit($job->get_work_dir()) . 'extracted/';
            if (Rmmigrate_Filesystem::is_dir($extract_dir)) {
                Rmmigrate_Filesystem::delete_directory($extract_dir);
            }
        }

        if ($job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            $steps->advance_next();
            return array('message' => __('Finalizing migration', 'rosenheinrich-multisite-migrate'));
        }

        $progress = $job->get_progress();
        $restore_mode = (string) ($progress['restore_mode'] ?? '');
        if ($restore_mode === 'subsite_on_network') {
            $map = $job->get_migration_map();
            $manifest = self::load_job_manifest($job) ?? array();
            try {
                self::run_topology_finalize($job, $map, $manifest);
            } catch (Throwable $e) {
                global $wpdb;
                $pdo = self::topology_pdo();
                $protect = is_array($progress['user_merge_protect_report'] ?? null)
                    ? $progress['user_merge_protect_report']
                    : array();
                if ($pdo !== null && $protect !== array()) {
                    $global = Rmmigrate_Restore_Topology_User_Merge::resolve_global_prefix(
                        $pdo,
                        (string) ($manifest['db_prefix'] ?? $wpdb->prefix)
                    );
                    Rmmigrate_Restore_Topology_User_Merge::restore_protected_users($pdo, $protect, $global);
                }
                throw $e;
            }
        }

        Rmmigrate_Post_Migration::purge_caches();
        Rmmigrate_Post_Migration::cleanup_bridge_mu_plugin();
        Rmmigrate_Post_Migration::maybe_revalidate_after_migration();
        $job->set_status(Rmmigrate_Job::STATUS_COMPLETE, self::restore_complete_warning($job));
        Rmmigrate_Runner::force_release_lock($job->get_id());
        self::prune_restore_work_artifacts($job);
        self::disable_maintenance();
        flush_rewrite_rules();
        Rmmigrate_Logger::log('Restore complete');
        /**
         * Fires after a restore completes (same-server path).
         *
         * @since 1.0.6
         * @param Rmmigrate_Job $job Restore job.
         */
        do_action('rmmigrate_restore_done', $job);
        return array('message' => __('Restore complete', 'rosenheinrich-multisite-migrate'));
    }

    /**
     * @return array{message:string}
     */
    private static function step_migration_finalize(Rmmigrate_Job $job): array
    {
        $map = $job->get_migration_map();
        $mode = $job->get_restore_mode();
        $progress = $job->get_progress();
        $topology_restore_mode = (string) ($progress['restore_mode'] ?? '');

        if ($topology_restore_mode !== 'subsite_on_network'
            && ($mode === Rmmigrate_Job::RESTORE_MODE_DB || $mode === Rmmigrate_Job::RESTORE_MODE_BOTH)) {
            if (!empty($map['site_url']['new'])) {
                update_option('siteurl', untrailingslashit($map['site_url']['new']));
            }
            if (!empty($map['home_url']['new'])) {
                update_option('home', untrailingslashit($map['home_url']['new']));
            }
        }

        if (is_multisite() && !empty($map['blogs']) && is_array($map['blogs'])) {
            global $wpdb;
            foreach ($map['blogs'] as $blog) {
                $blog_id = (int) ($blog['blog_id'] ?? 0);
                if ($blog_id <= 0) {
                    continue;
                }
                $fields = array();
                if (!empty($blog['new_domain'])) {
                    $fields['domain'] = $blog['new_domain'];
                }
                if (isset($blog['new_path'])) {
                    $fields['path'] = $blog['new_path'];
                }
                if ($fields !== array()) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin: custom plugin tables; values use prepare().
                    $wpdb->update($wpdb->blogs, $fields, array('blog_id' => $blog_id));
                    clean_blog_cache((int) $blog_id);
                    wp_cache_delete('blog-details', 'blog-details');
                }
            }
        }

        $notices = array();
        $manifest = $job->get_progress()['manifest'] ?? array();
        if (is_multisite() && is_array($manifest) && array_key_exists('subdomain_install', $manifest)) {
            $backup_subdomain = !empty($manifest['subdomain_install']);
            $current_subdomain = defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL;
            if ($backup_subdomain !== $current_subdomain) {
                $notices[] = $backup_subdomain
                    ? __('Backup used subdomain multisite; destination uses subdirectory (or vice versa). Update wp-config.php and .htaccess manually.', 'rosenheinrich-multisite-migrate')
                    : __('Backup used subdirectory multisite; destination uses subdomain (or vice versa). Update wp-config.php and .htaccess manually.', 'rosenheinrich-multisite-migrate');
            }
        }
        if ($topology_restore_mode !== 'subsite_on_network'
            && is_multisite() && defined('DOMAIN_CURRENT_SITE') && !empty($map['site_url']['new'])) {
            $new_host = Rmmigrate_Migration_Preflight::network_domain_from_url((string) $map['site_url']['new']);
            if ($new_host !== '' && DOMAIN_CURRENT_SITE !== $new_host) {
                $notices[] = sprintf(
                    "define('DOMAIN_CURRENT_SITE', '%s');",
                    $new_host
                );
            }
        }

        if ($notices !== array()) {
            update_site_option('rmmigrate_migration_notice', array(
                'job_id'  => $job->get_id(),
                'lines'   => $notices,
                'message' => __('Update wp-config.php with these constants, then remove this notice.', 'rosenheinrich-multisite-migrate'),
            ));
        }

        self::run_topology_finalize($job, $map, $manifest);

        if (!class_exists('Rmmigrate_Migration_Preflight', false)) {
            require_once RMMIGRATE_PATH . 'includes/shared/restore-topology/bootstrap.php';
        }
        $post_error = Rmmigrate_Migration_Preflight::validate_after_migration($map, is_array($manifest) ? $manifest : array());
        if ($post_error !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key(Rmmigrate_Error_Codes::VALIDATION),
                esc_html($post_error)
            );
        }

        $extract_dir = trailingslashit($job->get_work_dir()) . 'extracted/';
        if (Rmmigrate_Filesystem::is_dir($extract_dir)) {
            Rmmigrate_Filesystem::delete_directory($extract_dir);
        }

        $job->set_status(Rmmigrate_Job::STATUS_COMPLETE, self::restore_complete_warning($job));
        Rmmigrate_Runner::force_release_lock($job->get_id());
        self::disable_maintenance();
        flush_rewrite_rules();
        Rmmigrate_Post_Migration::purge_caches();
        Rmmigrate_Post_Migration::cleanup_bridge_mu_plugin();
        Rmmigrate_Post_Migration::maybe_revalidate_after_migration();
        self::prune_restore_work_artifacts($job);
        Rmmigrate_Logger::log('Migration restore complete');
        /**
         * Fires after a restore completes (migration path).
         *
         * @since 1.0.6
         * @param Rmmigrate_Job $job Restore job.
         */
        do_action('rmmigrate_restore_done', $job);

        return array('message' => __('Migration complete', 'rosenheinrich-multisite-migrate'));
    }

    private static function prune_restore_work_artifacts(Rmmigrate_Job $job): void
    {
        $dir = trailingslashit($job->get_work_dir());
        foreach (array('restore-queue.json') as $basename) {
            $path = $dir . $basename;
            if (Rmmigrate_Filesystem::exists($path)) {
                Rmmigrate_Filesystem::delete($path);
            }
        }
    }

    public static function get_percent(Rmmigrate_Job $job): int
    {
        $status = $job->get_status();
        if ($status === Rmmigrate_Job::STATUS_COMPLETE) {
            return 100;
        }
        if ($status < 0) {
            return 0;
        }

        $steps = new Rmmigrate_Restore_Build_Steps($job);
        $all = $steps->get_steps();
        $current = $steps->current();
        $idx = array_search($current, $all, true);
        $step_count = max(1, count($all));
        $base = $idx === false ? 0 : (int) floor(((int) $idx / $step_count) * 90);
        $step_span = (int) floor(90 / $step_count);

        $progress = $job->get_progress();
        if ($current === Rmmigrate_Restore_Build_Steps::STEP_EXTRACT) {
            $extract = is_array($progress['extract'] ?? null) ? $progress['extract'] : array();
            $zip_pct = Rmmigrate_Progress::extract_percent($extract, self::job_archive_size($job));
            if ($zip_pct > 0) {
                $base += (int) floor(($zip_pct / 100) * $step_span);
            }
        }
        if ($current === Rmmigrate_Restore_Build_Steps::STEP_DATABASE) {
            $import = is_array($progress['import'] ?? null) ? $progress['import'] : array();
            $extract_dir = trailingslashit($job->get_work_dir()) . 'extracted/';
            $sql_path = $extract_dir . 'database.sql';
            $fallback_total = Rmmigrate_Filesystem::exists($sql_path)
                ? (int) Rmmigrate_Filesystem::filesize($sql_path)
                : 0;
            $import_pct = Rmmigrate_Progress::import_percent($import, $fallback_total);
            if ($import_pct > 0) {
                $base += (int) floor(($import_pct / 100) * $step_span);
            }
        }
        if ($current === Rmmigrate_Restore_Build_Steps::STEP_POST_IMPORT_SR) {
            $sr = is_array($progress['post_import_sr'] ?? null) ? $progress['post_import_sr'] : array();
            $target_index = (int) ($sr['target_index'] ?? 0);
            $target_total = (int) ($sr['target_total'] ?? 0);
            if ($target_total > 0) {
                $base += (int) floor(($target_index / $target_total) * $step_span);
            }
        }
        if ($current === Rmmigrate_Restore_Build_Steps::STEP_FILES) {
            $file_restore = is_array($progress['file_restore'] ?? null) ? $progress['file_restore'] : array();
            $files_pct = Rmmigrate_Progress::files_percent($file_restore);
            if ($files_pct > 0) {
                $base += (int) floor(($files_pct / 100) * $step_span);
            } elseif (!empty($file_restore['queue'])) {
                $i = (int) ($file_restore['index'] ?? 0);
                $t = count($file_restore['queue']);
                if ($t > 0) {
                    $base += (int) floor(($i / $t) * $step_span);
                }
            }
        }

        return min(99, max(0, $base));
    }

    private static function job_archive_size(Rmmigrate_Job $job): int
    {
        $progress = $job->get_progress();
        $path = (string) ($progress['source']['zip_path'] ?? '');
        if ($path !== '' && Rmmigrate_Filesystem::exists($path)) {
            return (int) Rmmigrate_Filesystem::filesize($path);
        }

        return (int) ($job->data['file_size'] ?? 0);
    }

    private static function restore_complete_warning(Rmmigrate_Job $job): ?string
    {
        $progress = $job->get_progress();
        $messages = array();
        $import = is_array($progress['import'] ?? null) ? $progress['import'] : array();
        $import_warnings = is_array($import['warnings'] ?? null) ? $import['warnings'] : array();
        if ($import_warnings !== array()) {
            $messages[] = __('Some database objects were skipped during import. See the activity log for details.', 'rosenheinrich-multisite-migrate');
        }
        $file_restore = is_array($progress['file_restore'] ?? null) ? $progress['file_restore'] : array();
        $skipped = (int) ($file_restore['skipped_count'] ?? 0);
        if ($skipped > 0) {
            $messages[] = __('Some files could not be restored.', 'rosenheinrich-multisite-migrate');
        }
        if ($messages === array()) {
            return null;
        }

        return implode(' ', $messages);
    }

    /**
     * @return array<string,string>
     */
    public static function restore_step_labels(): array
    {
        return array(
            'complete'                                               => __('Restore complete', 'rosenheinrich-multisite-migrate'),
            'error'                                                  => __('Restore failed', 'rosenheinrich-multisite-migrate'),
            'cancelled'                                              => __('Restore cancelled', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_EXTRACT            => __('Extracting archive', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_DATABASE           => __('Importing database', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_POST_IMPORT_SR     => __('Scanning tables for URLs', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_FILES              => __('Restoring files', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_CLEANUP            => __('Cleaning up', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_MIGRATION_FINALIZE => __('Finalizing migration', 'rosenheinrich-multisite-migrate'),
        );
    }

    public static function get_status_step(Rmmigrate_Job $job): string
    {
        if ($job->get_status() === Rmmigrate_Job::STATUS_COMPLETE) {
            return 'complete';
        }
        if ($job->get_status() === Rmmigrate_Job::STATUS_ERROR) {
            return 'error';
        }
        if ($job->get_status() === Rmmigrate_Job::STATUS_CANCELLED) {
            return 'cancelled';
        }

        return (new Rmmigrate_Restore_Build_Steps($job))->current();
    }

    public static function get_status_label(Rmmigrate_Job $job): string
    {
        $step = self::get_status_step($job);
        $labels = self::restore_step_labels();

        return $labels[$step] ?? __('Restoring', 'rosenheinrich-multisite-migrate');
    }

    public static function admin_progress_label(Rmmigrate_Job $job): string
    {
        if (!$job->is_restore()) {
            return $job->get_progress_message();
        }

        return (string) self::with_user_locale(static function () use ($job): string {
            return self::get_status_label($job);
        });
    }

    /**
     * @param callable():mixed $callback
     * @return mixed
     */
    private static function with_user_locale(callable $callback)
    {
        if (!is_admin() || !function_exists('get_user_locale') || !function_exists('switch_to_locale')) {
            return $callback();
        }

        $user_locale = (string) get_user_locale();
        $current = function_exists('determine_locale') ? determine_locale() : get_locale();
        if ($user_locale === '' || $user_locale === $current) {
            return $callback();
        }

        $switched = switch_to_locale($user_locale);
        try {
            return $callback();
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
    }

    const MAINTENANCE_OPTION = 'rmmigrate_restore_maintenance';

    const MAINTENANCE_JOB_OPTION = 'rmmigrate_restore_maintenance_job';
    const MAINTENANCE_MARKER  = 'multisite-migrate-restore';

    public static function register_maintenance_hooks(): void
    {
        add_action('template_redirect', array(__CLASS__, 'maybe_block_frontend'), 0);
    }

    public static function is_maintenance_active(): bool
    {
        return (int) get_site_option(self::MAINTENANCE_OPTION, 0) > 0;
    }

    public static function enable_maintenance(int $job_id = 0): void
    {
        update_site_option(self::MAINTENANCE_OPTION, time());
        if ($job_id > 0) {
            update_site_option(self::MAINTENANCE_JOB_OPTION, $job_id);
        }
        self::remove_owned_maintenance_file();
    }

    public static function recover_stale_maintenance(): void
    {
        $legacy_file = self::has_owned_maintenance_file();
        if (!self::is_maintenance_active() && !$legacy_file) {
            return;
        }

        $active = Rmmigrate_Job::get_active();
        if ($active !== null) {
            if ($legacy_file) {
                self::remove_owned_maintenance_file();
                if (!self::is_maintenance_active()) {
                    update_site_option(self::MAINTENANCE_OPTION, time());
                }
            }
            return;
        }

        self::disable_maintenance();
        $job_id = (int) get_site_option(self::MAINTENANCE_JOB_OPTION, 0);
        if (get_transient('rmmigrate_stale_maint_activity')) {
            return;
        }
        set_transient('rmmigrate_stale_maint_activity', 1, 5 * MINUTE_IN_SECONDS);
        Rmmigrate_Activity_Log::record(
            'restore',
            __('Removed stale maintenance mode left by an interrupted restore.', 'rosenheinrich-multisite-migrate'),
            'warning',
            $job_id > 0 ? array('job_id' => $job_id) : array()
        );
    }

    public static function disable_maintenance(): void
    {
        delete_site_option(self::MAINTENANCE_OPTION);
        delete_site_option(self::MAINTENANCE_JOB_OPTION);
        self::remove_owned_maintenance_file();
    }

    public static function maybe_block_frontend(): void
    {
        if (!self::is_maintenance_active()) {
            return;
        }
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        if (!headers_sent()) {
            header('Retry-After: 600');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            nocache_headers();
        }

        wp_die(
            esc_html__('Briefly unavailable for scheduled maintenance. Check back in a minute.', 'rosenheinrich-multisite-migrate'),
            esc_html__('Maintenance', 'rosenheinrich-multisite-migrate'),
            array(
                'response'  => 503,
            )
        );
    }

    private static function has_owned_maintenance_file(): bool
    {
        $file = ABSPATH . '.maintenance';
        if (!Rmmigrate_Filesystem::exists($file)) {
            return false;
        }
        $contents = Rmmigrate_Filesystem::get_contents($file);
        return $contents !== false && strpos($contents, self::MAINTENANCE_MARKER) !== false;
    }

    private static function remove_owned_maintenance_file(): void
    {
        if (!self::has_owned_maintenance_file()) {
            return;
        }
        Rmmigrate_Filesystem::delete(ABSPATH . '.maintenance');
    }

    private static function assert_topology_allows_restore(Rmmigrate_Job $job, string $archive_path): void
    {
        $manifest = self::read_manifest_from_archive($archive_path);
        if ($manifest === null) {
            return;
        }

        $destination = Rmmigrate_Restore_Topology_Destination::probe_for_plugin();
        $progress = $job->get_progress();
        $state = array(
            'install_mode' => $job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION ? 'standard' : 'overwrite',
            'archive'      => array('has_wp_core_files' => !empty($manifest['has_wp_core_files'])),
            'restore_mode' => (string) ($progress['restore_mode'] ?? ''),
            'site_owr_map' => is_array($progress['site_owr_map'] ?? null) ? $progress['site_owr_map'] : array(),
            'target_blog_id' => (int) ($progress['target_blog_id'] ?? 0),
            'source_blog_id' => (int) ($progress['source_blog_id'] ?? 0),
        );
        if (!empty($progress['full_installation_replace'])) {
            $state['install_mode'] = 'overwrite';
            $state['full_installation_replace'] = true;
            $state['db_action'] = 'empty';
            $state['overwrite_confirmed'] = true;
        }
        $gate = Rmmigrate_Restore_Topology_Compatibility::validate_gates($state, $manifest, $destination, false);
        if ($gate !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::RESTORE_GATE_BLOCKED),
                esc_html((string) $gate['message'])
            );
        }
    }

    /**
     * Target blog for the database prepare step.
     *
     * Subsite-on-network restores fail closed when neither a blog ID nor a target URL was
     * chosen: falling back to the backup's own blog_id would silently overwrite that slot.
     *
     * @param array<string,mixed> $progress
     */
    public static function resolve_prepare_target_blog_id(array $progress, string $restore_mode): int
    {
        $target_blog_id = (int) ($progress['target_blog_id'] ?? 0);
        $map = is_array($progress['site_owr_map'] ?? null) ? $progress['site_owr_map'] : array();
        $entry = is_array($map[0] ?? null) ? $map[0] : array();
        if ($target_blog_id <= 0) {
            $target_blog_id = (int) ($entry['target_blog_id'] ?? 0);
        }
        if ($target_blog_id <= 0 && $restore_mode === 'subsite_on_network'
            && trim((string) ($entry['target_url'] ?? '')) === '') {
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key('topology_subsite_on_network_target_unresolved'),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                __('Subsite-on-network import could not resolve the target blog. Check the blog ID and URL.', 'rosenheinrich-multisite-migrate')
            );
        }

        return max(0, $target_blog_id);
    }

    private static function prepare_database_for_restore(Rmmigrate_Job $job): void
    {
        $manifest = self::load_job_manifest($job);
        if ($manifest === null) {
            return;
        }

        global $wpdb;
        $progress = $job->get_progress();
        $merge_context = array(
            'restore_mode' => (string) ($progress['restore_mode'] ?? ''),
            'user_merge'   => array_key_exists('user_merge', $progress) ? !empty($progress['user_merge']) : true,
            'db_prefix'    => (string) ($manifest['db_prefix'] ?? $wpdb->prefix),
        );
        $pdo = self::topology_pdo();
        if ($pdo !== null && in_array((string) ($merge_context['restore_mode'] ?? ''), Rmmigrate_Restore_Topology_User_Merge::eligible_modes(), true)) {
            $global = Rmmigrate_Restore_Topology_User_Merge::resolve_global_prefix(
                $pdo,
                (string) $merge_context['db_prefix']
            );
            $protect = Rmmigrate_Restore_Topology_User_Merge::protect_destination($pdo, $global);
            $job->update_progress(array('user_merge_protect_report' => $protect));
        }

        $is_subsite = Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest);
        $is_network = Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest);
        $full_replace = !empty($progress['full_installation_replace']);
        $restore_mode = (string) ($progress['restore_mode'] ?? '');
        $needs_table_prepare = $is_subsite
            || $is_network
            || $full_replace
            || $restore_mode === 'subsite_on_network'
            || $restore_mode === 'network_to_subsite';
        if (!$needs_table_prepare) {
            return;
        }

        if ($pdo === null) {
            $pdo = self::topology_pdo();
        }
        if ($pdo === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key(Rmmigrate_Error_Codes::DB_CONNECTION),
                esc_html__(
                    'Database connection failed. Cannot safely prepare tables for this restore.',
                    'rosenheinrich-multisite-migrate'
                )
            );
        }

        if (!class_exists('Rmmigrate_Migration_Preflight', false)) {
            require_once RMMIGRATE_PATH . 'includes/shared/restore-topology/bootstrap.php';
        }
        if ($is_network) {
            $preflight = Rmmigrate_Migration_Preflight::validate_before_database_drop(
                $manifest,
                $job->get_migration_map(),
                (int) ($progress['safety_job_id'] ?? 0)
            );
            if ($preflight !== null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                throw Rmmigrate_Job_Exception::raise(
                    sanitize_key((string) $preflight['code']),
                    esc_html((string) $preflight['message'])
                );
            }
        }

        $db_action = $full_replace
            ? 'empty'
            : (string) ($progress['db_action'] ?? 'tables_only');
        if ($db_action === '') {
            $db_action = 'tables_only';
        }

        if (!class_exists('Rmmigrate_Network_Restore_Quarantine', false)) {
            require_once RMMIGRATE_PATH . 'includes/shared/restore-topology/bootstrap.php';
        }
        $destination = Rmmigrate_Restore_Topology_Destination::probe_for_plugin();
        $use_quarantine = $is_network && Rmmigrate_Migration_Preflight::will_drop_network_tables($manifest);
        $target_blog_id = self::resolve_prepare_target_blog_id($progress, $restore_mode);
        $prepare_context = array(
            'db_prefix'   => (string) ($manifest['db_prefix'] ?? $wpdb->prefix),
            'destination' => $destination,
            'restore_mode'=> $restore_mode,
            'site_owr_map' => is_array($progress['site_owr_map'] ?? null) ? $progress['site_owr_map'] : array(),
            'target_blog_id' => $target_blog_id,
            'destination_blog_id' => $target_blog_id,
            'install_mode'=> 'overwrite',
            'db_action'   => $db_action,
            'full_installation_replace' => $full_replace,
            'overwrite_confirmed' => $full_replace,
            'job_id'      => $job->get_id(),
            'network_quarantine' => array(
                'enabled' => $use_quarantine,
                'token'   => $use_quarantine
                    ? Rmmigrate_Network_Restore_Quarantine::make_token($job->get_id())
                    : '',
            ),
        );
        $prepare_context = Rmmigrate_Restore_Topology_Db_Action::prepare_overwrite(
            $pdo,
            $db_action,
            $manifest,
            $prepare_context
        );
        $progress_updates = array(
            'destination' => $destination,
            'db_prefix'   => (string) ($manifest['db_prefix'] ?? $wpdb->prefix),
        );
        if (!empty($prepare_context['network_quarantine']['live_to_quarantine'])) {
            $progress_updates['network_quarantine'] = $prepare_context['network_quarantine'];
        }
        if (!empty($prepare_context['sql_table_prefix_rewrite'])) {
            $progress_updates['sql_table_prefix_rewrite'] = $prepare_context['sql_table_prefix_rewrite'];
        }
        if (!empty($prepare_context['subsite_on_network_target_blog_id'])) {
            $progress_updates['subsite_on_network_target_blog_id'] = (int) $prepare_context['subsite_on_network_target_blog_id'];
            if ($target_blog_id <= 0) {
                $progress_updates['target_blog_id'] = (int) $prepare_context['subsite_on_network_target_blog_id'];
            }
        }
        if (!empty($prepare_context['subsite_on_network_allocated_blog'])) {
            $progress_updates['subsite_on_network_allocated_blog'] = $prepare_context['subsite_on_network_allocated_blog'];
        }
        if (!empty($prepare_context['site_owr_map']) && is_array($prepare_context['site_owr_map'])) {
            $progress_updates['site_owr_map'] = $prepare_context['site_owr_map'];
        }
        $job->update_progress($progress_updates);
    }

    public static function attempt_network_rollback(Rmmigrate_Job $job): void
    {
        $progress = $job->get_progress();
        $pdo = self::topology_pdo();
        $allocated = is_array($progress['subsite_on_network_allocated_blog'] ?? null)
            ? $progress['subsite_on_network_allocated_blog']
            : array();
        if ($pdo !== null && $allocated !== array()) {
            if (!class_exists('Rmmigrate_Restore_Topology_Network_Import', false)) {
                require_once RMMIGRATE_PATH . 'includes/shared/restore-topology/class-restore-topology-network-import.php';
            }
            Rmmigrate_Restore_Topology_Network_Import::revert_allocated_blog_row($pdo, $allocated);
            $job->update_progress(array('subsite_on_network_allocated_blog' => null));
            $progress = $job->get_progress();
        }
        $protect = is_array($progress['user_merge_protect_report'] ?? null) ? $progress['user_merge_protect_report'] : array();
        if (!empty($protect['protected'])) {
            $manifest = self::load_job_manifest($job);
            global $wpdb;
            $pdo = self::topology_pdo();
            if ($manifest !== null && $pdo !== null) {
                $global = Rmmigrate_Restore_Topology_User_Merge::resolve_global_prefix(
                    $pdo,
                    (string) ($manifest['db_prefix'] ?? $wpdb->prefix)
                );
                Rmmigrate_Restore_Topology_User_Merge::restore_protected_users($pdo, $protect, $global);
            }
        }
        if (!Rmmigrate_Network_Restore_Quarantine::progress_has_quarantine($progress)) {
            return;
        }
        if (!class_exists('Rmmigrate_Network_Restore_Orchestration', false)) {
            require_once RMMIGRATE_PATH . 'includes/shared/restore-topology/bootstrap.php';
        }
        $manifest = self::load_job_manifest($job);
        $pdo = self::topology_pdo();
        if ($manifest === null || $pdo === null) {
            return;
        }
        $report = Rmmigrate_Network_Restore_Orchestration::rollback_failed_import($pdo, $manifest, $progress);
        $quarantine = is_array($progress['network_quarantine'] ?? null) ? $progress['network_quarantine'] : array();
        $quarantine['rolled_back'] = true;
        $job->update_progress(array(
            'network_quarantine'      => $quarantine,
            'network_rollback_report' => $report,
        ));
    }

    private static function finish_database_import(Rmmigrate_Job $job): void
    {
        if (!class_exists('Rmmigrate_Network_Restore_Orchestration', false)) {
            require_once RMMIGRATE_PATH . 'includes/shared/restore-topology/bootstrap.php';
        }
        $manifest = self::load_job_manifest($job);
        $pdo = self::topology_pdo();
        if ($manifest === null || $pdo === null) {
            return;
        }
        $updates = Rmmigrate_Network_Restore_Orchestration::after_database_import(
            $pdo,
            $manifest,
            $job->get_progress()
        );
        if ($updates !== array()) {
            $job->update_progress($updates);
        }

        if ($job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION && $pdo !== null) {
            $map = $job->get_migration_map();
            if (is_array($map) && $map !== array()) {
                global $wpdb;
                $progress = $job->get_progress();
                $context = array(
                    'db_prefix'     => (string) ($manifest['db_prefix'] ?? $wpdb->prefix),
                    'migration_map' => $map,
                    'destination'   => is_array($progress['destination'] ?? null)
                        ? $progress['destination']
                        : Rmmigrate_Restore_Topology_Destination::probe_for_plugin(),
                    'migration_network_domains_applied' => !empty($progress['migration_network_domains_applied']),
                    'migration_network_paths_applied'   => !empty($progress['migration_network_paths_applied']),
                );
                $early = Rmmigrate_Restore_Topology_Standalone::apply_migration_network_urls_early($pdo, $manifest, $context);
                if ($early !== array()) {
                    $job->update_progress($early);
                    if (!empty($early['migration_network_paths_applied'])) {
                        $job->update_progress(array('migration_network_paths_applied' => true));
                    }
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $map
     * @param array<string,mixed> $manifest
     */
    private static function run_topology_finalize(Rmmigrate_Job $job, array $map, array $manifest): void
    {
        if ($manifest === array()) {
            $loaded = self::load_job_manifest($job);
            $manifest = is_array($loaded) ? $loaded : array();
        }
        if ($manifest === array()) {
            return;
        }

        global $wpdb;
        $progress = $job->get_progress();
        $context = array(
            'db_prefix'           => (string) ($manifest['db_prefix'] ?? $wpdb->prefix),
            'destination'         => Rmmigrate_Restore_Topology_Destination::probe_for_plugin(),
            'migration_map'       => $map,
            'prefix_policy'       => (string) ($progress['prefix_policy'] ?? 'keep_source'),
            'prefix_remap_target' => 'wp_',
            'restore_mode'        => (string) ($progress['restore_mode'] ?? ''),
            'site_owr_map'        => is_array($progress['site_owr_map'] ?? null) ? $progress['site_owr_map'] : array(),
            'target_blog_id'      => (int) ($progress['target_blog_id'] ?? 0),
            'subsite_on_network_target_blog_id' => (int) ($progress['subsite_on_network_target_blog_id'] ?? 0),
            'sql_table_prefix_rewrite' => is_array($progress['sql_table_prefix_rewrite'] ?? null)
                ? $progress['sql_table_prefix_rewrite']
                : array(),
            'migration_network_domains_applied' => !empty($progress['migration_network_domains_applied']),
            'migration_network_paths_applied'   => !empty($progress['migration_network_paths_applied']),
        );

        $pdo = self::topology_pdo();
        if ($pdo !== null) {
            Rmmigrate_Restore_Topology_Standalone::finish($pdo, $manifest, $context);
            if ((string) ($progress['restore_mode'] ?? '') === 'subsite_on_network') {
                $progress = $job->get_progress();
                $context['subsite_on_network_target_blog_id'] = (int) ($progress['subsite_on_network_target_blog_id'] ?? $context['subsite_on_network_target_blog_id'] ?? 0);
                $context['target_blog_id'] = (int) ($progress['target_blog_id'] ?? $context['target_blog_id'] ?? 0);
                $context['site_owr_map'] = is_array($progress['site_owr_map'] ?? null)
                    ? $progress['site_owr_map']
                    : $context['site_owr_map'];
                $context['sql_table_prefix_rewrite'] = is_array($progress['sql_table_prefix_rewrite'] ?? null)
                    ? $progress['sql_table_prefix_rewrite']
                    : $context['sql_table_prefix_rewrite'];
                $verify = Rmmigrate_Restore_Topology_Db_Action::verify_subsite_on_network_restore(
                    $pdo,
                    $manifest,
                    $context
                );
                if ($verify !== null) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(
                        sanitize_key('topology_subsite_on_network_verify'),
                        esc_html($verify)
                    );
                }
                $committed_blog_id = (int) ($context['subsite_on_network_target_blog_id'] ?? 0);
                if ($committed_blog_id > 0) {
                    Rmmigrate_Restore_Topology_Network_Import::invalidate_multisite_blog_cache($committed_blog_id);
                }
                $job->update_progress(array('subsite_on_network_allocated_blog' => null));
            }
        }

        $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
        $slot_restore = Rmmigrate_Restore_Topology_Compatibility::is_network_slot_restore(
            array_merge($progress, array('migration_map' => $map)),
            $manifest,
            $destination
        );
        if ($slot_restore) {
            return;
        }

        $extract_dir = trailingslashit($job->get_work_dir()) . 'extracted/files/wp-config.php';
        $wp_policy = (string) ($progress['wp_config_policy'] ?? 'merge');
        if ($job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION
            && Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            $wp_policy = 'merge';
        }
        $wp_state = array(
            'wp_config_policy'    => $wp_policy,
            'manifest'            => $manifest,
            'destination'         => $destination,
            'restore_mode'        => (string) ($progress['restore_mode'] ?? ''),
            'target_blog_id'      => (int) ($progress['target_blog_id'] ?? 0),
            'site_owr_map'        => is_array($progress['site_owr_map'] ?? null) ? $progress['site_owr_map'] : array(),
            'db_prefix'           => $context['db_prefix'],
            'db_name'             => DB_NAME,
            'db_user'             => DB_USER,
            'db_pass'             => DB_PASSWORD,
            'db_host'             => DB_HOST,
            'db_locked'           => is_file(ABSPATH . 'wp-config.php'),
            'db_creds_source'     => is_file(ABSPATH . 'wp-config.php') ? 'wp_config' : 'manual',
            'migration_map'       => $map,
            'prefix_policy'       => $context['prefix_policy'],
            'prefix_remap_target' => 'wp_',
        );
        if (Rmmigrate_Restore_Topology_Wp_Config::apply_plugin($wp_state, ABSPATH, $extract_dir)) {
            delete_site_option('rmmigrate_migration_notice');
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function load_job_manifest(Rmmigrate_Job $job): ?array
    {
        $progress = $job->get_progress();
        if (is_array($progress['manifest'] ?? null)) {
            return $progress['manifest'];
        }

        $path = trailingslashit($job->get_work_dir()) . 'extracted/manifest.json';
        if (Rmmigrate_Filesystem::exists($path)) {
            $json = Rmmigrate_Filesystem::get_contents($path);
            if ($json !== false) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $source = $progress['source']['zip_path'] ?? '';
        if ($source === '') {
            $source_job = Rmmigrate_Job::get($job->get_source_job_id());
            if ($source_job !== null) {
                $source = Rmmigrate_Runner::resolve_local_path($source_job);
            }
        }

        return self::read_manifest_from_archive((string) $source);
    }

    /**
     * @return array<int,array{job_id:int,archive_path:string,format:string}>
     */
    private static function build_extract_plan(Rmmigrate_Job $job, string $archive_path = ''): array
    {
        $path = $archive_path !== '' ? $archive_path : Rmmigrate_Runner::resolve_local_path($job);
        if ($path === '' || !Rmmigrate_Filesystem::exists($path)) {
            return array();
        }
        $enc_ext = Rmmigrate_Archive_Encryption::EXT;
        if (substr($path, -strlen($enc_ext)) === $enc_ext) {
            return array();
        }
        $format = 'zip';
        if (class_exists('Rmmigrate_Validator', false)) {
            $probe = Rmmigrate_Validator::probe_payload_type($path);
            if (is_array($probe) && !empty($probe['type'])) {
                $format = (string) $probe['type'];
            }
        }

        return array(
            array(
                'job_id'       => $job->get_id(),
                'archive_path' => $path,
                'format'       => $format,
            ),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function read_manifest_from_archive(string $archive_path): ?array
    {
        if ($archive_path === '' || !Rmmigrate_Filesystem::exists($archive_path)) {
            return null;
        }

        return Rmmigrate_Manifest::read_from_archive($archive_path);
    }

    private static function maybe_warn_php_version_mismatch(Rmmigrate_Job $job, string $source_path): void
    {
        $manifest = Rmmigrate_Manifest::read_from_archive($source_path);
        if (!is_array($manifest) || empty($manifest['php_version'])) {
            return;
        }
        $backup_php = (string) $manifest['php_version'];
        $backup_mm = implode('.', array_slice(explode('.', $backup_php), 0, 2));
        $target_mm = implode('.', array_slice(explode('.', PHP_VERSION), 0, 2));
        if ($backup_mm === '' || $target_mm === '' || $backup_mm === $target_mm) {
            return;
        }
        $message = sprintf(
            /* translators: 1: PHP version from backup, 2: current PHP version */
            __('PHP version mismatch: backup was created on PHP %1$s, this site runs PHP %2$s. Restore continues; verify plugins and themes afterward.', 'rosenheinrich-multisite-migrate'),
            $backup_php,
            PHP_VERSION
        );
        Rmmigrate_Logger::log($message);
        Rmmigrate_Activity_Log::record('restore', $message, 'warning', array(
            'job_id'  => $job->get_id(),
            'context' => array(
                'backup_php' => $backup_php,
                'target_php' => PHP_VERSION,
            ),
        ));
        $job->update_progress(array(
            'php_version_warning' => array(
                'backup' => $backup_php,
                'target' => PHP_VERSION,
            ),
        ));
    }

    private static function topology_pdo(): ?PDO
    {
        if (array_key_exists('mm_test_topology_pdo', $GLOBALS)) {
            $override = $GLOBALS['mm_test_topology_pdo'];
            return $override instanceof PDO ? $override : null;
        }

        $pdo = Rmmigrate_Topology_Db_Connection::connect();
        if ($pdo === null) {
            Rmmigrate_Logger::log('Topology database step skipped: connection failed.');
        }

        return $pdo;
    }
}
