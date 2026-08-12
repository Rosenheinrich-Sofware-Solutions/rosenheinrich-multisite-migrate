<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Restore_Build_Steps
{
    const STEP_EXTRACT            = 'extract';
    const STEP_DATABASE           = 'database';
    const STEP_POST_IMPORT_SR     = 'post_import_sr';
    const STEP_FILES              = 'files';
    const STEP_CLEANUP            = 'cleanup';
    const STEP_MIGRATION_FINALIZE = 'migration_finalize';

    /** @var Rmmigrate_Job */
    private $job;

    public function __construct(Rmmigrate_Job $job)
    {
        $this->job = $job;
    }

    /**
     * @return string[]
     */
    public function get_steps(): array
    {
        $mode = $this->job->get_restore_mode();
        $steps = array(self::STEP_EXTRACT);

        if ($mode === Rmmigrate_Job::RESTORE_MODE_DB || $mode === Rmmigrate_Job::RESTORE_MODE_BOTH) {
            $steps[] = self::STEP_DATABASE;
            if (
                $this->job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION
                && Rmmigrate_Engine_Config::post_import_search_replace()
            ) {
                $steps[] = self::STEP_POST_IMPORT_SR;
            }
        }
        if ($mode === Rmmigrate_Job::RESTORE_MODE_FILES || $mode === Rmmigrate_Job::RESTORE_MODE_BOTH) {
            $steps[] = self::STEP_FILES;
        }

        $steps[] = self::STEP_CLEANUP;

        if ($this->job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            $steps[] = self::STEP_MIGRATION_FINALIZE;
        }

        return $steps;
    }

    public function current(): string
    {
        $progress = $this->job->get_progress();
        $step = $progress['step'] ?? self::STEP_EXTRACT;
        $steps = $this->get_steps();

        if (!in_array($step, $steps, true)) {
            return $steps[0];
        }

        return $step;
    }

    public function advance_to(string $step): void
    {
        $this->job->update_progress(array('step' => $step));
    }

    public function advance_next(): void
    {
        $steps = $this->get_steps();
        $current = $this->current();
        $idx = array_search($current, $steps, true);
        if ($idx === false || !isset($steps[$idx + 1])) {
            return;
        }
        $this->advance_to($steps[$idx + 1]);
    }
}
