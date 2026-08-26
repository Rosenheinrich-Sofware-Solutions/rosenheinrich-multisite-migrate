<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Logger
{
    /** @var int|null */
    private static $job_id = null;

    public static function for_job(int $job_id): void
    {
        self::$job_id = $job_id;
    }

    public static function log(string $message): void
    {
        if (self::$job_id === null) {
            return;
        }
        Rmmigrate_Plugin::ensure_backup_root();
        $path = Rmmigrate_Activity_Log::job_log_path(self::$job_id);
        if ($path === '') {
            return;
        }
        $line = gmdate('Y-m-d g:i:s a') . ' UTC ' . RMMIGRATE_IO::redact_log_message($message) . "\n";
        Rmmigrate_Filesystem::put_contents($path, $line, FILE_APPEND | LOCK_EX);
        Rmmigrate_Log_Rotator::maybe_rotate($path);
    }

    public static function log_job(int $job_id, string $message): void
    {
        self::for_job($job_id);
        self::log($message);
    }

    /**
     * Job log + activity log once per transient suffix (avoids worker poll spam).
     *
     * @param array<string,mixed> $context
     */
    public static function log_job_milestone(
        int $job_id,
        string $transient_suffix,
        string $message,
        string $type = 'backup',
        string $status = 'info',
        array $context = array(),
        int $activity_ttl = 60,
        bool $activity_log = true
    ): void {
        $key = 'rmmigrate_milestone_' . $transient_suffix . '_' . $job_id;
        if (get_transient($key)) {
            return;
        }
        set_transient($key, 1, max(15, $activity_ttl));

        self::log_job($job_id, $message);

        if (!$activity_log) {
            return;
        }

        Rmmigrate_Activity_Log::record($type, $message, $status, array(
            'job_id'  => $job_id,
            'context' => RMMIGRATE_IO::redact_context($context),
        ));
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function log_activity(string $type, string $message, string $status = 'info', array $data = array()): void
    {
        Rmmigrate_Activity_Log::record($type, $message, $status, $data);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function log_system(string $message, array $context = array(), string $status = 'info'): void
    {
        Rmmigrate_Plugin::ensure_backup_root();
        $path = Rmmigrate_Activity_Log::system_log_path();
        $line = gmdate('Y-m-d g:i:s a') . ' UTC ' . RMMIGRATE_IO::redact_log_message($message) . "\n";
        Rmmigrate_Filesystem::put_contents($path, $line, FILE_APPEND | LOCK_EX);
        Rmmigrate_Log_Rotator::maybe_rotate($path);
        Rmmigrate_Activity_Log::record('system', RMMIGRATE_IO::redact_log_message($message), $status, array(
            'job_id'  => (int) ($context['job_id'] ?? 0),
            'context' => RMMIGRATE_IO::redact_context($context),
        ));
    }
}
