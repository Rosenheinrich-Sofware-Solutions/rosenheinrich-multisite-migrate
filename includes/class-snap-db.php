<?php

/**
 * Database export helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Snap_DB
{
    /**
     * @return string[]
     */
    public static function get_primary_key_columns(string $table): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
        $keys = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME AS Column_name
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'PRIMARY'
                 ORDER BY ORDINAL_POSITION ASC",
                DB_NAME,
                $table
            )
        );
        if (empty($keys)) {
            return array();
        }
        $cols = array();
        foreach ($keys as $key) {
            if (!empty($key->Column_name)) {
                $cols[] = $key->Column_name;
            }
        }
        return $cols;
    }

    /**
     * @return array<string,string>
     */
    public static function get_column_types(string $table): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME AS Field, COLUMN_TYPE AS Type
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                 ORDER BY ORDINAL_POSITION ASC",
                DB_NAME,
                $table
            ),
            ARRAY_A
        );
        $types = array();
        foreach ($rows as $row) {
            $types[$row['Field']] = strtolower((string) ($row['Type'] ?? ''));
        }
        return $types;
    }

    public static function is_binary_type(string $type): bool
    {
        return strpos($type, 'blob') !== false
            || strpos($type, 'binary') !== false
            || strpos($type, 'bit') !== false;
    }

    /**
     * @param mixed $value
     */
    public static function sql_value($value, string $column_type): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (self::is_binary_type($column_type)) {
            $binary = (string) $value;
            // bin2hex('') is '', which would emit a bare "0x" — invalid SQL ("Unknown column '0x'").
            if ($binary === '') {
                return "''";
            }
            return '0x' . bin2hex($binary);
        }
        return "'" . esc_sql((string) $value) . "'";
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $pk_cols
     * @return array<string,mixed>
     */
    public static function extract_pk_offset(array $row, array $pk_cols): array
    {
        $offset = array();
        foreach ($pk_cols as $col) {
            $offset[$col] = $row[$col] ?? null;
        }
        return $offset;
    }

    /**
     * @param array<string,mixed>|null $offset
     */
    public static function build_pk_where(string $table, array $pk_cols, $offset): string
    {
        global $wpdb;
        if ($offset === null || $pk_cols === array()) {
            return '';
        }
        if (count($pk_cols) === 1) {
            $col = self::quote_identifier($pk_cols[0]);
            $prepared = $wpdb->prepare('%s', $offset[$pk_cols[0]] ?? '');
            return 'WHERE ' . $col . ' > ' . $prepared;
        }
        $tuple = array();
        foreach ($pk_cols as $col) {
            $tuple[] = $wpdb->prepare('%s', $offset[$col] ?? '');
        }
        $cols_sql = '(' . implode(',', array_map(array(self::class, 'quote_identifier'), $pk_cols)) . ')';
        return 'WHERE ' . $cols_sql . ' > (' . implode(',', $tuple) . ')';
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public static function compare_pk_tuples(array $pk_cols, array $a, array $b): int
    {
        foreach ($pk_cols as $col) {
            $va = (string) ($a[$col] ?? '');
            $vb = (string) ($b[$col] ?? '');
            if ($va === $vb) {
                continue;
            }
            return $va <=> $vb;
        }
        return 0;
    }

    /**
     * Quote a validated SQL identifier (table/column).
     */
    public static function quote_identifier(string $name): string
    {
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }
    /**
     * Executes an unbuffered query using mysqli if available, otherwise falls back to wpdb.
     * @return mysqli_result|array<int,array<string,mixed>>|false
     */
    public static function unbuffered_query(string $query)
    {
        global $wpdb;

        if ($wpdb->dbh instanceof mysqli) {
            return $wpdb->dbh->query($query, MYSQLI_USE_RESULT);
        }

        // SQLite / non-mysqli (e.g. WordPress Playground): no MYSQLI_USE_RESULT — buffer via wpdb.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Caller builds SELECT for known export tables via quote_identifier(); same SQL as mysqli path.
        $rows = $wpdb->get_results($query, ARRAY_A);
        if (!is_array($rows)) {
            return false;
        }

        return $rows;
    }

    /**
     * @param mysqli_result|array<int,mixed>|false $result
     * @return array<string,mixed>|null
     */
    public static function fetch_assoc(&$result): ?array
    {
        if ($result instanceof mysqli_result) {
            return $result->fetch_assoc();
        }
        if (is_array($result)) {
            if (empty($result)) {
                return null;
            }
            return array_shift($result);
        }

        return null;
    }

    /**
     * @param mysqli_result|array<int,mixed>|false $result
     */
    public static function free_result(&$result): void
    {
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $result = false;
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs/DB hub; table names via quote_identifier(); values via prepare().

    private static function jobs_table_sql(): string
    {
        self::ensure_jobs_table_exists();
        return self::quote_identifier(Rmmigrate_Job::table_name());
    }

    private static function ensure_jobs_table_exists(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return;
        }
        $table = Rmmigrate_Job::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-heal plugin schema if table missing post-restore.
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$table_exists && class_exists('Rmmigrate_Activator')) {
            Rmmigrate_Activator::ensure_schema();
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function jobs_get_row_by_id(int $id): ?array
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            return null;
        }
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public static function jobs_get_active_id(): ?int
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return null;
        }
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $table . ' WHERE status >= %d AND status < %d ORDER BY id DESC LIMIT 1',
                0,
                100
            )
        );

        return ($id === null || $id === '') ? null : (int) $id;
    }

    public static function jobs_get_active_id_for_blog(int $blog_id, string $scope): ?int
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return null;
        }
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $table . ' WHERE status >= %d AND status < %d AND scope = %s AND blog_id = %d ORDER BY id DESC LIMIT 1',
                0,
                100,
                $scope,
                $blog_id
            )
        );

        return ($id === null || $id === '') ? null : (int) $id;
    }

    public static function jobs_get_latest_import_id(): ?int
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return null;
        }
        self::ensure_jobs_table_exists();
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table.
        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $table . ' WHERE job_type = %s AND status = %d ORDER BY id DESC LIMIT 1',
                Rmmigrate_Job::JOB_TYPE_BACKUP,
                Rmmigrate_Job::STATUS_COMPLETE
            )
        );

        return ($id === null || $id === '') ? null : (int) $id;
    }

    /**
     * @param int[] $blog_ids
     * @return array<int,array<string,mixed>>
     */
    public static function jobs_count_subsite_backups_by_blog(array $blog_ids): array
    {
        global $wpdb;
        $table = self::jobs_table_sql();
        $where = array(
            "job_type = 'backup'",
            'status >= %d',
            'scope = %s',
        );
        $params = array(Rmmigrate_Job::STATUS_COMPLETE, Rmmigrate_Multisite_Scope::SCOPE_SUBSITE);

        if ($blog_ids !== array()) {
            $blog_ids = array_values(array_map('intval', $blog_ids));
            $placeholders = implode(',', array_fill(0, count($blog_ids), '%d'));
            $where[] = 'blog_id IN (' . $placeholders . ')';
            $params = array_merge($params, $blog_ids);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT blog_id, COUNT(*) AS cnt FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' GROUP BY blog_id',
                ...$params
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function jobs_list_rows(array $filters): array
    {
        global $wpdb;
        $table = self::jobs_table_sql();
        $where = array('1=1');
        $params = array();

        if (!empty($filters['job_type'])) {
            $where[] = 'job_type = %s';
            $params[] = $filters['job_type'];
        }

        if (!empty($filters['scope'])) {
            $where[] = 'scope = %s';
            $params[] = $filters['scope'];
        }

        if (isset($filters['blog_id'])) {
            $where[] = 'blog_id = %d';
            $params[] = (int) $filters['blog_id'];
        }

        if (!empty($filters['status_group'])) {
            switch ($filters['status_group']) {
                case 'active':
                    $where[] = 'status >= 0 AND status < 100';
                    break;
                case 'failed':
                    $where[] = '(status = %d OR (error_message IS NOT NULL AND error_message != \'\'))';
                    $params[] = Rmmigrate_Job::STATUS_ERROR;
                    break;
                case 'completed':
                    $where[] = 'status = %d';
                    $params[] = Rmmigrate_Job::STATUS_COMPLETE;
                    break;
                case 'cancelled':
                    $where[] = 'status = %d';
                    $params[] = Rmmigrate_Job::STATUS_CANCELLED;
                    break;
            }
        }

        if (!empty($filters['status_in']) && is_array($filters['status_in'])) {
            $placeholders = implode(',', array_fill(0, count($filters['status_in']), '%d'));
            $where[] = 'status IN (' . $placeholders . ')';
            foreach ($filters['status_in'] as $st) {
                $params[] = (int) $st;
            }
        } elseif (empty($filters['status_group'])) {
            // Soft-deleted jobs stay hidden until background purge finishes.
            $where[] = 'status != %d';
            $params[] = Rmmigrate_Job::STATUS_DELETING;
        }

        // Date range filters match the visible "Date" column (completed_at,
        // falling back to created_at). Bounds are inclusive, UTC, day-granular.
        if (!empty($filters['date_from'])) {
            $where[] = "COALESCE(NULLIF(completed_at, ''), created_at) >= %s";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "COALESCE(NULLIF(completed_at, ''), created_at) <= %s";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d',
                ...array_merge($params, array($limit))
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    public static function jobs_claim_status_at_least(int $job_id, int $new_status, string $updated_at): bool
    {
        global $wpdb;
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $table . ' SET status = %d, updated_at = %s WHERE id = %d AND status < %d',
                $new_status,
                $updated_at,
                $job_id,
                $new_status
            )
        );

        return (bool) $updated;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function jobs_retention_network_rows(): array
    {
        global $wpdb;
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM ' . $table . ' WHERE status = %d AND job_type = %s AND scope IN (%s, %s, %s) AND is_safety = 0 ORDER BY completed_at DESC',
                Rmmigrate_Job::STATUS_COMPLETE,
                Rmmigrate_Job::JOB_TYPE_BACKUP,
                Rmmigrate_Multisite_Scope::SCOPE_NETWORK,
                Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED,
                Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function jobs_retention_safety_rows(): array
    {
        global $wpdb;
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM ' . $table . ' WHERE status = %d AND job_type = %s AND is_safety = 1 ORDER BY completed_at DESC',
                Rmmigrate_Job::STATUS_COMPLETE,
                Rmmigrate_Job::JOB_TYPE_BACKUP
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function jobs_retention_subsite_rows(): array
    {
        global $wpdb;
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, blog_id FROM ' . $table . ' WHERE status = %d AND job_type = %s AND scope = %s AND is_safety = 0 ORDER BY blog_id ASC, completed_at DESC',
                Rmmigrate_Job::STATUS_COMPLETE,
                Rmmigrate_Job::JOB_TYPE_BACKUP,
                Rmmigrate_Multisite_Scope::SCOPE_SUBSITE
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function jobs_find_parent_row(string $scope, int $blog_id): ?array
    {
        global $wpdb;
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id FROM ' . $table . ' WHERE status = %d AND job_type = %s AND scope = %s AND blog_id = %d ORDER BY completed_at DESC LIMIT 1',
                Rmmigrate_Job::STATUS_COMPLETE,
                Rmmigrate_Job::JOB_TYPE_BACKUP,
                $scope,
                $blog_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function jobs_find_nearest_full_row(string $scope, int $blog_id): ?array
    {
        global $wpdb;
        $table = self::jobs_table_sql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin jobs table; identifier via quote_identifier(); values use prepare().
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id FROM ' . $table . ' WHERE status = %d AND job_type = %s AND scope = %s AND blog_id = %d AND backup_type = %s ORDER BY completed_at DESC LIMIT 1',
                Rmmigrate_Job::STATUS_COMPLETE,
                Rmmigrate_Job::JOB_TYPE_BACKUP,
                $scope,
                $blog_id,
                'full'
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public static function drop_table_if_exists(string $table): void
    {
        global $wpdb;
        $quoted = self::quote_identifier($table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall DDL on validated plugin table name.
        $wpdb->query('DROP TABLE IF EXISTS ' . $quoted);
    }

    /**
     * @param string[] $tables
     */
    public static function sum_table_bytes(array $tables): int
    {
        global $wpdb;
        $tables = array_values(array_filter(array_map('strval', $tables)));
        if ($tables === array()) {
            return 0;
        }
        $in  = implode(',', array_fill(0, count($tables), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- information_schema size estimate; table names use prepare placeholders.
        $sum = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = %s AND table_name IN (' . $in . ')',
                ...array_merge(array(DB_NAME), $tables)
            )
        );

        return max(0, (int) $sum);
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

    /**
     * @return array<int,mixed>|null
     */
    public static function get_show_create_table_row(string $table): ?array
    {
        global $wpdb;
        $quoted = self::quote_identifier($table);
        // Missing/ghost tables must not spam the error log during export.
        $prev_suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SHOW CREATE for validated table during export.
        $create = $wpdb->get_row('SHOW CREATE TABLE ' . $quoted, ARRAY_N);
        $wpdb->suppress_errors($prev_suppress);

        return is_array($create) ? $create : null;
    }

    /**
     * @return int|false
     */
    public static function execute_import_statement(string $statement)
    {
        global $wpdb;
        $wpdb->suppress_errors(false);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Trusted SQL from parsed backup dump; no placeholders.
        return $wpdb->query($statement);
    }
}
