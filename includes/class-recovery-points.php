<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Recovery_Points
{
    const DISPLAY_LIMIT = 5;

    /**
     * @return Rmmigrate_Job[]
     */
    public static function get_recent(bool $is_network): array
    {
        $args = array(
            'limit'        => self::DISPLAY_LIMIT,
            'status_group' => 'completed',
            'job_type'     => Rmmigrate_Job::JOB_TYPE_BACKUP,
        );
        if (!$is_network && is_multisite()) {
            $args['scope'] = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
            $args['blog_id'] = get_current_blog_id();
        }
        return Rmmigrate_Job::list_jobs($args);
    }

    public static function is_restorable(Rmmigrate_Job $job): bool
    {
        if ($job->get_status() !== Rmmigrate_Job::STATUS_COMPLETE) {
            return false;
        }
        if ($job->get_job_type() !== Rmmigrate_Job::JOB_TYPE_BACKUP) {
            return false;
        }
        $path = Rmmigrate_Runner::resolve_local_path($job);
        if ($path !== '' && Rmmigrate_Filesystem::exists($path)) {
            return true;
        }

        return false;
    }
}
