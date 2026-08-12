<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Archive_Factory
{
    public static function create_archiver(Rmmigrate_Job $job, Rmmigrate_Multisite_Scope $scope)
    {
        $mode = Rmmigrate_Hosting_Detection::effective_archive_mode_for_job($job);
        if ($mode === 'daf') {
            return new Rmmigrate_Daf_Archiver($job, $scope);
        }
        return new Rmmigrate_Zip_Archiver($job, $scope);
    }

    public static function resolve_archive_path(Rmmigrate_Job $job): string
    {
        $progress = $job->get_progress();
        $path = $progress['archive']['archive_path'] ?? $progress['archive']['zip_path'] ?? '';
        if ($path !== '' && Rmmigrate_Filesystem::exists($path)) {
            return $path;
        }
        return Rmmigrate_Runner::resolve_local_path($job);
    }
}
