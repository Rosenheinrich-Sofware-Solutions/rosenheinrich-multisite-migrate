<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Hosting_Detection
{
    const OPTION_KEY = 'rmmigrate_hosting_status';
    const CRON_HOOK    = 'rmmigrate_worker_cron';

    public static function register(): void
    {
        add_action('init', array(__CLASS__, 'maybe_loopback_ping'), 0);
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_cron_worker'), 10, 1);
        add_action('rmmigrate_deferred_hosting_detect', array(__CLASS__, 'run_deferred_detection'));
    }

    public static function run_deferred_detection(): void
    {
        self::run_detection(true);
    }

    public static function maybe_loopback_ping(): void
    {
        if (!Rmmigrate_Request_Input::get_exists('mm_loopback_ping')) {
            return;
        }
        $token = Rmmigrate_Request_Input::get_text('mm_token');
        if (!hash_equals(self::loopback_ping_token(), $token)) {
            status_header(403);
            exit;
        }
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'ok';
        exit;
    }

    public static function loopback_ping_token(): string
    {
        return substr(hash_hmac('sha256', 'mm_loopback_ping', wp_salt('auth')), 0, 16);
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_cached(): array
    {
        $cached = get_site_option(self::OPTION_KEY, array());
        return is_array($cached) ? $cached : array();
    }

    /**
     * @return array<string,mixed>
     */
    public static function run_detection(bool $force = false): array
    {
        $cached = self::get_cached();
        if (!$force && !empty($cached['detected_at']) && (time() - (int) $cached['detected_at']) < DAY_IN_SECONDS) {
            return $cached;
        }

        $loopback = self::test_loopback();
        $max_exec = (int) ini_get('max_execution_time');
        $memory = ini_get('memory_limit');

        $status = array(
            'detected_at'      => time(),
            'loopback'         => $loopback,
            'loopback_ok'      => !empty($loopback['ok']),
            'max_execution_time' => $max_exec,
            'memory_limit'     => (string) $memory,
            'lock_mode'        => self::detect_lock_mode(),
            'kickoff_mode'     => self::settings_kickoff_mode(),
            'effective_kickoff'=> self::resolve_kickoff_mode($loopback),
            'effective_archive'=> self::resolve_archive_mode($max_exec),
            'worker_budget_sec'=> self::worker_budget_sec($max_exec),
            'nfs_backups'      => self::is_nfs_backups_dir(),
            'php_version'      => PHP_VERSION,
        );

        update_site_option(self::OPTION_KEY, $status);
        return $status;
    }

    /**
     * @return array{ok:bool,url:string,code:int,error:string,body:string}
     */
    public static function test_loopback(): array
    {
        $url = add_query_arg(array(
            'mm_loopback_ping' => '1',
            'mm_token'         => self::loopback_ping_token(),
        ), admin_url('admin-ajax.php'));
        $response = wp_remote_get($url, Rmmigrate_Engine_Config::http_args(array(
            'timeout'   => 10,
            'blocking'  => true,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Plugin: third-party cache plugin hooks detected at runtime.
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        )));

        $out = array(
            'ok'    => false,
            'url'   => $url,
            'code'  => 0,
            'error' => '',
            'body'  => '',
        );

        if (is_wp_error($response)) {
            $out['error'] = $response->get_error_message();
            return $out;
        }

        $out['code'] = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $out['body'] = substr(trim($body), 0, 120);
        $out['ok'] = $out['code'] === 200 && strpos($body, 'ok') !== false;
        return $out;
    }

    public static function settings_kickoff_mode(): string
    {
        $mode = (string) (Rmmigrate_Settings::get()['kickoff_mode'] ?? 'auto');
        return in_array($mode, array('auto', 'loopback', 'cron', 'browser'), true) ? $mode : 'auto';
    }

    public static function settings_lock_mode(): string
    {
        $mode = (string) (Rmmigrate_Settings::get()['lock_mode'] ?? 'auto');
        return in_array($mode, array('auto', 'file', 'sql'), true) ? $mode : 'auto';
    }

    /**
     * @param array{ok?:bool}|null $loopback
     */
    public static function resolve_kickoff_mode(?array $loopback = null): string
    {
        $setting = self::settings_kickoff_mode();
        if ($setting !== 'auto') {
            return $setting;
        }
        // Never block on a live loopback HTTP probe here — that can deadlock single-worker hosts
        // during backup start / asset localize. Use cache or a conservative cron default.
        $loopback = $loopback ?? (self::get_cached()['loopback'] ?? null);
        if (!is_array($loopback)) {
            return 'cron';
        }
        if (!empty($loopback['ok'])) {
            return 'loopback';
        }
        return 'cron';
    }

    public static function effective_kickoff_mode(): string
    {
        $setting = self::settings_kickoff_mode();
        if ($setting !== 'auto') {
            return $setting;
        }
        $cached = self::get_cached();
        if (!empty($cached['effective_kickoff'])) {
            return (string) $cached['effective_kickoff'];
        }
        self::schedule_deferred_detection();
        // Shared hosts often have dead WP-Cron; browser pump is the reliable cold default.
        return 'browser';
    }

    public static function schedule_deferred_detection(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        try {
            if (!wp_next_scheduled('rmmigrate_deferred_hosting_detect')) {
                wp_schedule_single_event(time() + 5, 'rmmigrate_deferred_hosting_detect');
            }
        } catch (Throwable $e) {
            // Unit tests / incomplete WP bootstraps may stub cron APIs without expectations.
        }
    }

    public static function resolve_archive_mode(?int $max_exec = null): string
    {
        $settings = Rmmigrate_Settings::get();
        if (!empty($settings['archive_mode_manual'])) {
            return ($settings['archive_mode'] ?? 'zip') === 'daf' ? 'daf' : 'zip';
        }
        $mode = (string) ($settings['archive_mode'] ?? 'zip');
        if ($mode === 'daf') {
            return 'daf';
        }
        if ($max_exec === null) {
            $max_exec = (int) ini_get('max_execution_time');
        }
        if ($max_exec > 0 && $max_exec < 120) {
            return 'daf';
        }
        return $mode === 'daf' ? 'daf' : 'zip';
    }

    public static function effective_archive_mode(): string
    {
        $cached = self::get_cached();
        if (!empty($cached['effective_archive'])) {
            return (string) $cached['effective_archive'];
        }
        return self::resolve_archive_mode();
    }

    /**
     * Resolve the archive format for a specific job. A per-backup override stored
     * on the job (chosen in the create wizard) wins over the last-used setting; when
     * absent we fall back to the effective hosting mode.
     */
    public static function effective_archive_mode_for_job(?Rmmigrate_Job $job): string
    {
        if ($job !== null) {
            $progress = $job->get_progress();
            $override = (string) ($progress['archive_mode'] ?? $progress['init']['archive_mode'] ?? '');
            if ($override === 'zip' || $override === 'daf') {
                return $override;
            }
        }
        return self::effective_archive_mode();
    }

    public static function worker_budget_sec(?int $max_exec = null): int
    {
        if ($max_exec === null) {
            $max_exec = (int) ini_get('max_execution_time');
        }
        // Shared proxies often kill at 30s — keep every ajax slice ≤15s wall clock.
        if ($max_exec === 0) {
            return 15;
        }
        if ($max_exec < 15) {
            return max(5, $max_exec - 3);
        }
        // Low ceilings (e.g. Laragon 20s): reserve bootstrap/shutdown headroom.
        if ($max_exec < 25) {
            return min(15, max(5, $max_exec - 8));
        }
        return min(15, max(5, $max_exec - 5));
    }

    public static function detect_lock_mode(): string
    {
        $setting = self::settings_lock_mode();
        if ($setting !== 'auto') {
            return $setting;
        }
        return self::is_nfs_backups_dir() ? 'sql' : 'file';
    }

    public static function effective_lock_mode(): string
    {
        $setting = self::settings_lock_mode();
        if ($setting !== 'auto') {
            return $setting;
        }
        $cached = self::get_cached();
        if (!empty($cached['lock_mode'])) {
            return (string) $cached['lock_mode'];
        }
        return self::detect_lock_mode();
    }

    public static function is_nfs_backups_dir(): bool
    {
        $dir = Rmmigrate_Plugin::backups_dir();
        if (function_exists('posix_getpwuid') && function_exists('fileowner')) {
            $stat = @stat($dir);
            if (is_array($stat) && isset($stat['dev'])) {
                $parent = @stat(dirname($dir));
                if (is_array($parent) && isset($parent['dev']) && $stat['dev'] !== $parent['dev']) {
                    return false;
                }
            }
        }
        return (bool) apply_filters('rmmigrate_nfs_backups_dir', false, $dir);
    }

    public static function schedule_cron_worker(int $job_id, int $delay_seconds = 1): void
    {
        if ($job_id <= 0) {
            return;
        }

        $job = Rmmigrate_Job::get($job_id);
        if ($job !== null && in_array($job->get_status(), array(
            Rmmigrate_Job::STATUS_COMPLETE,
            Rmmigrate_Job::STATUS_ERROR,
            Rmmigrate_Job::STATUS_CANCELLED,
            Rmmigrate_Job::STATUS_DELETING,
        ), true)) {
            self::unschedule_cron_worker($job_id);
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK, array($job_id))) {
            $delay_seconds = max(1, min(300, $delay_seconds));
            wp_schedule_single_event(time() + $delay_seconds, self::CRON_HOOK, array($job_id));
            Rmmigrate_Logger::log_job_milestone(
                $job_id,
                'cron_scheduled',
                sprintf(
                    /* translators: %d: job ID */
                    __('Cron worker scheduled for job #%d.', 'rosenheinrich-multisite-migrate'),
                    $job_id
                ),
                ($job !== null && $job->is_restore()) ? 'restore' : 'backup',
                'info',
                array(
                    'kickoff_mode'  => 'cron',
                    'delay_seconds' => $delay_seconds,
                ),
                120,
                false
            );
        }
    }

    /**
     * Best-effort remove of worker cron events for a job.
     * Avoids infinite unschedule loops when the cron option write fails (could_not_set).
     */
    public static function unschedule_cron_worker(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }
        self::clear_hook_events(self::CRON_HOOK, array($job_id));
    }

    public static function run_cron_worker(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }

        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            self::unschedule_cron_worker($job_id);
            return;
        }

        if (in_array($job->get_status(), array(
            Rmmigrate_Job::STATUS_COMPLETE,
            Rmmigrate_Job::STATUS_ERROR,
            Rmmigrate_Job::STATUS_CANCELLED,
            Rmmigrate_Job::STATUS_DELETING,
        ), true)) {
            if ($job->get_status() === Rmmigrate_Job::STATUS_DELETING) {
                Rmmigrate_Job_Cleanup::purge_pending_deletes();
            }
            self::unschedule_cron_worker($job_id);
            return;
        }

        Rmmigrate_Logger::for_job($job_id);

        if ($job->get_status() === Rmmigrate_Job::STATUS_PENDING) {
            $message = $job->is_restore()
                ? __('Cron worker started restore job.', 'rosenheinrich-multisite-migrate')
                : __('Cron worker started backup job.', 'rosenheinrich-multisite-migrate');
            Rmmigrate_Logger::log_system($message, array(
                'triggered_by' => 'cron',
                'job_id'       => $job_id,
            ));
            Rmmigrate_Runner::note_worker_request($job_id, 'cron');
        }

        $result = Rmmigrate_Runner::process($job_id);

        if (empty($result['done'])) {
            // Waiters must not stampede every +1s — that floods rate limits and
            // masks stale recovery. Escalate via backoff transient (15→30→60).
            $delay = 1;
            if (!empty($result['waiting'])) {
                $backoff = (int) get_transient(Rmmigrate_Runner::WAIT_BACKOFF_PREFIX . $job_id);
                $delay   = $backoff > 0 ? $backoff : 30;
            }
            self::schedule_cron_worker($job_id, $delay);
            return;
        }

        self::unschedule_cron_worker($job_id);
    }

    /**
     * Clear matching single-event cron hooks with one write when possible.
     * Caps fallback retries so could_not_set cannot spin.
     *
     * @param array<int,mixed> $args
     */
    private static function clear_hook_events(string $hook, array $args): void
    {
        if (function_exists('_get_cron_array') && function_exists('_set_cron_array')) {
            $key = md5(serialize($args));
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $crons = _get_cron_array();
                if (!is_array($crons) || $crons === array()) {
                    return;
                }
                $changed = false;
                foreach ($crons as $timestamp => $hooks) {
                    if (!is_array($hooks) || !isset($hooks[$hook][$key])) {
                        continue;
                    }
                    unset($crons[$timestamp][$hook][$key]);
                    if (empty($crons[$timestamp][$hook])) {
                        unset($crons[$timestamp][$hook]);
                    }
                    if (empty($crons[$timestamp])) {
                        unset($crons[$timestamp]);
                    }
                    $changed = true;
                }
                if (!$changed) {
                    return;
                }
                $result = _set_cron_array($crons, true);
                if (!is_wp_error($result) && $result !== false) {
                    return;
                }
            }
            return;
        }

        if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
            return;
        }

        for ($i = 0; $i < 5; $i++) {
            $timestamp = wp_next_scheduled($hook, $args);
            if (!$timestamp) {
                return;
            }
            $result = wp_unschedule_event((int) $timestamp, $hook, $args, true);
            if ($result instanceof WP_Error || $result === false) {
                return;
            }
        }
    }

    /**
     * @param array<string,mixed> $loopback
     */
    public static function log_kickoff_failure(int $job_id, array $loopback, string $mode): void
    {
        Rmmigrate_Activity_Log::record(
            'system',
            /* translators: %s: worker kickoff mode */
            sprintf(__('Backup worker kickoff failed (mode: %1$s).', 'rosenheinrich-multisite-migrate'), $mode),
            'warning',
            array(
                'job_id'  => $job_id,
                'context' => array(
                    'triggered_by' => 'kickoff',
                    'kickoff_mode' => $mode,
                    'loopback'     => $loopback,
                ),
            )
        );
    }
}
