<?php

/**
 * Build step iterator for chunked backup jobs.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Build_Steps
{
    const STEP_INIT      = 'init';
    const STEP_DATABASE  = 'database';
    const STEP_ARCHIVE   = 'archive';
    const STEP_FINALIZE  = 'finalize';

    /** @var Rmmigrate_Job */
    private $job;

    public function __construct(Rmmigrate_Job $job)
    {
        $this->job = $job;
    }

    public function current(): string
    {
        $progress = $this->job->get_progress();
        $step = $progress['step'] ?? self::STEP_INIT;

        $status = $this->job->get_status();
        if ($status >= Rmmigrate_Job::STATUS_ARCHIVE_DONE) {
            return self::STEP_FINALIZE;
        }
        if ($status >= Rmmigrate_Job::STATUS_DB_DONE) {
            return self::STEP_ARCHIVE;
        }
        if ($status >= Rmmigrate_Job::STATUS_DUMPING_DB) {
            return self::STEP_DATABASE;
        }
        if ($status >= Rmmigrate_Job::STATUS_INIT) {
            return $step === self::STEP_INIT ? self::STEP_INIT : self::STEP_DATABASE;
        }

        return self::STEP_INIT;
    }

    public function advance_to(string $step): void
    {
        $this->job->update_progress(array('step' => $step));
    }
}
