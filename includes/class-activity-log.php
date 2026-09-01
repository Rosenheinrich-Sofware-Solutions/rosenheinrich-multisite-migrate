<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Activity_Log
{
    /** @deprecated Use max_entries() */
    const MAX_ENTRIES = 2000;

    /** @var array<int,Rmmigrate_Job|null> */
    private static $job_request_cache = array();

    /** @var object|null Unit-test override for active-job inject (not for production use). */
    public static $hydrate_active_job_override = null;

    /** @var array<string,bool> */
    private static $job_visibility_request_cache = array();

    public static function max_entries(): int
    {
        return Rmmigrate_Engine_Config::activity_max_entries();
    }

    public static function logs_dir(): string
    {
        Rmmigrate_Plugin::ensure_backup_root();
        return Rmmigrate_Plugin::backups_dir() . '/logs';
    }

    public static function path(): string
    {
        return self::logs_dir() . '/activity.jsonl';
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function record(string $type, string $message, string $status = 'info', array $data = array()): void
    {
        $context = is_array($data['context'] ?? null) ? $data['context'] : array();
        if (!isset($context['triggered_by'])) {
            if (function_exists('wp_doing_cron') && wp_doing_cron()) {
                $context['triggered_by'] = 'cron';
            } elseif (get_current_user_id() > 0) {
                $context['triggered_by'] = 'user';
            } else {
                $context['triggered_by'] = 'system';
            }
        }
        if ($status === 'error' && !empty($data['job_id'])) {
            $context = self::enrich_error_context((int) $data['job_id'], $context);
        }

        $entry = array(
            'entry_id' => '',
            'time'     => gmdate('c'),
            'type'     => $type,
            'status'   => $status,
            'message'  => RMMIGRATE_IO::redact_log_message($message),
            'job_id'   => (int) ($data['job_id'] ?? 0),
            'user_id'  => get_current_user_id(),
            'context'  => RMMIGRATE_IO::redact_context($context),
        );
        $entry['entry_id'] = self::make_entry_id($entry);

        $line = wp_json_encode($entry) . "\n";
        $written = self::with_activity_lock(static function () use ($entry, $line): void {
            if (self::is_duplicate_terminal($entry)) {
                return;
            }
            Rmmigrate_Filesystem::put_contents(self::path(), $line, FILE_APPEND);
            self::trim_file();
        });
        if (!$written) {
            Rmmigrate_Logger::log_system(
                'Activity entry dropped: could not acquire activity log lock.',
                array('type' => $type, 'status' => $status),
                'warning'
            );
        }
    }

    /**
     * @param callable():void $callback
     */
    private static function with_activity_lock(callable $callback, int $max_attempts = 5): bool
    {
        $path = self::path();
        $lock_path = $path . '.lock';
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $lock = Rmmigrate_Filesystem::open_lock($lock_path, 'c+');
            if ($lock === false) {
                usleep(50000 * ($attempt + 1));
                continue;
            }
            if (!Rmmigrate_Filesystem::try_exclusive_lock($lock)) {
                Rmmigrate_Filesystem::fclose_raw($lock);
                usleep(50000 * ($attempt + 1));
                continue;
            }

            try {
                $callback();
                return true;
            } finally {
                Rmmigrate_Filesystem::release_lock($lock);
                Rmmigrate_Filesystem::fclose_raw($lock);
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function make_entry_id(array $entry): string
    {
        return substr(hash('sha256', wp_json_encode(array(
            $entry['time'] ?? '',
            $entry['type'] ?? '',
            $entry['message'] ?? '',
            $entry['job_id'] ?? 0,
            $entry['status'] ?? '',
        ))), 0, 16);
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function is_duplicate_terminal(array $entry): bool
    {
        $path = self::path();
        if (!Rmmigrate_Filesystem::exists($path)) {
            return false;
        }
        $last = self::read_tail_lines($path, 1);
        if ($last === array()) {
            return false;
        }
        $decoded = json_decode($last[0], true);
        if (!is_array($decoded)) {
            return false;
        }
        if (($decoded['job_id'] ?? 0) !== ($entry['job_id'] ?? 0)) {
            return false;
        }
        if (($decoded['status'] ?? '') !== ($entry['status'] ?? '')) {
            return false;
        }
        if (($decoded['message'] ?? '') !== ($entry['message'] ?? '')) {
            return false;
        }
        $last_ts = strtotime((string) ($decoded['time'] ?? ''));
        $new_ts = strtotime((string) ($entry['time'] ?? ''));
        if ($last_ts === false || $new_ts === false) {
            return false;
        }
        return abs($new_ts - $last_ts) <= 2;
    }

    /**
     * @return array{entries:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int,total_estimated:bool}
     */
    public static function list_entries(
        int $per_page = 25,
        int $page = 1,
        string $type_filter = '',
        string $date_from = '',
        string $date_to = '',
        int $job_id = 0
    ): array {
        $per_page = max(1, $per_page);
        $page = max(1, $page);

        $collected = self::collect_filtered_entries($type_filter, $date_from, $date_to, $job_id);
        $bundled = self::hydrate_active_job_entries(
            self::bundle_entries_for_display($collected['entries']),
            $type_filter,
            $date_from,
            $date_to,
            $job_id
        );
        $total = count($bundled);
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;

        return array(
            'entries'          => array_slice($bundled, $offset, $per_page),
            'total'            => $total,
            'page'             => $page,
            'per_page'         => $per_page,
            'total_pages'      => $total_pages,
            'total_estimated'  => $collected['estimated'],
        );
    }

    /**
     * Overlay live job progress onto bundled activity rows, and inject an active
     * backup/restore job from the jobs table when it has no JSONL row yet
     * (e.g. activity lock drop on create).
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    public static function hydrate_active_job_entries(
        array $entries,
        string $type_filter = '',
        string $date_from = '',
        string $date_to = '',
        int $job_id_filter = 0
    ): array {
        $seen_job_ids = array();

        foreach ($entries as $index => $entry) {
            $job_id = (int) ($entry['job_id'] ?? 0);
            if ($job_id <= 0) {
                continue;
            }
            $seen_job_ids[$job_id] = true;
            $type = strtolower((string) ($entry['type'] ?? ''));
            if (!in_array($type, array('backup', 'restore'), true)) {
                continue;
            }

            $job = self::get_request_cached_job($job_id);
            if ($job === null) {
                continue;
            }

            $status = $job->get_status();
            if ($status < 0 || $status >= 100) {
                continue;
            }

            $entries[$index]['status'] = 'running';
            $entries[$index]['message'] = trim(
                $job->get_display_status() . ' ' . $job->get_progress_message()
            );
            $entries[$index]['status_label'] = self::status_label('running');
            $entries[$index]['type_label'] = self::type_label($type);
        }

        $type_filter = strtolower(trim($type_filter));
        if ($date_from !== '' || $date_to !== '') {
            return $entries;
        }
        if ($type_filter !== '' && !in_array($type_filter, array('backup', 'restore'), true)) {
            return $entries;
        }

        $is_network = function_exists('is_network_admin') && is_network_admin();
        $active = self::$hydrate_active_job_override;
        if ($active === null) {
            $active = apply_filters(
                'rmmigrate_activity_hydrate_active_job',
                Rmmigrate_Job::get_active_for_admin($is_network),
                $is_network
            );
        }
        if (!is_object($active) || !method_exists($active, 'get_id') || !method_exists($active, 'get_job_type')) {
            return $entries;
        }

        $active_id = $active->get_id();
        if ($active_id <= 0 || isset($seen_job_ids[$active_id])) {
            return $entries;
        }
        if ($job_id_filter > 0 && $active_id !== $job_id_filter) {
            return $entries;
        }

        $job_type = strtolower((string) $active->get_job_type());
        if (!in_array($job_type, array('backup', 'restore'), true)) {
            return $entries;
        }
        if ($type_filter !== '' && $type_filter !== $job_type) {
            return $entries;
        }

        $created = (string) ($active->data['created_at'] ?? '');
        $created_ts = $created !== '' ? strtotime($created . ' GMT') : false;
        $synthetic = array(
            'entry_id'     => 'active-job-' . $active_id,
            'time'         => $created_ts ? gmdate('c', $created_ts) : gmdate('c'),
            'type'         => $job_type,
            'status'       => 'running',
            'message'      => trim($active->get_display_status() . ' ' . $active->get_progress_message()),
            'job_id'       => $active_id,
            'user_id'      => 0,
            'context'      => array('synthetic_active_job' => true),
            'status_label' => self::status_label('running'),
            'type_label'   => self::type_label($job_type),
        );

        array_unshift($entries, $synthetic);
        return $entries;
    }

    /**
     * @return array{entries:array<int,array<string,mixed>>,estimated:bool}
     */
    private static function collect_filtered_entries(
        string $type_filter = '',
        string $date_from = '',
        string $date_to = '',
        int $job_id = 0
    ): array {
        $scan_budget = Rmmigrate_Engine_Config::activity_scan_budget();
        $files = Rmmigrate_Log_Rotator::list_generations(self::logs_dir(), 'activity.jsonl');
        if ($files === array()) {
            return array('entries' => array(), 'estimated' => false);
        }

        $from_ts = self::date_filter_start($date_from);
        $to_ts = self::date_filter_end($date_to);
        $entries = array();
        $lines_scanned = 0;
        $estimated = false;

        foreach ($files as $path) {
            if ($lines_scanned >= $scan_budget) {
                $estimated = true;
                break;
            }
            $remaining = $scan_budget - $lines_scanned;
            $lines = self::read_tail_lines($path, $remaining);
            $lines_scanned += count($lines);
            foreach (array_reverse($lines) as $line) {
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }
                if (empty($decoded['entry_id'])) {
                    $decoded['entry_id'] = self::make_entry_id($decoded);
                }
                if ($type_filter !== '' && ($decoded['type'] ?? '') !== $type_filter) {
                    continue;
                }
                if ($job_id > 0 && (int) ($decoded['job_id'] ?? 0) !== $job_id) {
                    continue;
                }
                if ($from_ts !== null || $to_ts !== null) {
                    $entry_ts = self::entry_timestamp($decoded);
                    if ($entry_ts === null) {
                        continue;
                    }
                    if ($from_ts !== null && $entry_ts < $from_ts) {
                        continue;
                    }
                    if ($to_ts !== null && $entry_ts > $to_ts) {
                        continue;
                    }
                }
                if (!self::entry_visible_to_current_user($decoded)) {
                    continue;
                }
                $entries[] = self::sanitize_entry_for_response($decoded);
            }
        }

        if ($lines_scanned >= $scan_budget) {
            $estimated = true;
        }

        return array('entries' => $entries, 'estimated' => $estimated);
    }

    /**
     * Collapse multiple backup rows for the same job into one summary row.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    public static function bundle_entries_for_display(array $entries): array
    {
        if ($entries === array()) {
            return array();
        }

        $upload_to_job = array();
        $safety_to_restore = array();
        foreach ($entries as $entry) {
            $job_id = (int) ($entry['job_id'] ?? 0);
            $context = is_array($entry['context'] ?? null) ? $entry['context'] : array();
            $upload_id = (string) ($context['upload_id'] ?? $context['import_id'] ?? '');

            if ($upload_id !== '' && $job_id > 0) {
                $upload_to_job[$upload_id] = $job_id;
            }

            $safety_job_id = (int) ($context['safety_job_id'] ?? 0);
            if ($safety_job_id > 0 && $job_id > 0) {
                $safety_to_restore[$safety_job_id] = $job_id;
            }
        }

        $process_groups = array();
        $display = array();

        foreach ($entries as $entry) {
            $job_id = (int) ($entry['job_id'] ?? 0);
            $context = is_array($entry['context'] ?? null) ? $entry['context'] : array();
            $upload_id = (string) ($context['upload_id'] ?? $context['import_id'] ?? '');
            $filename = (string) ($context['filename'] ?? $context['file_name'] ?? '');
            $message = (string) ($entry['message'] ?? '');

            if ($filename === '' && preg_match('/"([^"]+\.(?:zip|daf|venc))"/i', $message, $m)) {
                $filename = $m[1];
            }

            if ($job_id <= 0 && $upload_id !== '' && isset($upload_to_job[$upload_id])) {
                $job_id = $upload_to_job[$upload_id];
                $entry['job_id'] = $job_id;
            }

            if ($job_id <= 0 && preg_match('/job\s*#(\d+)/i', $message, $m)) {
                $job_id = (int) $m[1];
                $entry['job_id'] = $job_id;
            }

            // Fold pre-restore safety snapshot activity into the restore job row.
            if ($job_id > 0 && isset($safety_to_restore[$job_id])) {
                $job_id = (int) $safety_to_restore[$job_id];
                $entry['job_id'] = $job_id;
                $entry['type'] = 'restore';
            }

            $group_key = null;
            if ($job_id > 0) {
                $group_key = 'job_' . $job_id;
            } elseif ($upload_id !== '') {
                $group_key = 'upload_' . $upload_id;
            } elseif ($filename !== '') {
                $group_key = 'file_' . md5(strtolower($filename));
            }

            if ($group_key !== null) {
                if (!isset($process_groups[$group_key])) {
                    $process_groups[$group_key] = array();
                }
                $process_groups[$group_key][] = $entry;
                continue;
            }

            $display[] = $entry;
        }

        foreach ($process_groups as $group) {
            $summary = self::summarize_backup_activity_group($group);
            if ($summary !== array()) {
                $display[] = $summary;
            }
        }

        usort(
            $display,
            static function (array $a, array $b): int {
                $ta = self::entry_timestamp($a) ?? 0;
                $tb = self::entry_timestamp($b) ?? 0;
                return $tb <=> $ta;
            }
        );

        return $display;
    }

    /**
     * @param array<int,array<string,mixed>> $group
     * @return array<string,mixed>
     */
    private static function summarize_backup_activity_group(array $group): array
    {
        if ($group === array()) {
            return array();
        }

        $best = $group[0];
        $best_score = self::backup_activity_entry_score($group[0]);

        foreach ($group as $entry) {
            $score = self::backup_activity_entry_score($entry);
            if ($score > $best_score) {
                $best = $entry;
                $best_score = $score;
            }
        }

        $type_priority = array('import' => 10, 'backup' => 9, 'restore' => 8, 'staging' => 7, 'cloud' => 6, 'cleanup' => 5);
        $best_type = (string) ($best['type'] ?? '');
        $job_id = (int) ($best['job_id'] ?? 0);
        $job_type_locked = false;
        if ($job_id <= 0) {
            foreach ($group as $entry) {
                $candidate = (int) ($entry['job_id'] ?? 0);
                if ($candidate > 0) {
                    $job_id = $candidate;
                    break;
                }
            }
        }
        if ($job_id > 0) {
            $job = self::get_request_cached_job($job_id);
            if ($job !== null) {
                $best_type = $job->get_job_type() === Rmmigrate_Job::JOB_TYPE_RESTORE ? 'restore' : 'backup';
                $job_type_locked = true;
            }
        }
        if (!$job_type_locked) {
            foreach ($group as $entry) {
                if (strtolower((string) ($entry['type'] ?? '')) === 'restore') {
                    $best_type = 'restore';
                    $job_type_locked = true;
                    break;
                }
            }
        }
        $best_priority = $type_priority[strtolower($best_type)] ?? (strtolower($best_type) === 'system' ? 0 : 1);

        if (!$job_type_locked) {
            foreach ($group as $entry) {
                $t = strtolower((string) ($entry['type'] ?? ''));
                $p = $type_priority[$t] ?? ($t === 'system' ? 0 : 1);
                if ($p > $best_priority) {
                    $best_type = $t;
                    $best_priority = $p;
                }
            }
        }
        if ($best_type !== '') {
            $best['type'] = $best_type;
        }

        if (count($group) === 1) {
            return $best;
        }

        $best['bundled_count'] = count($group);
        $best['bundled_entry_ids'] = array_values(array_filter(array_map(
            static function (array $entry): string {
                return (string) ($entry['entry_id'] ?? '');
            },
            $group
        )));
        $best['bundled_entries'] = array_values(array_map(
            static function (array $entry): array {
                return self::sanitize_entry_for_response($entry);
            },
            $group
        ));

        return $best;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function backup_activity_entry_score(array $entry): int
    {
        $status = strtolower((string) ($entry['status'] ?? 'info'));
        $scores = array(
            'error'   => 100,
            'warning' => 80,
            'success' => 70,
            'running' => 40,
            'info'    => 10,
        );
        $score = $scores[$status] ?? 10;

        $message = strtolower((string) ($entry['message'] ?? ''));
        if (preg_match('/\b(completed|failed|cancelled|canceled|error)\b/', $message)) {
            $score += 50;
        }
        if (preg_match('/\bqueued\b/', $message)) {
            $score += 20;
        }
        if (preg_match('/worker (request|lock|kickoff)/', $message)) {
            $score -= 30;
        }

        return $score;
    }

    public static function normalize_date_filter(string $date): string
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return '';
        }

        return $date;
    }

    public static function format_display_time(string $iso_time): string
    {
        $ts = self::entry_timestamp(array('time' => $iso_time));
        if ($ts === null) {
            return $iso_time;
        }

        return Rmmigrate_Time::local_ts($ts, 'Y-m-d g:i:s a');
    }

    public static function status_pill_class(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, array('success', 'ok', 'complete', 'completed', 'done'), true)) {
            return 'mm-status-pill mm-status-ok';
        }
        if (in_array($status, array('error', 'failed', 'failure'), true)) {
            return 'mm-status-pill mm-status-error';
        }
        if (in_array($status, array('warn', 'warning', 'active', 'running', 'progress'), true)) {
            return 'mm-status-pill mm-status-warn';
        }

        return 'mm-status-pill mm-status-idle';
    }

    public static function status_label(string $status): string
    {
        switch (strtolower(trim($status))) {
            case 'success':
            case 'ok':
            case 'complete':
            case 'completed':
            case 'done':
                return __('Success', 'rosenheinrich-multisite-migrate');
            case 'error':
            case 'failed':
            case 'failure':
                return __('Error', 'rosenheinrich-multisite-migrate');
            case 'warn':
            case 'warning':
                return __('Warning', 'rosenheinrich-multisite-migrate');
            case 'active':
            case 'running':
            case 'progress':
                return __('In progress', 'rosenheinrich-multisite-migrate');
            case 'info':
                return __('Info', 'rosenheinrich-multisite-migrate');
            default:
                $status = trim($status);
                return $status !== '' ? ucfirst($status) : __('Info', 'rosenheinrich-multisite-migrate');
        }
    }

    public static function type_label(string $type): string
    {
        switch (strtolower(trim($type))) {
            case 'backup':
                return __('Backup', 'rosenheinrich-multisite-migrate');
            case 'restore':
                return __('Restore', 'rosenheinrich-multisite-migrate');
            case 'import':
                return __('Import', 'rosenheinrich-multisite-migrate');
            case 'staging':
                return __('Staging', 'rosenheinrich-multisite-migrate');
            case 'system':
                return __('System', 'rosenheinrich-multisite-migrate');
            case 'email':
                return __('Email', 'rosenheinrich-multisite-migrate');
            case 'schedule':
                return __('Schedule', 'rosenheinrich-multisite-migrate');
            default:
                $type = trim($type);
                return $type !== '' ? ucfirst($type) : __('Event', 'rosenheinrich-multisite-migrate');
        }
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function entry_timestamp(array $entry): ?int
    {
        $ts = strtotime((string) ($entry['time'] ?? ''));
        return $ts !== false ? $ts : null;
    }

    private static function date_filter_start(string $date): ?int
    {
        $date = self::normalize_date_filter($date);
        if ($date === '') {
            return null;
        }

        $ts = strtotime($date . ' 00:00:00 UTC');
        return $ts !== false ? $ts : null;
    }

    private static function date_filter_end(string $date): ?int
    {
        $date = self::normalize_date_filter($date);
        if ($date === '') {
            return null;
        }

        $ts = strtotime($date . ' 23:59:59 UTC');
        return $ts !== false ? $ts : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_entry(string $entry_id): ?array
    {
        $entry_id = sanitize_key($entry_id);
        if ($entry_id === '') {
            return null;
        }
        $bundled = self::bundle_entries_for_display(self::collect_filtered_entries('', '', '')['entries']);
        foreach ($bundled as $entry) {
            if (($entry['entry_id'] ?? '') === $entry_id) {
                return self::entry_visible_to_current_user($entry) ? $entry : null;
            }
        }
        return null;
    }

    public static function tail_job_log(int $job_id, int $lines = 30): string
    {
        if ($job_id <= 0) {
            return '';
        }
        $path = self::job_log_path($job_id);
        if ($path === '' || !Rmmigrate_Filesystem::is_readable($path)) {
            return '';
        }
        return RMMIGRATE_IO::redact_log_message(self::read_file_tail($path, $lines));
    }

    public static function read_file_tail(string $path, int $max_lines = 200): string
    {
        $chunk = self::read_file_chunk($path, $max_lines, 0);
        return $chunk['lines'];
    }

    public static function count_display_lines(string $lines): int
    {
        if ($lines === '') {
            return 0;
        }

        $count = substr_count($lines, "\n");
        if (substr($lines, -1) !== "\n") {
            $count++;
        }

        return $count;
    }

    /**
     * @return array{lines:string,total_lines:int,offset_from_end:int,has_older:bool,has_newer:bool,older_file:string}
     */
    public static function read_file_chunk(string $path, int $lines = 200, int $offset_from_end = 0): array
    {
        $lines = max(1, $lines);
        $offset_from_end = max(0, $offset_from_end);

        if (!Rmmigrate_Filesystem::is_readable($path)) {
            return array(
                'lines'             => '',
                'total_lines'       => 0,
                'offset_from_end'   => $offset_from_end,
                'has_older'         => false,
                'has_newer'         => false,
                'older_file'        => '',
            );
        }

        $need = $offset_from_end + $lines;
        $all = self::read_tail_lines($path, $need);
        $available = count($all);
        $end_index = max(0, $available - $offset_from_end);
        $start_index = max(0, $end_index - $lines);
        $chunk_lines = array_slice($all, $start_index, $end_index - $start_index);
        $total_lines = self::count_file_lines($path);

        $has_older_in_file = $start_index > 0;
        $older_file = '';
        if (!$has_older_in_file && $end_index === 0 && $offset_from_end >= $available && $available > 0) {
            $older_file = self::next_older_log_generation($path);
        } elseif (!$has_older_in_file && $offset_from_end + $lines >= $available && $available > 0) {
            $older_file = self::next_older_log_generation($path);
            if ($older_file === '') {
                if ($total_lines > 0 && ($offset_from_end + $lines) < $total_lines) {
                    $has_older_in_file = true;
                } elseif ($total_lines < 0 && $available >= $need && $start_index === 0) {
                    $has_older_in_file = true;
                }
            }
        }

        return array(
            'lines'             => implode("\n", $chunk_lines),
            'total_lines'       => $total_lines,
            'offset_from_end'   => $offset_from_end,
            'has_older'         => $has_older_in_file || $older_file !== '',
            'has_newer'         => $offset_from_end > 0,
            'older_file'        => $older_file,
        );
    }

    private static function next_older_log_generation(string $path): string
    {
        $basename = basename($path);
        if (preg_match('/^(.+)\.(\d+)(\.[^.]+)$/', $basename, $matches)) {
            $generation = (int) $matches[2] + 1;
            $candidate = dirname($path) . '/' . $matches[1] . '.' . $generation . $matches[3];
            return Rmmigrate_Filesystem::is_readable($candidate) ? basename($candidate) : '';
        }
        if (preg_match('/^(.+)(\.[^.]+)$/', $basename, $matches)) {
            $candidate = dirname($path) . '/' . $matches[1] . '.1' . $matches[2];
            return Rmmigrate_Filesystem::is_readable($candidate) ? basename($candidate) : '';
        }

        return '';
    }

    private static function count_file_lines(string $path): int
    {
        $size = Rmmigrate_Filesystem::filesize($path);
        if ($size <= 0) {
            return 0;
        }
        if ($size > 10485760) {
            return -1;
        }

        $raw = Rmmigrate_Filesystem::get_contents($path);
        if ($raw === false || $raw === '') {
            return 0;
        }

        $trimmed = rtrim(str_replace("\r", '', $raw), "\n");
        if ($trimmed === '') {
            return 0;
        }

        return substr_count($trimmed, "\n") + 1;
    }

    /**
     * @return array<int,string>
     */
    public static function read_tail_lines_for_export(string $path, int $max_lines): array
    {
        return self::read_tail_lines($path, $max_lines);
    }

    /**
     * @return array<int,string>
     */
    private static function read_tail_lines(string $path, int $max_lines): array
    {
        $handle = Rmmigrate_Filesystem::open($path, 'rb');
        if ($handle === false) {
            return array();
        }

        $chunk_size = 8192;
        $buffer = '';
        $pos = Rmmigrate_Filesystem::filesize($path);
        $lines = array();

        while ($pos > 0 && count($lines) <= $max_lines) {
            $read = min($chunk_size, $pos);
            $pos -= $read;
            $handle->seek($pos);
            $buffer = $handle->read($read) . $buffer;
            $parts = explode("\n", $buffer);
            $buffer = array_shift($parts);
            foreach (array_reverse($parts) as $part) {
                $part = rtrim($part, "\r");
                if ($part === '') {
                    continue;
                }
                array_unshift($lines, $part);
                if (count($lines) >= $max_lines) {
                    break 2;
                }
            }
        }

        if ($buffer !== '' && count($lines) < $max_lines) {
            $tail = rtrim($buffer, "\r");
            if ($tail !== '') {
                array_unshift($lines, $tail);
            }
        }

        $handle->close();
        return array_slice($lines, -$max_lines);
    }

    public static function clear(): void
    {
        foreach (Rmmigrate_Log_Rotator::glob_activity_log_files(self::logs_dir()) as $path) {
            if (Rmmigrate_Filesystem::is_file($path)) {
                Rmmigrate_Filesystem::delete($path);
            }
        }
    }

    public static function clear_all(): void
    {
        self::clear();
        self::delete_all_job_logs();
        self::delete_all_system_logs();
    }

    private static function trim_file(): void
    {
        $path = self::path();
        if (!Rmmigrate_Filesystem::exists($path)) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive lock required across read/rewrite.
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return;
        }
        if (!flock($fh, LOCK_EX)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
            fclose($fh);
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Locked read for trim.
        $content = stream_get_contents($fh);
        if ($content === false || $content === '') {
            flock($fh, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
            fclose($fh);
            return;
        }
        $max = self::max_entries();
        $lines = explode("\n", str_replace("\r", '', $content));
        $lines = array_filter(array_map('trim', $lines), 'strlen');
        if (count($lines) <= $max) {
            flock($fh, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
            fclose($fh);
            Rmmigrate_Log_Rotator::maintain_activity_log();
            return;
        }
        $keep = array_slice($lines, -$max);
        ftruncate($fh, 0);
        rewind($fh);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Plugin: centralized filesystem gateway.
        fwrite($fh, implode("\n", $keep) . "\n");
        flock($fh, LOCK_UN);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
        fclose($fh);
        Rmmigrate_Log_Rotator::maintain_activity_log();
    }

    /**
     * @return array{files:array<int,array{name:string,path:string,size:int}>,total:int,page:int,per_page:int,total_pages:int}
     */
    public static function list_job_logs(int $page = 1, int $per_page = 25): array
    {
        $dir = self::logs_dir();
        if (!Rmmigrate_Filesystem::is_dir($dir)) {
            return array(
                'files'       => array(),
                'total'       => 0,
                'page'        => 1,
                'per_page'    => $per_page,
                'total_pages' => 1,
            );
        }

        $files = Rmmigrate_Log_Rotator::glob_base_job_logs($dir);
        $total = count($files);
        $per_page = max(1, $per_page);
        $page = max(1, $page);
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;
        $slice = array_slice($files, $offset, $per_page);
        $out = array();
        foreach ($slice as $file) {
            $out[] = array(
                'name' => basename($file),
                'path' => $file,
                'size' => (int) Rmmigrate_Filesystem::filesize($file),
            );
        }

        return array(
            'files'       => $out,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total_pages,
        );
    }

    /**
     * @return array{files:array<int,array{name:string,path:string,size:int}>,total:int}
     */
    public static function list_system_logs(): array
    {
        $dir = self::logs_dir();
        $paths = Rmmigrate_Log_Rotator::glob_system_log_files($dir);
        $out = array();
        foreach ($paths as $file) {
            $out[] = array(
                'name' => basename($file),
                'path' => $file,
                'size' => (int) Rmmigrate_Filesystem::filesize($file),
            );
        }

        return array(
            'files' => $out,
            'total' => count($out),
        );
    }

    public static function delete_all_job_logs(): int
    {
        $deleted = 0;
        foreach (Rmmigrate_Log_Rotator::glob_job_log_files(self::logs_dir()) as $path) {
            if (Rmmigrate_Filesystem::is_file($path) && Rmmigrate_Filesystem::delete($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    public static function delete_all_system_logs(): int
    {
        $deleted = 0;
        foreach (Rmmigrate_Log_Rotator::glob_system_log_files(self::logs_dir()) as $path) {
            if (Rmmigrate_Filesystem::is_file($path) && Rmmigrate_Filesystem::delete($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    public static function generate_log_token(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Exception $e) {
            return substr(hash('sha256', uniqid((string) wp_rand(), true)), 0, 16);
        }
    }

    /**
     * Opaque site-wide token for system.log (not guessable as "system.log").
     */
    public static function site_log_token(): string
    {
        $token = (string) get_site_option('rmmigrate_log_site_token', '');
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            $token = self::generate_log_token();
            update_site_option('rmmigrate_log_site_token', $token);
        }

        return $token;
    }

    public static function system_log_basename_current(): string
    {
        return 'system-' . self::site_log_token() . '.log';
    }

    public static function system_log_path(): string
    {
        $dir = trailingslashit(self::logs_dir());
        $opaque = $dir . self::system_log_basename_current();
        // Keep writing/reading existing system.log after upgrade; only use opaque when no legacy file.
        if (Rmmigrate_Filesystem::is_file($opaque)) {
            return $opaque;
        }
        $legacy = $dir . 'system.log';
        if (Rmmigrate_Filesystem::is_file($legacy)) {
            return $legacy;
        }

        return $opaque;
    }

    /**
     * Whether a basename is allowed for authenticated log viewing.
     * Accepts opaque job-/system-{token} names and legacy backup-{id} / system.log (+ rotations).
     */
    public static function is_allowed_log_basename(string $log): bool
    {
        return (bool) preg_match(
            '/^(job-[a-f0-9]{16}(\.\d+)?|system-[a-f0-9]{16}(\.\d+)?|backup-\d+(\.\d+)?|system(\.\d+)?)\.log$/',
            $log
        );
    }

    /**
     * Read log_token from job progress without minting.
     */
    public static function read_job_log_token(int $job_id): string
    {
        if ($job_id <= 0) {
            return '';
        }
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            return '';
        }
        $progress = $job->get_progress();
        $token = isset($progress['log_token']) ? (string) $progress['log_token'] : '';

        return preg_match('/^[a-f0-9]{16}$/', $token) ? $token : '';
    }

    /**
     * Ensure job progress has log_token; persist if created.
     */
    public static function ensure_job_log_token(int $job_id): string
    {
        if ($job_id <= 0) {
            return '';
        }
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            return '';
        }
        $progress = $job->get_progress();
        $token = isset($progress['log_token']) ? (string) $progress['log_token'] : '';
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            $token = self::generate_log_token();
            $job->update_progress(array('log_token' => $token));
        }

        return $token;
    }

    public static function job_log_basename_from_token(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            return '';
        }

        return 'job-' . $token . '.log';
    }

    public static function legacy_job_log_basename(int $job_id): string
    {
        return $job_id > 0 ? 'backup-' . $job_id . '.log' : '';
    }

    /**
     * Resolve job log path: existing opaque file, else existing legacy backup-{id}.log,
     * else mint opaque token for new writes. Never renames or deletes legacy files.
     */
    public static function job_log_path(int $job_id): string
    {
        if ($job_id <= 0) {
            return '';
        }
        $dir = trailingslashit(self::logs_dir());

        $token = self::read_job_log_token($job_id);
        if ($token !== '') {
            $opaque = $dir . self::job_log_basename_from_token($token);
            if (Rmmigrate_Filesystem::is_file($opaque)) {
                return $opaque;
            }
        }

        $legacy = $dir . self::legacy_job_log_basename($job_id);
        if (Rmmigrate_Filesystem::is_file($legacy)) {
            return $legacy;
        }

        if ($token !== '') {
            return $dir . self::job_log_basename_from_token($token);
        }

        $token = self::ensure_job_log_token($job_id);

        return $token === '' ? '' : $dir . self::job_log_basename_from_token($token);
    }

    public static function job_log_basename(int $job_id): string
    {
        $path = self::job_log_path($job_id);

        return $path === '' ? '' : basename($path);
    }

    public static function job_log_readable(int $job_id): bool
    {
        $path = self::job_log_path($job_id);

        return $path !== '' && Rmmigrate_Filesystem::is_readable($path);
    }

    public static function system_log_readable(): bool
    {
        foreach (Rmmigrate_Log_Rotator::glob_system_log_files(self::logs_dir()) as $path) {
            if (Rmmigrate_Filesystem::is_readable($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null Primary system log filename when readable.
     */
    public static function system_log_basename(): ?string
    {
        $primary = self::system_log_path();
        if (Rmmigrate_Filesystem::is_readable($primary)) {
            return basename($primary);
        }
        foreach (Rmmigrate_Log_Rotator::glob_system_log_files(self::logs_dir()) as $path) {
            if (Rmmigrate_Filesystem::is_readable($path)) {
                return basename($path);
            }
        }

        return null;
    }

    public static function delete_job_log(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }
        $token = '';
        $job = Rmmigrate_Job::get($job_id);
        if ($job !== null) {
            $progress = $job->get_progress();
            $token = isset($progress['log_token']) ? (string) $progress['log_token'] : '';
        }
        $dir = trailingslashit(self::logs_dir());
        $paths = array();
        if (preg_match('/^[a-f0-9]{16}$/', $token)) {
            $paths = array_merge(
                glob($dir . 'job-' . $token . '.log') ?: array(),
                glob($dir . 'job-' . $token . '.*.log') ?: array()
            );
        }
        // Legacy predictable names from older releases.
        $paths = array_merge(
            $paths,
            glob($dir . 'backup-' . $job_id . '.log') ?: array(),
            glob($dir . 'backup-' . $job_id . '.*.log') ?: array()
        );
        foreach (array_unique($paths) as $path) {
            if (Rmmigrate_Filesystem::is_file($path)) {
                Rmmigrate_Filesystem::delete($path);
            }
        }
    }

    /**
     * No-op: legacy backup-*.log / system.log are retained so upgrades keep
     * existing logs readable and appendable alongside opaque names.
     */
    public static function purge_stale_predictable_logs(): int
    {
        return 0;
    }

    public static function delete_entries_for_job(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }

        if (!self::with_activity_lock(static function () use ($job_id): void {
            foreach (Rmmigrate_Log_Rotator::glob_activity_log_files(self::logs_dir()) as $path) {
                if (!Rmmigrate_Filesystem::exists($path)) {
                    continue;
                }

                $content = Rmmigrate_Filesystem::get_contents($path);
                if ($content === false || $content === '') {
                    continue;
                }

                $lines = explode("\n", str_replace("\r", '', $content));
                $kept = array();
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $decoded = json_decode($line, true);
                    if (!is_array($decoded)) {
                        $kept[] = $line;
                        continue;
                    }
                    if ((int) ($decoded['job_id'] ?? 0) === $job_id) {
                        continue;
                    }
                    $kept[] = $line;
                }

                if ($kept === array()) {
                    Rmmigrate_Filesystem::delete($path);
                    continue;
                }

                Rmmigrate_Filesystem::put_contents($path, implode("\n", $kept) . "\n");
            }
        })) {
            Rmmigrate_Logger::log(
                sprintf(
                    /* translators: %d: job ID */
                    __('Could not acquire activity log lock to delete entries for job #%d.', 'rosenheinrich-multisite-migrate'),
                    $job_id
                )
            );
        }
    }

    public static function log_view_url(string $log_name, bool $is_network): string
    {
        return Rmmigrate_Admin_Router::admin_url(
            'multisite-migrate-activity',
            array(
                'log'        => $log_name,
                'log_offset' => 0,
            ),
            $is_network
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function enrich_error_context(int $job_id, array $context): array
    {
        $tail = self::tail_job_log($job_id, 20);
        if ($tail !== '') {
            $context['job_log_tail'] = $tail;
        }
        $context['php_memory_limit'] = ini_get('memory_limit');
        $context['php_time_limit'] = ini_get('max_execution_time');
        $context['plugin_version'] = defined('RMMIGRATE_VERSION') ? RMMIGRATE_VERSION : '';
        return RMMIGRATE_IO::redact_context($context);
    }

    /**
     * @param array<string,mixed> $entry
     */
    public static function entry_visible_to_current_user(array $entry): bool
    {
        $job_id = (int) ($entry['job_id'] ?? 0);
        if ($job_id <= 0) {
            return self::current_user_is_activity_admin();
        }

        return self::resolve_job_visibility($job_id);
    }

    private static function job_visibility_cache_key(int $job_id): string
    {
        return (string) get_current_blog_id() . ':' . (string) get_current_user_id() . ':' . (string) $job_id;
    }

    private static function get_request_cached_job(int $job_id): ?Rmmigrate_Job
    {
        if ($job_id <= 0) {
            return null;
        }

        if (!array_key_exists($job_id, self::$job_request_cache)) {
            self::$job_request_cache[$job_id] = Rmmigrate_Job::get($job_id);
        }

        return self::$job_request_cache[$job_id];
    }

    private static function resolve_job_visibility(int $job_id): bool
    {
        $cache_key = self::job_visibility_cache_key($job_id);
        if (array_key_exists($cache_key, self::$job_visibility_request_cache)) {
            return self::$job_visibility_request_cache[$cache_key];
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            $visible = self::current_user_is_activity_admin();
            self::$job_visibility_request_cache[$cache_key] = $visible;
            return $visible;
        }

        $job = self::get_request_cached_job($job_id);
        if ($job === null) {
            $visible = self::current_user_is_activity_admin();
        } else {
            $visible = Rmmigrate_Access::can_view_job($job);
        }

        self::$job_visibility_request_cache[$cache_key] = $visible;
        return $visible;
    }

    private static function current_user_is_activity_admin(): bool
    {
        if (is_multisite()) {
            return current_user_can('manage_network');
        }

        return current_user_can('manage_options');
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function sanitize_entry_for_response(array $entry): array
    {
        $entry['message'] = RMMIGRATE_IO::redact_log_message((string) ($entry['message'] ?? ''));
        if (isset($entry['context']) && is_array($entry['context'])) {
            $entry['context'] = RMMIGRATE_IO::redact_context($entry['context']);
            unset($entry['context']['job_log_tail']);
        }
        $type = (string) ($entry['type'] ?? '');
        $status = (string) ($entry['status'] ?? 'info');
        $entry['type_label'] = self::type_label($type);
        $entry['status_label'] = self::status_label($status);
        $entry['time_display'] = self::format_display_time((string) ($entry['time'] ?? ''));
        return $entry;
    }
}
