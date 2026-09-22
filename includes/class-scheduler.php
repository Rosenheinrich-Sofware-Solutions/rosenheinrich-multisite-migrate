<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Free local schedule tick (WP-Cron). Destination is always local.
 */
class Rmmigrate_Scheduler
{
    const HOOK = 'rmmigrate_tick';
    /** Fallback seconds between one-shot ticks (not a wp_get_schedules() key). */
    const TICK_INTERVAL = 300;
    const BLOCKED_WARN_OPTION = 'rmmigrate_schedule_blocked_since';
    const FAIL_COUNT_OPTION = 'rmmigrate_schedule_fail_count';
    const FAIL_NOTIFY_THRESHOLD = 3;
    const ADMIN_DUE_TRANSIENT = 'rmmigrate_admin_due_tick';
    const ADMIN_DUE_LOCK_OPTION = 'rmmigrate_admin_due_lock';
    const LAST_TICK_OPTION = 'rmmigrate_last_tick';
    const MISS_LOG_TRANSIENT_PREFIX = 'rmmigrate_sched_miss_';
    const SLOT_TRANSIENT_PREFIX = 'rmmigrate_sched_slot_';

    public static function register(): void
    {
        add_action(self::HOOK, array(__CLASS__, 'tick'));
        add_action('init', array(__CLASS__, 'ensure_tick_scheduled'), 20);
        add_action('admin_init', array(__CLASS__, 'maybe_run_due_on_admin'), 30);
    }

    public static function tick_interval(): int
    {
        return defined('MINUTE_IN_SECONDS') ? (5 * MINUTE_IN_SECONDS) : self::TICK_INTERVAL;
    }

    public static function ensure_tick_scheduled(): void
    {
        if (wp_installing()) {
            return;
        }

        if (class_exists('Rmmigrate_Bootstrap', false)) {
            Rmmigrate_Bootstrap::register_cron_schedules_filter();
        }

        self::arm_single_tick();
    }

    /**
     * One-shot tick. Recurring custom keys (rmmigrate_5min) make WP 6.1+ log
     * invalid_schedule whenever cron_schedules is missing at reschedule time.
     */
    private static function arm_single_tick(): void
    {
        $hook = self::HOOK;
        $args = array();
        $next = wp_next_scheduled($hook, $args);
        if ($next) {
            $event = wp_get_scheduled_event($hook, $args, $next);
            $recurrence = (is_object($event) && isset($event->schedule)) ? $event->schedule : false;
            if ($recurrence === false || $recurrence === null || $recurrence === '') {
                return;
            }
            wp_unschedule_event($next, $hook, $args);
        }

        $when = ($next && (int) $next > 0) ? (int) $next : (time() + self::tick_interval());
        wp_schedule_single_event($when, $hook, $args);
    }

