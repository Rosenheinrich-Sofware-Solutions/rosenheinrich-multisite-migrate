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
    }

    public static function detail(): void
    {
        self::verify_request();

        $entry_id = Rmmigrate_Request_Input::post_key('entry_id');
        $job_id = (int) Rmmigrate_Request_Input::post_key('job_id');
        
        $entry = null;
        if ($entry_id !== '') {
            $entry = Rmmigrate_Activity_Log::get_entry($entry_id);
            if ($entry === null) {
                wp_send_json_error(array('message' => __('Activity entry not found.', 'rosenheinrich-multisite-migrate')));
            }
            if (!Rmmigrate_Activity_Log::entry_visible_to_current_user($entry)) {
                wp_send_json_error(array('message' => __('Permission denied.', 'rosenheinrich-multisite-migrate')), 403);
            }
            $job_id = (int) ($entry['job_id'] ?? 0);
        }

        if ($job_id <= 0 && $entry === null) {
             wp_send_json_error(array('message' => __('No entry or job specified.', 'rosenheinrich-multisite-migrate')));
        }
        $job_data = null;
        $log_snippet = '';
        $log_url = '';

        if ($job_id > 0) {
            $job = Rmmigrate_Job::get($job_id);
            if ($job === null) {
                if ($entry === null) {
                    wp_send_json_error(array('message' => __('Job not found or already deleted.', 'rosenheinrich-multisite-migrate')), 404);
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

                $progress = $job->get_progress();
                $started = strtotime((string) ($job->data['created_at'] ?? ''));
                $completed = strtotime((string) ($job->data['completed_at'] ?? $job->data['updated_at'] ?? ''));
                $duration = ($started && $completed && $completed >= $started) ? ($completed - $started) : null;
                $job_data = array(
                    'id'           => $job->get_id(),
                    'type'         => $job->get_display_job_type(),
                    'status'       => $job->get_display_status(),
                    'scope'        => $job->get_display_scope(),
                    'destination'  => $job->data['destination'] ?? '',
                    'file_size'    => (int) ($job->data['file_size'] ?? 0),
                    'error_message'=> (string) ($job->data['error_message'] ?? ''),
                    'duration_sec' => $duration,
                    'db_mode'      => Rmmigrate_Settings::get()['db_mode'] ?? 'auto',
                    'archive_mode' => Rmmigrate_Settings::get()['archive_mode'] ?? 'zip',
                );
            }
            // Fetch the first chunk of the complete log file
            $log_basename = Rmmigrate_Activity_Log::job_log_basename($job_id);
            if (Rmmigrate_Activity_Log::job_log_readable($job_id)) {
                $log_file = $log_basename;
                $log_chunk = Rmmigrate_Activity_Log::read_file_chunk(
                    Rmmigrate_Activity_Log::job_log_path($job_id),
                    Rmmigrate_Engine_Config::log_view_lines(),
                    0
                );
                $log_chunk['log'] = $log_file;
                $log_chunk['line_count'] = $log_chunk['lines'] === '' ? 0 : substr_count($log_chunk['lines'], "\n") + 1;
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

        $per_page = max(1, (int) Rmmigrate_Request_Input::post_key('per_page', (string) Rmmigrate_Engine_Config::activity_page_size()));
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
            wp_send_json_error(array('message' => __('Invalid log file.', 'rosenheinrich-multisite-migrate')));
        }

        $path = Rmmigrate_Activity_Log::logs_dir() . '/' . $log;
        if (!Rmmigrate_Filesystem::is_readable($path)) {
            wp_send_json_error(array('message' => __('Log file not found.', 'rosenheinrich-multisite-migrate')));
        }

        $lines = max(1, (int) Rmmigrate_Request_Input::post_key('lines', (string) Rmmigrate_Engine_Config::log_view_lines()));
        $offset = max(0, (int) Rmmigrate_Request_Input::post_key('offset', '0'));
        $chunk = Rmmigrate_Activity_Log::read_file_chunk($path, $lines, $offset);
        $chunk['log'] = $log;
        $chunk['line_count'] = $chunk['lines'] === '' ? 0 : substr_count($chunk['lines'], "\n") + 1;

        wp_send_json_success($chunk);
    }
}
