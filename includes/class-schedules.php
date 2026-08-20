<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Free edition: one local full-backup schedule per admin context
 * (network + one per subsite blog_id).
 */
class Rmmigrate_Schedules
{
    /** Max schedules per context (network row or a single blog_id). */
    const MAX_SCHEDULES = 1;

    /**
     * @return array<string,mixed>
     */
    public static function blank(array $settings = array()): array
    {
        $scope = $settings['default_scope'] ?? Rmmigrate_Multisite_Scope::SCOPE_NETWORK;
        $blog_id = 0;
        if (!is_multisite()) {
            $scope = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
            $blog_id = (int) get_current_blog_id();
        } elseif (Rmmigrate_Access::is_subsite_admin_context()) {
            $scope = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
            $blog_id = (int) get_current_blog_id();
        }

        return array(
            'id'             => self::new_id(),
            'name'           => __('Local schedule', 'rosenheinrich-multisite-migrate'),
            'enabled'        => false,
            'interval'       => 'weekly',
            'hour'           => 3,
            'minute'         => 0,
            'weekday'        => 1,
            'day_of_month'   => 1,
            'profile'        => 'full',
            'backup_type'    => 'full',
            'destination'    => 'local',
            'scope'          => $scope,
            'blog_id'        => $blog_id,
            'excluded_blogs' => array(),
            'included_blogs' => array(),
            'next_run'       => 0,
        );
    }

