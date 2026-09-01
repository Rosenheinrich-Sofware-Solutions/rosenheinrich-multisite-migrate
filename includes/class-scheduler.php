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

        update_site_option(self::LAST_TICK_OPTION, time());

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
        $next_run = (int) ($schedule['next_run'] ?? 0);
        $grace = self::grace_seconds_for_interval((string) ($schedule['interval'] ?? 'weekly'));
        if ($next_run > 0 && $next_run < (time() - $grace)) {
            Rmmigrate_Logger::log_system(
                sprintf(
                    /* translators: 1: schedule ID, 2: Unix timestamp of missed next_run */
                    __('Scheduled backup skipped: missed slot for schedule %1$s (next_run %2$d), advanced to next occurrence.', 'rosenheinrich-multisite-migrate'),
                    $schedule_id,
                    $next_run
                ),
                array(
                    'triggered_by' => 'cron',
                    'schedule_id'  => $schedule_id,
                    'next_run'     => $next_run,
                    'grace'        => $grace,
                ),
                'info'
            );
            self::advance_next_run($schedule_id);
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
            self::advance_next_run($schedule_id);
            return;
        }

        $raw_args['scope'] = $resolved['scope'];
        $raw_args['excluded_blogs'] = $resolved['excluded_blogs'];

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
            Rmmigrate_Logger::log_system(
                sprintf(
                    /* translators: 1: job ID, 2: schedule ID */
                    __('Scheduled backup #%1$d started (schedule %2$s).', 'rosenheinrich-multisite-migrate'),
                    $job_id,
                    $schedule_id
                ),
                array(
                    'triggered_by' => 'cron',
                    'job_id'       => $job_id,
                    'schedule_id'  => $schedule_id,
                ),
                'info'
            );
            delete_site_option(self::FAIL_COUNT_OPTION);
            self::advance_next_run($schedule_id);
        } catch (Throwable $e) {
            self::record_schedule_failure(sanitize_text_field($e->getMessage()));
            self::advance_next_run($schedule_id);
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

    public static function advance_next_run(string $schedule_id = ''): void
    {
        $settings = Rmmigrate_Schedules::normalize(Rmmigrate_Settings::get());
        if ($schedule_id === '') {
            foreach ($settings['schedules'] as $schedule) {
                if (!empty($schedule['enabled'])) {
                    $schedule_id = (string) ($schedule['id'] ?? '');
                    break;
                }
            }
            if ($schedule_id === '' && !empty($settings['schedules'][0]['id'])) {
                $schedule_id = (string) $settings['schedules'][0]['id'];
            }
        }

        if ($schedule_id === '') {
            return;
        }

        Rmmigrate_Schedules::advance_schedule($schedule_id);
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
        if (!current_user_can('manage_network') && !current_user_can('manage_options')) {
            return;
        }

        $last = (int) get_transient(self::ADMIN_DUE_TRANSIENT);
        if ($last > 0 && (time() - $last) < MINUTE_IN_SECONDS) {
            return;
        }

        if (!self::acquire_admin_due_lock()) {
            return;
        }

        try {
            set_transient(self::ADMIN_DUE_TRANSIENT, time(), 2 * MINUTE_IN_SECONDS);
            $settings = Rmmigrate_Schedules::normalize(Rmmigrate_Settings::get());
            if (!Rmmigrate_Schedules::has_enabled($settings)) {
                return;
            }
            if (Rmmigrate_Schedules::due_schedules($settings) === array()) {
                return;
            }

            self::tick();
        } finally {
            self::release_admin_due_lock();
        }
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
