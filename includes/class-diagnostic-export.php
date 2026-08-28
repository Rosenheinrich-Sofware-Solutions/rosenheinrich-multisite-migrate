<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Diagnostic_Export
{
    const LOG_TAIL_LINES = 500;
    const ACTIVITY_TAIL_LINES = 100;

    /**
     * @return array<string,string> Map of zip entry name => file contents.
     */
    public static function collect_entries(): array
    {
        $settings = Rmmigrate_Settings::get();
        $settings = RMMIGRATE_IO::redact_context($settings);

        /**
         * Diagnostic snapshot of the active edition/tier. A separate paid edition
         * can enrich this with its own metadata via the filter below.
         *
         * @param array<string,mixed> $edition Base edition snapshot.
         */
        $edition = apply_filters('rmmigrate_diagnostic_edition', self::base_edition_snapshot());

        $entries = array(
            'health.json'            => wp_json_encode(Rmmigrate_Health::get_status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'settings-redacted.json' => wp_json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'edition.json'           => wp_json_encode($edition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'README.txt'             => self::readme_text(),
        );

        $activity_path = Rmmigrate_Activity_Log::path();
        if (Rmmigrate_Filesystem::is_readable($activity_path)) {
            $activity_lines = Rmmigrate_Activity_Log::read_tail_lines_for_export($activity_path, self::ACTIVITY_TAIL_LINES);
            if ($activity_lines !== array()) {
                $entries['activity-tail.jsonl'] = implode("\n", $activity_lines) . "\n";
            }
        }

        $job_logs = Rmmigrate_Activity_Log::list_job_logs(1, 1);
        if (!empty($job_logs['files'][0]['path']) && Rmmigrate_Filesystem::is_readable($job_logs['files'][0]['path'])) {
            $entries['last-job.log'] = Rmmigrate_Activity_Log::read_file_tail($job_logs['files'][0]['path'], self::LOG_TAIL_LINES);
        }

        $logs_dir = Rmmigrate_Activity_Log::logs_dir();
        $manifest_files = array();
        foreach (Rmmigrate_Log_Rotator::glob_job_log_files($logs_dir) as $path) {
            $manifest_files[] = basename($path);
        }
        foreach (Rmmigrate_Log_Rotator::glob_system_log_files($logs_dir) as $path) {
            $manifest_files[] = basename($path);
        }
        foreach (Rmmigrate_Log_Rotator::glob_activity_log_files($logs_dir) as $path) {
            $manifest_files[] = basename($path);
        }
        if ($manifest_files !== array()) {
            $entries['logs-manifest.txt'] = wp_json_encode(
                array(
                    'logs_dir' => wp_normalize_path($logs_dir),
                    'note'     => 'Rotated logs use numbered suffixes before the extension (e.g. job-{token}.1.log, system-{token}.1.log, activity.1.jsonl).',
                    'files'    => array_values(array_unique($manifest_files)),
                ),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n";
        }

        $system_basename = Rmmigrate_Activity_Log::system_log_basename();
        if ($system_basename !== null) {
            $system_log = Rmmigrate_Activity_Log::logs_dir() . '/' . $system_basename;
            if (Rmmigrate_Filesystem::is_readable($system_log)) {
                $entries['system.log'] = Rmmigrate_Activity_Log::read_file_tail($system_log, self::LOG_TAIL_LINES);
            }
        }

        return $entries;
    }

    /**
     * @return array<string,mixed>
     */
    public static function base_edition_snapshot(): array
    {
        $snapshot = array(
            'status'          => 'free',
            'tier'            => 'free',
            'bootstrap_owner' => defined('RMMIGRATE_BOOTSTRAP_OWNER') ? RMMIGRATE_BOOTSTRAP_OWNER : null,
        );

        return $snapshot;
    }

    /**
     * @return string|false Absolute path to temp zip, or false on failure.
     */
    public static function build_temp_zip()
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $tmp = wp_tempnam('mm-diag');
        if ($tmp === false || $tmp === '') {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Rmmigrate_Filesystem::delete($tmp);
            return false;
        }

        foreach (self::collect_entries() as $name => $contents) {
            if (!is_string($contents) || $contents === '') {
                continue;
            }
            $zip->addFromString($name, $contents);
        }

        if ($zip->close() !== true) {
            Rmmigrate_Filesystem::delete($tmp);
            return false;
        }

        if (!Rmmigrate_Filesystem::is_readable($tmp) || Rmmigrate_Filesystem::filesize($tmp) <= 0) {
            Rmmigrate_Filesystem::delete($tmp);
            return false;
        }

        return $tmp;
    }

    public static function stream_zip(string $path): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $size = (int) Rmmigrate_Filesystem::filesize($path);
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="multisite-migrate-diagnostic.zip"');
        if ($size > 0) {
            header('Content-Length: ' . $size);
        }

        Rmmigrate_Filesystem::stream_to_stdout($path);
    }

    private static function readme_text(): string
    {
        $lines = array(
            'Multisite Migrate diagnostic export',
            'Generated (UTC): ' . gmdate('Y-m-d H:i:s'),
            'Plugin version: ' . (defined('RMMIGRATE_VERSION') ? RMMIGRATE_VERSION : 'unknown'),
            'Site URL: ' . network_home_url(),
            '',
            'Files in this archive:',
            '- README.txt — this file',
            '- health.json — disk, PHP, ZipArchive, mysqldump, hosting detection',
            '- settings-redacted.json — plugin settings (passwords/secrets removed)',
            '- edition.json — active edition and tier',
            '- activity-tail.jsonl — last activity events (if any)',
            '- last-job.log — tail of the newest job-{token}.log (if any)',
            '- system.log — tail of system messages (if any)',
            '- logs-manifest.txt — on-disk log filenames including rotated generations',
            '',
            'No database content or backup archives are included.',
        );

        return implode("\n", $lines) . "\n";
    }
}
