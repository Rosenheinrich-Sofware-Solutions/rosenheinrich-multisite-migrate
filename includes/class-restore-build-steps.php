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
        $progress = $this->job->get_progress();
        $stored = $progress['restore_steps'] ?? null;
        $validated = is_array($stored) ? self::validate_stored_steps($stored) : null;
        if ($validated !== null) {
            return $validated;
        }

        $steps = $this->resolve_steps();
        $this->job->update_progress(array('restore_steps' => $steps));

        return $steps;
    }

    /**
     * @return string[]|null
     */
    private static function validate_stored_steps(array $stored): ?array
    {
        if ($stored === array() || !isset($stored[0])) {
            return null;
        }
        if (array_keys($stored) !== range(0, count($stored) - 1)) {
            return null;
        }

        $recognized = array(
            self::STEP_EXTRACT,
            self::STEP_DATABASE,
            self::STEP_POST_IMPORT_SR,
            self::STEP_FILES,
            self::STEP_CLEANUP,
            self::STEP_MIGRATION_FINALIZE,
        );
        $last_index = -1;
        $clean = array();
        foreach ($stored as $step) {
            if (!is_string($step)) {
                return null;
            }
            $idx = array_search($step, $recognized, true);
            if ($idx === false || $idx <= $last_index) {
                return null;
            }
            $last_index = $idx;
            $clean[] = $step;
        }
        if (!in_array(self::STEP_EXTRACT, $clean, true) || !in_array(self::STEP_CLEANUP, $clean, true)) {
            return null;
        }

        return $clean;
    }

    /**
     * @return string[]
     */
    private function resolve_steps(): array
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
        if ($idx === false) {
            return;
        }
        $idx = (int) $idx;
        if (!isset($steps[$idx + 1])) {
            return;
        }
        $this->advance_to($steps[$idx + 1]);
    }
}
