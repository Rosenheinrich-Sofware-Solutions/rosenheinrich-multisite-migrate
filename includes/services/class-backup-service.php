<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Rmmigrate_Backup_Service
{
    /**
     * @param array<string,mixed> $input
     * @return array{job_id:int}
     */
    public static function start_backup(array $input): array
    {
        Rmmigrate_Job::recover_stale_active();

        $triggered_by = (string) ($input['triggered_by'] ?? 'manual');
        $is_cron = ($triggered_by === 'cron');

        $scope = (string) ($input['scope'] ?? Rmmigrate_Multisite_Scope::SCOPE_NETWORK);
        if (!is_multisite()) {
            $scope = Rmmigrate_Multisite_Scope::SCOPE_NETWORK;
        } elseif (!$is_cron && !current_user_can('manage_network') && $scope !== Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Network backup requires super admin.', 'rosenheinrich-multisite-migrate')),
                array(), sanitize_key(Rmmigrate_Error_Codes::PERMISSION_DENIED));
        }

        $excluded = isset($input['excluded_blogs']) && is_array($input['excluded_blogs'])
            ? array_map('intval', $input['excluded_blogs'])
            : array();
        $included = isset($input['included_blogs']) && is_array($input['included_blogs'])
            ? array_map('intval', $input['included_blogs'])
            : array();
        $settings = null;
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED && $included === array()) {
            $settings = Rmmigrate_Settings::get();
            $included = array_map('intval', (array) ($settings['default_included_blogs'] ?? array()));
        }
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED && $excluded === array()) {
            $settings = $settings ?? Rmmigrate_Settings::get();
            $excluded = array_map('intval', (array) ($settings['default_excluded_blogs'] ?? array()));
        }
        // Backups are stored locally on this server.
        $destination = 'local';

        $allowed_profiles = array('full', 'db', 'files', 'media', 'plugins', 'themes', 'custom');
        $profile = (string) ($input['backup_profile'] ?? 'full');

        $custom_paths = array();
        if (!empty($input['custom_paths'])) {
            if (is_array($input['custom_paths'])) {
                $custom_paths = $input['custom_paths'];
            } else {
                $custom_paths = array_filter(array_map('trim', explode("\n", (string) $input['custom_paths'])));
            }
        }
        $excluded_tables = array();
        if (!empty($input['exclude_tables'])) {
            if (is_array($input['exclude_tables'])) {
                $excluded_tables = $input['exclude_tables'];
            } else {
                $excluded_tables = array_filter(array_map('trim', explode("\n", (string) $input['exclude_tables'])));
            }
        }
        $exclude_log_tables = !empty($input['exclude_log_tables']);
        $exclude_revisions = !empty($input['exclude_revisions']);
        $backup_type = 'full';

        // Wizard choice for this run. null = fall back to last-used setting.
        $include_wp_core_override = null;
        if (array_key_exists('include_wp_core', $input)
            && $input['include_wp_core'] !== null
            && $input['include_wp_core'] !== '') {
            $include_wp_core_override = !empty($input['include_wp_core']) && $input['include_wp_core'] !== '0';
        }

        // Wizard archive format. Empty = fall back to last-used setting.
        $archive_mode_override = strtolower((string) ($input['archive_mode'] ?? ''));
        if (!in_array($archive_mode_override, array('zip', 'daf'), true)) {
            $archive_mode_override = '';
        }

        $resolved = Rmmigrate_Multisite_Scope::resolve_backup_scope($scope, $excluded, $included, $is_cron);
        if (is_wp_error($resolved)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($resolved->get_error_message()),
                array(), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($resolved)));
        }
        $scope = $resolved['scope'];
        $excluded = $resolved['excluded_blogs'];

        if ($triggered_by === 'manual' && current_user_can(is_multisite() ? 'manage_network' : 'manage_options')) {
            $settings = Rmmigrate_Settings::get();
            $settings['default_scope'] = $scope;
            $settings['default_excluded_blogs'] = ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED) ? $excluded : array();
            $settings['default_included_blogs'] = ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED) ? $included : array();
            if (in_array($profile, $allowed_profiles, true)) {
                $settings['default_backup_profile'] = $profile;
            }
            if ($custom_paths !== array()) {
                $settings['default_custom_paths'] = implode("\n", $custom_paths);
            }
            if ($archive_mode_override !== '') {
                $settings['archive_mode'] = $archive_mode_override;
            }
            if ($include_wp_core_override !== null) {
                $settings['include_wp_core'] = $include_wp_core_override;
            }
            if (array_key_exists('exclude_log_tables', $input)) {
                $settings['exclude_log_tables'] = $exclude_log_tables;
            }
            if (array_key_exists('exclude_revisions', $input)) {
                $settings['exclude_revisions'] = $exclude_revisions;
            }
            Rmmigrate_Settings::save($settings);
        }

        $args = array(
            'scope'              => $scope,
            'blog_id'            => (int) ($input['blog_id'] ?? get_current_blog_id()),
            'excluded_blogs'     => $excluded,
            'included_blogs'     => $included,
            'excluded_tables'    => $excluded_tables,
            'exclude_log_tables' => $exclude_log_tables,
            'exclude_revisions'  => $exclude_revisions,
            'destination'        => $destination,
            'backup_profile'     => in_array($profile, $allowed_profiles, true) ? $profile : 'full',
            'archive_mode'       => $archive_mode_override,
            'custom_paths'       => wp_json_encode($custom_paths),
            'backup_type'        => $backup_type,
            'include_wp_core'    => $include_wp_core_override,
            'triggered_by'       => $triggered_by,
        );

        $validation = Rmmigrate_Validator::validate_backup_start($args);
        if (is_wp_error($validation)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($validation->get_error_message()),
                array('phase' => 'start'), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($validation)));
        }

        $job = Rmmigrate_Job::create($args);

        // Remember the chosen destination for this user so the wizard preselects
        // it next time (manual runs only; cron/CLI must not clobber it).
        if (($args['triggered_by'] ?? 'manual') === 'manual' && get_current_user_id() > 0) {
            update_user_meta(get_current_user_id(), 'rmmigrate_last_destination', $destination);
        }

        delete_transient('rmmigrate_preflight');
        if (!empty($input['kick_worker'])) {
            Rmmigrate_Runner::kick_worker($job->get_id());
        }

        return array('job_id' => $job->get_id());
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_status(?int $job_id = null): array
    {
        $job = $job_id ? Rmmigrate_Job::get($job_id) : Rmmigrate_Job::get_active();
        if ($job === null) {
            return array('active' => false);
        }
        Rmmigrate_Job_Preflight::assert_can_view_job($job);
        $active = $job->get_status() >= 0 && $job->get_status() < 100;
        $payload = array(
            'active'      => $active,
            'is_stale'    => $active && Rmmigrate_Job::is_stale($job),
            'lease_fresh' => Rmmigrate_Runner::lease_is_fresh($job->get_id()),
            'job_id'      => $job->get_id(),
            'job_type'    => $job->get_job_type(),
            'status'      => $job->get_status(),
            'percent'     => $job->get_percent(),
            'message'     => $job->is_restore()
                ? Rmmigrate_Restore_Runner::admin_progress_label($job)
                : $job->get_progress_message(),
            'error'       => $job->data['error_message'] ?? '',
        );
        if ($job->is_restore()) {
            $payload['progress_step'] = Rmmigrate_Restore_Runner::get_status_step($job);
        }

        return $payload;
    }

    public static function cancel(int $job_id): void
    {
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw Rmmigrate_Service_Exception::not_found(esc_html(__('Job not found.', 'rosenheinrich-multisite-migrate')));
        }
        Rmmigrate_Job_Preflight::assert_can_view_job($job);
        $job->cancel();
    }

    /**
     * @param int[] $job_ids
     * @return array{deleted:int,skipped:int,warnings:string[],message:string,queued:bool}
     */
    public static function delete_jobs(array $job_ids): array
    {
        $job_ids = array_values(array_unique(array_filter(array_map('intval', $job_ids), static function ($id) {
            return $id > 0;
        })));
        if ($job_ids === array()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(esc_html(__('Select at least one backup to delete.', 'rosenheinrich-multisite-migrate')));
        }

        $deleted = 0;
        $skipped = 0;
        $warnings = array();

        foreach ($job_ids as $job_id) {
            $job = Rmmigrate_Job::get($job_id);
            if ($job === null) {
                Rmmigrate_Job_Cleanup::purge_orphan_references($job_id);
                $deleted++;
                continue;
            }
            if (!Rmmigrate_Access::can_view_job($job)) {
                $skipped++;
                continue;
            }
            $result = self::delete_job_record($job);
            if (!$result['ok']) {
                $skipped++;
                continue;
            }
            $deleted++;
            if ($result['warning'] !== '') {
                $warnings[] = sprintf(
                    /* translators: 1: job ID, 2: warning message */
                    __('#%1$d: %2$s', 'rosenheinrich-multisite-migrate'),
                    $job_id,
                    $result['warning']
                );
            }
        }

        if ($deleted === 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(esc_html(__('No backups were deleted. Active or inaccessible jobs were skipped.', 'rosenheinrich-multisite-migrate')));
        }

        $message = sprintf(
            /* translators: %d: number of backups queued for deletion */
            _n('%d backup queued for deletion.', '%d backups queued for deletion.', $deleted, 'rosenheinrich-multisite-migrate'),
            $deleted
        );
        if ($skipped > 0) {
            $message .= ' ' . sprintf(
                /* translators: %d: number of skipped backups */
                _n('%d skipped.', '%d skipped.', $skipped, 'rosenheinrich-multisite-migrate'),
                $skipped
            );
        }

        return array(
            'deleted'  => $deleted,
            'skipped'  => $skipped,
            'warnings' => $warnings,
            'message'  => $message,
            'queued'   => true,
        );
    }

    /**
     * @return array{warning:string,message:string,queued:bool}
     */
    public static function delete_job(int $job_id): array
    {
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            Rmmigrate_Job_Cleanup::purge_orphan_references($job_id);
            return array('warning' => '', 'message' => '', 'queued' => false);
        }
        Rmmigrate_Job_Preflight::assert_can_view_job($job);
        $result = self::delete_job_record($job);
        if (!$result['ok']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(esc_html($result['message']));
        }
        return array(
            'warning' => $result['warning'],
            'message' => (string) ($result['message'] ?? ''),
            'queued'  => !empty($result['queued']),
        );
    }

    /**
     * @return array{message:string,reload:bool,status:array<string,mixed>}
     */
    public static function test_hosting(): array
    {
        $status = Rmmigrate_Hosting_Detection::run_detection(true);
        $loopback = !empty($status['loopback_ok']) ? __('OK', 'rosenheinrich-multisite-migrate') : __('Failed', 'rosenheinrich-multisite-migrate');
        return array(
            'message' => sprintf(
                /* translators: 1: kickoff mode, 2: lock mode, 3: loopback status. */
                __('Detection complete. Kickoff: %1$s, lock: %2$s, loopback: %3$s', 'rosenheinrich-multisite-migrate'),
                (string) ($status['effective_kickoff'] ?? ''),
                (string) ($status['lock_mode'] ?? ''),
                $loopback
            ),
            'reload'  => true,
            'status'  => $status,
        );
    }

    /**
     * @return array{message:string}
     */
    public static function test_destination(): array
    {
        $result = Rmmigrate_Destination_Probe::run();
        if (is_wp_error($result)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(esc_html($result->get_error_message()));
        }
        return array('message' => $result);
    }

    /**
     * @return array{ok:bool,message:string,warning:string,queued?:bool}
     */
    private static function delete_job_record(Rmmigrate_Job $job): array
    {
        if ($job->get_status() >= 0 && $job->get_status() < 100) {
            return array(
                'ok'      => false,
                'message' => __('Cannot delete an active job.', 'rosenheinrich-multisite-migrate'),
                'warning' => '',
            );
        }

        $job_id = $job->get_id();

        // User-initiated deletion: mark deleting and return fast. Heavy file purge
        // runs via Job_Cleanup::purge_pending_deletes (cron / scheduled hook).
        // Do not schedule_cron_worker — it bails/unschedules on STATUS_DELETING.
        if ($job->get_status() === Rmmigrate_Job::STATUS_DELETING) {
            Rmmigrate_Job_Cleanup::schedule_purge();
            return array(
                'ok'      => true,
                'message' => __('Deleting…', 'rosenheinrich-multisite-migrate'),
                'warning' => '',
                'queued'  => true,
            );
        }

        $job->update_progress(array('purge_failed' => false));
        $job->set_status(Rmmigrate_Job::STATUS_DELETING);

        $message = sprintf(
            /* translators: %d: job ID */
            __('Backup #%1$d queued for deletion.', 'rosenheinrich-multisite-migrate'),
            $job_id
        );
        Rmmigrate_Logger::log_activity('backup', $message, 'info', array('job_id' => $job_id));

        Rmmigrate_Job_Cleanup::schedule_purge();

        return array(
            'ok'      => true,
            'message' => $message,
            'warning' => '',
            'queued'  => true,
        );
    }

}
