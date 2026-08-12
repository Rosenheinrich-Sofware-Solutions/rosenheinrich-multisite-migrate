<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Rmmigrate_Zip_Extract_Core')) {
    require_once __DIR__ . '/shared/zip-extract-core.php';
}

class Rmmigrate_Zip_Extractor
{
    /** @var Rmmigrate_Job */
    private $job;

    public function __construct(Rmmigrate_Job $job)
    {
        $this->job = $job;
    }

    public function run_slice(int $budget_sec, bool $only_sql = false, bool $throttle = false, string $password = ''): bool
    {
        $progress = $this->job->get_progress();
        $extract = $progress['extract'] ?? array();
        $zip_path = $progress['source']['zip_path'] ?? '';

        if ($zip_path === '' || !Rmmigrate_Filesystem::exists($zip_path)) {
            throw new RuntimeException(esc_html__('Backup archive not found.', 'rosenheinrich-multisite-migrate'));
        }

        $extract_dir = trailingslashit($this->job->get_work_dir()) . 'extracted/';
        wp_mkdir_p($extract_dir);

        if ($only_sql === false) {
            $only_sql = $this->job->get_restore_mode() === Rmmigrate_Job::RESTORE_MODE_DB;
        }

        $result = Rmmigrate_Zip_Extract_Core::extract_slice(
            $zip_path,
            $extract_dir,
            $extract,
            $budget_sec,
            $only_sql,
            $throttle,
            $password
        );

        $this->job->update_progress(array('extract' => $result['progress']));

        return $result['done'];
    }

    public function get_extract_dir(): string
    {
        return trailingslashit($this->job->get_work_dir()) . 'extracted/';
    }
}
