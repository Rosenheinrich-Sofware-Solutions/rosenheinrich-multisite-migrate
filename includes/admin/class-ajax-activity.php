<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Ajax_Activity
{
    use Rmmigrate_Ajax_Base;

    public static function register(): void
    {
        add_action('wp_ajax_rmmigrate_activity_detail', array(__CLASS__, 'detail'));
        add_action('wp_ajax_rmmigrate_activity_list', array(__CLASS__, 'list_page'));
        add_action('wp_ajax_rmmigrate_log_chunk', array(__CLASS__, 'log_chunk'));
        add_action('wp_ajax_rmmigrate_report_ajax_error', array(__CLASS__, 'report_client_error'));
    }

    public static function detail(): void
    {
        self::verify_request();

        $entry_id = Rmmigrate_Request_Input::post_key('entry_id');
        $job_id = (int) Rmmigrate_Request_Input::post_key('job_id');
        
        $entry = null;
        if ($entry_id !== '') {
            $entry = Rmmigrate_Activity_Log::get_entry($entry_id);
            if ($entry !== null) {
                if (!Rmmigrate_Activity_Log::entry_visible_to_current_user($entry)) {
                    self::send_ajax_error(
                        __('Permission denied.', 'rosenheinrich-multisite-migrate'),
                        403,
                        'system',
                        (int) ($entry['job_id'] ?? 0),
                        array('phase' => 'activity_detail'),
                        'warning'
                    );
                }
                $job_id = (int) ($entry['job_id'] ?? 0);
            } elseif ($job_id <= 0) {
                self::send_ajax_error(
                    __('Activity entry not found.', 'rosenheinrich-multisite-migrate'),
                    404,
                    'system',
                    0,
                    array('phase' => 'activity_detail')
                );
            }
            // Synthetic / missing JSONL row: fall through with job_id from the request.
        }

        if ($job_id <= 0 && $entry === null) {
            self::send_ajax_error(
                __('No entry or job specified.', 'rosenheinrich-multisite-migrate'),
                400,
                'system',
                0,
                array('phase' => 'activity_detail')
            );
        }
        $job_data = null;
        $log_file = '';
        $log_chunk = null;

        if ($job_id > 0) {
            $job = Rmmigrate_Job::get($job_id);
            if ($job === null) {
                if ($entry === null) {
                    self::send_ajax_error(
                        __('Job not found or already deleted.', 'rosenheinrich-multisite-migrate'),
                        404,
                        self::ajax_operation_type(),
                        $job_id,
                        array('phase' => 'activity_detail')
                    );
                }
                // Job deleted, but we have an activity entry. Proceed without job data.
            } else {
                self::assert_job_access($job);
                
                if ($entry === null) {
                    $entry = array(
                        'time'    => $job->data['completed_at'] ?? $job->data['updated_at'] ?? gmdate('c'),
                        'type'    => $job->get_job_type(),
                        'status'  => $job->get_status() === Rmmigrate_Job::STATUS_ERROR || !empty($job->data['error_message']) ? 'error' : 'info',
                        'message' => $job->data['error_message'] ?? '',
                        'job_id'  => $job->get_id(),
                    );
                }

                $started = strtotime((string) ($job->data['created_at'] ?? ''));
                $completed = strtotime((string) ($job->data['completed_at'] ?? $job->data['updated_at'] ?? ''));
                $duration = ($started && $completed && $completed >= $started) ? ($completed - $started) : null;
                $settings = Rmmigrate_Settings::get();
                $job_data = array(
                    'id'           => $job->get_id(),
                    'type'         => $job->get_display_job_type(),
                    'status'       => $job->get_display_status(),
                    'scope'        => $job->get_display_scope(),
                    'destination'  => $job->data['destination'] ?? '',
                    'file_size'    => (int) ($job->data['file_size'] ?? 0),
                    'error_message'=> (string) ($job->data['error_message'] ?? ''),
                    'duration_sec' => $duration,
                    'db_mode'      => $settings['db_mode'] ?? 'auto',
                    'archive_mode' => $settings['archive_mode'] ?? 'zip',
                );
            }
            // Fetch the first chunk of the complete log file
            $log_basename = Rmmigrate_Activity_Log::job_log_basename($job_id);
            if (Rmmigrate_Activity_Log::job_log_readable($job_id)) {
                $log_file = $log_basename;
                $log_chunk = self::decorate_chunk(
                    Rmmigrate_Activity_Log::read_file_chunk(
                        Rmmigrate_Activity_Log::job_log_path($job_id),
                        Rmmigrate_Engine_Config::log_view_lines(),
                        0
                    ),
                    $log_file
                );
            }
        }

        wp_send_json_success(array(
            'entry'       => $entry !== null ? Rmmigrate_Activity_Log::sanitize_entry_for_response($entry) : array(),
            'job'         => $job_data,
            'log_file'    => $log_file ?? '',
            'log_chunk'   => $log_chunk ?? null,
            'server'      => array(
                'php_memory'  => ini_get('memory_limit'),
                'time_limit'  => ini_get('max_execution_time'),
                'wp_version'  => get_bloginfo('version'),
                'plugin_version' => defined('RMMIGRATE_VERSION') ? RMMIGRATE_VERSION : '',
            ),
        ));
    }

    public static function list_page(): void
    {
        self::verify_request();

        $per_page = min(50, max(1, (int) Rmmigrate_Request_Input::post_key('per_page', (string) Rmmigrate_Engine_Config::activity_page_size())));
        $page = max(1, (int) Rmmigrate_Request_Input::post_key('page', '1'));
        $type_filter = Rmmigrate_Request_Input::post_key('type');
        $date_from = Rmmigrate_Activity_Log::normalize_date_filter(
            Rmmigrate_Request_Input::post_text('date_from')
        );
        $date_to = Rmmigrate_Activity_Log::normalize_date_filter(
            Rmmigrate_Request_Input::post_text('date_to')
        );

        wp_send_json_success(Rmmigrate_Activity_Log::list_entries($per_page, $page, $type_filter, $date_from, $date_to));
    }

    public static function log_chunk(): void
    {
        self::verify_request();

        $log = sanitize_file_name(Rmmigrate_Request_Input::post_text('log'));
        if ($log === '' || !Rmmigrate_Activity_Log::is_allowed_log_basename($log)) {
            self::send_ajax_error(
                __('Invalid log file.', 'rosenheinrich-multisite-migrate'),
                400,
                'system',
                0,
                array('phase' => 'log_chunk')
            );
        }

        $path = Rmmigrate_Activity_Log::logs_dir() . '/' . $log;
        if (!Rmmigrate_Filesystem::is_readable($path)) {
            self::send_ajax_error(
                __('Log file not found.', 'rosenheinrich-multisite-migrate'),
                404,
                'system',
                0,
                array('phase' => 'log_chunk', 'log' => $log)
            );
        }

        $max_lines = max(1, (int) Rmmigrate_Engine_Config::log_view_lines());
        $lines = min($max_lines, max(1, (int) Rmmigrate_Request_Input::post_key('lines', (string) $max_lines)));
        $offset = max(0, (int) Rmmigrate_Request_Input::post_key('offset', '0'));
        $chunk = self::decorate_chunk(
            Rmmigrate_Activity_Log::read_file_chunk($path, $lines, $offset),
            $log
        );

        wp_send_json_success($chunk);
    }

    public static function report_client_error(): void
    {
        $nonce = Rmmigrate_Request_Input::post_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_admin')) {
            wp_send_json_success(array('skipped' => 'nonce'));
        }

        if (!self::user_can_any_backup_action()) {
            wp_send_json_success(array('skipped' => 'capability'));
        }

        $ajax_action = sanitize_key(Rmmigrate_Request_Input::post_text('ajax_action'));
        $message = sanitize_text_field(Rmmigrate_Request_Input::post_text('message'));
        $job_id = max(0, Rmmigrate_Request_Input::post_int('job_id'));
        $http_status = max(0, Rmmigrate_Request_Input::post_int('http_status'));
        $phase = sanitize_key(Rmmigrate_Request_Input::post_text('phase', 'transport'));

        if ($ajax_action === '' || $message === '' || strpos($ajax_action, 'rmmigrate_') !== 0) {
            wp_send_json_success(array('skipped' => 'invalid'));
        }

        $detail = $message;
        if ($http_status > 0) {
            $detail = sprintf(
                /* translators: 1: HTTP status code, 2: error message */
                __('HTTP %1$d — %2$s', 'rosenheinrich-multisite-migrate'),
                $http_status,
                $message
            );
        }

        $log_message = sprintf(
            /* translators: 1: WordPress admin-ajax action, 2: error detail */
            __('Admin request failed (%1$s): %2$s', 'rosenheinrich-multisite-migrate'),
            $ajax_action,
            $detail
        );

        $context = array(
            'phase'       => $phase !== '' ? $phase : 'transport',
            'ajax_action' => $ajax_action,
            'http_status' => $http_status,
            'source'      => 'client',
        );
        if (self::should_skip_duplicate_ajax_log($log_message, $context)) {
            wp_send_json_success(array('deduped' => true));
        }

        self::log_operation_failure(
            self::ajax_operation_type($ajax_action),
            $log_message,
            $job_id,
            $context
        );

        wp_send_json_success(array('logged' => true));
    }

    /**
     * @param array<string,mixed> $chunk
     * @return array<string,mixed>
     */
    private static function decorate_chunk(array $chunk, string $log): array
    {
        $chunk['log'] = $log;
        $chunk['line_count'] = Rmmigrate_Activity_Log::count_display_lines(
            (string) ($chunk['lines'] ?? '')
        );

        return $chunk;
    }
}
