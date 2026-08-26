<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned job table; not core posts/options API.

class Rmmigrate_Job
{
    const JOB_TYPE_BACKUP  = 'backup';
    const JOB_TYPE_RESTORE = 'restore';

    const RESTORE_MODE_DB    = 'db';
    const RESTORE_MODE_FILES = 'files';
    const RESTORE_MODE_BOTH  = 'both';

    const RESTORE_TYPE_SAME_SERVER = 'same_server';
    const RESTORE_TYPE_MIGRATION   = 'migration';

    const STATUS_PENDING      = 0;
    const STATUS_INIT         = 5;
    const STATUS_DUMPING_DB   = 10;
    const STATUS_DB_DONE      = 20;
    const STATUS_ARCHIVING    = 30;
    const STATUS_ARCHIVE_DONE = 40;
    const STATUS_COMPLETE     = 100;
    const STATUS_ERROR        = -1;
    const STATUS_CANCELLED    = -2;
    /** Soft-deleted; local files purged asynchronously. */
    const STATUS_DELETING     = -3;

    /** @var array<string,mixed> */
    public $data = array();

    public static function table_name(): string
    {
        global $wpdb;
        return (is_object($wpdb) && !empty($wpdb->base_prefix) ? $wpdb->base_prefix : 'wp_') . 'rmmigrate_jobs';
    }

    /**
     * @param array<string,mixed> $args
     */
    public static function create(array $args): self
    {
        global $wpdb;

        if (self::get_active() !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('A backup or restore is already in progress.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::ACTIVE_JOB_CONFLICT));
        }

        $now = current_time('mysql', true);
        $scope = $args['scope'] ?? Rmmigrate_Multisite_Scope::SCOPE_NETWORK;
        $included_blog_ids = array_values(array_map('intval', (array) ($args['included_blogs'] ?? array())));
        $excluded = $args['excluded_blogs'] ?? array();
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED && $included_blog_ids !== array()) {
            // Store the selected subsites in progress — not as an inverted exclude
            // list that can exceed the TEXT column on large networks.
            $excluded = array();
        }
        $blog_id = (int) ($args['blog_id'] ?? 0);
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            $blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();
        }

        $backup_profile = $args['backup_profile'] ?? 'full';
        $backup_type = 'full';
        $parent_job_id = 0;
        $destination = 'local';

        $triggered_by = (string) ($args['triggered_by'] ?? 'manual');
        $backup_intent = 'manual';

