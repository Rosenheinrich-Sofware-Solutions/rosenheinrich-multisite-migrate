<?php

if (!defined('ABSPATH')) {
    exit;
}

trait Rmmigrate_Ajax_Base
{
    private static function verify_request(int $job_id = 0, bool $allow_worker = false, string $action = 'any'): void
    {
        $nonce = Rmmigrate_Request_Input::request_text('nonce');
        $worker_token = Rmmigrate_Request_Input::request_text('worker_token');

        if ($allow_worker && $worker_token !== '' && Rmmigrate_Runner::verify_worker_token($job_id, $worker_token)) {
            if ($job_id > 0 && Rmmigrate_Job::get($job_id) === null) {
                wp_send_json_error(array('message' => __('Job not found.', 'rosenheinrich-multisite-migrate')), 404);
            }
            return;
        }

        // Verify nonce FIRST (cheap, stateless) before capability checks (DB query).
        // This also prevents a timing oracle that would reveal whether the current user
        // has a given capability before the nonce is validated.
        if (!wp_verify_nonce($nonce, 'rmmigrate_admin')) {
            wp_send_json_error(array('message' => __('Your session expired. Refresh the page and try again.', 'rosenheinrich-multisite-migrate')), 403);
        }

        if ($action === 'any') {
            if (!self::user_can_any_backup_action()) {
                wp_send_json_error(array('message' => __('You do not have permission to run backups. Contact your site administrator.', 'rosenheinrich-multisite-migrate')), 403);
            }
        } elseif (!self::user_can_action($action)) {
            wp_send_json_error(array('message' => __('You do not have permission to run backups. Contact your site administrator.', 'rosenheinrich-multisite-migrate')), 403);
        }

        if ($job_id > 0) {
            $job = Rmmigrate_Job::get($job_id);
            if ($job === null) {
                wp_send_json_error(array('message' => __('Job not found.', 'rosenheinrich-multisite-migrate')), 404);
            }
            self::assert_job_access($job);
        }
    }

    private static function user_can_access_job(Rmmigrate_Job $job): bool
    {
        return Rmmigrate_Access::can_view_job($job);
    }

    private static function assert_job_access(Rmmigrate_Job $job): void
    {
        if (!self::user_can_access_job($job)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rosenheinrich-multisite-migrate')), 403);
        }
    }

    private static function user_can_action(string $action): bool
    {
        if (Rmmigrate_Access::is_super_admin()) {
            return true;
        }
        if (!is_multisite() && current_user_can('manage_options')) {
            return true;
        }
        if (is_multisite() && current_user_can('manage_options')) {
            return Rmmigrate_Access::can_current_site($action);
        }

        return false;
    }

    private static function user_can_any_backup_action(): bool
    {
        return self::user_can_action('backup_create')
            || self::user_can_action('backup_restore')
            || self::user_can_action('backup_download')
            || self::user_can_action('backup_delete');
    }

    private static function user_can_backup(): bool
    {
        return self::user_can_action('backup_create');
    }

    private static function user_can_restore(): bool
    {
        return self::user_can_action('backup_restore');
    }

    private static function user_can_download(): bool
    {
        return self::user_can_action('backup_download');
    }

    private static function user_can_delete_backup(): bool
    {
        return self::user_can_action('backup_delete');
    }

    private static function user_can_import(): bool
    {
        return self::user_can_action('import_run');
    }

    private static function assert_import_access(): void
    {
        if (is_multisite() && is_network_admin()) {
            self::assert_network_management();
        } elseif (is_multisite() && !self::user_can_import()) {
            wp_send_json_error(array('message' => __('You do not have permission to run imports.', 'rosenheinrich-multisite-migrate')), 403);
        }
        if (!self::user_can_import()) {
            wp_send_json_error(array('message' => __('You do not have permission to run imports.', 'rosenheinrich-multisite-migrate')), 403);
        }
    }

    private static function ensure_local_archive_for_user(Rmmigrate_Job $job): void
    {
        if (Rmmigrate_Recovery_Points::is_restorable($job)) {
            return;
        }
        $fetched = Rmmigrate_Local_Archive::ensure_local_archive($job);
        if (is_wp_error($fetched)) {
            self::restore_error($fetched->get_error_message(), $job->get_id(), array(
                'destination' => $job->get_destination(),
                'phase'       => 'archive_lookup',
            ));
        }
    }

    private static function assert_network_management(): void
    {
        if (is_multisite() && !current_user_can('manage_network')) {
            wp_send_json_error(array('message' => __('Network admin required.', 'rosenheinrich-multisite-migrate')), 403);
        }
    }

