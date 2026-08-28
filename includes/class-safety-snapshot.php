<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Safety_Snapshot
{
    /**
     * Create a safety backup job before restore. Returns job id or 0 if skipped.
     */
    public static function create_before_restore(Rmmigrate_Job $source, string $restore_mode): int
    {
        $settings = Rmmigrate_Settings::get();
        if (empty($settings['safety_snapshot_enabled'])) {
            return 0;
        }

        $scope = $source->get_scope();
        $blog_id = $source->get_blog_id();

        $args = array(
            'scope'            => $scope,
            'blog_id'          => $blog_id,
            'excluded_blogs'   => $source->get_excluded_blogs(),
            'triggered_by'     => 'safety',
            'backup_profile'   => $restore_mode === Rmmigrate_Job::RESTORE_MODE_FILES ? 'files' : 'full',
            'is_safety'        => true,
        );

        $validation = Rmmigrate_Validator::validate_backup_start($args);
        if (is_wp_error($validation)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($validation->get_error_message()),
                array(), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($validation)));
        }

        $job = Rmmigrate_Job::create($args);
        Rmmigrate_Logger::log_activity(
            'backup',
            sprintf(
                /* translators: %1$d: safety backup job ID */
                __('Safety snapshot #%1$d queued before restore.', 'rosenheinrich-multisite-migrate'),
                $job->get_id()
            ),
            'info',
            array(
                'job_id'  => $job->get_id(),
                'context' => array(
                    'triggered_by' => 'safety',
                    'source_job' => $source->get_id(),
                ),
            )
        );
        Rmmigrate_Runner::kick_worker($job->get_id());
        return $job->get_id();
    }

    public static function is_complete(int $job_id): bool
    {
        if ($job_id <= 0) {
            return true;
        }
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            return true;
        }
        $status = $job->get_status();

        return in_array(
            $status,
            array(
                Rmmigrate_Job::STATUS_COMPLETE,
                Rmmigrate_Job::STATUS_ERROR,
                Rmmigrate_Job::STATUS_CANCELLED,
                Rmmigrate_Job::STATUS_DELETING,
            ),
            true
        );
    }
}
