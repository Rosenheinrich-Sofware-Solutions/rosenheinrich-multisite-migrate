<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Ajax_Backup
{
    use Rmmigrate_Ajax_Base;

    public static function register(): void
    {
        add_action('wp_ajax_rmmigrate_start', array(__CLASS__, 'start_backup'));
        add_action('wp_ajax_rmmigrate_worker', array(__CLASS__, 'worker'));
        add_action('wp_ajax_nopriv_rmmigrate_worker', array(__CLASS__, 'worker'));
        add_action('wp_ajax_rmmigrate_status', array(__CLASS__, 'status'));
        add_action('wp_ajax_rmmigrate_cancel', array(__CLASS__, 'cancel'));
        add_action('wp_ajax_rmmigrate_delete', array(__CLASS__, 'delete_local'));
        add_action('wp_ajax_rmmigrate_delete_bulk', array(__CLASS__, 'delete_bulk'));
        add_action('wp_ajax_rmmigrate_download', array(__CLASS__, 'download'));
        add_action('wp_ajax_rmmigrate_prune', array(__CLASS__, 'prune_now'));
        add_action('wp_ajax_rmmigrate_clean_storage', array(__CLASS__, 'clean_storage'));
        add_action('wp_ajax_rmmigrate_dismiss_last_error', array(__CLASS__, 'dismiss_last_error'));
        add_action('wp_ajax_rmmigrate_test_hosting', array(__CLASS__, 'test_hosting'));
        add_action('wp_ajax_rmmigrate_test_destination', array(__CLASS__, 'test_destination'));
        add_action('wp_ajax_rmmigrate_search_subsites', array(__CLASS__, 'search_subsites'));
    }

    public static function search_subsites(): void
    {
        self::verify_request();
        self::assert_network_management();
        if (!is_multisite()) {
            wp_send_json_success(array('sites' => array()));
        }
        $search = sanitize_text_field(Rmmigrate_Request_Input::post_text('search'));
        $offset = max(0, Rmmigrate_Request_Input::post_int('offset'));
        wp_send_json_success(array(
            'sites' => Rmmigrate_Multisite_Scope::search_network_sites($search, 25, $offset),
            'total' => (int) get_sites(array('count' => true)),
        ));
    }

    public static function test_destination(): void
    {
        self::verify_request();
        self::assert_network_management();
        try {
            wp_send_json_success(Rmmigrate_Backup_Service::test_destination());
        } catch (Rmmigrate_Service_Exception $e) {
            self::log_operation_failure('storage', $e->getMessage(), 0, array('phase' => 'test_destination', 'service_code' => $e->get_code_key()));
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public static function test_hosting(): void
    {
        self::verify_request();
        self::assert_network_management();
        try {
            $result = Rmmigrate_Backup_Service::test_hosting();
            wp_send_json_success($result);
        } catch (Rmmigrate_Service_Exception $e) {
            self::log_operation_failure('backup', $e->getMessage(), 0, array('phase' => 'test_hosting', 'service_code' => $e->get_code_key()));
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public static function dismiss_last_error(): void
    {
        self::verify_request();
        self::assert_network_management();
        $error = Rmmigrate_Job::resolve_admin_last_error(is_network_admin());
        if ((string) ($error['message'] ?? '') === '') {
            wp_send_json_error(array('message' => __('Nothing to dismiss.', 'rosenheinrich-multisite-migrate')), 400);
        }
        delete_site_option('rmmigrate_last_error');
        wp_send_json_success();
    }

    public static function start_backup(): void
    {
        self::verify_request(0, false, 'backup_create');
        if (!self::user_can_backup()) {
            self::backup_start_error(__('You do not have permission to create backups.', 'rosenheinrich-multisite-migrate'), array(), 403);
        }

        $input = array(
            'scope'                      => Rmmigrate_Request_Input::post_text('scope', Rmmigrate_Multisite_Scope::SCOPE_NETWORK),
            'excluded_blogs'             => Rmmigrate_Request_Input::post_int_array('excluded_blogs'),
            'included_blogs'             => Rmmigrate_Request_Input::post_int_array('included_blogs'),
            'backup_profile'             => Rmmigrate_Request_Input::post_text('backup_profile', 'full'),
            'archive_mode'               => Rmmigrate_Request_Input::post_text('archive_mode'),
            'custom_paths'               => Rmmigrate_Request_Input::post_textarea('custom_paths'),
            'exclude_tables'             => Rmmigrate_Request_Input::post_textarea('exclude_tables'),
            'exclude_log_tables'         => Rmmigrate_Request_Input::post_bool('exclude_log_tables'),
            'exclude_revisions'          => Rmmigrate_Request_Input::post_bool('exclude_revisions'),
            // Sent as text so an absent field (other callers) is distinguishable
            // from an explicit "0" (wizard override to exclude core).
            'include_wp_core'            => Rmmigrate_Request_Input::post_text('include_wp_core', ''),
            'kick_worker'                => true,
            'triggered_by'               => 'manual',
        );

        try {
            wp_send_json_success(Rmmigrate_Backup_Service::start_backup($input));
        } catch (Rmmigrate_Service_Exception $e) {
            $ctx = $e->get_context();
            self::backup_start_error(
                $e->getMessage(),
                array_merge(array('phase' => 'start'), $ctx),
                isset($ctx['capability']) ? 403 : null
            );
        } catch (Throwable $e) {
            self::rethrow_test_ajax_exit($e);
            $message = self::json_error_message($e);
            self::log_operation_failure('backup', $message, 0, array('phase' => 'start'));
            wp_send_json_error(array('message' => $message));
        }
    }

    public static function worker(): void
    {
        $job_id = Rmmigrate_Request_Input::request_int('job_id');
        $worker_token = Rmmigrate_Request_Input::request_text('worker_token');
        self::verify_request($job_id, true);

        Rmmigrate_Job::recover_stale_active();

        $source = $worker_token !== '' ? 'loopback' : 'browser';
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            $source = 'cron';
        }
        Rmmigrate_Runner::note_worker_request($job_id, $source);

        try {
            $result = Rmmigrate_Runner::process($job_id);
            wp_send_json_success($result);
        } catch (Rmmigrate_Service_Exception $e) {
            $ctx = $e->get_context();
            self::backup_start_error(
                $e->getMessage(),
                array_merge(array('phase' => 'worker', 'job_id' => $job_id), $ctx),
                isset($ctx['capability']) ? 403 : null
            );
        } catch (Throwable $e) {
            self::rethrow_test_ajax_exit($e);
            $message = self::json_error_message($e);
            self::log_operation_failure('backup', $message, $job_id, array('phase' => 'worker'));
            wp_send_json_error(array('message' => $message));
        }
    }

    public static function status(): void
    {
        self::verify_request();
        Rmmigrate_Job::recover_stale_active();
        $job_id = Rmmigrate_Request_Input::get_int('job_id');
        try {
            wp_send_json_success(Rmmigrate_Backup_Service::get_status($job_id ?: null));
        } catch (Rmmigrate_Service_Exception $e) {
            self::respond_service_exception($e, 'backup', $job_id);
        }
    }

    public static function cancel(): void
    {
        self::verify_request();
        if (!self::user_can_backup() && !self::user_can_restore()) {
            wp_send_json_error(array('message' => __('You do not have permission to cancel this job.', 'rosenheinrich-multisite-migrate')), 403);
        }
        $job_id = Rmmigrate_Request_Input::post_int('job_id');
        try {
            Rmmigrate_Backup_Service::cancel($job_id);
            wp_send_json_success();
        } catch (Rmmigrate_Service_Exception $e) {
            self::respond_service_exception($e, 'backup', $job_id);
        }
    }

    public static function delete_local(): void
    {
        self::verify_request(0, false, 'backup_delete');
        $job_id = Rmmigrate_Request_Input::post_int('job_id');
        try {
            $result = Rmmigrate_Backup_Service::delete_job($job_id);
            $message = (string) ($result['message'] ?? '');
            wp_send_json_success(array(
                'queued'  => !empty($result['queued']),
                'message' => $message !== ''
                    ? $message
                    : __('Deleting…', 'rosenheinrich-multisite-migrate'),
            ));
        } catch (Rmmigrate_Service_Exception $e) {
            self::respond_service_exception($e, 'backup', $job_id);
        }
    }

    public static function delete_bulk(): void
    {
        self::verify_request(0, false, 'backup_delete');
        try {
            wp_send_json_success(Rmmigrate_Backup_Service::delete_jobs(
                Rmmigrate_Request_Input::post_int_array('job_ids')
            ));
        } catch (Rmmigrate_Service_Exception $e) {
            self::respond_service_exception($e, 'backup');
        }
    }



    public static function download(): void
    {
        $job_id = Rmmigrate_Request_Input::get_int('job_id');
        if (!wp_verify_nonce(Rmmigrate_Request_Input::get_text('nonce'), 'rmmigrate_admin')) {
            wp_die(esc_html__('Invalid nonce.', 'rosenheinrich-multisite-migrate'));
        }
        if (!self::user_can_download()) {
            wp_die(esc_html__('Permission denied.', 'rosenheinrich-multisite-migrate'));
        }
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            wp_die(esc_html__('Backup not found.', 'rosenheinrich-multisite-migrate'));
        }
        self::assert_job_access($job);
        $path = Rmmigrate_Runner::resolve_local_path($job);
        // Path-safety: current or legacy storage roots (pre DIR_NAME rename).
        if (!Rmmigrate_Engine_Config::is_path_under_allowed_storage($path)) {
            wp_die(esc_html__('Invalid backup path.', 'rosenheinrich-multisite-migrate'));
        }
        if (!Rmmigrate_Filesystem::exists($path)) {
            wp_die(esc_html__('File not found.', 'rosenheinrich-multisite-migrate'));
        }
        self::prepare_binary_download_response();
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        if ($ext === 'zip') {
            $mime = 'application/zip';
        } elseif ($ext === 'daf') {
            $mime = 'application/x-mm-daf';
        } elseif ($ext === 'venc') {
            $mime = 'application/octet-stream';
        }
        $filename = sanitize_file_name(basename($path));
        if ($filename === '') {
            $filename = 'archive.' . $ext;
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . Rmmigrate_Filesystem::filesize($path));
        Rmmigrate_Filesystem::stream_to_stdout($path);
        exit;
    }

    public static function prune_now(): void
    {
        self::verify_request();
        self::assert_network_management();
        Rmmigrate_Retention::prune();
        Rmmigrate_Activity_Log::record('backup', __('Retention prune completed.', 'rosenheinrich-multisite-migrate'), 'success');
        wp_send_json_success(array('message' => __('Retention prune completed.', 'rosenheinrich-multisite-migrate')));
    }

    public static function clean_storage(): void
    {
        self::verify_request();
        self::assert_network_management();
        $result = Rmmigrate_Engine_Maintenance::clean_storage();
        Rmmigrate_Activity_Log::record('system', $result['message'], 'success', array(
            'context' => array(
                'removed' => $result['removed'],
                'bytes'   => $result['bytes'],
            ),
        ));
        wp_send_json_success($result);
    }

}
