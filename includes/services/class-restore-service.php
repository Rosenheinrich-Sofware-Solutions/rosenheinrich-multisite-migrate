<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Rmmigrate_Restore_Service
{
    /**
     * @return array<string,mixed>
     */
    public static function quick_restore_defaults(): array
    {
        return array(
            'restore_mode'      => Rmmigrate_Job::RESTORE_MODE_BOTH,
            'restore_type'      => Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER,
            'confirm_overwrite' => true,
            'safety_snapshot'   => true,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function prefetch_archive(int $job_id): array
    {
        $job = self::require_job($job_id);
        Rmmigrate_Job_Preflight::assert_can_view_job($job);
        $path = Rmmigrate_Local_Archive::ensure_local_archive($job);
        if (is_wp_error($path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($path->get_error_message()),
                array('phase' => 'prefetch'), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($path)));
        }
        return array('ready' => true);
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_manifest(int $job_id): array
    {
        $job = self::require_complete_job($job_id);
        Rmmigrate_Job_Preflight::assert_can_view_job($job);
        Rmmigrate_Job_Preflight::ensure_local_archive($job);

        $archive_path = Rmmigrate_Runner::resolve_local_path($job);
        if (!Rmmigrate_Filesystem::exists($archive_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Archive file is missing on this server. Import the backup archive first.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::ARCHIVE_MISSING));
        }

        $manifest = self::resolve_job_manifest($job, $archive_path);
        $manifest_incomplete = false;
        if ($manifest === null) {
            $manifest_incomplete = true;
            $manifest = array(
                'site_url'     => site_url(),
                'home_url'     => home_url(),
                'is_multisite' => is_multisite(),
            );
        }

        return array(
            'manifest'              => self::redact_manifest_for_admin($manifest),
            'manifest_incomplete'   => $manifest_incomplete,
            'install_type_mismatch' => !$manifest_incomplete && self::install_type_mismatch($manifest),
            'is_encrypted'          => substr($archive_path, -strlen(Rmmigrate_Archive_Encryption::EXT)) === Rmmigrate_Archive_Encryption::EXT,
        );
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private static function redact_manifest_for_admin(array $manifest): array
    {
        static $secret_keys = array(
            'db_password',
            'db_pass',
            'installer_passcode',
            'installer_password_hash',
            'archive_passphrase',
            'encryption_key',
            'manifest_signature',
        );
        foreach ($manifest as $key => $value) {
            if (in_array((string) $key, $secret_keys, true)) {
                unset($manifest[$key]);
                continue;
            }
            if (is_array($value)) {
                $manifest[$key] = self::redact_manifest_for_admin($value);
            }
        }

        return $manifest;
    }

    /**
     * @return array{pairs:array<int,array<string,string>>,count:int}
     */
    public static function migration_preview(string $old_url, string $new_url): array
    {
        if ($new_url === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(esc_html(__('Enter a new URL.', 'rosenheinrich-multisite-migrate')));
        }
        $pairs = Rmmigrate_Search_Replace::preview_url_pairs($old_url, $new_url);
        return array(
            'pairs' => $pairs,
            'count' => count($pairs),
        );
    }

    /**
     * @param array{target_index?:int,last_id?:int,updated_so_far?:int} $resume
     * @return array{updated:int,complete:bool,resume:?array{target_index:int,last_id:int,updated_so_far:int},message:string}
     */
    public static function migration_search_replace(string $old_url, string $new_url, array $resume = array()): array
    {
        if ($old_url === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(esc_html(__('Enter an old URL.', 'rosenheinrich-multisite-migrate')));
        }
        if ($new_url === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(esc_html(__('Enter a new URL.', 'rosenheinrich-multisite-migrate')));
        }

        $map = array(
            'site_url' => array('old' => $old_url, 'new' => $new_url),
            'home_url' => array('old' => $old_url, 'new' => $new_url),
        );
        $engine = new Rmmigrate_Search_Replace($map);
        $targets = self::migration_search_replace_targets();
        $updated = 0;
        $updated_so_far = max(0, (int) ($resume['updated_so_far'] ?? 0));
        $target_index = max(0, (int) ($resume['target_index'] ?? 0));
        $last_id = max(0, (int) ($resume['last_id'] ?? 0));
        $start = microtime(true);
        $rows_scanned = 0;
        $batch_size = 200;
        $time_budget_sec = 20;
        $row_budget = 1000;

        global $wpdb;
        while ($target_index < count($targets)) {
            if ($rows_scanned >= $row_budget || (microtime(true) - $start) >= $time_budget_sec) {
                break;
            }
            $target = $targets[$target_index];
            $table = $target['table'];
            $id_col = $target['id_col'];
            $quoted = Rmmigrate_Snap_DB::quote_identifier($table);
            $id_quoted = Rmmigrate_Snap_DB::quote_identifier($id_col);
            $col_quoted = array_map(array(Rmmigrate_Snap_DB::class, 'quote_identifier'), $target['columns']);
            $select_cols = array_unique(array_merge(array($id_quoted), $col_quoted));
            $sql = 'SELECT ' . implode(', ', $select_cols) . ' FROM ' . $quoted
                . ' WHERE ' . $id_quoted . ' > ' . (int) $last_id
                . ' ORDER BY ' . $id_quoted . ' ASC LIMIT ' . (int) $batch_size;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Batch read; identifiers quoted; LIMIT ints.
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if ($wpdb->last_error !== '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
                throw new Rmmigrate_Service_Exception(
                    esc_html(__('Search & Replace failed while reading the database.', 'rosenheinrich-multisite-migrate')),
                    array('phase' => 'search_replace'),
                    sanitize_key(Rmmigrate_Error_Codes::VALIDATION)
                );
            }
            if (empty($rows)) {
                $target_index++;
                $last_id = 0;
                continue;
            }
            foreach ($rows as $row) {
                if ($rows_scanned >= $row_budget || (microtime(true) - $start) >= $time_budget_sec) {
                    break 2;
                }
                $rows_scanned++;
                $id = $row[$id_col] ?? null;
                if ($id === null) {
                    continue;
                }
                $last_id = max($last_id, (int) $id);
                $fields = array();
                foreach ($target['columns'] as $column) {
                    if (!array_key_exists($column, $row) || !is_string($row[$column]) || $row[$column] === '') {
                        continue;
                    }
                    $replaced = $engine->apply($row[$column]);
                    if ($replaced !== $row[$column]) {
                        $fields[$column] = $replaced;
                    }
                }
                if ($fields !== array()) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirmed repair write on validated table/columns.
                    $written = $wpdb->update($table, $fields, array($id_col => $id));
                    if ($written !== false) {
                        $updated++;
                    }
                }
            }
            if (count($rows) < $batch_size) {
                $target_index++;
                $last_id = 0;
            }
        }

        $complete = $target_index >= count($targets);
        $updated_total = $updated_so_far + $updated;
        $result = array(
            'updated'  => $updated_total,
            'complete' => $complete,
            'resume'   => $complete ? null : array(
                'target_index'  => $target_index,
                'last_id'       => $last_id,
                'updated_so_far'=> $updated_total,
            ),
            'message'  => $complete
                ? sprintf(
                    /* translators: %d: rows updated */
                    __('Updated %d row(s).', 'rosenheinrich-multisite-migrate'),
                    $updated_total
                )
                : __('Search & Replace in progress…', 'rosenheinrich-multisite-migrate'),
        );

        return $result;
    }

    /**
     * @return array<int,array{table:string,columns:string[],id_col:string}>
     */
    private static function migration_search_replace_targets(): array
    {
        global $wpdb;
        $prefix = (string) $wpdb->prefix;
        $candidates = array(
            array('table' => $prefix . 'options', 'columns' => array('option_value'), 'id_col' => 'option_id'),
            array('table' => $prefix . 'posts', 'columns' => array('post_content', 'post_excerpt', 'guid'), 'id_col' => 'ID'),
            array('table' => $prefix . 'postmeta', 'columns' => array('meta_value'), 'id_col' => 'meta_id'),
            array('table' => $prefix . 'comments', 'columns' => array('comment_content', 'comment_author_url'), 'id_col' => 'comment_ID'),
            array('table' => $prefix . 'usermeta', 'columns' => array('meta_value'), 'id_col' => 'umeta_id'),
        );

        $out = array();
        foreach ($candidates as $candidate) {
            $like = $wpdb->esc_like($candidate['table']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence probe.
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            if ($found === $candidate['table']) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function start_restore(array $params = array()): array
    {
        Rmmigrate_Job::recover_stale_active();

        $params = Rmmigrate_Access::subsite_restore_params($params);
        $source_id = (int) ($params['source_job_id'] ?? 0);
        $mode = (string) ($params['restore_mode'] ?? Rmmigrate_Job::RESTORE_MODE_BOTH);
        $restore_type = (string) ($params['restore_type'] ?? Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER);
        $confirm = array_key_exists('confirm_overwrite', $params)
            ? !empty($params['confirm_overwrite'])
            : true;

        if (!$confirm) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(esc_html(__('Check the confirmation box to continue — restore will overwrite existing data.', 'rosenheinrich-multisite-migrate')));
        }

        $migration_map = array();
        if ($restore_type === Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            $migration_map = self::parse_migration_map($params);
            if (empty($migration_map['site_url']['new'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
                throw new Rmmigrate_Service_Exception(esc_html(__('Enter the new site URL for migration.', 'rosenheinrich-multisite-migrate')));
            }
        }

        $source = self::require_job($source_id);
        Rmmigrate_Job_Preflight::assert_can_view_job($source);
        Rmmigrate_Access::assert_subsite_restore_source($source);

        Rmmigrate_Job_Preflight::ensure_local_archive($source);

        $archive_path = Rmmigrate_Runner::resolve_local_path($source);
        $disk = Rmmigrate_Validator::validate_restore_disk_space($archive_path);
        if (is_wp_error($disk)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($disk->get_error_message()),
                array('phase' => 'disk_space'), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($disk)));
        }

        $manifest = self::resolve_job_manifest($source, $archive_path);
        $topology = array();
        if (is_array($manifest)) {
            $topology = self::parse_topology($params, $manifest);
            $destination = Rmmigrate_Restore_Topology_Destination::probe_for_plugin();
            $gate_state = array_merge(
                array(
                    'install_mode' => $restore_type === Rmmigrate_Job::RESTORE_TYPE_MIGRATION ? 'standard' : 'overwrite',
                    'archive'      => array('has_wp_core_files' => !empty($manifest['has_wp_core_files'])),
                ),
                $topology
            );
            $gate = Rmmigrate_Restore_Topology_Compatibility::validate_gates(
                $gate_state,
                $manifest,
                $destination,
                false
            );
            if ($gate !== null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
                throw new Rmmigrate_Service_Exception(
                    esc_html($gate['message']),
                    array(
                        'gate'          => esc_html((string) $gate['gate']),
                        'recovery_hint' => esc_html((string) $gate['recovery_hint']),
                    ), sanitize_key(Rmmigrate_Error_Codes::RESTORE_GATE_BLOCKED));
            }

            $topology = self::apply_topology_extras($topology, $params, $manifest);
        }

        $safety_id = (int) ($params['safety_job_id'] ?? 0);
        $use_safety_snapshot = array_key_exists('safety_snapshot', $params)
            ? !empty($params['safety_snapshot'])
            : true;
        if ($use_safety_snapshot && $safety_id <= 0) {
            $safety_id = Rmmigrate_Safety_Snapshot::create_before_restore($source, $mode);
            if ($safety_id <= 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
                throw new Rmmigrate_Service_Exception(
                    esc_html(__('Safety snapshot could not be created. Restore was cancelled.', 'rosenheinrich-multisite-migrate')),
                    array('phase' => 'restore'),
                    sanitize_key(Rmmigrate_Error_Codes::FILE_RESTORE_FAILED)
                );
            } elseif (!Rmmigrate_Safety_Snapshot::is_complete($safety_id)) {
                return array(
                    'awaiting_safety'      => true,
                    'waiting_for_snapshot' => true,
                    'safety_job_id'        => $safety_id,
                    'message'              => sprintf(
                        /* translators: %d: backup job ID */
                        __('Creating safety backup first (job #%1$d)…', 'rosenheinrich-multisite-migrate'),
                        $safety_id
                    ),
                );
            }
        } elseif ($safety_id > 0 && !Rmmigrate_Safety_Snapshot::is_complete($safety_id)) {
            return array(
                'awaiting_safety'      => true,
                'waiting_for_snapshot' => true,
                'safety_job_id'        => $safety_id,
                'message'              => sprintf(
                    /* translators: %d: backup job ID */
                    __('Creating safety backup first (job #%1$d)…', 'rosenheinrich-multisite-migrate'),
                    $safety_id
                ),
            );
        }

        $archive_passphrase = (string) ($params['archive_passphrase'] ?? '');
        $job = Rmmigrate_Job::create_restore($source_id, $mode, array(
            'restore_type'         => $restore_type,
            'migration_map'      => $migration_map,
            'archive_passphrase' => $archive_passphrase,
            'topology'           => $topology,
            'safety_job_id'      => $safety_id,
        ));

        $kick = !empty($params['kick_worker']);
        if ($kick) {
            Rmmigrate_Runner::kick_worker($job->get_id());
        }

        return array(
            'job_id'        => $job->get_id(),
            'safety_job_id' => $safety_id,
            'activity_url'  => Rmmigrate_Admin_Router::admin_url(
                'multisite-migrate-activity',
                array(),
                is_multisite() && current_user_can('manage_network')
            ),
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function parse_migration_map(array $params): array
    {
        $map = array(
            'site_url' => array(
                'old' => (string) ($params['old_site_url'] ?? $params['old_url'] ?? ''),
                'new' => (string) ($params['new_site_url'] ?? $params['new_url'] ?? ''),
            ),
            'home_url' => array(
                'old' => (string) ($params['old_home_url'] ?? $params['old_url'] ?? ''),
                'new' => (string) ($params['new_home_url'] ?? $params['new_url'] ?? ''),
            ),
            'blogs' => array(),
        );

        $blog_domains = $params['blog_domains'] ?? null;
        if (is_string($blog_domains) && $blog_domains !== '') {
            $decoded = json_decode($blog_domains, true);
            $blog_domains = is_array($decoded) ? $decoded : null;
        }
        if (is_array($blog_domains)) {
            foreach ($blog_domains as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $map['blogs'][] = array(
                    'blog_id'    => (int) ($entry['blog_id'] ?? 0),
                    'old_domain' => self::sanitize_blog_map_field($entry['old_domain'] ?? ''),
                    'new_domain' => self::sanitize_blog_map_field($entry['new_domain'] ?? ''),
                    'old_path'   => self::sanitize_blog_map_field($entry['old_path'] ?? '/', '/'),
                    'new_path'   => self::sanitize_blog_map_field($entry['new_path'] ?? '/', '/'),
                );
            }
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private static function parse_topology(array $params, array $manifest): array
    {
        if (!class_exists('Rmmigrate_Restore_Topology_Ui', false)) {
            require_once RMMIGRATE_PATH . 'includes/shared/restore-topology/bootstrap.php';
        }

        return Rmmigrate_Restore_Topology_Ui::parse_topology_import(
            array(
                'topology_import'         => (string) ($params['topology_import'] ?? 'auto'),
                'topology_target_blog_id' => (string) ($params['topology_target_blog_id'] ?? '0'),
                'topology_target_url'     => (string) ($params['topology_target_url'] ?? ''),
                'prefix_policy'           => (string) ($params['prefix_policy'] ?? 'keep_source'),
            ),
            $manifest,
            Rmmigrate_Restore_Topology_Destination::probe_for_plugin()
        );
    }

    /**
     * @param array<string,mixed> $topology
     * @param array<string,mixed> $params
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private static function apply_topology_extras(array $topology, array $params, array $manifest): array
    {
        if (!class_exists('Rmmigrate_Restore_Topology_Ui', false)) {
            require_once RMMIGRATE_PATH . 'includes/shared/restore-topology/bootstrap.php';
        }

        $merge = $params['wp_config_merge_constants'] ?? array();
        if (is_string($merge) && $merge !== '') {
            $merge = array_filter(array_map('trim', explode(',', $merge)));
        }
        if (is_array($merge) && $merge !== array()) {
            $allowed = Rmmigrate_Restore_Topology_Ui::wp_config_merge_constant_keys();
            $topology['wp_config_merge_constants'] = array_values(array_intersect($allowed, array_map('strval', $merge)));
        }

        $skip = $params['import_skip_tables'] ?? array();
        if (is_string($skip) && $skip !== '') {
            $skip = array_filter(array_map('trim', explode(',', $skip)));
        }
        if (is_array($skip) && $skip !== array()) {
            $topology['import_skip_tables'] = Rmmigrate_Restore_Topology_Ui::normalize_import_skip_tables(
                $manifest,
                array_map('strval', $skip)
            );
        }

        return $topology;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private static function install_type_mismatch(array $manifest): bool
    {
        if (!is_multisite() || empty($manifest['is_multisite'])) {
            return false;
        }
        if (!array_key_exists('subdomain_install', $manifest)) {
            return false;
        }
        $archive_subdomain = (bool) $manifest['subdomain_install'];
        $current_subdomain = defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL;
        return $archive_subdomain !== $current_subdomain;
    }

    /**
     * Prefer job progress (written during backup), then ZIP/DAF archive.
     *
     * @return array<string,mixed>|null
     */
    private static function resolve_job_manifest(Rmmigrate_Job $job, string $archive_path): ?array
    {
        $progress = $job->get_progress();
        if (!empty($progress['manifest']) && is_array($progress['manifest'])) {
            return $progress['manifest'];
        }

        return Rmmigrate_Manifest::read_from_archive($archive_path);
    }

    private static function require_job(int $job_id): Rmmigrate_Job
    {
        if ($job_id <= 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Source backup not found. It may have been deleted.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::SOURCE_NOT_FOUND));
        }
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Source backup not found. It may have been deleted.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::SOURCE_NOT_FOUND));
        }
        return $job;
    }

    private static function sanitize_blog_map_field($value, string $default = ''): string
    {
        if (!is_scalar($value)) {
            return $default;
        }

        return sanitize_text_field((string) $value);
    }

    private static function require_complete_job(int $job_id): Rmmigrate_Job
    {
        $job = self::require_job($job_id);
        if ($job->get_status() !== Rmmigrate_Job::STATUS_COMPLETE) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Backup not found or not complete yet.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::SOURCE_NOT_COMPLETE));
        }
        return $job;
    }
}
