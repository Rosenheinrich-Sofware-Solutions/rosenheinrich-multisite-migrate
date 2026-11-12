<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned job table retention queries; wp_cache used where applicable.

/**
 * Backup retention.
 *
 * Completed backups follow the configured keep counts (0 = keep all). Failed and cancelled
 * entries are not usable backups: they expire after FAILED_MAX_AGE_DAYS, and at most
 * FAILED_KEEP_MAX of them are kept, whatever the keep counts say. Nothing here deletes a
 * completed backup outside the configured rule.
 */
class Rmmigrate_Retention
{
    public const DEFERRED_PRUNE_HOOK = 'rmmigrate_deferred_retention_prune';

    /** Failed or cancelled backup entries older than this many days are removed. */
    public const FAILED_MAX_AGE_DAYS = 14;

    /** At most this many failed or cancelled backup entries are kept. */
    public const FAILED_KEEP_MAX = 10;

    /** Throttles the failed-entry sweep that runs from the scheduler tick. */
    private const FAILED_SWEEP_TRANSIENT = 'rmmigrate_failed_sweep';

    /** Per-user flag: show the retention review notice after the next settings save. */
    public const REVIEW_TRANSIENT_PREFIX = 'rmmigrate_retention_review_';

    public static function register(): void
    {
        add_action(self::DEFERRED_PRUNE_HOOK, array(__CLASS__, 'prune'));
    }

    /**
     * Schedule prune off the completing worker ajax so large deletes cannot extend a 504.
     */
    public static function schedule_deferred_prune(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        if (!wp_next_scheduled(self::DEFERRED_PRUNE_HOOK)) {
            wp_schedule_single_event(time() + 1, self::DEFERRED_PRUNE_HOOK);
        }
    }

    public static function prune(): void
    {
        $plan = self::plan();
        $removed = self::delete_rows(array_merge($plan['network'], $plan['safety'], $plan['subsite']));
        self::log_removed($removed);
        self::expire_failed();
    }

    /**
     * What prune() would delete right now, without deleting anything.
     *
     * @param array<string,mixed>|null $settings Settings to evaluate (defaults to the saved ones).
     * @return array{network:array<int,array<string,mixed>>,safety:array<int,array<string,mixed>>,subsite:array<int,array<string,mixed>>,failed:array<int,array<string,mixed>>}
     */
    public static function plan(?array $settings = null): array
    {
        self::flush_cache();

        $settings = $settings ?? Rmmigrate_Settings::get();
        $network_keep = max(0, (int) ($settings['retention_network'] ?? 0));
        $subsite_keep = max(0, (int) ($settings['retention_subsite'] ?? 0));

        $plan = array(
            'network' => array(),
            'safety'  => self::excess_rows(Rmmigrate_Snap_DB::jobs_retention_safety_rows(), 1),
            'subsite' => array(),
            'failed'  => self::failed_rows_to_expire(),
        );

        if ($network_keep > 0) {
            $plan['network'] = self::excess_rows(Rmmigrate_Snap_DB::jobs_retention_network_rows(), $network_keep);
        }

        if ($subsite_keep > 0) {
            $by_blog = array();
            foreach (Rmmigrate_Snap_DB::jobs_retention_subsite_rows() as $row) {
                $by_blog[(int) $row['blog_id']][] = $row;
            }
            foreach ($by_blog as $rows) {
                $plan['subsite'] = array_merge($plan['subsite'], self::excess_rows($rows, $subsite_keep));
            }
        }

        return $plan;
    }

    /**
     * Counts for the "apply retention now" notice.
     *
     * @param array<string,mixed>|null $settings
     * @return array{backups:int,failed:int}
     */
    public static function preview(?array $settings = null): array
    {
        $plan = self::plan($settings);

        return array(
            'backups' => count($plan['network']) + count($plan['safety']) + count($plan['subsite']),
            'failed'  => count($plan['failed']),
        );
    }

    /**
     * Remove failed and cancelled backup entries that are past the age limit or beyond the cap.
     *
     * @return int Number of entries removed.
     */
    public static function expire_failed(): int
    {
        $removed = self::delete_rows(self::failed_rows_to_expire());
        if ($removed !== array()) {
            $triggered_by = (function_exists('wp_doing_cron') && wp_doing_cron()) ? 'cron' : 'system';
            Rmmigrate_Logger::log_system(
                sprintf(
                    /* translators: %d: number of failed or cancelled backup entries removed */
                    _n('Retention removed %d failed or cancelled backup entry.', 'Retention removed %d failed or cancelled backup entries.', count($removed), 'rosenheinrich-multisite-migrate'),
                    count($removed)
                ),
                array(
                    'triggered_by' => $triggered_by,
                    'job_ids'      => $removed,
                ),
                'info'
            );
        }

        return count($removed);
    }

    /**
     * Hourly failed-entry sweep from the scheduler tick (failed jobs never trigger a prune).
     */
    public static function maybe_expire_failed(): void
    {
        if (get_site_transient(self::FAILED_SWEEP_TRANSIENT)) {
            return;
        }
        set_site_transient(self::FAILED_SWEEP_TRANSIENT, time(), HOUR_IN_SECONDS);
        self::expire_failed();
    }

