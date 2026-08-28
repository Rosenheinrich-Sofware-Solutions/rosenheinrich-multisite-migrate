<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Log_Rotator
{
    /** Upper bound when discovering numbered log generations (independent of current rotation limit). */
    private const MAX_GENERATION_SCAN = 999;
    /**
     * Rotate if file exceeds max_bytes; keep at most max_files generations.
     */
    public static function maybe_rotate(string $path, ?int $max_bytes = null, ?int $max_files = null): void
    {
        if (!Rmmigrate_Filesystem::exists($path)) {
            return;
        }

        $max_bytes = $max_bytes ?? Rmmigrate_Engine_Config::log_max_bytes();
        $max_files = $max_files ?? Rmmigrate_Engine_Config::log_max_rotated_files();
        if ($max_files < 2 || $max_bytes <= 0) {
            return;
        }

        if (Rmmigrate_Filesystem::filesize($path) < $max_bytes) {
            return;
        }

        $parts = self::split_basename(basename($path));
        if ($parts === null) {
            return;
        }

        $dir = trailingslashit(dirname($path));
        $stem = $parts['stem'];
        $ext = $parts['ext'];

        $oldest = $dir . $stem . '.' . ($max_files - 1) . $ext;
        if (Rmmigrate_Filesystem::exists($oldest)) {
            Rmmigrate_Filesystem::delete($oldest);
        }

        for ($i = $max_files - 1; $i >= 2; $i--) {
            $from = $dir . $stem . '.' . ($i - 1) . $ext;
            $to = $dir . $stem . '.' . $i . $ext;
            if (Rmmigrate_Filesystem::exists($from)) {
                Rmmigrate_Filesystem::move($from, $to);
            }
        }

        $first = $dir . $stem . '.1' . $ext;
        Rmmigrate_Filesystem::move($path, $first);
        Rmmigrate_Filesystem::put_contents($path, '');
    }

    public static function maintain_activity_log(): void
    {
        self::maybe_rotate(Rmmigrate_Activity_Log::path());
    }

    /**
     * @return array{stem:string,ext:string}|null
     */
    private static function split_basename(string $basename): ?array
    {
        $dot = strrpos($basename, '.');
        if ($dot === false || $dot === 0) {
            return null;
        }

        return array(
            'stem' => substr($basename, 0, $dot),
            'ext'  => substr($basename, $dot),
        );
    }

    /**
     * Glob all generations of a log basename, newest first.
     *
     * @return array<int,string>
     */
    public static function list_generations(string $dir, string $basename): array
    {
        $parts = self::split_basename($basename);
        if ($parts === null) {
            return array();
        }

        $dir = trailingslashit($dir);
        $out = array();

        $base = $dir . $basename;
        if (Rmmigrate_Filesystem::exists($base)) {
            $out[] = $base;
        }

        $rotated = glob($dir . $parts['stem'] . '.*' . $parts['ext']) ?: array();
        $indexed = array();
        foreach ($rotated as $path) {
            if (preg_match('/\.(\d+)' . preg_quote($parts['ext'], '/') . '$/', basename($path), $m)
                && (int) $m[1] >= 1
                && (int) $m[1] <= self::MAX_GENERATION_SCAN
            ) {
                $indexed[(int) $m[1]] = $path;
            }
        }
        ksort($indexed);
        foreach ($indexed as $path) {
            $out[] = $path;
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    public static function glob_job_log_files(string $dir): array
    {
        $dir = trailingslashit($dir);
        $base = glob($dir . 'job-*.log') ?: array();
        $rotated = glob($dir . 'job-*.*.log') ?: array();
        // Legacy pre-hardening names (retention prune + cleanup).
        $legacy = glob($dir . 'backup-*.log') ?: array();
        $legacy_rotated = glob($dir . 'backup-*.*.log') ?: array();
        $files = array_values(array_unique(array_merge($base, $rotated, $legacy, $legacy_rotated)));
        rsort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array<int,string>
     */
    public static function glob_system_log_files(string $dir): array
    {
        $dir = trailingslashit($dir);
        $candidates = array_merge(
            glob($dir . 'system-*.log') ?: array(),
            glob($dir . 'system-*.*.log') ?: array(),
            glob($dir . 'system.log') ?: array(),
            glob($dir . 'system.*.log') ?: array()
        );
        $out = array();
        foreach (array_unique($candidates) as $path) {
            $base = basename($path);
            if (preg_match('/^system-[a-f0-9]{16}(\.\d+)?\.log$/', $base)
                || preg_match('/^system(\.\d+)?\.log$/', $base)
            ) {
                $out[] = $path;
            }
        }
        rsort($out, SORT_STRING);

        return $out;
    }

    /**
     * @return array<int,string>
     */
    public static function glob_activity_log_files(string $dir): array
    {
        return self::list_generations($dir, 'activity.jsonl');
    }

    /**
     * Base job log files only (no numbered rotations).
     *
     * @return array<int,string>
     */
    public static function glob_base_job_logs(string $dir): array
    {
        $dir = trailingslashit($dir);
        $files = array_merge(
            glob($dir . 'job-*.log') ?: array(),
            glob($dir . 'backup-*.log') ?: array()
        );
        $out = array();
        foreach ($files as $file) {
            $base = basename($file);
            if (preg_match('/^job-[a-f0-9]{16}\.log$/', $base)
                || preg_match('/^backup-\d+\.log$/', $base)
            ) {
                $out[] = $file;
            }
        }
        rsort($out, SORT_STRING);

        return $out;
    }
}