    public static function tick(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + self::tick_interval(), self::HOOK);
        }

        self::tick_body();
    }

    private static function tick_body(): void
    {
        update_site_option(self::LAST_TICK_OPTION, time());

        Rmmigrate_Job::recover_stale_active();

        if (Rmmigrate_Schedules::heal_mismatched_next_runs()) {
            Rmmigrate_Settings::clear_cache();
        }

        $settings = Rmmigrate_Schedules::normalize(Rmmigrate_Settings::get());
        if (!Rmmigrate_Schedules::has_enabled($settings)) {
            return;
        }

        $due = Rmmigrate_Schedules::due_schedules($settings);
        if ($due === array()) {
            return;
        }

        $active = Rmmigrate_Job::get_active();
        $earliest = (int) ($due[0]['next_run'] ?? 0);

        if ($earliest > 0 && $earliest <= time() && $active !== null) {
            $blocked_since = (int) get_site_option(self::BLOCKED_WARN_OPTION, 0);
            if ($blocked_since === 0) {
                update_site_option(self::BLOCKED_WARN_OPTION, time());
            } elseif (time() - $blocked_since > 2 * HOUR_IN_SECONDS) {
                Rmmigrate_Logger::log_system(
                    'Scheduled backup overdue: active job blocking scheduler for >2 hours.',
                    array('triggered_by' => 'cron'),
                    'warning'
                );
            }
            return;
        }

        delete_site_option(self::BLOCKED_WARN_OPTION);

        if ($active !== null) {
            return;
        }

        $schedule = $due[0];
        $schedule_id = (string) ($schedule['id'] ?? '');
        $blog_id = isset($schedule['blog_id']) ? (int) $schedule['blog_id'] : null;
        $next_run = (int) ($schedule['next_run'] ?? 0);

        if (!self::claim_schedule_slot($schedule_id, $next_run, $blog_id)) {
            self::advance_next_run($schedule_id, $blog_id);
            return;
        }

        $raw_args = Rmmigrate_Schedules::job_args($schedule, $settings);
        $schedule_id_arg = (string) ($raw_args['schedule_id'] ?? '');
        $schedule_name_arg = (string) ($raw_args['schedule_name'] ?? '');
        unset($raw_args['schedule_id'], $raw_args['schedule_name']);

        $resolved = Rmmigrate_Multisite_Scope::resolve_backup_scope(
            (string) $raw_args['scope'],
            (array) $raw_args['excluded_blogs'],
            (array) $raw_args['included_blogs'],
            true
        );
        if (is_wp_error($resolved)) {
            self::record_schedule_failure('Scope invalid: ' . $resolved->get_error_message());
            self::advance_next_run($schedule_id, $blog_id);
            return;
        }

        $raw_args['scope'] = $resolved['scope'];
        $raw_args['excluded_blogs'] = $resolved['excluded_blogs'];

        self::advance_next_run($schedule_id, $blog_id);

        try {
            $result = Rmmigrate_Backup_Service::start_backup($raw_args);
            $job_id = (int) ($result['job_id'] ?? 0);
            if ($job_id > 0) {
                $job = Rmmigrate_Job::get($job_id);
                if ($job !== null) {
                    $job->update_progress(
                        array(
                            'scheduled'     => true,
                            'schedule_id'   => $schedule_id_arg,
                            'schedule_name' => $schedule_name_arg,
                        )
                    );
                }
            }
            Rmmigrate_Logger::log_job(
                $job_id,
                sprintf(
                    'Scheduled backup #%1$d started (schedule %2$s).',
                    $job_id,
                    $schedule_id
                )
            );
            delete_site_option(self::FAIL_COUNT_OPTION);
        } catch (Throwable $e) {
            self::record_schedule_failure(sanitize_text_field($e->getMessage()));
        }
    }

    public static function record_schedule_failure(string $message): void
    {
        $clean_msg = sanitize_text_field($message);
        Rmmigrate_Logger::log_system(
            'Scheduled backup failure: ' . $clean_msg,
            array('triggered_by' => 'cron'),
            'error'
        );
        if (class_exists('Rmmigrate_Telemetry', false)) {
            Rmmigrate_Telemetry::record_operation_error(
                'schedule',
                $clean_msg,
                0,
                array(
                    'phase'        => 'cron_tick',
                    'service_code' => class_exists('Rmmigrate_Error_Codes', false)
                        ? Rmmigrate_Error_Codes::from_message($clean_msg)
                        : 'schedule_failed',
                )
            );
        }
        $count = (int) get_site_option(self::FAIL_COUNT_OPTION, 0) + 1;
        update_site_option(self::FAIL_COUNT_OPTION, $count);
        if ($count >= self::FAIL_NOTIFY_THRESHOLD) {
            Rmmigrate_Notifications::notify_schedule_failures($count, $clean_msg);
            update_site_option(self::FAIL_COUNT_OPTION, 0);
        }
    }

    public static function reset_schedule_failures(): void
    {
        delete_site_option(self::FAIL_COUNT_OPTION);
    }

    public static function advance_next_run(string $schedule_id = '', ?int $blog_id = null): void
    {
        $settings = Rmmigrate_Schedules::normalize(Rmmigrate_Settings::get());
        if ($schedule_id === '' && $blog_id === null) {
            foreach ($settings['schedules'] as $schedule) {
                if (!empty($schedule['enabled'])) {
                    $schedule_id = (string) ($schedule['id'] ?? '');
                    $blog_id = (int) ($schedule['blog_id'] ?? 0);
                    break;
                }
            }
            if ($schedule_id === '' && !empty($settings['schedules'][0]['id'])) {
                $schedule_id = (string) $settings['schedules'][0]['id'];
                $blog_id = (int) ($settings['schedules'][0]['blog_id'] ?? 0);
            }
        }

        if ($schedule_id === '' && $blog_id === null) {
            return;
        }

        Rmmigrate_Schedules::advance_schedule($schedule_id, $blog_id);
    }

    public static function grace_seconds_for_interval(string $interval): int
    {
        switch ($interval) {
            case 'every_15':
                return 5 * MINUTE_IN_SECONDS;
            case 'hourly':
                return 10 * MINUTE_IN_SECONDS;
            case 'daily':
            case 'weekly':
            case 'monthly':
            default:
                return 15 * MINUTE_IN_SECONDS;
        }
    }

    public static function maybe_run_due_on_admin(): void
    {
        if (self::is_background_admin_request()) {
            return;
        }

        if (!current_user_can('manage_network') && !current_user_can('manage_options')) {
            return;
        }

        $last = (int) get_site_transient(self::ADMIN_DUE_TRANSIENT);
        if ($last > 0 && (time() - $last) < MINUTE_IN_SECONDS) {
            return;
        }

        if (!self::acquire_admin_due_lock()) {
            return;
        }

        try {
            $settings = Rmmigrate_Schedules::normalize(Rmmigrate_Settings::get());
            if (!Rmmigrate_Schedules::has_enabled($settings)) {
                return;
            }
            if (Rmmigrate_Schedules::due_schedules($settings) === array()) {
                return;
            }

            set_site_transient(self::ADMIN_DUE_TRANSIENT, time(), 2 * MINUTE_IN_SECONDS);
            self::tick();
        } finally {
            self::release_admin_due_lock();
        }
    }

    /**
     * Activity-log polling and Heartbeat hit admin_init every ~1.5s. Those must
     * not drive the scheduler (one skip log per poll while next_run is stuck).
     */
    private static function is_background_admin_request(): bool
    {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        return false;
    }

    /**
     * @deprecated Scheduled backups are not skipped on delayed cron.
     */
    private static function log_missed_slot_once(string $schedule_id, int $next_run, int $grace): void
    {
    }

    /**
     * First tick to win this wall-clock slot starts the backup. Later ticks
     * only advance next_run (no second 370MB archive in the grace window).
     */
    private static function claim_schedule_slot(string $schedule_id, int $next_run, ?int $blog_id = null): bool
    {
        $slot_id = ($blog_id !== null && $blog_id > 0) ? ($schedule_id . '_b' . $blog_id) : $schedule_id;
        $key = self::SLOT_TRANSIENT_PREFIX . md5($slot_id . '|' . $next_run);
        if (get_site_transient($key)) {
            return false;
        }
        set_site_transient($key, 1, defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        return true;
    }

    private static function acquire_admin_due_lock(): bool
    {
        $now = microtime(true);
        if (add_site_option(self::ADMIN_DUE_LOCK_OPTION, $now)) {
            return true;
        }
        $started = (float) get_site_option(self::ADMIN_DUE_LOCK_OPTION, 0);
        if ($started <= 0 || ($now - $started) >= MINUTE_IN_SECONDS) {
            delete_site_option(self::ADMIN_DUE_LOCK_OPTION);
            return add_site_option(self::ADMIN_DUE_LOCK_OPTION, $now);
        }
        return false;
    }

    private static function release_admin_due_lock(): void
    {
        delete_site_option(self::ADMIN_DUE_LOCK_OPTION);
    }
}
