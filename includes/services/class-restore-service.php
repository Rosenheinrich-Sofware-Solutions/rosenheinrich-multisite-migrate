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
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function prefetch_archive(int $job_id): array
    {
        $job = self::require_job($job_id);
        Rmmigrate_Job_Preflight::assert_can_view_job($job);
        $path = Rmmigrate_Local_Archive::ensure_local_archive($job);
        if (is_wp_error($path)) {
            throw new Rmmigrate_Service_Exception(esc_html($path->get_error_message()), array('phase' => 'prefetch'));
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
            throw new Rmmigrate_Service_Exception(esc_html(__('Archive file is missing on this server. Import the backup archive first.', 'rosenheinrich-multisite-migrate')));
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
            'is_encrypted'          => substr($archive_path, -5) === Rmmigrate_Archive_Encryption::EXT,
        );
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private static function redact_manifest_for_admin(array $manifest): array
    {
        foreach (array(
            'db_password',
            'db_pass',
            'installer_passcode',
            'installer_password_hash',
            'archive_passphrase',
            'encryption_key',
            'manifest_signature',
        ) as $key) {
            unset($manifest[$key]);
        }

        return $manifest;
    }

    /**
     * @return array{pairs:array<int,array<string,string>>,count:int}
     */
    public static function migration_preview(string $old_url, string $new_url): array
    {
        if ($new_url === '') {
            throw new Rmmigrate_Service_Exception(esc_html(__('Enter a new URL.', 'rosenheinrich-multisite-migrate')));
        }
        $pairs = Rmmigrate_Search_Replace::preview_url_pairs($old_url, $new_url);
        return array(
            'pairs' => $pairs,
            'count' => count($pairs),
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function start_restore(array $params = array()): array
    {
        $params = Rmmigrate_Access::subsite_restore_params($params);
        $source_id = (int) ($params['source_job_id'] ?? 0);
        $mode = (string) ($params['restore_mode'] ?? Rmmigrate_Job::RESTORE_MODE_BOTH);
        $restore_type = (string) ($params['restore_type'] ?? Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER);
        $confirm = array_key_exists('confirm_overwrite', $params)
            ? !empty($params['confirm_overwrite'])
            : true;

        if (!$confirm) {
            throw new Rmmigrate_Service_Exception(esc_html(__('Check the confirmation box to continue — restore will overwrite existing data.', 'rosenheinrich-multisite-migrate')));
        }

        $migration_map = array();
        if ($restore_type === Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            $migration_map = self::parse_migration_map($params);
            if (empty($migration_map['site_url']['new'])) {
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
            throw new Rmmigrate_Service_Exception(esc_html($disk->get_error_message()), array('phase' => 'disk_space'));
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
                throw new Rmmigrate_Service_Exception(esc_html($gate['message']), array(
                    'gate'          => esc_html((string) $gate['gate']),
                    'recovery_hint' => esc_html((string) $gate['recovery_hint']),
                ));
            }

            $topology = self::apply_topology_extras($topology, $params, $manifest);
        }

        $safety_id = (int) ($params['safety_job_id'] ?? 0);
        $use_safety_snapshot = array_key_exists('safety_snapshot', $params)
            ? !empty($params['safety_snapshot'])
            : true;
        if ($use_safety_snapshot && $safety_id <= 0) {
            $safety_id = Rmmigrate_Safety_Snapshot::create_before_restore($source, $mode);
            if ($safety_id > 0 && !Rmmigrate_Safety_Snapshot::is_complete($safety_id)) {
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
                    'old_domain' => sanitize_text_field($entry['old_domain'] ?? ''),
                    'new_domain' => sanitize_text_field($entry['new_domain'] ?? ''),
                    'old_path'   => sanitize_text_field($entry['old_path'] ?? '/'),
                    'new_path'   => sanitize_text_field($entry['new_path'] ?? '/'),
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
            throw new Rmmigrate_Service_Exception(esc_html(__('Source backup not found. It may have been deleted.', 'rosenheinrich-multisite-migrate')));
        }
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            throw new Rmmigrate_Service_Exception(esc_html(__('Source backup not found. It may have been deleted.', 'rosenheinrich-multisite-migrate')));
        }
        return $job;
    }

    private static function require_complete_job(int $job_id): Rmmigrate_Job
    {
        $job = self::require_job($job_id);
        if ($job->get_status() !== Rmmigrate_Job::STATUS_COMPLETE) {
            throw new Rmmigrate_Service_Exception(esc_html(__('Backup not found or not complete yet.', 'rosenheinrich-multisite-migrate')));
        }
        return $job;
    }
}
