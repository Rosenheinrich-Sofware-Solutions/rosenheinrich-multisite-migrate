<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned job table retention queries; wp_cache used where applicable.

class Rmmigrate_Retention
{
    public const DEFERRED_PRUNE_HOOK = 'rmmigrate_deferred_retention_prune';

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
        wp_cache_delete('mm_retention_network', 'rmmigrate');
        wp_cache_delete('mm_retention_safety', 'rmmigrate');
        wp_cache_delete('mm_retention_subsite', 'rmmigrate');

        $settings = Rmmigrate_Settings::get();
        $network_keep = max(0, (int) ($settings['retention_network'] ?? 0));
        $subsite_keep = max(0, (int) ($settings['retention_subsite'] ?? 0));

        $network_rows = Rmmigrate_Snap_DB::jobs_retention_network_rows();
        if ($network_keep > 0) {
            self::prune_group($network_rows, $network_keep);
        }

        $safety_rows = Rmmigrate_Snap_DB::jobs_retention_safety_rows();
        self::prune_group($safety_rows, 1);

        $subsite_rows = Rmmigrate_Snap_DB::jobs_retention_subsite_rows();

        if ($subsite_keep > 0) {
            $by_blog = array();
            foreach ($subsite_rows as $row) {
                $bid = (int) $row['blog_id'];
                if (!isset($by_blog[$bid])) {
                    $by_blog[$bid] = array();
                }
                $by_blog[$bid][] = $row;
            }

            foreach ($by_blog as $rows) {
                self::prune_group($rows, $subsite_keep);
            }
        }
    }

    /**
     * @param array<int,array{id:int}> $rows
     */
    private static function prune_group(array $rows, int $keep): void
    {
        if ($keep <= 0 || count($rows) <= $keep) {
            return;
        }

        global $wpdb;
        $table = Rmmigrate_Job::table_name();
        $to_delete = array_slice($rows, $keep);
        $deleted_ids = array();

        foreach ($to_delete as $row) {
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
            wp_cache_delete('mm_retention_network', 'rmmigrate');
            wp_cache_delete('mm_retention_safety', 'rmmigrate');
            wp_cache_delete('mm_retention_subsite', 'rmmigrate');
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