    /**
     * @return true|WP_Error
     */
    private static function validate_import_file(string $path, string $archive_passphrase = '')
    {
        $probe = Rmmigrate_Validator::probe_archive($path);
        if ($probe === null) {
            Rmmigrate_Filesystem::delete($path);
            return new WP_Error('mm_invalid_archive', __('Invalid backup archive. Upload a .zip, .daf, or .venc file.', 'rosenheinrich-multisite-migrate'));
        }

        if (($probe['type'] ?? '') === 'venc') {
            if ($archive_passphrase === '') {
                Rmmigrate_Filesystem::delete($path);
                return new WP_Error('mm_passphrase', __('Enter the archive passphrase for encrypted .venc files.', 'rosenheinrich-multisite-migrate'));
            }
            $plain_path = $path . '.import-probe.tmp';
            Rmmigrate_Archive_Encryption::set_runtime_passphrase($archive_passphrase);
            $decrypted = Rmmigrate_Archive_Encryption::decrypt_file($path, $plain_path);
            Rmmigrate_Archive_Encryption::clear_runtime_passphrase();
            if (!$decrypted || !Rmmigrate_Filesystem::exists($plain_path)) {
                Rmmigrate_Filesystem::delete($plain_path);
                Rmmigrate_Filesystem::delete($path);
                return new WP_Error('mm_passphrase', __('Incorrect archive passphrase.', 'rosenheinrich-multisite-migrate'));
            }
            $readable = Rmmigrate_Validator::validate_archive_readable($plain_path);
            Rmmigrate_Filesystem::delete($plain_path);
            if (is_wp_error($readable)) {
                Rmmigrate_Filesystem::delete($path);
                return $readable;
            }
            return true;
        }

        $readable = Rmmigrate_Validator::validate_archive_readable($path);
        if (is_wp_error($readable)) {
            Rmmigrate_Filesystem::delete($path);
            return $readable;
        }
        return true;
    }

    private static function json_error_message(Throwable $e): string
    {
        return Rmmigrate_User_Error_Messages::format($e);
    }

    /**
     * PHPUnit Brain-Monkey aliases throw RuntimeExceptions to simulate wp_die().
     * Re-raise those so AJAX try/catch blocks do not turn success into errors.
     */
    private static function rethrow_test_ajax_exit(Throwable $e): void
    {
        $class = get_class($e);
        if (strpos($class, 'Json_Success_Exception') !== false
            || strpos($class, 'Json_Error_Exception') !== false
        ) {
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function send_service_exception(Rmmigrate_Service_Exception $e, string $type = 'restore', int $job_id = 0, array $context = array()): void
    {
        $payload = array('message' => self::json_error_message($e));
        $ctx = $e->get_context();
        if (isset($ctx['upgrade'])) {
            $payload['upgrade'] = $ctx['upgrade'];
        }
        if (isset($ctx['capability'])) {
            $payload['capability'] = $ctx['capability'];
        }
        if (isset($ctx['gate'])) {
            $payload['gate'] = $ctx['gate'];
            $payload['recovery_hint'] = $ctx['recovery_hint'] ?? '';
        }
        self::log_operation_failure($type, $payload['message'], $job_id, array_merge($context, $ctx, array('service_code' => $e->get_code_key())));
        $status = $e->get_http_status();
        if (isset($ctx['capability'])) {
            $status = 403;
        }
        $payload['code'] = $e->get_code_key();
        wp_send_json_error($payload, $status);
    }

    private static function respond_service_exception(Rmmigrate_Service_Exception $e, string $type = 'backup', int $job_id = 0): void
    {
        self::send_service_exception($e, $type, $job_id);
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function log_operation_failure(string $type, string $message, int $job_id = 0, array $context = array()): void
    {
        if (empty($context['service_code']) && $message !== '') {
            $context['service_code'] = Rmmigrate_Error_Codes::from_message($message);
        }
        Rmmigrate_Telemetry::record_operation_error($type, $message, $job_id, $context);
        Rmmigrate_Logger::log_activity($type, $message, 'error', array(
            'job_id'  => $job_id,
            'context' => $context,
        ));
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function backup_start_error(string $message, array $context = array(), ?int $status = null): void
    {
        self::log_operation_failure('backup', $message, 0, array_merge(array('phase' => 'start'), $context));
        wp_send_json_error(array('message' => $message), $status);
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function import_error(string $message, array $context = array()): void
    {
        self::log_operation_failure('import', $message, 0, $context);
        wp_send_json_error(array('message' => $message));
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function restore_error(string $message, int $job_id = 0, array $context = array()): void
    {
        self::log_operation_failure('restore', $message, $job_id, $context);
        wp_send_json_error(array('message' => $message));
    }

    private static function record_import_success(Rmmigrate_Job $job): void
    {
        Rmmigrate_Activity_Log::record(
            'import',
            /* translators: %d: backup job ID */
            sprintf(__('Imported backup #%1$d', 'rosenheinrich-multisite-migrate'), $job->get_id()),
            'success',
            array('job_id' => $job->get_id())
        );
        Rmmigrate_Notifications::maybe_send_event(
            'import',
            /* translators: %d: job ID */
            sprintf(__('Import completed for job #%1$d', 'rosenheinrich-multisite-migrate'), $job->get_id()),
            true
        );
    }

    /**
     * Discard accidental plugin bootstrap output before binary attachment headers.
     */
    private static function prepare_binary_download_response(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
