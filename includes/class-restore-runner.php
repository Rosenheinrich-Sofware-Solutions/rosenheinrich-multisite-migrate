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
        if ($job->get_status() === Rmmigrate_Job::STATUS_CANCELLED) {
            self::disable_maintenance();
            return array('message' => $job->get_status_label());
        }

        $steps = new Rmmigrate_Restore_Build_Steps($job);
        $step = $steps->current();

        if ($job->get_status() === Rmmigrate_Job::STATUS_PENDING) {
            return self::step_init($job, $steps);
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
                return self::step_migration_finalize($job, $steps);
        }

        return array('message' => $job->get_status_label());
    }

    /**
     * @return array{message:string}
     */
    private static function step_init(Rmmigrate_Job $job, Rmmigrate_Restore_Build_Steps $steps): array
    {
        if (!defined('WP_IMPORTING')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core import flag required during restore/import.
            define('WP_IMPORTING', true);
        }

        Rmmigrate_Plugin::ensure_backup_root();
        $work_dir = Rmmigrate_Local_Storage::ensure_job_work_dir($job, false);
        $work_rel = 'jobs/' . $job->get_id() . '/';

        self::enable_maintenance();

        $source_job = Rmmigrate_Job::get($job->get_source_job_id()) ?? $job;
        if ($source_job->get_backup_type() === 'incremental') {
            throw new RuntimeException(esc_html(__('Incremental backups can only be restored in Multisite Migrate Pro.', 'rosenheinrich-multisite-migrate')));
        }

        $progress = $job->get_progress();
        $extract_plan = self::build_extract_plan($source_job);
        if ($extract_plan === array()) {
            throw new RuntimeException(esc_html(__('No backup archives found for restore.', 'rosenheinrich-multisite-migrate')));
        }

        $source_path = $progress['source']['zip_path'] ?? Rmmigrate_Runner::resolve_local_path($source_job);
        if (substr($source_path, -5) === Rmmigrate_Archive_Encryption::EXT) {
            $plain_name = preg_replace('/\.venc$/', '', basename($source_path));
            if ($plain_name === basename($source_path)) {
                $plain_name = 'archive.zip';
            }
            $plain_path = trailingslashit($work_dir) . $plain_name;
            $passphrase = (string) ($progress['archive_passphrase'] ?? ($job->data['archive_passphrase'] ?? ''));
            if ($passphrase !== '') {
                Rmmigrate_Archive_Encryption::set_runtime_passphrase($passphrase);
            }
            if (!Rmmigrate_Archive_Encryption::decrypt_file($source_path, $plain_path)) {
                Rmmigrate_Archive_Encryption::clear_runtime_passphrase();
                throw new RuntimeException(esc_html(__('Failed to decrypt backup archive. Enter the correct passphrase in Restore.', 'rosenheinrich-multisite-migrate')));
            }
            Rmmigrate_Archive_Encryption::clear_runtime_passphrase();
            $source_path = $plain_path;
        }

        self::maybe_warn_php_version_mismatch($job, $source_path);

        $job->update_progress(array(
            'init'          => array('work_dir' => $work_rel),
            'source'        => array(
                'zip_path'       => $source_path,
                'source_job_id'  => $job->get_source_job_id(),
            ),
            'extract_plan'  => $extract_plan,
            'plan_index'    => 0,
            'extract_engine' => Rmmigrate_Extract_Engine::ENGINE_AUTO,
        ));

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
        $budget = Rmmigrate_Extract_Engine::budget_sec($engine_state, Rmmigrate_Runner::worker_budget_sec());
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
        }

        $job->set_status(Rmmigrate_Job::STATUS_DUMPING_DB);
        $importer = new Rmmigrate_SQL_Importer($job);
        $done = $importer->run_slice(Rmmigrate_Runner::worker_budget_sec());

        if ($done) {
            $job->set_status(Rmmigrate_Job::STATUS_DB_DONE);
            $steps->advance_next();
            Rmmigrate_Logger::log('Database import complete');
            return array('message' => __('Database restored', 'rosenheinrich-multisite-migrate'));
        }

        $progress = $job->get_progress();
        $import = is_array($progress['import'] ?? null) ? $progress['import'] : array();
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
        $done = $scanner->run_slice(Rmmigrate_Runner::worker_budget_sec());

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
        $done = $restorer->run_slice(Rmmigrate_Runner::worker_budget_sec());

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
        $extract_dir = trailingslashit($job->get_work_dir()) . 'extracted/';
        if (Rmmigrate_Filesystem::is_dir($extract_dir)) {
            Rmmigrate_Filesystem::delete_directory($extract_dir);
        }

        if ($job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            $steps->advance_next();
            return array('message' => __('Finalizing migration', 'rosenheinrich-multisite-migrate'));
        }

        Rmmigrate_Post_Migration::purge_caches();
        Rmmigrate_Post_Migration::cleanup_bridge_mu_plugin();
        Rmmigrate_Post_Migration::maybe_revalidate_after_migration();
        $job->set_status(Rmmigrate_Job::STATUS_COMPLETE);
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
    private static function step_migration_finalize(Rmmigrate_Job $job, Rmmigrate_Restore_Build_Steps $steps): array
    {
        $map = $job->get_migration_map();
        $mode = $job->get_restore_mode();

        if ($mode === Rmmigrate_Job::RESTORE_MODE_DB || $mode === Rmmigrate_Job::RESTORE_MODE_BOTH) {
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
        if (is_multisite() && defined('DOMAIN_CURRENT_SITE') && !empty($map['site_url']['new'])) {
            $new_host = wp_parse_url($map['site_url']['new'], PHP_URL_HOST);
            if ($new_host && DOMAIN_CURRENT_SITE !== $new_host) {
                $notices[] = sprintf(
                    "define('DOMAIN_CURRENT_SITE', '%s');",
                    esc_html($new_host)
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

        $job->set_status(Rmmigrate_Job::STATUS_COMPLETE);
        self::disable_maintenance();
        flush_rewrite_rules();
        Rmmigrate_Post_Migration::purge_caches();
        Rmmigrate_Post_Migration::cleanup_bridge_mu_plugin();
        Rmmigrate_Post_Migration::maybe_revalidate_after_migration();
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
        $base = $idx === false ? 0 : (int) floor(($idx / $step_count) * 90);
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

    public static function get_status_label(Rmmigrate_Job $job): string
    {
        if ($job->get_status() === Rmmigrate_Job::STATUS_COMPLETE) {
            return __('Restore complete', 'rosenheinrich-multisite-migrate');
        }
        if ($job->get_status() === Rmmigrate_Job::STATUS_ERROR) {
            return __('Restore failed', 'rosenheinrich-multisite-migrate');
        }
        if ($job->get_status() === Rmmigrate_Job::STATUS_CANCELLED) {
            return __('Restore cancelled', 'rosenheinrich-multisite-migrate');
        }

        $steps = new Rmmigrate_Restore_Build_Steps($job);
        $labels = array(
            Rmmigrate_Restore_Build_Steps::STEP_EXTRACT            => __('Extracting archive', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_DATABASE           => __('Importing database', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_FILES              => __('Restoring files', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_CLEANUP            => __('Cleaning up', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Restore_Build_Steps::STEP_MIGRATION_FINALIZE => __('Finalizing migration', 'rosenheinrich-multisite-migrate'),
        );
        return $labels[$steps->current()] ?? __('Restoring', 'rosenheinrich-multisite-migrate');
    }

    const MAINTENANCE_OPTION = 'rmmigrate_restore_maintenance';
    const MAINTENANCE_MARKER  = 'multisite-migrate-restore';

    public static function register_maintenance_hooks(): void
    {
        add_action('template_redirect', array(__CLASS__, 'maybe_block_frontend'), 0);
    }

    public static function is_maintenance_active(): bool
    {
        return (int) get_site_option(self::MAINTENANCE_OPTION, 0) > 0;
    }

    public static function enable_maintenance(): void
    {
        update_site_option(self::MAINTENANCE_OPTION, time());
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
        Rmmigrate_Activity_Log::record(
            'restore',
            __('Removed stale maintenance mode left by an interrupted restore.', 'rosenheinrich-multisite-migrate'),
            'warning'
        );
    }

    public static function disable_maintenance(): void
    {
        delete_site_option(self::MAINTENANCE_OPTION);
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

        wp_die(
            esc_html__('Briefly unavailable for scheduled maintenance. Check back in a minute.', 'rosenheinrich-multisite-migrate'),
            esc_html__('Maintenance', 'rosenheinrich-multisite-migrate'),
            array(
                'response'  => 503,
                'retry-after' => 600,
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
        $state = array(
            'install_mode' => $job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION ? 'standard' : 'overwrite',
            'archive'      => array('has_wp_core_files' => !empty($manifest['has_wp_core_files'])),
        );
        $gate = Rmmigrate_Restore_Topology_Compatibility::validate_gates($state, $manifest, $destination, false);
        if ($gate !== null) {
            throw new RuntimeException(esc_html((string) $gate['message']));
        }
    }

    private static function prepare_database_for_restore(Rmmigrate_Job $job): void
    {
        $manifest = self::load_job_manifest($job);
        if ($manifest === null) {
            return;
        }

        $is_network = Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest);
        $is_subsite = Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest);
        if (!$is_network && !$is_subsite) {
            return;
        }

        global $wpdb;
        $pdo = self::topology_pdo();
        if ($pdo === null) {
            return;
        }

        Rmmigrate_Restore_Topology_Db_Action::prepare_overwrite(
            $pdo,
            'tables_only',
            $manifest,
            array(
                'db_prefix'   => (string) ($manifest['db_prefix'] ?? $wpdb->prefix),
                'destination' => Rmmigrate_Restore_Topology_Destination::probe_for_plugin(),
            )
        );
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
        );

        $pdo = self::topology_pdo();
        if ($pdo !== null) {
            Rmmigrate_Restore_Topology_Standalone::finish($pdo, $manifest, $context);
        }

        $extract_dir = trailingslashit($job->get_work_dir()) . 'extracted/files/wp-config.php';
        $wp_state = array(
            'wp_config_policy'    => (string) ($job->get_progress()['wp_config_policy'] ?? 'merge'),
            'manifest'            => $manifest,
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
    private static function build_extract_plan(Rmmigrate_Job $job): array
    {
        $path = Rmmigrate_Runner::resolve_local_path($job);
        if (!Rmmigrate_Filesystem::exists($path)) {
            return array();
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'venc') {
            $ext = strtolower(pathinfo(substr($path, 0, -5), PATHINFO_EXTENSION));
        }

        return array(
            array(
                'job_id'       => $job->get_id(),
                'archive_path' => $path,
                'format'       => $ext === 'daf' ? 'daf' : 'zip',
            ),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function read_manifest_from_archive(string $archive_path): ?array
    {
        if ($archive_path === '' || !Rmmigrate_Filesystem::exists($archive_path) || !class_exists('ZipArchive')) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($archive_path) !== true) {
            return null;
        }
        $json = $zip->getFromName('manifest.json');
        $zip->close();
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
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
        $pdo = Rmmigrate_Topology_Db_Connection::connect();
        if ($pdo === null) {
            Rmmigrate_Logger::log('Topology database step skipped: connection failed.');
        }

        return $pdo;
    }
}
