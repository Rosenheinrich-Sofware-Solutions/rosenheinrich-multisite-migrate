<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Ajax_Restore
{
    use Rmmigrate_Ajax_Base;

    public static function register(): void
    {
        add_action('wp_ajax_rmmigrate_start_restore', array(__CLASS__, 'start_restore'));
        add_action('wp_ajax_rmmigrate_quick_restore', array(__CLASS__, 'quick_restore'));
        add_action('wp_ajax_rmmigrate_prefetch_archive', array(__CLASS__, 'prefetch_archive'));
        add_action('wp_ajax_rmmigrate_manifest', array(__CLASS__, 'get_manifest'));
        add_action('wp_ajax_rmmigrate_search_replace_preview', array(__CLASS__, 'search_replace_preview'));
        add_action('wp_ajax_rmmigrate_search_replace_run', array(__CLASS__, 'search_replace_run'));
    }

    public static function search_replace_preview(): void
    {
        self::verify_request(0, false, 'backup_restore');
        if (Rmmigrate_Access::is_subsite_scoped_operation()) {
            wp_send_json_error(array('message' => __('Migration restore is not available for subsite administrators.', 'rosenheinrich-multisite-migrate')), 403);
        }
        try {
            $result = Rmmigrate_Restore_Service::migration_preview(
                Rmmigrate_Request_Input::post_url_raw('old_url', site_url()),
                Rmmigrate_Request_Input::post_url_raw('new_url')
            );
            wp_send_json_success($result);
        } catch (Rmmigrate_Service_Exception $e) {
            self::log_operation_failure('restore', $e->getMessage(), 0, array('phase' => 'search_replace_preview', 'service_code' => $e->get_code_key()));
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public static function search_replace_run(): void
    {
        self::verify_request(0, false, 'backup_restore');
        if (Rmmigrate_Access::is_subsite_scoped_operation()) {
            wp_send_json_error(array('message' => __('Migration restore is not available for subsite administrators.', 'rosenheinrich-multisite-migrate')), 403);
        }
        try {
            $resume_raw = Rmmigrate_Request_Input::post_text('resume');
            $resume = array();
            if ($resume_raw !== '') {
                $decoded = json_decode($resume_raw, true);
                if (is_array($decoded)) {
                    $resume = $decoded;
                }
            }
            $result = Rmmigrate_Restore_Service::migration_search_replace(
                Rmmigrate_Request_Input::post_url_raw('old_url', site_url()),
                Rmmigrate_Request_Input::post_url_raw('new_url'),
                $resume
            );
            wp_send_json_success($result);
        } catch (Rmmigrate_Service_Exception $e) {
            self::log_operation_failure('restore', $e->getMessage(), 0, array('phase' => 'search_replace_run', 'service_code' => $e->get_code_key()));
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public static function quick_restore(): void
    {
        self::start_restore(self::quick_restore_defaults());
    }

    /**
     * @return array<string,mixed>
     */
    public static function quick_restore_defaults(): array
    {
        return Rmmigrate_Restore_Service::quick_restore_defaults();
    }

    public static function prefetch_archive(): void
    {
        self::verify_request(0, false, 'backup_restore');
        if (is_multisite() && is_network_admin()) {
            self::assert_network_management();
        }
        $job_id = Rmmigrate_Request_Input::post_int('job_id');
        try {
            wp_send_json_success(Rmmigrate_Restore_Service::prefetch_archive($job_id));
        } catch (Rmmigrate_Service_Exception $e) {
            self::send_service_exception($e, 'restore', $job_id, array('phase' => 'prefetch'));
        }
    }

    /**
     * AJAX + programmatic entry. wp_ajax passes '' as arg #1 — coerce to array.
     *
     * @param array<string,mixed>|mixed $overrides
     */
    public static function start_restore($overrides = array()): void
    {
        if (!is_array($overrides)) {
            $overrides = array();
        }

        self::verify_request(0, false, 'backup_restore');

        $params = array_merge($overrides, array(
            'source_job_id'        => isset($overrides['source_job_id'])
                ? (int) $overrides['source_job_id']
                : Rmmigrate_Request_Input::post_int('source_job_id'),
            'restore_mode'         => (string) ($overrides['restore_mode'] ?? Rmmigrate_Request_Input::post_text('restore_mode', Rmmigrate_Job::RESTORE_MODE_BOTH)),
            'restore_type'         => (string) ($overrides['restore_type'] ?? Rmmigrate_Request_Input::post_text('restore_type', Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER)),
            'confirm_overwrite'    => array_key_exists('confirm_overwrite', $overrides)
                ? !empty($overrides['confirm_overwrite'])
                : Rmmigrate_Request_Input::post_bool('confirm_overwrite'),
            'safety_snapshot'      => array_key_exists('safety_snapshot', $overrides)
                ? !empty($overrides['safety_snapshot'])
                : Rmmigrate_Request_Input::post_bool('safety_snapshot'),
            'safety_job_id'        => array_key_exists('safety_job_id', $overrides)
                ? (int) $overrides['safety_job_id']
                : Rmmigrate_Request_Input::post_int('safety_job_id'),
            'archive_passphrase'   => array_key_exists('archive_passphrase', $overrides)
                ? (string) $overrides['archive_passphrase']
                : Rmmigrate_Request_Input::post_text('archive_passphrase'),
            'topology_import'      => array_key_exists('topology_import', $overrides)
                ? (string) $overrides['topology_import']
                : Rmmigrate_Request_Input::post_text('topology_import', 'auto'),
            'topology_target_blog_id' => array_key_exists('topology_target_blog_id', $overrides)
                ? (string) $overrides['topology_target_blog_id']
                : Rmmigrate_Request_Input::post_text('topology_target_blog_id', '0'),
            'topology_target_url'  => array_key_exists('topology_target_url', $overrides)
                ? (string) $overrides['topology_target_url']
                : Rmmigrate_Request_Input::post_url_raw('topology_target_url'),
            'kick_worker'          => array_key_exists('kick_worker', $overrides)
                ? !empty($overrides['kick_worker'])
                : true,
        ));

        if ($params['restore_type'] === Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            $params['old_site_url'] = Rmmigrate_Request_Input::post_url_raw('old_site_url');
            $params['new_site_url'] = Rmmigrate_Request_Input::post_url_raw('new_site_url');
            $params['old_home_url'] = Rmmigrate_Request_Input::post_url_raw('old_home_url');
            $params['new_home_url'] = Rmmigrate_Request_Input::post_url_raw('new_home_url');
            $params['blog_domains'] = self::sanitize_blog_domains(
                Rmmigrate_Request_Input::post_array()['blog_domains'] ?? null
            );
        }

        try {
            $result = Rmmigrate_Restore_Service::start_restore($params);
            wp_send_json_success($result);
        } catch (Rmmigrate_Service_Exception $e) {
            self::send_service_exception($e, 'restore', (int) $params['source_job_id'], array('phase' => 'start'));
        } catch (Throwable $e) {
            self::rethrow_test_ajax_exit($e);
            $message = self::json_error_message($e);
            self::log_operation_failure('restore', $message, (int) $params['source_job_id'], array(
                'phase'        => 'start',
                'service_code' => Rmmigrate_Error_Codes::from_throwable($e),
            ));
            wp_send_json_error(array('message' => $message));
        }
    }

    public static function get_manifest(): void
    {
        self::verify_request(0, false, 'backup_restore');
        $job_id = Rmmigrate_Request_Input::get_int('job_id');
        if ($job_id <= 0) {
            $job_id = Rmmigrate_Request_Input::post_int('job_id');
        }
        try {
            wp_send_json_success(Rmmigrate_Restore_Service::get_manifest($job_id));
        } catch (Rmmigrate_Service_Exception $e) {
            self::log_operation_failure('restore', $e->getMessage(), $job_id, array('phase' => 'manifest', 'service_code' => $e->get_code_key()));
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * @param mixed $raw
     * @return array<int,string>|null
     */
    private static function sanitize_blog_domains($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $out = array();
        foreach ($raw as $blog_id => $domain) {
            if (is_array($domain) || is_object($domain)) {
                continue;
            }
            if (!is_int($blog_id) && !is_string($blog_id)) {
                continue;
            }
            $blog_key = is_int($blog_id) ? (string) $blog_id : $blog_id;
            if ($blog_key === '' || !ctype_digit($blog_key)) {
                continue;
            }
            $id = (int) $blog_key;
            if ($id <= 0) {
                continue;
            }
            if (!is_scalar($domain)) {
                continue;
            }
            $host = sanitize_text_field((string) $domain);
            if ($host === '') {
                continue;
            }
            $host = strtolower($host);
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\\d{1,5})?$/', $host)) {
                continue;
            }
            $out[$id] = $host;
        }

        return $out === array() ? null : $out;
    }
}