    /**
     * Remember that this user just changed the retention counts, so the next page shows the review notice.
     *
     * @param array<string,mixed> $prev
     * @param array<string,mixed> $next
     */
    public static function flag_review_if_changed(array $prev, array $next): void
    {
        if (!function_exists('get_current_user_id') || get_current_user_id() <= 0) {
            return;
        }
        foreach (array('retention_network', 'retention_subsite') as $key) {
            if ((int) ($prev[$key] ?? 0) !== (int) ($next[$key] ?? 0)) {
                set_transient(self::REVIEW_TRANSIENT_PREFIX . get_current_user_id(), 1, 10 * MINUTE_IN_SECONDS);
                return;
            }
        }
    }

    /**
     * Consume the review flag set on save. Returns the preview counts when there is something to show.
     *
     * @return array{backups:int,failed:int}|null
     */
    public static function take_review_notice(): ?array
    {
        if (!function_exists('get_current_user_id') || get_current_user_id() <= 0) {
            return null;
        }
        $key = self::REVIEW_TRANSIENT_PREFIX . get_current_user_id();
        if (!get_transient($key)) {
            return null;
        }
        delete_transient($key);
        $preview = self::preview();

        return ($preview['backups'] > 0 || $preview['failed'] > 0) ? $preview : null;
    }

    /**
     * Delete the rows beyond the newest $keep and log the count.
     *
     * @param array<int,array<string,mixed>> $rows Newest first.
     */
    private static function prune_group(array $rows, int $keep): void
    {
        self::log_removed(self::delete_rows(self::excess_rows($rows, $keep)));
    }

    /**
     * Rows beyond the newest $keep.
     *
     * @param array<int,array<string,mixed>> $rows Newest first.
     * @return array<int,array<string,mixed>>
     */
    private static function excess_rows(array $rows, int $keep): array
    {
        if ($keep <= 0 || count($rows) <= $keep) {
            return array();
        }

        return array_slice($rows, $keep);
    }

    /**
     * Failed/cancelled rows past the age limit, plus everything beyond the newest FAILED_KEEP_MAX.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function failed_rows_to_expire(): array
    {
        $cutoff = time() - self::FAILED_MAX_AGE_DAYS * DAY_IN_SECONDS;
        $expire = array();
        foreach (Rmmigrate_Snap_DB::jobs_retention_failed_rows() as $index => $row) {
            if ($index >= self::FAILED_KEEP_MAX || self::row_timestamp($row) < $cutoff) {
                $expire[] = $row;
            }
        }

        return $expire;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function row_timestamp(array $row): int
    {
        foreach (array('updated_at', 'created_at') as $key) {
            $raw = (string) ($row[$key] ?? '');
            if ($raw !== '') {
                $ts = strtotime($raw . ' UTC');
                if ($ts !== false) {
                    return (int) $ts;
                }
            }
        }

        // No usable timestamp: treat as old so broken rows cannot linger forever.
        return 0;
    }

    /**
     * Delete job rows with their files.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,int> Deleted job IDs.
     */
    private static function delete_rows(array $rows): array
    {
        if ($rows === array()) {
            return array();
        }

        global $wpdb;
        $table = Rmmigrate_Job::table_name();
        $deleted_ids = array();

        foreach ($rows as $row) {
            $job = Rmmigrate_Job::get((int) $row['id']);
            if ($job === null) {
                // Orphan row: no hydratable job. Remove it so retention can converge.
                $wpdb->delete($table, array('id' => (int) $row['id']), array('%d'));
                continue;
            }
            Rmmigrate_Job_Cleanup::purge($job);
            $wpdb->delete($table, array('id' => $job->get_id()), array('%d'));
            $deleted_ids[] = $job->get_id();
        }

        if ($deleted_ids !== array()) {
            self::flush_cache();
        }

        return $deleted_ids;
    }

    /**
     * @param array<int,int> $deleted_ids
     */
    private static function log_removed(array $deleted_ids): void
    {
        if ($deleted_ids === array()) {
            return;
        }
        $triggered_by = (function_exists('wp_doing_cron') && wp_doing_cron()) ? 'cron' : 'system';
        Rmmigrate_Logger::log_system(
            sprintf(
                /* translators: %d: number of backups removed */
                __('Retention removed %d backup(s).', 'rosenheinrich-multisite-migrate'),
                count($deleted_ids)
            ),
            array(
                'triggered_by' => $triggered_by,
                'job_ids'      => $deleted_ids,
            ),
            'info'
        );
    }

    private static function flush_cache(): void
    {
        wp_cache_delete('mm_retention_network', 'rmmigrate');
        wp_cache_delete('mm_retention_safety', 'rmmigrate');
        wp_cache_delete('mm_retention_subsite', 'rmmigrate');
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function partition_rows(array $rows): array
    {
        $network = array();
        $subsite = array();

        foreach ($rows as $row) {
            $scope = $row['scope'] ?? '';
            if ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
                $bid = (int) ($row['blog_id'] ?? 0);
                if (!isset($subsite[$bid])) {
                    $subsite[$bid] = array();
                }
                $subsite[$bid][] = $row;
            } elseif (in_array($scope, array(Rmmigrate_Multisite_Scope::SCOPE_NETWORK, Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED), true)) {
                $network[] = $row;
            }
        }

        return array('network' => $network, 'subsite' => $subsite);
    }
}
