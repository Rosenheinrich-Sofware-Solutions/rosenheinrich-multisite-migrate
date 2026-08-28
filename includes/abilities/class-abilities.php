<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress Abilities API registration for Multisite Migrate (Free).
 *
 * Exposed via MCP Adapter as tools. No license gate — wp.org Guideline 5/6.
 */
final class Rmmigrate_Abilities
{
    public const CATEGORY = 'multisite-migrate';

    public const APP_ID = 'mm-mcp';

    /** @var self|null */
    private static $instance = null;

    public static function register(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self();
    }

    private function __construct()
    {
        add_action('wp_abilities_api_categories_init', array($this, 'register_categories'));
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    public function register_categories(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category(
            self::CATEGORY,
            array(
                'label'       => __('Multisite Migrate', 'rosenheinrich-multisite-migrate'),
                'description' => __('Backup, health, and archive abilities for Multisite Migrate.', 'rosenheinrich-multisite-migrate'),
            )
        );
    }

    public function register_abilities(): void
    {
        $this->register_backup_status();
        $this->register_list_archives();
        $this->register_health_summary();
        $this->register_last_job_error();
        $this->register_activity_log();
        $this->register_backup_now();
        $this->register_cancel_job();
        $this->register_mapping_propose_stub();
    }

    /**
     * @return array<string,mixed>
     */
    private function mcp_meta(bool $readonly = true, ?array $write_annotations = null): array
    {
        $meta = array(
            'mcp' => array(
                'public' => true,
            ),
        );
        if ($readonly) {
            $meta['annotations'] = array(
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            );
        } elseif ($write_annotations !== null) {
            $meta['annotations'] = $write_annotations;
        }
        return $meta;
    }

    private function register_backup_status(): void
    {
        wp_register_ability(
            'multisite-migrate/backup-status',
            array(
                'label'               => __('Get backup job status', 'rosenheinrich-multisite-migrate'),
                'description'         => __('Returns Multisite Migrate job status. Pass job_id or detail:true for progress, database tables, and excludes.', 'rosenheinrich-multisite-migrate'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'job_id' => array(
                            'type'        => 'integer',
                            'description' => __('Optional job ID. Defaults to the active job.', 'rosenheinrich-multisite-migrate'),
                        ),
                        'detail' => array(
                            'type'        => 'boolean',
                            'description' => __('When true (or when job_id is set), include progress, database.tables, excludes, path, and size.', 'rosenheinrich-multisite-migrate'),
                            'default'     => false,
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'execute_callback'    => array($this, 'execute_backup_status'),
                'permission_callback' => array($this, 'can_read_ops'),
                'meta'                => $this->mcp_meta(true),
            )
        );
    }

    private function register_list_archives(): void
    {
        wp_register_ability(
            'multisite-migrate/list-archives',
            array(
                'label'               => __('List backup archives', 'rosenheinrich-multisite-migrate'),
                'description'         => __('Lists Multisite Migrate jobs/archives. Default: completed backups. Filter with status_group and job_type.', 'rosenheinrich-multisite-migrate'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'limit' => array(
                            'type'        => 'integer',
                            'description' => __('Max jobs to return (1–50). Default 20.', 'rosenheinrich-multisite-migrate'),
                            'default'     => 20,
                        ),
                        'status_group' => array(
                            'type'        => 'string',
                            'description' => __('Filter: completed (default), active, failed, cancelled, or all.', 'rosenheinrich-multisite-migrate'),
                            'default'     => 'completed',
                        ),
                        'job_type' => array(
                            'type'        => 'string',
                            'description' => __('Job type: backup (default), restore, or all.', 'rosenheinrich-multisite-migrate'),
                            'default'     => 'backup',
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'execute_callback'    => array($this, 'execute_list_archives'),
                'permission_callback' => array($this, 'can_read_ops'),
                'meta'                => $this->mcp_meta(true),
            )
        );
    }

    private function register_activity_log(): void
    {
        wp_register_ability(
            'multisite-migrate/activity-log',
            array(
                'label'               => __('Read activity log', 'rosenheinrich-multisite-migrate'),
                'description'         => __('Returns paginated Multisite Migrate activity log entries (timestamp, type, message, job_id).', 'rosenheinrich-multisite-migrate'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'page' => array(
                            'type'        => 'integer',
                            'description' => __('Page number (1-based). Default 1.', 'rosenheinrich-multisite-migrate'),
                            'default'     => 1,
                        ),
                        'per_page' => array(
                            'type'        => 'integer',
                            'description' => __('Entries per page (1–50). Default 25.', 'rosenheinrich-multisite-migrate'),
                            'default'     => 25,
                        ),
                        'type_filter' => array(
                            'type'        => 'string',
                            'description' => __('Optional activity type filter (e.g. backup, restore, mcp).', 'rosenheinrich-multisite-migrate'),
                        ),
                        'job_id' => array(
                            'type'        => 'integer',
                            'description' => __('Optional: only entries for this job ID.', 'rosenheinrich-multisite-migrate'),
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'execute_callback'    => array($this, 'execute_activity_log'),
                'permission_callback' => array($this, 'can_read_ops'),
                'meta'                => $this->mcp_meta(true),
            )
        );
    }

    private function register_cancel_job(): void
    {
        wp_register_ability(
            'multisite-migrate/cancel-job',
            array(
                'label'               => __('Cancel job', 'rosenheinrich-multisite-migrate'),
                'description'         => __('Cancels a running Multisite Migrate backup or restore job. Requires confirm:true and job_id.', 'rosenheinrich-multisite-migrate'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'job_id' => array(
                            'type'        => 'integer',
                            'description' => __('Job ID to cancel.', 'rosenheinrich-multisite-migrate'),
                        ),
                        'confirm' => array(
                            'type'        => 'boolean',
                            'description' => __('Must be true to cancel the job.', 'rosenheinrich-multisite-migrate'),
                        ),
                    ),
                    'required'             => array('job_id', 'confirm'),
                    'additionalProperties' => false,
                ),
                'execute_callback'    => array($this, 'execute_cancel_job'),
                'permission_callback' => array($this, 'can_write_ops'),
                'meta'                => $this->mcp_meta(false, array(
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => true,
                )),
            )
        );
    }

    private function register_health_summary(): void
    {
        wp_register_ability(
            'multisite-migrate/health-summary',
            array(
                'label'               => __('Hosting health summary', 'rosenheinrich-multisite-migrate'),
                'description'         => __('Summarizes Multisite Migrate Health checks with blockers/warnings and recommended next_action per failing check.', 'rosenheinrich-multisite-migrate'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'                 => 'object',
                    'properties'           => array(),
                    'additionalProperties' => false,
                ),
                'execute_callback'    => array($this, 'execute_health_summary'),
                'permission_callback' => array($this, 'can_read_ops'),
                'meta'                => $this->mcp_meta(true),
            )
        );
    }

    private function register_last_job_error(): void
    {
        wp_register_ability(
            'multisite-migrate/last-job-error',
            array(
                'label'               => __('Last job error', 'rosenheinrich-multisite-migrate'),
                'description'         => __('Returns the last recorded Multisite Migrate job error payload for the current admin scope, or empty if none.', 'rosenheinrich-multisite-migrate'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'                 => 'object',
                    'properties'           => array(),
                    'additionalProperties' => false,
                ),
                'execute_callback'    => array($this, 'execute_last_job_error'),
                'permission_callback' => array($this, 'can_read_ops'),
                'meta'                => $this->mcp_meta(true),
            )
        );
    }

    private function register_backup_now(): void
    {
        wp_register_ability(
            'multisite-migrate/backup-now',
            array(
                'label'               => __('Start network backup', 'rosenheinrich-multisite-migrate'),
                'description'         => __('Starts a Multisite Migrate backup job. Requires confirm:true. Free stores locally; pass exclude_tables / exclude_log_tables as needed. Cloud destinations require Pro.', 'rosenheinrich-multisite-migrate'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'confirm' => array(
                            'type'        => 'boolean',
                            'description' => __('Must be true to start the backup.', 'rosenheinrich-multisite-migrate'),
                        ),
                        'scope'   => array(
                            'type'        => 'string',
                            'description' => __('Backup scope: network or site. Default network on multisite, site on single.', 'rosenheinrich-multisite-migrate'),
                        ),
                        'profile' => array(
                            'type'        => 'string',
                            'description' => __('Backup profile: full or db. Default full.', 'rosenheinrich-multisite-migrate'),
                            'default'     => 'full',
                        ),
                        'destination' => array(
                            'type'        => 'string',
                            'description' => __('Free supports local only. Cloud destinations require Pro.', 'rosenheinrich-multisite-migrate'),
                            'default'     => 'local',
                        ),
                        'exclude_tables' => array(
                            'type'        => 'array',
                            'description' => __('Table names or patterns to exclude from the database dump.', 'rosenheinrich-multisite-migrate'),
                            'items'       => array('type' => 'string'),
                        ),
                        'exclude_log_tables' => array(
                            'type'        => 'boolean',
                            'description' => __('When true, exclude common logging tables.', 'rosenheinrich-multisite-migrate'),
                            'default'     => false,
                        ),
                        'exclude_revisions' => array(
                            'type'        => 'boolean',
                            'description' => __('When true, exclude post revisions from the dump.', 'rosenheinrich-multisite-migrate'),
                            'default'     => false,
                        ),
                    ),
                    'required'             => array('confirm'),
                    'additionalProperties' => false,
                ),
                'execute_callback'    => array($this, 'execute_backup_now'),
                'permission_callback' => array($this, 'can_write_ops'),
                'meta'                => $this->mcp_meta(false, array(
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                )),
            )
        );
    }

    /**
     * Bridge stub until AI Mapping Assist (Pain plan Phase 1) ships.
     */
    private function register_mapping_propose_stub(): void
    {
        wp_register_ability(
            'multisite-migrate/mapping-propose',
            array(
                'label'               => __('Propose domain mapping', 'rosenheinrich-multisite-migrate'),
                'description'         => __('Proposes old→new domain pairs for restore when Mapping Assist is available; otherwise returns guidance to use the restore UI.', 'rosenheinrich-multisite-migrate'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'job_id' => array(
                            'type'        => 'integer',
                            'description' => __('Backup job ID whose manifest should be mapped.', 'rosenheinrich-multisite-migrate'),
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'execute_callback'    => array($this, 'execute_mapping_propose'),
                'permission_callback' => array($this, 'can_read_ops'),
                'meta'                => $this->mcp_meta(true),
            )
        );
    }

    public function can_read_ops(): bool
    {
        if (!Rmmigrate_OAuth_Scopes::current_token_allows_read()) {
            return false;
        }
        return $this->user_can_ops();
    }

    public function can_write_ops(): bool
    {
        if (!Rmmigrate_OAuth_Scopes::current_token_allows_write()) {
            return false;
        }
        return Rmmigrate_Access::can_create_backup() || $this->user_can_ops();
    }

    private function user_can_ops(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        if (is_multisite()) {
            return current_user_can('manage_network') || current_user_can('manage_options');
        }
        return current_user_can('manage_options');
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|\WP_Error
     */
    public function execute_backup_status($input = array())
    {
        $input = is_array($input) ? $input : array();
        $job_id = isset($input['job_id']) ? (int) $input['job_id'] : null;
        if ($job_id !== null && $job_id <= 0) {
            $job_id = null;
        }
        $want_detail = !empty($input['detail']) || $job_id !== null;
        if ($job_id !== null) {
            $job = Rmmigrate_Job::get($job_id);
            if ($job === null) {
                return new WP_Error(
                    'rmmigrate_job_not_found',
                    __('Job not found.', 'rosenheinrich-multisite-migrate'),
                    array('status' => 404)
                );
            }
            if (!Rmmigrate_Access::can_view_job($job)) {
                return new WP_Error(
                    'rmmigrate_forbidden',
                    __('Permission denied.', 'rosenheinrich-multisite-migrate'),
                    array('status' => 403)
                );
            }
        }
        try {
            $status = Rmmigrate_Backup_Service::get_status($job_id);
        } catch (Rmmigrate_Service_Exception $e) {
            return array(
                'active' => false,
                'error'  => $e->getMessage(),
            );
        }
        if ($want_detail && !empty($status['job_id'])) {
            $status = $this->enrich_status_detail($status);
        }
        return $status;
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function enrich_status_detail(array $status): array
    {
        $job = Rmmigrate_Job::get((int) $status['job_id']);
        if ($job === null) {
            return $status;
        }

        $progress = $job->get_progress();
        $db       = (isset($progress['database']) && is_array($progress['database'])) ? $progress['database'] : array();
        $tables   = array();
        if (isset($db['tables']) && is_array($db['tables'])) {
            foreach ($db['tables'] as $table) {
                $tables[] = (string) $table;
            }
        }
        $excluded = array();
        if (isset($progress['excluded_tables']) && is_array($progress['excluded_tables'])) {
            foreach ($progress['excluded_tables'] as $pattern) {
                $excluded[] = (string) $pattern;
            }
        }

        $table_index = null;
        foreach (array('table_index', 'index', 'current_index') as $key) {
            if (isset($db[$key])) {
                $table_index = (int) $db[$key];
                break;
            }
        }

        $status['progress'] = array(
            'phase'    => (string) ($progress['phase'] ?? ''),
            'database' => array(
                'tables'      => $tables,
                'table_count' => count($tables),
                'table_index' => $table_index,
                'mode'        => (string) ($db['mode'] ?? ''),
                'rows'        => isset($db['rows']) ? (int) $db['rows'] : null,
            ),
        );
        $status['database'] = array(
            'tables'      => $tables,
            'table_count' => count($tables),
        );
        $status['excluded_tables']    = $excluded;
        $status['exclude_log_tables'] = !empty($progress['exclude_log_tables']);
        $status['file_size']          = $job->get_file_size();
        $status['local_path']         = $job->get_local_path();
        $status['label']              = method_exists($job, 'get_status_label') ? $job->get_status_label() : '';
        $status['scope']              = $job->get_scope();
        $status['blog_id']            = $job->get_blog_id();

        return $status;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|\WP_Error
     */
    public function execute_list_archives($input = array())
    {
        $input = is_array($input) ? $input : array();
        $limit = isset($input['limit']) ? (int) $input['limit'] : 20;
        $limit = max(1, min(50, $limit));

        $status_group = isset($input['status_group']) ? sanitize_key((string) $input['status_group']) : 'completed';
        if (!in_array($status_group, array('completed', 'active', 'failed', 'cancelled', 'all'), true)) {
            $status_group = 'completed';
        }

        $job_type = isset($input['job_type']) ? sanitize_key((string) $input['job_type']) : 'backup';
        if (!in_array($job_type, array('backup', 'restore', 'all'), true)) {
            $job_type = 'backup';
        }

        $filters = array('limit' => $limit);
        if ($status_group !== 'all') {
            $filters['status_group'] = $status_group;
        }
        if ($job_type !== 'all') {
            $filters['job_type'] = $job_type;
        }

        $jobs = Rmmigrate_Job::list_jobs($filters);

        $rows = array();
        foreach ($jobs as $job) {
            if (!Rmmigrate_Access::can_view_job($job)) {
                continue;
            }
            $rows[] = array(
                'job_id'     => $job->get_id(),
                'job_type'   => $job->get_job_type(),
                'scope'      => $job->get_scope(),
                'blog_id'    => $job->get_blog_id(),
                'file_size'  => $job->get_file_size(),
                'local_path' => $job->get_local_path(),
                'status'     => $job->get_status(),
                'label'      => method_exists($job, 'get_status_label') ? $job->get_status_label() : '',
                'created_at' => (string) ($job->data['created_at'] ?? ''),
                'error'      => (string) ($job->data['error_message'] ?? ''),
            );
        }

        return array(
            'count'        => count($rows),
            'status_group' => $status_group,
            'job_type'     => $job_type,
            'archives'     => $rows,
            'jobs'         => $rows,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute_activity_log($input = array())
    {
        $input = is_array($input) ? $input : array();
        $page = isset($input['page']) ? (int) $input['page'] : 1;
        $per_page = isset($input['per_page']) ? (int) $input['per_page'] : 25;
        $page = max(1, $page);
        $per_page = max(1, min(50, $per_page));
        $type_filter = isset($input['type_filter']) ? sanitize_key((string) $input['type_filter']) : '';
        $job_id = isset($input['job_id']) ? (int) $input['job_id'] : 0;

        if (!class_exists('Rmmigrate_Activity_Log', false)) {
            return array(
                'entries'     => array(),
                'total'       => 0,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => 1,
            );
        }

        if ($job_id > 0) {
            $raw = Rmmigrate_Activity_Log::list_entries($per_page, $page, $type_filter, '', '', $job_id);
        } else {
            $raw = Rmmigrate_Activity_Log::list_entries($per_page, $page, $type_filter);
        }

        $entries = array();
        foreach ((array) ($raw['entries'] ?? array()) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = array(
                'time'    => (string) ($entry['time'] ?? $entry['timestamp'] ?? ''),
                'type'    => (string) ($entry['type'] ?? ''),
                'status'  => (string) ($entry['status'] ?? ''),
                'message' => (string) ($entry['message'] ?? ''),
                'job_id'  => (int) ($entry['job_id'] ?? 0),
            );
        }

        return array(
            'entries'         => $entries,
            'total'           => (int) ($raw['total'] ?? count($entries)),
            'page'            => (int) ($raw['page'] ?? $page),
            'per_page'        => (int) ($raw['per_page'] ?? $per_page),
            'total_pages'     => (int) ($raw['total_pages'] ?? 1),
            'total_estimated' => !empty($raw['total_estimated']),
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|\WP_Error
     */
    public function execute_cancel_job($input = array())
    {
        $input = is_array($input) ? $input : array();
        if (empty($input['confirm'])) {
            return new WP_Error(
                'rmmigrate_confirm_required',
                __('Set confirm to true to cancel the job.', 'rosenheinrich-multisite-migrate'),
                array('status' => 400)
            );
        }
        $job_id = (int) ($input['job_id'] ?? 0);
        if ($job_id <= 0) {
            return new WP_Error(
                'rmmigrate_job_required',
                __('job_id is required.', 'rosenheinrich-multisite-migrate'),
                array('status' => 400)
            );
        }
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            return new WP_Error(
                'rmmigrate_job_not_found',
                __('Job not found.', 'rosenheinrich-multisite-migrate'),
                array('status' => 404)
            );
        }
        if (!Rmmigrate_Access::can_view_job($job)) {
            return new WP_Error(
                'rmmigrate_forbidden',
                __('Permission denied.', 'rosenheinrich-multisite-migrate'),
                array('status' => 403)
            );
        }
        try {
            Rmmigrate_Backup_Service::cancel($job_id);
            $this->audit_ability('multisite-migrate/cancel-job', array('job_id' => $job_id));
            return array(
                'cancelled' => true,
                'job_id'    => $job_id,
            );
        } catch (Rmmigrate_Service_Exception $e) {
            return new WP_Error('rmmigrate_cancel_failed', $e->getMessage(), array('status' => 400));
        }
    }

    public function execute_health_summary($input = array())
    {
        unset($input);
        $health = Rmmigrate_Health::get_status();
        $checks_out = array();
        $checks = isset($health['checks']) && is_array($health['checks']) ? $health['checks'] : array();
        foreach ($checks as $key => $check) {
            if (!is_array($check)) {
                continue;
            }
            $next = Rmmigrate_Health::next_action_for_check((string) $key, $check);
            $checks_out[(string) $key] = array(
                'status'      => (string) ($check['status'] ?? ''),
                'label'       => (string) ($check['label'] ?? $key),
                'detail'      => (string) ($check['detail'] ?? ''),
                'next_action' => $next,
            );
        }

        return array(
            'summary'       => Rmmigrate_Health::summary_text($health),
            'blocker_count' => (int) ($health['blocker_count'] ?? 0),
            'warn_count'    => (int) ($health['warn_count'] ?? 0),
            'ok_count'      => (int) ($health['ok_count'] ?? 0),
            'checks'        => $checks_out,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute_last_job_error($input = array())
    {
        unset($input);
        $is_network = is_multisite() && (is_network_admin() || current_user_can('manage_network'));
        $error = Rmmigrate_Job::resolve_admin_last_error($is_network);
        if ($error === array()) {
            return array('has_error' => false);
        }
        return array(
            'has_error' => true,
            'error'     => $error,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|\WP_Error
     */
    public function execute_backup_now($input = array())
    {
        $input = is_array($input) ? $input : array();
        if (empty($input['confirm'])) {
            return new WP_Error(
                'rmmigrate_confirm_required',
                __('Set confirm to true to start a backup.', 'rosenheinrich-multisite-migrate'),
                array('status' => 400)
            );
        }

        $scope = isset($input['scope']) ? sanitize_key((string) $input['scope']) : '';
        if ($scope === 'site' || $scope === 'subsite') {
            $scope = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
        } elseif ($scope === '') {
            $scope = Rmmigrate_Multisite_Scope::SCOPE_NETWORK;
        }

        $allowed_scopes = array(
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK,
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED,
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED,
            Rmmigrate_Multisite_Scope::SCOPE_SUBSITE,
        );
        if (!in_array($scope, $allowed_scopes, true)) {
            return new WP_Error(
                'rmmigrate_invalid_scope',
                __('Invalid backup scope.', 'rosenheinrich-multisite-migrate'),
                array('status' => 400)
            );
        }

        $profile = isset($input['profile']) ? sanitize_key((string) $input['profile']) : 'full';
        if (!in_array($profile, array('full', 'db'), true)) {
            $profile = 'full';
        }

        $destination = isset($input['destination']) ? sanitize_key((string) $input['destination']) : 'local';
        if ($destination === '') {
            $destination = 'local';
        }
        if ($destination !== 'local') {
            return new WP_Error(
                'rmmigrate_destination_pro',
                __('Cloud destinations require Multisite Migrate Pro. Free backups stay on local storage.', 'rosenheinrich-multisite-migrate'),
                array('status' => 400)
            );
        }

        $payload = array(
            'scope'              => $scope,
            'backup_profile'     => $profile,
            'destination'        => 'local',
            'kick_worker'        => true,
            'triggered_by'       => 'mcp',
            'exclude_log_tables' => !empty($input['exclude_log_tables']),
            'exclude_revisions'  => !empty($input['exclude_revisions']),
        );
        if (isset($input['exclude_tables']) && is_array($input['exclude_tables'])) {
            $exclude_tables = array();
            foreach ($input['exclude_tables'] as $table) {
                if (!is_scalar($table)) {
                    continue;
                }
                $name = trim((string) $table);
                if ($name !== '') {
                    $exclude_tables[] = $name;
                }
            }
            if ($exclude_tables !== array()) {
                $payload['exclude_tables'] = $exclude_tables;
            }
        }

        try {
            $result = Rmmigrate_Backup_Service::start_backup($payload);
            $this->audit_ability('multisite-migrate/backup-now', $result);
            return array(
                'started'     => true,
                'job_id'      => (int) ($result['job_id'] ?? 0),
                'destination' => 'local',
            );
        } catch (Rmmigrate_Service_Exception $e) {
            return new WP_Error('rmmigrate_backup_failed', $e->getMessage(), array('status' => 400));
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute_mapping_propose($input = array())
    {
        $input = is_array($input) ? $input : array();
        if (class_exists('Rmmigrate_Mapping_Assist', false) && method_exists('Rmmigrate_Mapping_Assist', 'propose')) {
            return Rmmigrate_Mapping_Assist::propose($input);
        }

        return array(
            'available' => false,
            'message'   => __('Domain mapping proposals will appear here after AI Mapping Assist ships. Use Suggest mapping in the restore dialog when available.', 'rosenheinrich-multisite-migrate'),
            'job_id'    => isset($input['job_id']) ? (int) $input['job_id'] : 0,
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function audit_ability(string $ability_id, array $context = array()): void
    {
        if (!class_exists('Rmmigrate_Logger', false)) {
            return;
        }
        Rmmigrate_Logger::log_activity(
            'mcp',
            sprintf('Ability %s via MCP', $ability_id),
            'info',
            array_merge(
                array(
                    'ability' => $ability_id,
                    'user_id' => get_current_user_id(),
                ),
                $context
            )
        );
    }

    /**
     * Ability names that only register when Pro/Plus commercial modules load.
     *
     * @return list<string>
     */
    public static function pro_ability_ids(): array
    {
        return array(
            'multisite-migrate/setup-schedule',
            'multisite-migrate/connect-cloud',
            'multisite-migrate/restore-preview',
            'multisite-migrate/restore-run',
        );
    }

    /**
     * @return 'free'|'pro'
     */
    public static function ability_tier(string $name): string
    {
        return in_array($name, self::pro_ability_ids(), true) ? 'pro' : 'free';
    }

    /**
     * Boot-data for MCP admin UI.
     *
     * @return list<array{id:string,label:string,tier:string,description?:string}>
     */
    public static function boot_registered_list(): array
    {
        if (!function_exists('wp_get_abilities')) {
            $fallback = array(
                'multisite-migrate/backup-status',
                'multisite-migrate/list-archives',
                'multisite-migrate/health-summary',
                'multisite-migrate/last-job-error',
                'multisite-migrate/activity-log',
                'multisite-migrate/backup-now',
                'multisite-migrate/cancel-job',
                'multisite-migrate/mapping-propose',
            );
            $out = array();
            foreach ($fallback as $id) {
                $short = substr($id, strlen('multisite-migrate/'));
                $out[] = array(
                    'id'    => $id,
                    'label' => $short !== false ? $short : $id,
                    'tier'  => self::ability_tier($id),
                );
            }
            return $out;
        }
        $out = array();
        foreach (wp_get_abilities() as $ability) {
            if (!is_object($ability)) {
                continue;
            }
            $name = method_exists($ability, 'get_name') ? (string) $ability->get_name() : '';
            if ($name === '' || strpos($name, 'multisite-migrate/') !== 0) {
                continue;
            }
            $label = method_exists($ability, 'get_label') ? (string) $ability->get_label() : $name;
            $row = array(
                'id'    => $name,
                'label' => $label,
                'tier'  => self::ability_tier($name),
            );
            if (method_exists($ability, 'get_description')) {
                $desc = (string) $ability->get_description();
                if ($desc !== '') {
                    $row['description'] = $desc;
                }
            }
            $out[] = $row;
        }
        return $out;
    }
}