        $progress_init = array(
            'step'              => 'init',
            'custom_paths'      => $args['custom_paths'] ?? '[]',
            'excluded_tables'   => array_values(array_filter(array_map('trim', (array) ($args['excluded_tables'] ?? array())))),
            'exclude_log_tables'=> !empty($args['exclude_log_tables']),
            'exclude_revisions' => !empty($args['exclude_revisions']),
            'backup_intent'     => $backup_intent,
            'include_wp_core'   => $backup_intent === 'migration',
            'log_token'         => Rmmigrate_Activity_Log::generate_log_token(),
        );
        // Explicit per-backup override from the wizard wins over both the
        // migration-intent default and the global setting.
        if (array_key_exists('include_wp_core', $args) && $args['include_wp_core'] !== null) {
            $progress_init['include_wp_core'] = (bool) $args['include_wp_core'];
            $progress_init['include_wp_core_explicit'] = true;
        }
        if (!empty($args['archive_mode']) && in_array($args['archive_mode'], array('zip', 'daf'), true)) {
            $progress_init['archive_mode'] = $args['archive_mode'];
        }
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED && $included_blog_ids !== array()) {
            $progress_init['included_blogs'] = $included_blog_ids;
        }

        $row = array(
            'blog_id'          => $blog_id,
            'scope'            => $scope,
            'job_type'         => self::JOB_TYPE_BACKUP,
            'backup_profile'   => $backup_profile,
            'backup_type'      => $backup_type,
            'is_safety'        => !empty($args['is_safety']) ? 1 : 0,
            'destination'      => $destination,
            'status'           => self::STATUS_PENDING,
            'progress'         => wp_json_encode($progress_init),
            'excluded_blogs'   => wp_json_encode(array_values(array_map('intval', $excluded))),
            'triggered_by'     => $args['triggered_by'] ?? 'manual',
            'restore_type'     => self::RESTORE_TYPE_SAME_SERVER,
            'created_at'       => $now,
            'updated_at'       => $now,
        );
        $formats = array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s');
        if ($parent_job_id > 0) {
            $row['parent_job_id'] = $parent_job_id;
            $formats[] = '%d';
        }

        $inserted = $wpdb->insert(self::table_name(), $row, $formats);
        if ($inserted === false) {
            Rmmigrate_Activator::ensure_schema();
            $inserted = $wpdb->insert(self::table_name(), $row, $formats);
        }

        if ($inserted === false) {
            $db_error = is_string($wpdb->last_error) ? trim($wpdb->last_error) : '';
            if ($db_error !== '') {
                Rmmigrate_Logger::log_system(
                    'Backup job insert failed: ' . $db_error,
                    array('operation' => 'job_insert'),
                    'error'
                );
            }
            if ($db_error !== '' && stripos($db_error, 'doesn\'t exist') !== false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
                throw new Rmmigrate_Service_Exception(
                    esc_html(__('Backup database table is missing. Deactivate and reactivate Multisite Migrate, then try again.', 'rosenheinrich-multisite-migrate')),
                    array(), sanitize_key(Rmmigrate_Error_Codes::JOB_TABLE_MISSING));
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Failed to create backup job.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::JOB_CREATE_FAILED));
        }

        $job = self::get((int) $wpdb->insert_id);
        if ($job === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Failed to create backup job.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::JOB_CREATE_FAILED));
        }

        $triggered_by = (string) ($args['triggered_by'] ?? 'manual');
        $message = sprintf(
            /* translators: 1: job ID, 2: scope, 3: backup profile */
            __('Backup job #%1$d queued (%2$s, %3$s).', 'rosenheinrich-multisite-migrate'),
            $job->get_id(),
            $job->get_scope(),
            $job->get_backup_profile()
        );
        Rmmigrate_Logger::log_job($job->get_id(), $message);
        Rmmigrate_Activity_Log::record('backup', $message, 'info', array(
            'job_id'  => $job->get_id(),
            'context' => array(
                'triggered_by'   => $triggered_by,
                'scope'          => $job->get_scope(),
                'destination'    => (string) ($job->data['destination'] ?? 'local'),
                'backup_profile' => $job->get_backup_profile(),
                'backup_type'    => $job->get_backup_type(),
            ),
        ));

        return $job;
    }

    /**
     * Register an imported archive as a completed backup job.
     */
    public static function register_imported(string $local_rel_path, int $file_size): self
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $full_path = trailingslashit(Rmmigrate_Plugin::backups_dir()) . ltrim($local_rel_path, '/');
        $manifest = Rmmigrate_Manifest::read_from_archive($full_path);
        $default_scope = is_multisite() && (!function_exists('is_network_admin') || is_network_admin())
            ? Rmmigrate_Multisite_Scope::SCOPE_NETWORK
            : Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
        $scope = is_array($manifest) && !empty($manifest['scope'])
            ? sanitize_text_field((string) $manifest['scope'])
            : $default_scope;
        $blog_id = is_array($manifest) && isset($manifest['blog_id'])
            ? (int) $manifest['blog_id']
            : get_current_blog_id();
        $wpdb->insert(
            self::table_name(),
            array(
                'blog_id'          => $blog_id,
                'scope'            => $scope,
                'job_type'         => self::JOB_TYPE_BACKUP,
                'status'           => self::STATUS_COMPLETE,
                'progress'         => wp_json_encode(array(
                    'step'      => 'import',
                    'log_token' => Rmmigrate_Activity_Log::generate_log_token(),
                )),
                'local_path'       => $local_rel_path,
                'file_size'        => $file_size,
                'triggered_by'     => 'import',
                'destination'      => 'local',
                'completed_at'     => $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        $job = self::get((int) $wpdb->insert_id);
        if ($job === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Failed to register import.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::IMPORT_REGISTER_FAILED));
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $options
     */
    public static function create_restore(int $source_job_id, string $mode, array $options = array()): self
    {
        global $wpdb;

        if (self::get_active() !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('A backup or restore is already in progress.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::ACTIVE_JOB_CONFLICT));
        }

        $source = self::get($source_job_id);
        if ($source === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Source backup not found.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::SOURCE_NOT_FOUND));
        }
        if ($source->get_status() !== self::STATUS_COMPLETE) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Source backup is not complete.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::SOURCE_NOT_COMPLETE));
        }
        if ($source->get_job_type() !== self::JOB_TYPE_BACKUP) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Invalid source job.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::INVALID_SOURCE_JOB));
        }

        $zip_path = Rmmigrate_Runner::resolve_local_path($source);
        if (!Rmmigrate_Filesystem::exists($zip_path)) {
            $fetched = Rmmigrate_Local_Archive::ensure_local_archive($source);
            if (is_wp_error($fetched)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
                throw new Rmmigrate_Service_Exception(
                    esc_html($fetched->get_error_message()),
                    array(), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($fetched)));
            }
            $zip_path = $fetched;
        }

        $allowed_modes = array(self::RESTORE_MODE_DB, self::RESTORE_MODE_FILES, self::RESTORE_MODE_BOTH);
        if (!in_array($mode, $allowed_modes, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Invalid restore mode.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::INVALID_RESTORE_MODE));
        }

        $restore_type = $options['restore_type'] ?? self::RESTORE_TYPE_SAME_SERVER;
        if (!in_array($restore_type, array(self::RESTORE_TYPE_SAME_SERVER, self::RESTORE_TYPE_MIGRATION), true)) {
            $restore_type = self::RESTORE_TYPE_SAME_SERVER;
        }

        $migration_map = $options['migration_map'] ?? array();
        if ($restore_type === self::RESTORE_TYPE_MIGRATION && empty($migration_map)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Migration requires URL mapping.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::MIGRATION_MAP_REQUIRED));
        }

        if ($source->get_scope() === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            if (is_multisite() && $source->get_blog_id() !== get_current_blog_id()) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
                throw new Rmmigrate_Service_Exception(
                    esc_html(__('Cannot restore another subsite backup here.', 'rosenheinrich-multisite-migrate')),
                    array(), sanitize_key(Rmmigrate_Error_Codes::RESTORE_SCOPE_DENIED));
            }
        }

        if ($restore_type === self::RESTORE_TYPE_MIGRATION && is_multisite() && !current_user_can('manage_network')) {
            if ($source->get_scope() !== Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
                throw new Rmmigrate_Service_Exception(
                    esc_html(__('Network migration requires super admin.', 'rosenheinrich-multisite-migrate')),
                    array(), sanitize_key(Rmmigrate_Error_Codes::NETWORK_MIGRATION_DENIED));
            }
        }

        $now = current_time('mysql', true);
        $progress_data = array(
            'step'      => Rmmigrate_Restore_Build_Steps::STEP_EXTRACT,
            'source'    => array('zip_path' => $zip_path, 'source_job_id' => $source_job_id),
            'log_token' => Rmmigrate_Activity_Log::generate_log_token(),
        );
        $safety_job_id = (int) ($options['safety_job_id'] ?? 0);
        if ($safety_job_id > 0) {
            $progress_data['safety_job_id'] = $safety_job_id;
        }
        if (!empty($options['archive_passphrase'])) {
            $progress_data['archive_passphrase'] = (string) $options['archive_passphrase'];
        }
        if (!empty($options['topology']) && is_array($options['topology'])) {
            foreach (array('restore_mode', 'site_owr_map', 'target_blog_id', 'prefix_policy', 'wp_config_merge_constants', 'import_skip_tables') as $topology_key) {
                if (array_key_exists($topology_key, $options['topology'])) {
                    $progress_data[$topology_key] = $options['topology'][$topology_key];
                }
            }
        }

        $wpdb->insert(
            self::table_name(),
            array(
                'blog_id'         => $source->get_blog_id(),
                'scope'           => $source->get_scope(),
                'job_type'        => self::JOB_TYPE_RESTORE,
                'source_job_id'   => $source_job_id,
                'restore_mode'    => $mode,
                'restore_type'    => $restore_type,
                'migration_map'   => wp_json_encode($migration_map),
                'status'          => self::STATUS_PENDING,
                'progress'        => wp_json_encode($progress_data),
                'excluded_blogs'  => $source->data['excluded_blogs'] ?? '[]',
                'triggered_by'    => 'manual',
                'local_path'      => $source->data['local_path'] ?? '',
                'file_size'       => $source->data['file_size'] ?? 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        $job = self::get((int) $wpdb->insert_id);
        if ($job === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Failed to create restore job.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::JOB_CREATE_FAILED));
        }

        $message = sprintf(
            /* translators: 1: restore job ID, 2: source backup ID, 3: restore mode */
            __('Restore job #%1$d queued from backup #%2$d (%3$s).', 'rosenheinrich-multisite-migrate'),
            $job->get_id(),
            $source_job_id,
            $mode
        );
        Rmmigrate_Logger::log_job($job->get_id(), $message);
        $activity_context = array(
            'source_job_id' => $source_job_id,
            'restore_mode'  => $mode,
            'restore_type'  => $restore_type,
            'triggered_by'  => 'manual',
        );
        if ($safety_job_id > 0) {
            $activity_context['safety_job_id'] = $safety_job_id;
        }
        Rmmigrate_Activity_Log::record('restore', $message, 'info', array(
            'job_id'  => $job->get_id(),
            'context' => $activity_context,
        ));

        return $job;
    }

    public static function get(int $id): ?self
    {
        $row = Rmmigrate_Snap_DB::jobs_get_row_by_id($id);
        if (!$row) {
            return null;
        }
        $job = new self();
        $job->data = $row;
        return $job;
    }

    public static function get_active_id(): ?int
    {
        return Rmmigrate_Snap_DB::jobs_get_active_id();
    }

    public static function get_active(): ?self
    {
        $id = self::get_active_id();
        if ($id === null) {
            return null;
        }
        return self::get($id);
    }

    public static function stale_threshold_seconds(): int
    {
        $settings = Rmmigrate_Settings::get();
        $minutes  = (int) ($settings['stale_job_minutes'] ?? 30);

        return max(300, min(7200, $minutes * MINUTE_IN_SECONDS));
    }

    public static function has_recent_worker_activity(int $job_id): bool
    {
        // Lock holders only. Waiter milestones (worker_request_*) must not mask
        // stale recovery during a lock-wait stampede.
        return (bool) get_transient('rmmigrate_milestone_lock_acquired_' . $job_id);
    }

    public static function is_stale(self $job, ?int $threshold_seconds = null): bool
    {
        $status = $job->get_status();
        if ($status < 0 || $status >= 100) {
            return false;
        }

        $threshold = $threshold_seconds ?? self::stale_threshold_seconds();
        $updated_raw = (string) ($job->data['updated_at'] ?? '');
        if ($updated_raw === '') {
            // Corrupt/legacy active row with no heartbeat — do not block forever.
            return true;
        }
        $updated = strtotime($updated_raw . ' UTC');
        if ($updated === false) {
            return true;
        }

        // Fresh worker lease proves the holder is alive (long quiet slices OK).
        if (Rmmigrate_Runner::lease_is_fresh($job->get_id())) {
            return false;
        }

        $age = time() - $updated;
        if ($age < $threshold) {
            return false;
        }

        return true;
    }

    /**
     * Mark stuck active jobs as failed when the worker has been silent past the threshold.
     */
    public static function recover_stale_active(): ?self
    {
        $job = self::get_active();
        if ($job === null || !self::is_stale($job)) {
            return null;
        }

        $message = __('Worker stopped responding. The job was marked as failed. You can start a new backup or restore.', 'rosenheinrich-multisite-migrate');
        Rmmigrate_Logger::log_job($job->get_id(), $message);
        $job->set_status(self::STATUS_ERROR, $message, 'stale_worker');
        Rmmigrate_Runner::force_release_lock($job->get_id());
        if ($job->is_restore()) {
            Rmmigrate_Restore_Runner::disable_maintenance();
        }
        Rmmigrate_Activity_Log::record(
            $job->is_restore() ? 'restore' : 'backup',
            $message,
            'error',
            array('job_id' => $job->get_id(), 'context' => array('recovered' => 'stale_worker'))
        );

        return $job;
    }

    /**
     * Active job visible in the current admin context (network vs subsite).
     */
    public static function get_active_for_admin(bool $is_network): ?self
    {
        if ($is_network || !is_multisite()) {
            $job = self::get_active();
            if ($job !== null && Rmmigrate_Access::can_view_job($job)) {
                return $job;
            }

            return null;
        }

        $id = Rmmigrate_Snap_DB::jobs_get_active_id_for_blog(
            get_current_blog_id(),
            Rmmigrate_Multisite_Scope::SCOPE_SUBSITE
        );
        if ($id === null) {
            return null;
        }

        $job = self::get((int) $id);
        if ($job === null || !Rmmigrate_Access::can_view_job($job)) {
            return null;
        }

        return $job;
    }

    /**
     * Prefer a highlighted job from the URL when it is still active and viewable.
     */
    public static function resolve_admin_active_job(bool $is_network, int $highlight_job_id = 0): ?self
    {
        $active = self::get_active_for_admin($is_network);
        if ($highlight_job_id <= 0) {
            return $active;
        }

        $highlight = self::get($highlight_job_id);
        if ($highlight === null || !Rmmigrate_Access::can_view_job($highlight)) {
            return $active;
        }

        $status = $highlight->get_status();
        if ($status >= 0 && $status < 100) {
            return $highlight;
        }

        return $active;
    }

    /**
     * Completed restore highlighted by ?mm_restore_ok=1&job_id= after success redirect.
     */
    public static function resolve_admin_restore_success_job(bool $is_network, int $highlight_job_id): ?self
    {
        unset($is_network);
        if (Rmmigrate_Request_Input::get_text('mm_restore_ok') !== '1' || $highlight_job_id <= 0) {
            return null;
        }

        $job = self::get($highlight_job_id);
        if (
            $job === null
            || !$job->is_restore()
            || $job->get_status() !== self::STATUS_COMPLETE
            || !Rmmigrate_Access::can_view_job($job)
        ) {
            return null;
        }

        return $job;
    }

    /**
     * Last failed-job banner payload scoped to the current admin context.
     *
     * @return array<string,mixed>
     */
    public static function resolve_admin_last_error(bool $is_network): array
    {
        $error = get_site_option('rmmigrate_last_error', array());
        if (!is_array($error) || (string) ($error['message'] ?? '') === '') {
            return array();
        }

        $job_id = (int) ($error['job_id'] ?? 0);
        if ($job_id > 0) {
            $job = self::get($job_id);
            if ($job === null) {
                delete_site_option('rmmigrate_last_error');
                return array();
            }

            return Rmmigrate_Access::can_view_job($job) ? $error : array();
        }

        $scope = (string) ($error['scope'] ?? '');
        $blog_id = (int) ($error['blog_id'] ?? 0);

        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            if ($is_network || !is_multisite()) {
                return current_user_can('manage_network') ? $error : array();
            }

            return $blog_id === get_current_blog_id() ? $error : array();
        }

        if ($scope !== '') {
            if ($is_network || !is_multisite()) {
                return (!is_multisite() || current_user_can('manage_network')) ? $error : array();
            }

            return array();
        }

        // Legacy payloads without scope: only network admins may see them.
        if ($is_network || !is_multisite()) {
            return (!is_multisite() || current_user_can('manage_network')) ? $error : array();
        }

        return array();
    }

    public static function is_status_active(int $status): bool
    {
        return $status >= 0 && $status < 100;
    }

    /**
     * @param int[] $blog_ids
     * @return array<int,int>
     */
    public static function count_subsite_backups_by_blog(array $blog_ids = array()): array
    {
        $rows = Rmmigrate_Snap_DB::jobs_count_subsite_backups_by_blog($blog_ids);
        $counts = array();
        foreach ($rows as $row) {
            $counts[(int) $row['blog_id']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * @param array<string,mixed> $filters
     * @return self[]
     */
    public static function list_jobs(array $filters = array()): array
    {
        $rows = Rmmigrate_Snap_DB::jobs_list_rows($filters);
        if ($rows === array()) {
            return array();
        }

        $jobs = array();
        foreach ($rows as $row) {
            $job = new self();
            $job->data = $row;
            $jobs[] = $job;
        }
        return $jobs;
    }

    /**
     * Ensure a specific job appears in a list (e.g. deep-link from error banner).
     *
     * @param self[] $jobs
     * @return self[]
     */
    public static function ensure_job_in_list(array $jobs, int $job_id): array
    {
        if ($job_id <= 0) {
            return $jobs;
        }

        foreach ($jobs as $job) {
            if ($job->get_id() === $job_id) {
                return $jobs;
            }
        }

        $job = self::get($job_id);
        if ($job === null || !Rmmigrate_Access::can_view_job($job)) {
            return $jobs;
        }

        array_unshift($jobs, $job);
        return $jobs;
    }

    public function get_id(): int
    {
        return (int) $this->data['id'];
    }

    public function get_local_path(): string
    {
        return (string) ($this->data['local_path'] ?? '');
    }

    public function get_file_size(): int
    {
        return (int) ($this->data['file_size'] ?? 0);
    }

    public function get_status(): int
    {
        return (int) $this->data['status'];
    }

    public function get_scope(): string
    {
        return (string) $this->data['scope'];
    }

    public function get_blog_id(): int
    {
        return (int) $this->data['blog_id'];
    }

    public function get_job_type(): string
    {
        return (string) ($this->data['job_type'] ?? self::JOB_TYPE_BACKUP);
    }

    public function get_display_job_type(): string
    {
        if ($this->get_job_type() === self::JOB_TYPE_BACKUP) {
            return __('Full backup', 'rosenheinrich-multisite-migrate');
        }
        if ($this->get_job_type() === self::JOB_TYPE_RESTORE) {
            return __('Restore', 'rosenheinrich-multisite-migrate');
        }

        return ucfirst($this->get_job_type());
    }

    public function get_display_scope(): string
    {
        $labels = array(
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK          => __('Entire network', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED => __('Network (filtered)', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED => __('Selected subsites only', 'rosenheinrich-multisite-migrate'),
            Rmmigrate_Multisite_Scope::SCOPE_SUBSITE          => __('Subsite only', 'rosenheinrich-multisite-migrate'),
        );

        $label = $labels[$this->get_scope()] ?? ucfirst(str_replace('_', ' ', $this->get_scope()));
        if ($this->get_scope() === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED) {
            $included = $this->get_included_blogs();
            if ($included !== array()) {
                $names = array();
                $max_display = 2;
                foreach (array_slice($included, 0, $max_display) as $blog_id) {
                    $details = get_blog_details($blog_id);
                    if ($details && !empty($details->blogname)) {
                        $names[] = $details->blogname;
                    } elseif ($details && !empty($details->domain)) {
                        $names[] = $details->domain;
                    } else {
                        $names[] = 'Site ID ' . $blog_id;
                    }
                }
                if (!empty($names)) {
                    $label = implode(', ', $names);
                    if (count($included) > $max_display) {
                        $label .= ' +' . (count($included) - $max_display) . ' more';
                    }
                } else {
                    $label .= ' (' . count($included) . ')';
                }
            }
        } elseif ($this->get_scope() === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            $blog_id = (int) ($this->data['blog_id'] ?? 0);
            if ($blog_id > 0) {
                $details = get_blog_details($blog_id);
                if ($details && !empty($details->blogname)) {
                    $label .= ': ' . $details->blogname;
                } elseif ($details && !empty($details->domain)) {
                    $label .= ': ' . $details->domain;
                }
            }
        }

        return $label;
    }

    public function is_restore(): bool
    {
        return $this->get_job_type() === self::JOB_TYPE_RESTORE;
    }

    public function get_restore_mode(): string
    {
        return (string) ($this->data['restore_mode'] ?? self::RESTORE_MODE_BOTH);
    }

    public function get_restore_type(): string
    {
        return (string) ($this->data['restore_type'] ?? self::RESTORE_TYPE_SAME_SERVER);
    }

    public function get_source_job_id(): int
    {
        return (int) ($this->data['source_job_id'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    public function get_migration_map(): array
    {
        $raw = $this->data['migration_map'] ?? '';
        if ($raw === '' || $raw === null) {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    public function get_restore_root(): string
    {
        return trailingslashit(ABSPATH);
    }

    public function get_destination(): string
    {
        return (string) ($this->data['destination'] ?? 'local');
    }

    public function get_backup_profile(): string
    {
        return (string) ($this->data['backup_profile'] ?? 'full');
    }

    public function get_backup_type(): string
    {
        return (string) ($this->data['backup_type'] ?? 'full');
    }

    public function is_safety(): bool
    {
        return !empty($this->data['is_safety']);
    }

    public function get_parent_job_id(): int
    {
        return (int) ($this->data['parent_job_id'] ?? 0);
    }

    public function get_parent_job(): ?self
    {
        $id = $this->get_parent_job_id();
        return $id > 0 ? self::get($id) : null;
    }

    public function skips_database(): bool
    {
        return in_array($this->get_backup_profile(), array('files', 'media', 'plugins', 'themes', 'custom'), true);
    }

    public function skips_files(): bool
    {
        return $this->get_backup_profile() === 'db';
    }

    /**
     * @return int[]
     */
    public function get_excluded_blogs(): array
    {
        $raw = $this->data['excluded_blogs'] ?? '[]';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_map('intval', $decoded) : array();
    }

    /**
     * @return string[]
     */
    public function get_excluded_table_patterns(): array
    {
        $progress = $this->get_progress();
        return Rmmigrate_Validator::resolve_table_patterns(array(
            'excluded_tables'    => $progress['excluded_tables'] ?? array(),
            'exclude_log_tables' => !empty($progress['exclude_log_tables']),
        ));
    }

    /**
     * @return int[]
     */
    public function get_included_blogs(): array
    {
        if ($this->get_scope() !== Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED) {
            return array();
        }

        $progress = $this->get_progress();
        if (!empty($progress['included_blogs']) && is_array($progress['included_blogs'])) {
            return array_values(array_map('intval', $progress['included_blogs']));
        }

        return array_values(array_diff(
            Rmmigrate_Multisite_Scope::get_all_site_ids(),
            $this->get_excluded_blogs()
        ));
    }

    /**
     * Atomically advance status for milestone logging (avoids duplicate worker logs).
     */
    public function claim_status_at_least(int $new_status): bool
    {
        if ($this->get_status() >= $new_status) {
            return false;
        }

        $updated = Rmmigrate_Snap_DB::jobs_claim_status_at_least(
            $this->get_id(),
            $new_status,
            current_time('mysql', true)
        );

        if (!$updated) {
            return false;
        }

        $this->data['status'] = $new_status;
        $this->data['updated_at'] = current_time('mysql', true);
        return true;
    }

    public function set_status(int $status, ?string $error = null, ?string $service_code = null): void
    {
        global $wpdb;
        $previous = $this->get_status();
        if ($status === $previous && $error === null) {
            return;
        }
        if ($status === self::STATUS_COMPLETE && $previous === self::STATUS_COMPLETE) {
            return;
        }
        if ($status === self::STATUS_ERROR && $previous === self::STATUS_ERROR && $error === null) {
            return;
        }

        $fields = array(
            'status'     => $status,
            'updated_at' => current_time('mysql', true),
        );
        $formats = array('%d', '%s');
        if ($error !== null) {
            $fields['error_message'] = $error;
            $formats[] = '%s';
            $resolved_code = sanitize_key((string) ($service_code ?? ''));
            if ($resolved_code === '') {
                $resolved_code = Rmmigrate_Error_Codes::from_message($error);
            }
            if ($resolved_code !== '') {
                $this->update_progress(array('service_code' => $resolved_code));
            }
        }
        if ($status === self::STATUS_COMPLETE) {
            $fields['completed_at'] = current_time('mysql', true);
            $formats[] = '%s';
            if ($error === null && empty($fields['error_message'] ?? $this->data['error_message'] ?? '')) {
                $last_error = get_site_option('rmmigrate_last_error', array());
                if (is_array($last_error) && (int) ($last_error['job_id'] ?? 0) === $this->get_id()) {
                    delete_site_option('rmmigrate_last_error');
                }
            }
        }
        if (($status === self::STATUS_ERROR || ($status === self::STATUS_COMPLETE && !$this->is_restore())) && $error !== null) {
            update_site_option('rmmigrate_last_error', $this->build_last_error_payload($error));
        }
        $updated = $wpdb->update(self::table_name(), $fields, array('id' => $this->get_id()), $formats, array('%d'));
        if ($updated === false) {
            self::log_db_write_failure('set_status', $this->get_id());
        }
        $this->data = array_merge($this->data, $fields);

        if ($status === self::STATUS_COMPLETE || $status === self::STATUS_ERROR) {
            $was_terminal = in_array($previous, array(self::STATUS_COMPLETE, self::STATUS_ERROR, self::STATUS_CANCELLED), true);
            if (!$was_terminal) {
                $type = $this->get_job_type() === self::JOB_TYPE_RESTORE ? 'restore' : 'backup';
                $level = 'success';
                if ($status === self::STATUS_ERROR) {
                    $level = 'error';
                } elseif ($error !== null) {
                    $level = 'warning';
                }
                $triggered_by = (string) ($this->data['triggered_by'] ?? 'manual');
                $activity_context = array('triggered_by' => $triggered_by);
                $progress = $this->get_progress();
                $safety_job_id = (int) ($progress['safety_job_id'] ?? 0);
                if ($type === 'restore' && $safety_job_id > 0) {
                    $activity_context['safety_job_id'] = $safety_job_id;
                }
                Rmmigrate_Activity_Log::record(
                    $type,
                    $status === self::STATUS_COMPLETE
                        ? ($error !== null
                            ? sprintf(
                                /* translators: 1: job type, 2: job ID, 3: warning message */
                                __('%1$s job #%2$d completed with a warning: %3$s', 'rosenheinrich-multisite-migrate'),
                                ucfirst($type),
                                $this->get_id(),
                                $error
                            )
                            : sprintf(
                                /* translators: 1: job type, 2: job ID */
                                __('%1$s job #%2$d completed.', 'rosenheinrich-multisite-migrate'),
                                ucfirst($type),
                                $this->get_id()
                            ))
                        : sprintf(
                            /* translators: 1: job type, 2: job ID, 3: error message */
                            __('%1$s job #%2$d failed: %3$s', 'rosenheinrich-multisite-migrate'),
                            ucfirst($type),
                            $this->get_id(),
                            $error ?? ''
                        ),
                    $level,
                    array(
                        'job_id'  => $this->get_id(),
                        'context' => $activity_context,
                    )
                );
                if ($type === 'backup' && !empty($progress['scheduled'])) {
                    if ($status === self::STATUS_COMPLETE && $error === null) {
                        Rmmigrate_Scheduler::reset_schedule_failures();
                    } elseif ($status === self::STATUS_ERROR) {
                        Rmmigrate_Scheduler::record_schedule_failure(
                            $error ?? __('Unknown error', 'rosenheinrich-multisite-migrate')
                        );
                    }
                }
                Rmmigrate_Notifications::maybe_send_job($this, $status, $error);
                /**
                 * Fires when a backup or restore job reaches a terminal state.
                 *
                 * @param Rmmigrate_Job $job    Job object.
                 * @param int           $status Terminal status code.
                 * @param string|null   $error  Optional error or warning message.
                 */
                do_action('rmmigrate_job_terminal', $this, $status, $error);
            }
        }

        if ($status === self::STATUS_CANCELLED && !in_array($previous, array(self::STATUS_CANCELLED, self::STATUS_COMPLETE, self::STATUS_ERROR), true)) {
            $type = $this->get_job_type() === self::JOB_TYPE_RESTORE ? 'restore' : 'backup';
            $message = sprintf(
                /* translators: 1: job type, 2: job ID */
                __('%1$s job #%2$d cancelled.', 'rosenheinrich-multisite-migrate'),
                ucfirst($type),
                $this->get_id()
            );
            $progress = $this->get_progress();
            if ($this->is_restore() && !empty($progress['cancel_notice'])) {
                $message = (string) $progress['cancel_notice'];
            }
            Rmmigrate_Logger::log_job($this->get_id(), $message);
            Rmmigrate_Activity_Log::record($type, $message, 'warning', array(
                'job_id' => $this->get_id(),
            ));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function build_last_error_payload(string $error): array
    {
        $job_type = $this->get_job_type();
        if ((string) ($this->data['triggered_by'] ?? '') === 'import') {
            $job_type = 'import';
        }
        $progress = $this->get_progress();
        $code     = sanitize_key((string) ($progress['service_code'] ?? ''));
        if ($code === '') {
            $code = Rmmigrate_Error_Codes::from_message($error);
        }

        return array(
            'job_id'     => $this->get_id(),
            'message'    => $error,
            'job_type'   => $job_type,
            'time'       => current_time('mysql', true),
            'scope'      => $this->get_scope(),
            'blog_id'    => $this->get_blog_id(),
            'code'       => $code,
            'error_code' => $code,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function get_progress(): array
    {
        $raw = $this->data['progress'] ?? '{}';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string,mixed> $patch
     */
    /**
     * @param array<string,mixed> $fields
     */
    public function update_data(array $fields): void
    {
        global $wpdb;
        $allowed = array('local_path', 'file_size', 'destination');
        $update = array('updated_at' => current_time('mysql', true));
        $formats = array('%s');
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $update[$key] = $fields[$key];
                $formats[] = in_array($key, array('file_size'), true) ? '%d' : '%s';
            }
        }
        $updated = $wpdb->update(self::table_name(), $update, array('id' => $this->get_id()), $formats, array('%d'));
        if ($updated === false) {
            self::log_db_write_failure('update_data', $this->get_id());
        }
        $this->data = array_merge($this->data, $update);
    }

    public function update_progress(array $patch): void
    {
        $this->persist_progress(array_replace_recursive($this->get_progress(), $patch));
    }

    /**
     * Replace a top-level progress section entirely (no recursive merge).
     *
     * @param array<string,mixed> $value
     */
    public function replace_progress_section(string $section, array $value): void
    {
        $progress = $this->get_progress();
        $progress[$section] = $value;
        $this->persist_progress($progress);
    }

    /**
     * @param array<string,mixed> $progress
     */
    private function persist_progress(array $progress): void
    {
        global $wpdb;
        $updated = $wpdb->update(
            self::table_name(),
            array(
                'progress'   => wp_json_encode($progress),
                'updated_at' => current_time('mysql', true),
            ),
            array('id' => $this->get_id()),
            array('%s', '%s'),
            array('%d')
        );
        if ($updated === false) {
            self::log_db_write_failure('persist_progress', $this->get_id());
        }
        $this->data['progress'] = wp_json_encode($progress);
        $this->data['updated_at'] = current_time('mysql', true);
    }

    private static function log_db_write_failure(string $operation, int $job_id): void
    {
        global $wpdb;

        $db_error = is_string($wpdb->last_error) ? trim($wpdb->last_error) : '';
        if ($db_error === '') {
            $db_error = 'unknown database write failure';
        }

        Rmmigrate_Logger::log_system(
            sprintf('Job #%d %s failed: %s', $job_id, $operation, $db_error),
            array(
                'job_id'    => $job_id,
                'operation' => $operation,
            ),
            'error'
        );

        if ($job_id > 0) {
            Rmmigrate_Logger::log_job(
                $job_id,
                sprintf('Database write failed (%s): %s', $operation, $db_error)
            );
        }
    }

    public function get_work_dir(): string
    {
        $progress = $this->get_progress();
        $rel = $progress['init']['work_dir'] ?? ('jobs/' . $this->get_id() . '/');
        return trailingslashit(Rmmigrate_Plugin::backups_dir()) . ltrim($rel, '/');
    }

    public function cancel(): void
    {
        if ($this->is_restore()) {
            $status = $this->get_status();
            if ($status > self::STATUS_PENDING && $status < self::STATUS_COMPLETE) {
                $progress = $this->get_progress();
                $progress['step'] = 'cancelled_partial';
                $progress['cancel_notice'] = __(
                    'Restore cancelled. The site may be inconsistent — use a recovery point or safety snapshot before retrying.',
                    'rosenheinrich-multisite-migrate'
                );
                $this->update_progress($progress);
            }
            Rmmigrate_Restore_Runner::disable_maintenance();
        }
        $this->set_status(self::STATUS_CANCELLED);
        if (Rmmigrate_Runner::job_worker_is_idle($this->get_id())) {
            Rmmigrate_Job_Cleanup::purge_cancelled_artifacts($this);
        }
    }

    public function get_percent(): int
    {
        if ($this->is_restore()) {
            return Rmmigrate_Restore_Runner::get_percent($this);
        }

        $status = $this->get_status();
        $progress = $this->get_progress();

        if ($status === self::STATUS_COMPLETE) {
            return 100;
        }
        if ($status < 0) {
            return 0;
        }

        $map = array(
            self::STATUS_PENDING      => 0,
            self::STATUS_INIT         => 5,
            self::STATUS_DUMPING_DB   => 15,
            self::STATUS_DB_DONE      => 30,
            self::STATUS_ARCHIVING    => 50,
            self::STATUS_ARCHIVE_DONE => 90,
        );
        $base = $map[$status] ?? 0;

        if ($status === self::STATUS_DUMPING_DB) {
            $base = $this->database_progress_percent($progress['database'] ?? array());
        }

        if ($status === self::STATUS_ARCHIVING && !empty($progress['archive']['total_files'])) {
            $idx = (int) ($progress['archive']['file_index'] ?? 0);
            $total = (int) $progress['archive']['total_files'];
            if ($total > 0) {
                $base = 30 + (int) floor(($idx / $total) * 60);
            }
        }

        return min(99, max(0, $base));
    }

    public function get_status_label(): string
    {
        if ($this->is_restore()) {
            return Rmmigrate_Restore_Runner::get_status_label($this);
        }

        $labels = array(
            self::STATUS_PENDING      => __('Pending', 'rosenheinrich-multisite-migrate'),
            self::STATUS_INIT         => __('Initializing', 'rosenheinrich-multisite-migrate'),
            self::STATUS_DUMPING_DB   => __('Dumping database', 'rosenheinrich-multisite-migrate'),
            self::STATUS_DB_DONE      => __('Database complete', 'rosenheinrich-multisite-migrate'),
            self::STATUS_ARCHIVING    => __('Archiving files', 'rosenheinrich-multisite-migrate'),
            self::STATUS_ARCHIVE_DONE => __('Archive complete', 'rosenheinrich-multisite-migrate'),
            self::STATUS_COMPLETE     => __('Complete', 'rosenheinrich-multisite-migrate'),
            self::STATUS_ERROR        => __('Error', 'rosenheinrich-multisite-migrate'),
            self::STATUS_CANCELLED    => __('Cancelled', 'rosenheinrich-multisite-migrate'),
            self::STATUS_DELETING     => __('Deleting', 'rosenheinrich-multisite-migrate'),
        );
        return $labels[$this->get_status()] ?? __('Unknown', 'rosenheinrich-multisite-migrate');
    }

    public function get_progress_message(): string
    {
        if ($this->is_restore()) {
            return Rmmigrate_Restore_Runner::get_status_label($this);
        }

        $status = $this->get_status();
        $progress = $this->get_progress();

        if ($status === self::STATUS_COMPLETE) {
            return __('Your backup is ready.', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_ERROR) {
            return $this->data['error_message'] ?? __('Something went wrong during the backup.', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_CANCELLED) {
            return __('Backup cancelled.', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_DELETING) {
            return __('Deleting backup…', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_PENDING || $status === self::STATUS_INIT) {
            return __('Preparing your backup…', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_DUMPING_DB) {
            if ($this->skips_database()) {
                return __('Skipping database (files only)…', 'rosenheinrich-multisite-migrate');
            }
            $db = $progress['database'] ?? array();
            $phase = $db['phase'] ?? '';
            $tables = $db['tables'] ?? array();
            $total = is_array($tables) ? count($tables) : 0;
            $mode = (string) ($db['mode'] ?? '');
            if ($mode === 'mysqldump' && $total > 0) {
                $md_phase = (string) ($db['mysqldump_phase'] ?? 'schema');
                if ($md_phase === 'schema') {
                    return sprintf(
                        /* translators: %d: total tables */
                        __('Saving database structure for %d tables…', 'rosenheinrich-multisite-migrate'),
                        $total
                    );
                }
                $idx = min((int) ($db['mysqldump_table_index'] ?? 0) + 1, $total);
                return sprintf(
                    /* translators: 1: current table number, 2: total tables */
                    __('Saving database content — table %1$d of %2$d…', 'rosenheinrich-multisite-migrate'),
                    $idx,
                    $total
                );
            }
            if ($phase === 'inserts' && $total > 0) {
                $idx = min((int) ($db['table_index'] ?? 0) + 1, $total);
                $table_rows = (int) ($db['table_rows_done'] ?? 0);
                $total_rows = (int) ($db['rows_done'] ?? 0);
                if ($total_rows > 0) {
                    if ($table_rows > 0) {
                        return sprintf(
                            /* translators: 1: current table number, 2: total tables, 3: rows in current table, 4: cumulative rows exported */
                            __('Saving your site content — table %1$d of %2$d (%3$s rows in this table, %4$s exported so far)…', 'rosenheinrich-multisite-migrate'),
                            $idx,
                            $total,
                            number_format_i18n($table_rows),
                            number_format_i18n($total_rows)
                        );
                    }
                    return sprintf(
                        /* translators: 1: current table number, 2: total tables, 3: cumulative rows exported */
                        __('Saving your site content — table %1$d of %2$d (%3$s rows exported so far)…', 'rosenheinrich-multisite-migrate'),
                        $idx,
                        $total,
                        number_format_i18n($total_rows)
                    );
                }
                return sprintf(
                    /* translators: 1: current table number, 2: total tables */
                    __('Saving your site content — table %1$d of %2$d…', 'rosenheinrich-multisite-migrate'),
                    $idx,
                    $total
                );
            }
            if ($phase === 'creates') {
                return __('Preparing database tables…', 'rosenheinrich-multisite-migrate');
            }
            $estimated = (int) ($db['estimated_db_bytes'] ?? 0);
            $written = (int) ($db['sql_bytes_written'] ?? 0);
            if ($estimated > 0 && $written > 0) {
                $pct = min(99, (int) floor(($written / $estimated) * 100));
                return sprintf(
                    /* translators: %d: database export progress percentage */
                    __('Saving your site database — %d%%…', 'rosenheinrich-multisite-migrate'),
                    $pct
                );
            }
            return __('Saving your site database…', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_DB_DONE) {
            return __('Database saved.', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_ARCHIVING) {
            if ($this->skips_files()) {
                return __('Packaging database backup…', 'rosenheinrich-multisite-migrate');
            }
            $idx = (int) ($progress['archive']['file_index'] ?? 0);
            $total = (int) ($progress['archive']['total_files'] ?? 0);
            if ($total > 0) {
                $offset = (int) ($progress['archive']['entry_source_offset'] ?? 0);
                $entry_total = (int) ($progress['archive']['entry_total_bytes'] ?? 0);
                if ($offset > 0 && $entry_total > 0) {
                    $file_pct = min(99, (int) floor(($offset / $entry_total) * 100));
                    return sprintf(
                        /* translators: 1: files processed, 2: total files, 3: percent of current file */
                        __('Adding site files — %1$d of %2$d (%3$d%% of current file)…', 'rosenheinrich-multisite-migrate'),
                        min($idx, $total),
                        $total,
                        $file_pct
                    );
                }
                return sprintf(
                    /* translators: 1: files processed, 2: total files */
                    __('Adding site files — %1$d of %2$d…', 'rosenheinrich-multisite-migrate'),
                    min($idx, $total),
                    $total
                );
            }
            if (!empty($progress['file_scan']['heartbeat_at']) && empty($progress['archive']['file_list_built'])) {
                return __('Scanning files…', 'rosenheinrich-multisite-migrate');
            }
            return __('Collecting your site files…', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_ARCHIVE_DONE) {
            $fin = is_array($progress['finalize'] ?? null) ? $progress['finalize'] : array();
            $phase = (string) ($fin['phase'] ?? '');
            $hash_total = (int) ($fin['hash_total'] ?? 0);
            $hash_index = (int) ($fin['hash_index'] ?? 0);
            if (($phase === 'hashes' || $phase === '') && $hash_total > 0 && $hash_index < $hash_total) {
                return sprintf(
                    /* translators: 1: files hashed, 2: total files */
                    __('Verifying files — %1$d of %2$d…', 'rosenheinrich-multisite-migrate'),
                    $hash_index,
                    $hash_total
                );
            }
            if ($phase === 'encrypt') {
                return __('Encrypting archive…', 'rosenheinrich-multisite-migrate');
            }
            return __('Finalizing backup package…', 'rosenheinrich-multisite-migrate');
        }

        return $this->get_status_label();
    }

    /**
     * @param array<string,mixed> $db
     */
    private function database_progress_percent(array $db): int
    {
        $tables = $db['tables'] ?? array();
        $total_tables = is_array($tables) ? count($tables) : 0;
        if ($total_tables === 0) {
            return 12;
        }

        $mode = (string) ($db['mode'] ?? '');
        $phase = (string) ($db['phase'] ?? '');
        $estimated = (int) ($db['estimated_db_bytes'] ?? 0);
        $written = (int) ($db['sql_bytes_written'] ?? 0);
        $md_phase = (string) ($db['mysqldump_phase'] ?? 'schema');

        if ($mode === 'mysqldump' && $total_tables > 0) {
            if ($md_phase === 'schema') {
                return 8;
            }

            $idx = (int) ($db['mysqldump_table_index'] ?? 0);
            if ($estimated > 0 && $written > 0) {
                $schema_weight = 0.15;
                $data_fraction = min(1.0, $written / max(1, $estimated));
                return 5 + (int) floor(($schema_weight + ((1.0 - $schema_weight) * $data_fraction)) * 20);
            }

            $steps_done = $total_tables + min($idx, $total_tables);
            $total_steps = max(1, $total_tables * 2);
            return 5 + (int) floor(($steps_done / $total_steps) * 20);
        }

        if ($estimated > 0 && $written > 0 && $phase === 'inserts') {
            return 5 + (int) floor(min(1.0, $written / $estimated) * 20);
        }

        if ($phase === 'inserts') {
            $idx = (int) ($db['table_index'] ?? 0);
            return 5 + (int) floor((min($idx, $total_tables) / $total_tables) * 20);
        }

        if ($phase === 'creates') {
            return 8;
        }

        return 12;
    }

    public function get_display_status(): string
    {
        $status = $this->get_status();
        $error = trim((string) ($this->data['error_message'] ?? ''));
        if ($status === self::STATUS_COMPLETE) {
            if ($error !== '') {
                return __('Complete (with warning)', 'rosenheinrich-multisite-migrate');
            }
            return __('Complete', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_ERROR) {
            return __('Failed', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_CANCELLED) {
            return __('Cancelled', 'rosenheinrich-multisite-migrate');
        }
        if ($status === self::STATUS_DELETING) {
            return __('Deleting…', 'rosenheinrich-multisite-migrate');
        }
        if ($status >= 0 && $status < 100) {
            /* translators: %d: progress percentage */
            return sprintf(__('In progress (%1$d%%)', 'rosenheinrich-multisite-migrate'), $this->get_percent());
        }
        return $this->get_status_label();
    }

    public function save_fields(array $fields): void
    {
        global $wpdb;
        $fields['updated_at'] = current_time('mysql', true);
        $wpdb->update(self::table_name(), $fields, array('id' => $this->get_id()));
        $this->data = array_merge($this->data, $fields);
    }

    /**
     * @return self[]
     */
    public static function list_completed(int $limit = 50): array
    {
        return self::list_jobs(array(
            'status_group' => 'completed',
            'job_type'     => self::JOB_TYPE_BACKUP,
            'limit'        => $limit,
        ));
    }

    public static function get_last_completed(): ?self
    {
        $jobs = self::list_completed(1);
        return $jobs[0] ?? null;
    }
}
