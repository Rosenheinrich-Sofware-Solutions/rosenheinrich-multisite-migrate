<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Safety_Snapshot
{
    const LAST_SNAPSHOT_OPTION = 'rmmigrate_last_safety_snapshot';

    /**
     * Create a safety backup job before restore. Returns job id or 0 if skipped.
     */
    public static function create_before_restore(Rmmigrate_Job $source, string $restore_mode): int
    {
        $settings = Rmmigrate_Settings::get();
        if (empty($settings['safety_snapshot_enabled'])) {
            return 0;
        }

        $scope = $source->get_scope();
        $blog_id = $source->get_blog_id();

        $args = array(
            'scope'            => $scope,
            'blog_id'          => $blog_id,
            'excluded_blogs'   => $source->get_excluded_blogs(),
            'triggered_by'     => 'safety',
            'backup_profile'   => $restore_mode === Rmmigrate_Job::RESTORE_MODE_FILES ? 'files' : 'full',
            'is_safety'        => true,
        );

        $validation = Rmmigrate_Validator::validate_backup_start($args);
        if (is_wp_error($validation)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($validation->get_error_message()),
                array(), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($validation)));
        }

        $job = Rmmigrate_Job::create($args);
        $state = self::state();
        $state['pending'] = array(
            'safety_job_id' => $job->get_id(),
            'source_job_id' => $source->get_id(),
            'created_at'    => time(),
        );
        update_site_option(self::LAST_SNAPSHOT_OPTION, $state);
        Rmmigrate_Logger::log_activity(
            'backup',
            sprintf(
                /* translators: %1$d: safety backup job ID */
                __('Safety snapshot #%1$d queued before restore.', 'rosenheinrich-multisite-migrate'),
                $job->get_id()
            ),
            'info',
            array(
                'job_id'  => $job->get_id(),
                'context' => array(
                    'triggered_by' => 'safety',
                    'source_job' => $source->get_id(),
                ),
            )
        );
        Rmmigrate_Runner::kick_worker($job->get_id());
        return $job->get_id();
    }

    /**
     * Resolve the latest restorable pre-restore safety snapshot job.
     */
    public static function get_latest_rollback_job(?int $blog_id = null): ?Rmmigrate_Job
    {
        // Prefer the site-option link when present. A restore that replaces wp_options /
        // sitemeta wipes that option, so fall back to the surviving restore job row
        // (plugin runtime tables are skipped on import) which still stores safety_job_id.
        $from_option = self::job_from_option_links($blog_id);
        if ($from_option !== null) {
            return $from_option;
        }

        return self::job_from_restore_progress($blog_id);
    }

    /**
     * @return Rmmigrate_Job|null
     */
    private static function job_from_option_links(?int $blog_id): ?Rmmigrate_Job
    {
        // Only the snapshot taken for the most recent restore is offered: an older snapshot
        // would silently undo more than the last restore.
        $links = self::state()['by_site'];
        if ($blog_id !== null && $blog_id > 0) {
            $links = isset($links[(string) $blog_id]) ? array($links[(string) $blog_id]) : array();
        } else {
            usort($links, static function (array $a, array $b): int {
                return array((int) ($b['created_at'] ?? 0), (int) ($b['restore_job_id'] ?? 0))
                    <=> array((int) ($a['created_at'] ?? 0), (int) ($a['restore_job_id'] ?? 0));
            });
            $links = array_slice($links, 0, 1);
        }

        foreach ($links as $link) {
            $job = self::acceptable_safety_job((int) ($link['safety_job_id'] ?? 0), $blog_id);
            if ($job !== null) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Restore job rows survive DB import; read the newest completed restore's safety_job_id.
     *
     * @return Rmmigrate_Job|null
     */
    private static function job_from_restore_progress(?int $blog_id): ?Rmmigrate_Job
    {
        if (!class_exists('Rmmigrate_Job')) {
            return null;
        }

        $restores = Rmmigrate_Job::list_jobs(array(
            'status_group' => 'completed',
            'job_type'     => Rmmigrate_Job::JOB_TYPE_RESTORE,
            'limit'        => 25,
        ));

        foreach ($restores as $restore) {
            if (!($restore instanceof Rmmigrate_Job) || !$restore->is_restore()) {
                continue;
            }
            if ($blog_id !== null && $blog_id > 0) {
                $scope = $restore->get_scope();
                $restore_blog = $restore->get_blog_id();
                if ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE && $restore_blog !== $blog_id) {
                    continue;
                }
            }
            // Newest applicable restore wins: if its safety snapshot is gone, do not
            // silently offer an older restore's snapshot.
            $safety_id = (int) ($restore->get_progress()['safety_job_id'] ?? 0);
            return self::acceptable_safety_job($safety_id, $blog_id);
        }

        return null;
    }

    /**
     * @return Rmmigrate_Job|null
     */
    private static function acceptable_safety_job(int $safety_job_id, ?int $blog_id): ?Rmmigrate_Job
    {
        if ($safety_job_id <= 0) {
            return null;
        }
        $job = Rmmigrate_Job::get($safety_job_id);
        if (
            $job !== null
            && $job->is_safety()
            && $job->get_status() === Rmmigrate_Job::STATUS_COMPLETE
            && Rmmigrate_Recovery_Points::is_restorable($job)
            && ($blog_id === null || $blog_id <= 0 || $job->get_blog_id() === $blog_id)
        ) {
            return $job;
        }

        return null;
    }

    /**
     * Re-write the site-option link after a restore finishes. The earlier remember_for_restore()
     * call runs before DB import, which overwrites the option with the archive's copy.
     */
    public static function persist_link_after_restore(Rmmigrate_Job $restore_job): void
    {
        $safety_job_id = (int) ($restore_job->get_progress()['safety_job_id'] ?? 0);
        $site_key = self::site_key($restore_job->get_scope(), $restore_job->get_blog_id());
        $state = array('pending' => array(), 'by_site' => array());

        if ($site_key !== '0') {
            // Keep unrelated site links from a surviving option when present.
            $existing = self::state()['by_site'];
            unset($existing['0'], $existing[$site_key]);
            $state['by_site'] = $existing;
        }

        $safety_job = self::acceptable_safety_job($safety_job_id, null);
        if ($safety_job !== null) {
            $state['by_site'][$site_key] = array(
                'safety_job_id'  => $safety_job_id,
                'restore_job_id' => $restore_job->get_id(),
                'created_at'     => time(),
            );
        }

        if ($state['by_site'] === array()) {
            delete_site_option(self::LAST_SNAPSHOT_OPTION);
            return;
        }
        update_site_option(self::LAST_SNAPSHOT_OPTION, $state);
    }

    /**
     * Link the latest restore to its pre-restore snapshot; a restore without one (including
     * a rollback itself) clears the link so no stale snapshot is offered afterwards.
     * Only the snapshot queued for this restore's source is accepted.
     */
    public static function remember_for_restore(int $safety_job_id, Rmmigrate_Job $restore_job): void
    {
        $state = self::state();
        $pending = $state['pending'];
        $state['pending'] = array();
        $site_key = self::site_key($restore_job->get_scope(), $restore_job->get_blog_id());

        // A network-wide restore replaces every site, and a site restore changes what the
        // network snapshot would undo, so those links are no longer the "last restore".
        if ($site_key === '0') {
            $state['by_site'] = array();
        } else {
            unset($state['by_site']['0']);
        }
        unset($state['by_site'][$site_key]);

        $safety_job = $safety_job_id > 0 ? Rmmigrate_Job::get($safety_job_id) : null;
        if (
            $safety_job !== null
            && $safety_job->is_safety()
            && (int) ($pending['safety_job_id'] ?? 0) === $safety_job_id
            && (int) ($pending['source_job_id'] ?? 0) === (int) $restore_job->get_source_job_id()
        ) {
            $state['by_site'][$site_key] = array(
                'safety_job_id'  => $safety_job_id,
                'restore_job_id' => $restore_job->get_id(),
                'created_at'     => time(),
            );
        }

        if ($state['by_site'] === array()) {
            delete_site_option(self::LAST_SNAPSHOT_OPTION);
            return;
        }
        update_site_option(self::LAST_SNAPSHOT_OPTION, $state);
    }

    /**
     * @return array{pending: array<string,mixed>, by_site: array<string,array<string,mixed>>}
     */
    private static function state(): array
    {
        $raw = get_site_option(self::LAST_SNAPSHOT_OPTION, array());
        $state = array('pending' => array(), 'by_site' => array());
        if (is_array($raw)) {
            if (isset($raw['pending']) && is_array($raw['pending'])) {
                $state['pending'] = $raw['pending'];
            }
            if (isset($raw['by_site']) && is_array($raw['by_site'])) {
                $state['by_site'] = array_filter($raw['by_site'], 'is_array');
            } elseif (!empty($raw['safety_job_id'])) {
                // Older single-record format: keep offering it until the next restore replaces it.
                $legacy_blog = (int) ($raw['blog_id'] ?? 0);
                $state['by_site'][$legacy_blog > 0 ? (string) $legacy_blog : '0'] = array(
                    'safety_job_id'  => (int) $raw['safety_job_id'],
                    'restore_job_id' => (int) ($raw['restore_job_id'] ?? 0),
                    'created_at'     => (int) ($raw['created_at'] ?? 0),
                );
            }
        }
        return $state;
    }

    private static function site_key(string $scope, int $blog_id): string
    {
        return ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE && $blog_id > 0) ? (string) $blog_id : '0';
    }

    public static function is_complete(int $job_id): bool
    {
        if ($job_id <= 0) {
            return true;
        }
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            return true;
        }
        $status = $job->get_status();

        return in_array(
            $status,
            array(
                Rmmigrate_Job::STATUS_COMPLETE,
                Rmmigrate_Job::STATUS_ERROR,
                Rmmigrate_Job::STATUS_CANCELLED,
                Rmmigrate_Job::STATUS_DELETING,
            ),
            true
        );
    }
}