    public static function new_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return 'sch_' . substr(wp_generate_uuid4(), 0, 8);
        }

        return 'sch_' . substr(md5(uniqid((string) wp_rand(), true)), 0, 8);
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function normalize(array $settings): array
    {
        if (!isset($settings['schedules']) || !is_array($settings['schedules'])) {
            $settings['schedules'] = array();
        }

        $normalized = array();
        foreach ($settings['schedules'] as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            $item = self::sanitize_schedule($schedule, $settings);
            if ($item['id'] !== '') {
                $normalized[] = $item;
            }
        }

        $settings['schedules'] = self::enforce_one_per_context($normalized, $settings);

        return $settings;
    }

    /**
     * Keep at most one network schedule and one schedule per blog_id.
     *
     * @param array<int,array<string,mixed>> $schedules
     * @param array<string,mixed>            $settings
     * @return array<int,array<string,mixed>>
     */
    public static function enforce_one_per_context(array $schedules, array $settings = array()): array
    {
        $network = null;
        $by_blog = array();
        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            $blog_id = (int) ($schedule['blog_id'] ?? 0);
            $scope = (string) ($schedule['scope'] ?? Rmmigrate_Multisite_Scope::SCOPE_NETWORK);
            $is_network = $blog_id <= 0
                || $scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK
                || $scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED
                || $scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED;
            if ($is_network) {
                if ($network === null) {
                    $schedule['blog_id'] = 0;
                    $network = $schedule;
                }
                continue;
            }
            if (!isset($by_blog[$blog_id])) {
                $by_blog[$blog_id] = $schedule;
            }
        }

        $out = array();
        if ($network !== null) {
            $out[] = $network;
        }
        foreach ($by_blog as $row) {
            $out[] = $row;
        }
        if ($out === array()) {
            $out[] = self::blank($settings);
        }

        return $out;
    }

    /**
     * Network-scoped schedule row for UI (or blank).
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function network_schedule(array $settings): array
    {
        $settings = self::normalize($settings);
        foreach ($settings['schedules'] as $schedule) {
            $blog_id = (int) ($schedule['blog_id'] ?? 0);
            $scope = (string) ($schedule['scope'] ?? '');
            if ($blog_id <= 0 || $scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK
                || $scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED
                || $scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED) {
                return $schedule;
            }
        }

        $blank = self::blank($settings);
        $blank['scope'] = Rmmigrate_Multisite_Scope::SCOPE_NETWORK;
        $blank['blog_id'] = 0;

        return $blank;
    }

    /**
     * @param array<string,mixed> $settings
     */
    public static function has_enabled(array $settings): bool
    {
        foreach ($settings['schedules'] ?? array() as $schedule) {
            if (!empty($schedule['enabled'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<int,array<string,mixed>>
     */
    public static function due_schedules(array $settings): array
    {
        $now = time();
        $due = array();
        foreach ($settings['schedules'] ?? array() as $schedule) {
            if (empty($schedule['enabled'])) {
                continue;
            }
            $next = (int) ($schedule['next_run'] ?? 0);
            if ($next > 0 && $next <= $now) {
                $due[] = $schedule;
            }
        }

        usort(
            $due,
            static function (array $a, array $b): int {
                return (int) ($a['next_run'] ?? 0) <=> (int) ($b['next_run'] ?? 0);
            }
        );

        return $due;
    }

    /**
     * @param array<string,mixed> $settings
     */
    public static function earliest_next_run(array $settings): int
    {
        $earliest = 0;
        foreach ($settings['schedules'] ?? array() as $schedule) {
            if (empty($schedule['enabled'])) {
                continue;
            }
            $next = (int) ($schedule['next_run'] ?? 0);
            if ($next <= 0) {
                continue;
            }
            if ($earliest === 0 || $next < $earliest) {
                $earliest = $next;
            }
        }

        return $earliest;
    }

    /**
     * @param array<string,mixed> $schedule
     */
    public static function compute_next_run(array $schedule, ?int $from = null): int
    {
        $hour = max(0, min(23, (int) ($schedule['hour'] ?? 3)));
        $minute = max(0, min(59, (int) ($schedule['minute'] ?? 0)));
        $interval = $schedule['interval'] ?? 'weekly';
        $now = $from ?? time();
        $tz = wp_timezone();
        $dt = new DateTime('@' . $now);
        $dt->setTimezone($tz);

        if ($interval === 'daily') {
            $dt->setTime($hour, $minute, 0);
            if ($dt->getTimestamp() <= $now) {
                $dt->modify('+1 day');
            }
            return $dt->getTimestamp();
        }

        if ($interval === 'monthly') {
            $dom = max(1, min(28, (int) ($schedule['day_of_month'] ?? 1)));
            $dt->setDate((int) $dt->format('Y'), (int) $dt->format('m'), $dom);
            $dt->setTime($hour, $minute, 0);
            if ($dt->getTimestamp() <= $now) {
                $dt->modify('+1 month');
            }
            return $dt->getTimestamp();
        }

        $weekday = (int) ($schedule['weekday'] ?? 1);
        $current_wday = (int) $dt->format('w');
        $target_wday = max(0, min(6, $weekday));
        $days_ahead = $target_wday - $current_wday;
        if ($days_ahead < 0) {
            $days_ahead += 7;
        }
        if ($days_ahead > 0) {
            $dt->modify('+' . $days_ahead . ' days');
        }
        $dt->setTime($hour, $minute, 0);
        if ($dt->getTimestamp() <= $now) {
            $dt->modify('+7 days');
        }

        return $dt->getTimestamp();
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function merge_from_post(array $settings, array $post): array
    {
        $settings = self::normalize($settings);
        $previous_by_id = array();
        foreach ($settings['schedules'] as $row) {
            if (!empty($row['id'])) {
                $previous_by_id[(string) $row['id']] = $row;
            }
        }

        $kept = array();
        foreach ($settings['schedules'] as $schedule) {
            $blog_id = (int) ($schedule['blog_id'] ?? 0);
            $scope = (string) ($schedule['scope'] ?? '');
            $is_network = $blog_id <= 0
                || $scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK
                || $scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED
                || $scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED;
            if (!$is_network) {
                $kept[] = $schedule;
            }
        }

        $raw = $post['schedules'] ?? array();
        if (!is_array($raw)) {
            $raw = array();
        }

        $network_row = null;
        foreach ($raw as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = sanitize_key((string) $id);
            if ($id === '') {
                continue;
            }
            $network_row = self::sanitize_schedule(array_merge($row, array('id' => $id)), $settings);
            $network_row['blog_id'] = 0;
            if (($network_row['scope'] ?? '') === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
                $network_row['scope'] = Rmmigrate_Multisite_Scope::SCOPE_NETWORK;
            }
            break;
        }

        if ($network_row === null) {
            $network_row = self::network_schedule($settings);
        }

        $settings['schedules'] = self::enforce_one_per_context(array_merge(array($network_row), $kept), $settings);
        $settings['retention_network'] = max(0, (int) ($post['retention_network'] ?? $settings['retention_network'] ?? 0));
        $settings['retention_subsite'] = max(0, (int) ($post['retention_subsite'] ?? $settings['retention_subsite'] ?? 0));

        foreach ($settings['schedules'] as $index => $schedule) {
            if (empty($schedule['enabled'])) {
                $settings['schedules'][$index]['next_run'] = 0;
                continue;
            }
            $prev = $previous_by_id[$schedule['id'] ?? ''] ?? null;
            $settings['schedules'][$index]['next_run'] = self::next_run_after_save($schedule, $prev);
        }

        return $settings;
    }

    /**
     * Subsite admin: one schedule for the current blog only; keep other contexts.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function merge_subsite_from_post(array $current, array $post, int $blog_id): array
    {
        $blog_id = max(1, $blog_id);
        $current = self::normalize($current);
        $previous_by_id = array();
        foreach ($current['schedules'] as $row) {
            if (!empty($row['id'])) {
                $previous_by_id[(string) $row['id']] = $row;
            }
        }

        $kept = array();
        foreach ($current['schedules'] as $schedule) {
            if ((int) ($schedule['blog_id'] ?? 0) !== $blog_id) {
                $kept[] = $schedule;
            }
        }

        $raw = $post['schedules'] ?? array();
        if (!is_array($raw)) {
            $raw = array();
        }

        $subsite_row = null;
        foreach ($raw as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = sanitize_key((string) $id);
            if ($id === '') {
                continue;
            }
            $row['scope'] = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
            $row['blog_id'] = $blog_id;
            $subsite_row = self::sanitize_schedule(array_merge($row, array('id' => $id)), $current);
            break;
        }

        if ($subsite_row === null) {
            $subsite_row = self::blank($current);
            $subsite_row['scope'] = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
            $subsite_row['blog_id'] = $blog_id;
        }

        $current['schedules'] = self::enforce_one_per_context(array_merge($kept, array($subsite_row)), $current);

        foreach ($current['schedules'] as $index => $schedule) {
            if (empty($schedule['enabled'])) {
                $current['schedules'][$index]['next_run'] = 0;
                continue;
            }
            $prev = $previous_by_id[$schedule['id'] ?? ''] ?? null;
            $current['schedules'][$index]['next_run'] = self::next_run_after_save($schedule, $prev);
        }

        return $current;
    }

    /**
     * @param array<string,mixed> $settings
     * @return true|WP_Error
     */
    public static function validate_enabled_schedules(array $settings)
    {
        $settings = self::normalize($settings);
        foreach ($settings['schedules'] as $schedule) {
            if (empty($schedule['enabled'])) {
                continue;
            }
            $blog_lists = self::resolve_schedule_blog_lists($schedule, $settings);
            $resolved = Rmmigrate_Multisite_Scope::resolve_backup_scope(
                (string) ($schedule['scope'] ?? Rmmigrate_Multisite_Scope::SCOPE_NETWORK),
                $blog_lists['excluded_blogs'],
                $blog_lists['included_blogs'],
                true
            );
            if (is_wp_error($resolved)) {
                $name = (string) ($schedule['name'] ?? '');
                if ($name !== '') {
                    return new WP_Error(
                        'mm_schedule_scope',
                        sprintf(
                            /* translators: 1: schedule name, 2: error message */
                            __('Schedule "%1$s": %2$s', 'rosenheinrich-multisite-migrate'),
                            $name,
                            $resolved->get_error_message()
                        )
                    );
                }

                return $resolved;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $settings
     * @param string              $schedule_id
     */
    public static function advance_schedule(array $settings, string $schedule_id): void
    {
        $settings = self::normalize($settings);
        foreach ($settings['schedules'] as $index => $schedule) {
            if (($schedule['id'] ?? '') !== $schedule_id) {
                continue;
            }
            if (empty($schedule['enabled'])) {
                $settings['schedules'][$index]['next_run'] = 0;
                break;
            }
            $settings['schedules'][$index]['next_run'] = self::compute_next_run($schedule);
            break;
        }
        Rmmigrate_Settings::save($settings);
    }

    /**
     * @param array<string,mixed> $schedule
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function job_args(array $schedule, array $settings): array
    {
        $scope = $schedule['scope'] ?? ($settings['default_scope'] ?? Rmmigrate_Multisite_Scope::SCOPE_NETWORK);
        $blog_lists = self::resolve_schedule_blog_lists($schedule, $settings);

        $blog_id = max(0, (int) ($schedule['blog_id'] ?? 0));
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE && $blog_id <= 0) {
            $blog_id = max(0, (int) get_current_blog_id());
        }

        return array(
            'scope'            => $scope,
            'blog_id'          => $blog_id,
            'excluded_blogs'   => $blog_lists['excluded_blogs'],
            'included_blogs'   => $blog_lists['included_blogs'],
            'triggered_by'     => 'cron',
            'destination'      => 'local',
            'backup_profile'   => $schedule['profile'] ?? 'full',
            'backup_type'      => 'full',
            'kick_worker'      => true,
            'schedule_id'      => $schedule['id'] ?? '',
            'schedule_name'    => $schedule['name'] ?? '',
        );
    }

    /**
     * @param array<string,mixed> $schedule
     * @param array<string,mixed> $settings
     * @return array{excluded_blogs:int[],included_blogs:int[]}
     */
    public static function resolve_schedule_blog_lists(array $schedule, array $settings): array
    {
        $scope = $schedule['scope'] ?? ($settings['default_scope'] ?? Rmmigrate_Multisite_Scope::SCOPE_NETWORK);
        $excluded = array_values(array_unique(array_map('intval', (array) ($schedule['excluded_blogs'] ?? array()))));
        $included = array_values(array_unique(array_map('intval', (array) ($schedule['included_blogs'] ?? array()))));

        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED && $excluded === array()) {
            $excluded = array_values(array_unique(array_map('intval', (array) ($settings['default_excluded_blogs'] ?? array()))));
        }
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED && $included === array()) {
            $included = array_values(array_unique(array_map('intval', (array) ($settings['default_included_blogs'] ?? array()))));
        }

        return array(
            'excluded_blogs' => $excluded,
            'included_blogs' => $included,
        );
    }

    /**
     * @param array<string,mixed> $schedule
     */
    public static function interval_label(array $schedule): string
    {
        $hour = max(0, min(23, (int) ($schedule['hour'] ?? 3)));
        $minute = max(0, min(59, (int) ($schedule['minute'] ?? 0)));
        $time = sprintf('%02d:%02d', $hour, $minute);
        $interval = $schedule['interval'] ?? 'weekly';

        if ($interval === 'daily') {
            /* translators: %s: time */
            return sprintf(__('Daily at %s', 'rosenheinrich-multisite-migrate'), $time);
        }
        if ($interval === 'monthly') {
            /* translators: 1: day of month, 2: time */
            return sprintf(
                __('Monthly on day %1$d at %2$s', 'rosenheinrich-multisite-migrate'),
                max(1, min(28, (int) ($schedule['day_of_month'] ?? 1))),
                $time
            );
        }

        $days = array(
            __('Sunday', 'rosenheinrich-multisite-migrate'),
            __('Monday', 'rosenheinrich-multisite-migrate'),
            __('Tuesday', 'rosenheinrich-multisite-migrate'),
            __('Wednesday', 'rosenheinrich-multisite-migrate'),
            __('Thursday', 'rosenheinrich-multisite-migrate'),
            __('Friday', 'rosenheinrich-multisite-migrate'),
            __('Saturday', 'rosenheinrich-multisite-migrate'),
        );
        $weekday = $days[max(0, min(6, (int) ($schedule['weekday'] ?? 1)))] ?? $days[1];
        /* translators: 1: weekday name, 2: time */
        return sprintf(__('Weekly on %1$s at %2$s', 'rosenheinrich-multisite-migrate'), $weekday, $time);
    }

    /**
     * @param array<string,mixed> $schedule
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private static function sanitize_schedule(array $schedule, array $settings): array
    {
        $interval = sanitize_key($schedule['interval'] ?? 'weekly');
        if (!in_array($interval, array('daily', 'weekly', 'monthly'), true)) {
            $interval = 'weekly';
        }

        $profile = sanitize_key($schedule['profile'] ?? 'full');
        if (!in_array($profile, array('full', 'db', 'files'), true)) {
            $profile = 'full';
        }

        $blog_id = max(0, (int) ($schedule['blog_id'] ?? 0));
        $scope = sanitize_key($schedule['scope'] ?? ($settings['default_scope'] ?? Rmmigrate_Multisite_Scope::SCOPE_NETWORK));
        if (is_multisite()) {
            if ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
                if ($blog_id <= 0 || !get_blog_details($blog_id)) {
                    $scope = Rmmigrate_Multisite_Scope::SCOPE_NETWORK;
                    $blog_id = 0;
                }
            } elseif (!in_array($scope, array('network', 'network_filtered', 'network_included'), true)) {
                $scope = Rmmigrate_Multisite_Scope::SCOPE_NETWORK;
                $blog_id = 0;
            } else {
                $blog_id = 0;
            }
        } else {
            $scope = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
            $blog_id = (int) get_current_blog_id();
        }

        $name = sanitize_text_field($schedule['name'] ?? '');
        if ($name === '') {
            $name = __('Local schedule', 'rosenheinrich-multisite-migrate');
        }

        $hour_12 = isset($schedule['hour_12']) ? (int) $schedule['hour_12'] : null;
        $ampm = isset($schedule['ampm']) ? strtolower((string) $schedule['ampm']) : null;
        if ($hour_12 !== null && $ampm !== null) {
            $hour = $hour_12 % 12;
            if ($ampm === 'pm') {
                $hour += 12;
            }
        } else {
            $hour = (int) ($schedule['hour'] ?? 3);
        }

        $minute = max(0, min(59, (int) ($schedule['minute'] ?? 0)));

        return array(
            'id'             => sanitize_key($schedule['id'] ?? self::new_id()),
            'name'           => $name,
            'enabled'        => !empty($schedule['enabled']),
            'interval'       => $interval,
            'hour'           => max(0, min(23, $hour)),
            'minute'         => $minute,
            'weekday'        => max(0, min(6, (int) ($schedule['weekday'] ?? 1))),
            'day_of_month'   => max(1, min(28, (int) ($schedule['day_of_month'] ?? 1))),
            'profile'        => $profile,
            'backup_type'    => 'full',
            'destination'    => 'local',
            'scope'          => $scope,
            'blog_id'        => $blog_id,
            'excluded_blogs' => array_map('intval', (array) ($schedule['excluded_blogs'] ?? array())),
            'included_blogs' => array_map('intval', (array) ($schedule['included_blogs'] ?? array())),
            'next_run'       => max(0, (int) ($schedule['next_run'] ?? 0)),
        );
    }

    /**
     * @param array<string,mixed>      $schedule
     * @param array<string,mixed>|null $previous
     */
    private static function next_run_after_save(array $schedule, $previous): int
    {
        $prev_next = is_array($previous) ? (int) ($previous['next_run'] ?? 0) : 0;
        if (
            is_array($previous)
            && self::schedule_timing_fields_unchanged($previous, $schedule)
            && $prev_next > time()
            && self::next_run_matches_schedule($schedule, $prev_next)
        ) {
            return $prev_next;
        }

        return self::compute_next_run($schedule);
    }

    /**
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $current
     */
    private static function schedule_timing_fields_unchanged(array $previous, array $current): bool
    {
        return ($previous['interval'] ?? '') === ($current['interval'] ?? '')
            && (int) ($previous['hour'] ?? 0) === (int) ($current['hour'] ?? 0)
            && (int) ($previous['minute'] ?? 0) === (int) ($current['minute'] ?? 0)
            && (int) ($previous['weekday'] ?? 0) === (int) ($current['weekday'] ?? 0)
            && (int) ($previous['day_of_month'] ?? 0) === (int) ($current['day_of_month'] ?? 0);
    }

    /**
     * @param array<string,mixed> $schedule
     */
    public static function next_run_matches_schedule(array $schedule, int $next_run): bool
    {
        if ($next_run <= 0) {
            return false;
        }

        $interval = (string) ($schedule['interval'] ?? 'weekly');
        $hour = max(0, min(23, (int) ($schedule['hour'] ?? 3)));
        $minute = max(0, min(59, (int) ($schedule['minute'] ?? 0)));

        $dt = new DateTimeImmutable('@' . $next_run);
        $dt = $dt->setTimezone(wp_timezone());

        if ((int) $dt->format('i') !== $minute) {
            return false;
        }
        if ((int) $dt->format('G') !== $hour) {
            return false;
        }
        if ($interval === 'weekly') {
            return (int) $dt->format('w') === max(0, min(6, (int) ($schedule['weekday'] ?? 1)));
        }
        if ($interval === 'monthly') {
            return (int) $dt->format('j') === max(1, min(28, (int) ($schedule['day_of_month'] ?? 1)));
        }

        return true;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<int,array<string,mixed>>
     */
    public static function for_blog(array $settings, int $blog_id): array
    {
        $blog_id = max(0, $blog_id);
        $settings = self::normalize($settings);
        $rows = array();
        foreach ($settings['schedules'] as $schedule) {
            if ((int) ($schedule['blog_id'] ?? 0) === $blog_id) {
                $rows[] = $schedule;
            }
        }

        if ($rows === array() && $blog_id > 0) {
            $blank = self::blank($settings);
            $blank['scope'] = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
            $blank['blog_id'] = $blog_id;
            $rows[] = $blank;
        }

        return $rows;
    }

    /**
     * Cap picker size for network scope UI.
     *
     * @return array<int,array{id:int,label:string}>
     */
    public static function sites_for_picker(): array
    {
        if (!is_multisite()) {
            return array();
        }

        $rows = array();
        foreach (get_sites(array('number' => 500, 'fields' => 'ids', 'orderby' => 'domain')) as $blog_id) {
            $blog_id = (int) $blog_id;
            $details = get_blog_details($blog_id);
            $label = get_blog_option($blog_id, 'blogname');
            if (!is_string($label) || $label === '') {
                $label = is_object($details) ? (string) $details->domain : (string) $blog_id;
            }
            $rows[] = array(
                'id'    => $blog_id,
                'label' => $label,
            );
        }

        return $rows;
    }
}
