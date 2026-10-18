<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-sql-import-stream-parser.php';
require_once __DIR__ . '/class-sql-import-ddl.php';
require_once __DIR__ . '/class-sql-import-prefix-rewrite.php';
require_once __DIR__ . '/class-database-grants.php';
require_once __DIR__ . '/class-sql-import-statement-guard.php';

class Rmmigrate_SQL_Importer
{
    const BUFFER_FILE = 'import-buffer.dat';

    const GUARD_SCAN_BUFFER_FILE = 'import-guard-scan.dat';

    /** @var array<string,mixed>|null|false Cached guard policy (false = not computed yet). */
    private $guard_policy = false;

    /** @var Rmmigrate_Job */
    private $job;

    /** @var Rmmigrate_Search_Replace|null */
    private $search_replace;

    /** @var bool */
    private $transaction_open = false;

    public function __construct(Rmmigrate_Job $job)
    {
        $this->job = $job;
        if ($job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            $this->search_replace = new Rmmigrate_Search_Replace($job->get_migration_map());
        }
    }

    public function run_slice(int $budget_sec): bool
    {
        $extract_dir = trailingslashit($this->job->get_work_dir()) . 'extracted/';
        $sql_path = $extract_dir . 'database.sql';

        if (!Rmmigrate_Filesystem::exists($sql_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DATABASE_SQL_MISSING),
                esc_html__('database.sql not found in backup.', 'rosenheinrich-multisite-migrate')
            );
        }

        $progress = $this->job->get_progress();
        $import = $progress['import'] ?? array();

        if (!empty($import['done'])) {
            return true;
        }

        $offset = (int) ($import['offset'] ?? 0);
        if ($offset === 0) {
            $this->preflight_routine_privileges($sql_path);
            $this->preflight_subsite_on_network_prefix_rewrite();
            $this->preflight_database_integrity($sql_path);
            if (!$this->run_statement_guard_scan($sql_path, $budget_sec)) {
                return false;
            }
            $import = $this->job->get_progress()['import'] ?? array();
            if (!is_array($import)) {
                $import = array();
            }
        }
        $buffer = $this->load_buffer($import);
        $rows_done = (int) ($import['rows'] ?? 0);
        $total_bytes = (int) ($import['total'] ?? 0);
        if ($total_bytes <= 0) {
            $total_bytes = max(1, (int) Rmmigrate_Filesystem::filesize($sql_path));
        }
        $set_queries = $import['set_queries'] ?? array();
        if (!is_array($set_queries)) {
            $set_queries = array();
        }
        $parser = new Rmmigrate_Sql_Import_Stream_Parser((string) ($import['sql_delimiter'] ?? ';'));
        $import_warnings = is_array($import['warnings'] ?? null) ? $import['warnings'] : array();

        if ($offset > 0 && $set_queries !== array()) {
            foreach ($set_queries as $set_sql) {
                if ($this->should_skip_mysqldump_session_set($set_sql)) {
                    continue;
                }
                if (is_string($set_sql) && $set_sql !== '') {
                    $this->execute_statement($set_sql, $import_warnings);
                }
            }
        }

        $handle = Rmmigrate_Filesystem::open($sql_path, 'rb');
        if ($handle === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DATABASE_SQL_UNREADABLE),
                esc_html__('Cannot read database.sql.', 'rosenheinrich-multisite-migrate')
            );
        }
        if ($handle->seek($offset) !== 0) {
            $handle->close();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DATABASE_SQL_UNREADABLE),
                esc_html__('Cannot read database.sql.', 'rosenheinrich-multisite-migrate')
            );
        }

        $start = microtime(true);
        $executed = 0;

        $max_chunk_reads = (int) ($import['max_chunk_reads_per_slice'] ?? 0);
        $max_chunk_reads = (int) apply_filters(
            'rmmigrate_sql_import_max_chunk_reads_per_slice',
            $max_chunk_reads,
            $this->job
        );
        $chunk_reads = 0;

        try {
            while ((microtime(true) - $start) < $budget_sec) {
                $chunk = $handle->read(Rmmigrate_Engine_Config::sql_import_chunk_bytes());
                if ($chunk === false || $chunk === '') {
                    if ($buffer !== '') {
                        $statement = trim($buffer);
                        if ($statement !== '' && $this->is_executable($statement) && !$this->should_skip_statement($statement)) {
                            $this->execute_statement($statement, $import_warnings);
                            $executed++;
                        }
                    }
                    $handle->close();
                    $this->delete_buffer();
                    $this->job->update_progress(array(
                        'import' => array(
                            'done'          => true,
                            'method'        => 'php',
                            'rows'          => $rows_done + $executed,
                            'offset'        => $total_bytes,
                            'total'         => $total_bytes,
                            'sql_delimiter' => $parser->delimiter(),
                            'warnings'      => array_values(array_unique($import_warnings)),
                        ),
                    ));
                    $this->commit_import_transaction();
                    return true;
                }

                $buffer .= $chunk;
                $extracted = $parser->extract_completed_statements($buffer);
                $buffer = $extracted['remainder'];
                foreach ($extracted['statements'] as $statement) {
                    if ($this->is_set_statement($statement)) {
                        $set_queries[] = $statement;
                    }
                    if ($this->is_executable($statement)) {
                        if (!$this->should_skip_statement($statement)) {
                            $this->execute_statement($statement, $import_warnings);
                            $executed++;
                        }
                    }
                }

                $offset = (int) $handle->tell();
                $chunk_reads++;
                if ($max_chunk_reads > 0 && $chunk_reads >= $max_chunk_reads) {
                    break;
                }
            }

            $handle->close();
            $this->save_buffer($buffer);
            $import_progress = array(
                'offset'        => $offset,
                'total'         => $total_bytes,
                'rows'          => $rows_done + $executed,
                'set_queries'   => array_values(array_unique($set_queries)),
                'sql_delimiter' => $parser->delimiter(),
                'warnings'      => array_values(array_unique($import_warnings)),
            );
            if ($max_chunk_reads > 0) {
                $import_progress['max_chunk_reads_per_slice'] = $max_chunk_reads;
            }
            $this->job->update_progress(array(
                'import' => $import_progress,
            ));
            $this->commit_import_transaction();

            return false;
        } catch (Throwable $e) {
            if (isset($handle) && is_object($handle)) {
                $handle->close();
            }
            $this->rollback_import_transaction();
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $import
     */
    private function load_buffer(array $import): string
    {
        $path = trailingslashit($this->job->get_work_dir()) . self::BUFFER_FILE;
        if (Rmmigrate_Filesystem::exists($path)) {
            $contents = Rmmigrate_Filesystem::get_contents($path);
            return $contents !== false ? $contents : '';
        }

        if (isset($import['buffer']) && is_string($import['buffer']) && $import['buffer'] !== '') {
            $legacy = $import['buffer'];
            $this->save_buffer($legacy);
            return $legacy;
        }

        return '';
    }

    private function save_buffer(string $buffer): void
    {
        $path = trailingslashit($this->job->get_work_dir()) . self::BUFFER_FILE;
        if ($buffer === '') {
            $this->delete_buffer();
            return;
        }
        Rmmigrate_Filesystem::put_contents($path, $buffer);
    }

    private function delete_buffer(): void
    {
        $path = trailingslashit($this->job->get_work_dir()) . self::BUFFER_FILE;
        if (Rmmigrate_Filesystem::exists($path)) {
            Rmmigrate_Filesystem::delete($path);
        }
    }

    /**
     * @deprecated Use Rmmigrate_Sql_Import_Stream_Parser (kept for unit tests).
     *
     * @return int|false
     */
    private function find_statement_end(string $buffer, int $start = 0)
    {
        $parser = new Rmmigrate_Sql_Import_Stream_Parser(';');

        return $parser->find_statement_end($buffer, $start);
    }

    private function strip_leading_sql_comments(string $statement): string
    {
        $trim = ltrim($statement);
        $len = strlen($trim);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ($trim[$i] === ' ' || $trim[$i] === "\t" || $trim[$i] === "\n" || $trim[$i] === "\r")) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($trim[$i] === '#' || ($trim[$i] === '-' && $i + 1 < $len && $trim[$i + 1] === '-')) {
                while ($i < $len && $trim[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($trim[$i] === '/' && $i + 1 < $len && $trim[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len && !($trim[$i] === '*' && $trim[$i + 1] === '/')) {
                    $i++;
                }
                if ($i + 1 < $len) {
                    $i += 2;
                }
                continue;
            }
            break;
        }

        return substr($trim, $i);
    }

    private function is_executable(string $statement): bool
    {
        $trim = $this->strip_leading_sql_comments($statement);
        if ($trim === '') {
            return false;
        }
        if ($this->is_set_statement($trim)) {
            return true;
        }
        if (strpos($trim, '--') === 0 || strpos($trim, '/*') === 0 || strpos($trim, '#') === 0) {
            return false;
        }
        if (stripos($trim, 'RMMIGRATE_DB_EOF') !== false) {
            return false;
        }
        if (Rmmigrate_Sql_Import_Stream_Parser::parse_delimiter_directive($trim) !== null) {
            return false;
        }
        return true;
    }

    private function is_set_statement(string $statement): bool
    {
        return (bool) preg_match('/^[\s\t]*(?:\/\*!\d+)?[\s\t]*SET[\s\t]/i', ltrim($statement));
    }

    /**
     * @deprecated Use Rmmigrate_Sql_Import_Stream_Parser::parse_delimiter_directive().
     */
    private function is_delimiter_directive(string $statement): bool
    {
        return Rmmigrate_Sql_Import_Stream_Parser::parse_delimiter_directive($statement) !== null;
    }

    /**
     * Network-landing restores must not overwrite shared network globals (blogs, site, …).
     * Subsite-on-network / network-to-subsite apply regardless of backup manifest scope.
     */
    private function preserves_network_globals(): bool
    {
        if (!is_multisite()) {
            return false;
        }
        $restore_mode = (string) ($this->job->get_progress()['restore_mode'] ?? '');
        if (in_array($restore_mode, array('subsite_on_network', 'network_to_subsite'), true)) {
            return true;
        }
        $scope = (string) ($this->job->data['scope'] ?? '');
        if ($scope !== Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            return false;
        }
        $restore_type = (string) ($this->job->data['restore_type'] ?? Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER);

        return $restore_type === Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER;
    }

    private function user_merge_imports_archive_users(): bool
    {
        if (!class_exists('Rmmigrate_Restore_Topology_User_Merge', false)) {
            return false;
        }
        $progress = $this->job->get_progress();
        $context = array(
            'restore_mode' => (string) ($progress['restore_mode'] ?? ''),
            'user_merge'   => array_key_exists('user_merge', $progress) ? !empty($progress['user_merge']) : true,
        );

        return Rmmigrate_Restore_Topology_User_Merge::should_run($context);
    }

    private function preflight_subsite_on_network_prefix_rewrite(): void
    {
        $progress = $this->job->get_progress();
        if ((string) ($progress['restore_mode'] ?? '') !== 'subsite_on_network') {
            return;
        }
        $manifest = is_array($progress['manifest'] ?? null) ? $progress['manifest'] : array();
        $source_blog_id = (int) ($manifest['blog_id'] ?? 0);
        $target_blog_id = (int) ($progress['subsite_on_network_target_blog_id'] ?? 0);
        // Fail closed before the first statement: an unresolved or missing target must never
        // fall through to an import under the source prefix (which is a live blog on this network).
        if ($target_blog_id <= 0 || !$this->network_blog_exists($target_blog_id)) {
            $this->throw_subsite_on_network_target_unresolved();
        }
        $rewrite = is_array($progress['sql_table_prefix_rewrite'] ?? null)
            ? $progress['sql_table_prefix_rewrite']
            : array();
        $from = (string) ($rewrite['from'] ?? '');
        $to = (string) ($rewrite['to'] ?? '');
        $has_rewrite = $from !== '' && $to !== '' && $from !== $to;
        global $wpdb;
        $base_prefix = isset($wpdb->base_prefix) ? (string) $wpdb->base_prefix : '';
        $expected_to = $target_blog_id > 1 ? $base_prefix . $target_blog_id . '_' : $base_prefix;
        if ($has_rewrite) {
            if ($base_prefix !== '' && $to !== $expected_to) {
                $this->throw_subsite_on_network_target_unresolved();
            }

            return;
        }
        if ($source_blog_id > 0 && $source_blog_id !== $target_blog_id) {
            $this->throw_subsite_on_network_target_unresolved();
        }
        $dump_prefix = (string) ($manifest['db_prefix'] ?? '');
        if ($source_blog_id <= 0 && $dump_prefix !== '' && $base_prefix !== '' && $dump_prefix !== $expected_to) {
            $this->throw_subsite_on_network_target_unresolved();
        }
    }

    private function network_blog_exists(int $blog_id): bool
    {
        if ($blog_id <= 0) {
            return false;
        }
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fail-closed import preflight must read the live blogs row.
        $found = (int) $wpdb->get_var($wpdb->prepare("SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id = %d LIMIT 1", $blog_id));

        return $found === $blog_id;
    }

    /**
     * @return never
     */
    private function throw_subsite_on_network_target_unresolved(): void
    {
        throw Rmmigrate_Job_Exception::raise(
            sanitize_key('topology_subsite_on_network_target_unresolved'),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            __('Subsite-on-network import could not resolve the target blog. Check the blog ID and URL.', 'rosenheinrich-multisite-migrate')
        );
    }

    private function preflight_routine_privileges(string $sql_path): void
    {
        if (!Rmmigrate_Sql_Import_Ddl::file_contains_routines($sql_path)) {
            return;
        }
        $missing = Rmmigrate_Database_Grants::missing_routine_restore_privileges();
        if ($missing === array()) {
            return;
        }
        $import = $this->job->get_progress()['import'] ?? array();
        if (!is_array($import)) {
            $import = array();
        }
        $warnings = is_array($import['warnings'] ?? null) ? $import['warnings'] : array();
        $warnings[] = sprintf(
            /* translators: %s: comma-separated MySQL privilege names */
            __('Database user lacks required privileges (%s) to restore views or triggers in this backup.', 'rosenheinrich-multisite-migrate'),
            implode(', ', $missing)
        );
        $import['warnings'] = array_values(array_unique($warnings));
        $this->job->update_progress(array('import' => $import));
        Rmmigrate_Logger::log((string) $warnings[count($warnings) - 1]);

        if (apply_filters('rmmigrate_block_restore_on_missing_routine_privileges', false, $this->job)) {
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key(Rmmigrate_Error_Codes::SQL_IMPORT_FAILED),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception; raw DB error for storage.
                $warnings[count($warnings) - 1]
            );
        }
    }

    /**
     * @param string[] $import_warnings
     */
    private function record_import_warning(array &$import_warnings, string $message): void
    {
        $import_warnings[] = $message;
        Rmmigrate_Logger::log($message);
    }

    private function should_skip_mysqldump_session_set(string $statement): bool
    {
        if (!preg_match('/^[\s\t]*(?:\/\*!\d+)?[\s\t]*SET[\s\t]/i', ltrim($statement))) {
            return false;
        }
        if (preg_match('/character_set_(client|results)|collation_connection/i', $statement)
            && preg_match('/\bNULL\b/i', $statement)) {
            return true;
        }
        if (preg_match('/@saved_cs_client|@OLD_CHARACTER_SET/i', $statement)) {
            return true;
        }
        // mysqldump GTID / binlog preamble: server-global state, never part of a site restore.
        if (preg_match('/\bGTID_PURGED\b|\bSQL_LOG_BIN\b/i', $statement)) {
            return true;
        }
        if (preg_match('/\bautocommit\b/i', $statement)
            && (preg_match('/\bNULL\b/i', $statement) || preg_match('/@(?:OLD_)?AUTOCOMMIT/i', $statement))) {
            return true;
        }

        return false;
    }

    private function should_skip_statement(string $statement): bool
    {
        if ($this->should_skip_mysqldump_session_set($statement)) {
            return true;
        }
        $table = $this->statement_table_name($statement);
        if ($table !== '' && $this->is_plugin_runtime_table($table)) {
            return true;
        }
        if (!$this->preserves_network_globals()) {
            return false;
        }
        if ($table === '') {
            return false;
        }
        global $wpdb;
        $base = (string) $wpdb->base_prefix;
        $globals = Rmmigrate_Restore_Topology_Db_Action::network_global_table_names($base);
        if (!in_array($table, $globals, true)) {
            return false;
        }
        $restore_mode = (string) ($this->job->get_progress()['restore_mode'] ?? '');
        if (
            in_array($restore_mode, array('subsite_on_network', 'network_to_subsite'), true)
            && $this->user_merge_imports_archive_users()
            && in_array($table, array($base . 'users', $base . 'usermeta'), true)
        ) {
            // Destination users were staged before import; archive rows may land in live names.
            return false;
        }

        return true;
    }

    private function is_plugin_runtime_table(string $table): bool
    {
        if (class_exists('Rmmigrate_Restore_Topology_Db_Action', false)) {
            return Rmmigrate_Restore_Topology_Db_Action::is_plugin_runtime_table($table);
        }
        foreach (array('*rmmigrate_jobs', '*rmmigrate_pro_jobs') as $pattern) {
            if (fnmatch($pattern, $table)) {
                return true;
            }
        }

        return false;
    }

    private function statement_table_name(string $statement): string
    {
        $statement = $this->strip_leading_sql_comments($statement);
        if (preg_match(
            '/^\s*(?:\/\*!\d+\s*)?(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|ALTER\s+TABLE|LOCK\s+TABLES|TRUNCATE(?:\s+TABLE)?)\s+(?:`?[a-zA-Z0-9_]+`?\.)?`?([a-zA-Z0-9_]+)`?/i',
            $statement,
            $m
        )) {
            return (string) $m[1];
        }

        return '';
    }

    /**
     * InnoDB still validates FK formation on CREATE even with foreign_key_checks=0
     * (errno 150 when referenced table missing / type mismatch). Strip constraints;
     * dumps already omit plugin job tables and typically do not need live FKs to restore.
     */
    private function strip_foreign_key_constraints(string $statement): string
    {
        if (!preg_match('/^\s*(?:\/\*!\d+\s*)?CREATE\s+TABLE/i', $statement)) {
            return $statement;
        }
        $removed = false;
        $stripped = preg_replace(
            '/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+`[^`]+`\s*\([^)]*\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION|SET\s+DEFAULT))*/i',
            '',
            $statement,
            -1,
            $constraint_count
        );
        if (!is_string($stripped)) {
            return $statement;
        }
        if ($constraint_count > 0) {
            $removed = true;
        }
        $stripped = preg_replace(
            '/,?\s*FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+`[^`]+`\s*\([^)]*\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION|SET\s+DEFAULT))*/i',
            '',
            $stripped,
            -1,
            $foreign_key_count
        );
        if (!is_string($stripped)) {
            return $statement;
        }
        if ($foreign_key_count > 0) {
            $removed = true;
        }
        if (!$removed) {
            return $statement;
        }
        $open = strpos($stripped, '(');
        if ($open === false) {
            return $stripped;
        }
        $depth = 0;
        $len = strlen($stripped);
        $close = false;
        for ($i = $open; $i < $len; $i++) {
            if ($stripped[$i] === '(') {
                $depth++;
            } elseif ($stripped[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }
        if ($close === false) {
            return $stripped;
        }
        $column_list = substr($stripped, $open + 1, $close - $open - 1);
        $column_list = preg_replace('/,\s*,/', ',', $column_list) ?? $column_list;
        $column_list = preg_replace('/,\s*$/', '', $column_list) ?? $column_list;

        return substr($stripped, 0, $open + 1) . $column_list . substr($stripped, $close);
    }

    /**
     * @param string[] $import_warnings
     */
    private function execute_statement(string $statement, array &$import_warnings): void
    {
        if ($this->is_implicit_commit_statement($statement)) {
            $this->commit_import_transaction();
        } elseif (
            Rmmigrate_Engine_Config::sql_import_transactions()
            && $this->is_transactional_data_statement($statement)
        ) {
            $this->begin_import_transaction();
        }

        $statement = $this->transform_statement($statement);
        $this->assert_statement_in_scope($statement);

        /**
         * Fires immediately before each SQL statement is executed during a restore import.
         * Throwing a job exception from a callback aborts the import (used by fault-injection tests).
         *
         * @param string $statement Final statement about to run.
         * @param object $job       Restore job.
         */
        do_action('rmmigrate_sql_import_before_statement', $statement, $this->job);

        global $wpdb;
        $wpdb->suppress_errors(false);
        $result = Rmmigrate_Snap_DB::execute_import_statement($statement);
        if ($result === false) {
            $error = $wpdb->last_error ?: __('Unknown database error.', 'rosenheinrich-multisite-migrate');
            Rmmigrate_Logger::log('SQL error: ' . $error);
            if (
                Rmmigrate_Sql_Import_Ddl::skipped_routine_kind($statement) !== null
                && Rmmigrate_Sql_Import_Ddl::is_skippable_routine_error($error)
            ) {
                $kind = Rmmigrate_Sql_Import_Ddl::skipped_routine_kind($statement) ?: 'ROUTINE';
                Rmmigrate_Logger::log(
                    'Skipped database object (' . $kind . '): ' . $error
                );
                $this->record_import_warning(
                    $import_warnings,
                    esc_html__(
                        'Some database objects were skipped during import. See the activity log for details.',
                        'rosenheinrich-multisite-migrate'
                    )
                );

                return;
            }
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception; raw DB error stored for display-time escaping.
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key(Rmmigrate_Error_Codes::SQL_IMPORT_FAILED),
                sprintf(
                    /* translators: %s: database error message */
                    __('Database restore failed: %1$s', 'rosenheinrich-multisite-migrate'),
                    $error
                )
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    /**
     * Search-replace, prefix rewrite, FK strip and DEFINER sanitize — exactly what runs.
     */
    private function transform_statement(string $statement): string
    {
        if ($this->search_replace !== null) {
            $statement = $this->search_replace->apply($statement);
        }

        $progress = $this->job->get_progress();
        $rewrite = is_array($progress['sql_table_prefix_rewrite'] ?? null)
            ? $progress['sql_table_prefix_rewrite']
            : array();
        $from_prefix = (string) ($rewrite['from'] ?? '');
        $to_prefix = (string) ($rewrite['to'] ?? '');
        if ($from_prefix !== '' && $to_prefix !== '' && $from_prefix !== $to_prefix) {
            $statement = Rmmigrate_Sql_Import_Prefix_Rewrite::apply($statement, $from_prefix, $to_prefix);
        }

        $statement = $this->strip_foreign_key_constraints($statement);

        return Rmmigrate_Sql_Import_Ddl::sanitize_executable_statement($statement);
    }

    /**
     * Subsite-scoped restores (subsite on a network, subsite same-server) may only touch the
     * target blog's tables. Null when the import is not subsite-scoped.
     *
     * @return array<string,mixed>|null
     */
    private function statement_guard_policy(): ?array
    {
        if ($this->guard_policy !== false) {
            return $this->guard_policy;
        }
        $this->guard_policy = null;
        if (!function_exists('is_multisite') || !is_multisite()) {
            return null;
        }
        $progress = $this->job->get_progress();
        $restore_mode = (string) ($progress['restore_mode'] ?? '');
        $scope = (string) ($this->job->data['scope'] ?? '');
        $restore_type = (string) ($this->job->data['restore_type'] ?? Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER);
        $subsite_same_server = $scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE
            && $restore_type === Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER
            && empty($progress['full_installation_replace']);
        if ($restore_mode !== 'subsite_on_network' && !$subsite_same_server) {
            return null;
        }
        global $wpdb;
        $base = isset($wpdb->base_prefix) ? (string) $wpdb->base_prefix : '';
        $target_blog_id = (int) ($progress['subsite_on_network_target_blog_id'] ?? 0);
        if ($target_blog_id <= 0) {
            $target_blog_id = (int) ($progress['target_blog_id'] ?? 0);
        }
        if ($target_blog_id <= 0) {
            $target_blog_id = (int) $this->job->get_blog_id();
        }
        $extra = array();
        if ($restore_mode === 'subsite_on_network' && $this->user_merge_imports_archive_users()) {
            // Destination users were staged before import; archive rows land in the live names.
            $extra = array($base . 'users', $base . 'usermeta');
        }
        if (!class_exists('Rmmigrate_Restore_Topology_Db_Action', false)) {
            require_once __DIR__ . '/shared/restore-topology/bootstrap.php';
        }
        $version = $this->guard_server_version();
        $this->guard_policy = array(
            'target_prefix'          => $target_blog_id > 1 ? $base . $target_blog_id . '_' : ($target_blog_id === 1 ? $base : ''),
            'base_prefix'            => $base,
            'global_tables'          => Rmmigrate_Restore_Topology_Db_Action::network_global_table_names($base),
            'extra_allowed'          => $extra,
            'db_name'                => defined('DB_NAME') ? (string) DB_NAME : '',
            'server_version'         => $version['version'],
            'server_is_mariadb'      => $version['mariadb'],
            'trigger_table_resolver' => array($this, 'guard_trigger_owner'),
        );

        return $this->guard_policy;
    }

    /**
     * @return array{version:int,mariadb:bool}
     */
    private function guard_server_version(): array
    {
        global $wpdb;
        $raw = '';
        if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Server version decides which executable comments run.
            $raw = (string) $wpdb->get_var('SELECT VERSION()');
        }
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $raw, $m)) {
            return array('version' => 0, 'mariadb' => false);
        }

        return array(
            'version' => ((int) $m[1]) * 10000 + ((int) $m[2]) * 100 + (int) $m[3],
            'mariadb' => stripos($raw, 'mariadb') !== false,
        );
    }

    /**
     * Owning table of an existing trigger in this schema, or null when the trigger does not exist.
     *
     * @internal Guard callback.
     */
    public function guard_trigger_owner(string $trigger): ?string
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare') || !defined('DB_NAME')) {
            return '';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fail-closed import guard reads live trigger ownership.
        $owner = $wpdb->get_var($wpdb->prepare(
            'SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s AND TRIGGER_NAME = %s LIMIT 1',
            DB_NAME,
            $trigger
        ));

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * Throws before execution when a subsite-scoped import would leave the target blog.
     */
    private function assert_statement_in_scope(string $statement): void
    {
        $policy = $this->statement_guard_policy();
        if ($policy === null) {
            return;
        }
        if (!$this->guard_session_sql_mode_checked) {
            $this->guard_session_sql_mode_checked = true;
            $this->enforce_guard_session_sql_mode();
        }
        $reason = Rmmigrate_Sql_Import_Statement_Guard::check($statement, $policy);
        if ($reason === Rmmigrate_Sql_Import_Statement_Guard::OK) {
            return;
        }
        $this->throw_statement_out_of_scope($reason, $statement);
    }

    /** @var bool */
    private $guard_session_sql_mode_checked = false;

    /**
     * The guard tokenizes with default quoting rules; drop session modes that change them.
     */
    private function enforce_guard_session_sql_mode(): void
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query')) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-only read.
        $mode = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
        if (!preg_match('/NO_BACKSLASH_ESCAPES|ANSI_QUOTES/i', $mode)) {
            return;
        }
        $kept = array();
        foreach (explode(',', $mode) as $part) {
            $part = strtoupper(trim($part));
            if ($part !== '' && !in_array($part, array('NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES', 'ANSI'), true)) {
                $kept[] = $part;
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-only sql_mode adjustment for the import connection.
        $wpdb->query($wpdb->prepare('SET SESSION sql_mode = %s', implode(',', $kept)));
    }

    /**
     * @return never
     */
    private function throw_statement_out_of_scope(string $reason, string $statement): void
    {
        $this->rollback_import_transaction();
        $excerpt = (string) preg_replace('/\s+/', ' ', substr($statement, 0, 100));
        Rmmigrate_Logger::log('SQL import guard rejected statement (' . $reason . '): ' . $excerpt);
        throw Rmmigrate_Job_Exception::raise(
            sanitize_key('sql_import_statement_out_of_scope'),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            __("This backup's database contains statements outside this site's tables. Import stopped before any change.", 'rosenheinrich-multisite-migrate')
        );
    }

    /**
     * Fail closed before the first statement: scan the whole dump (resumable across slices).
     */
    private function run_statement_guard_scan(string $sql_path, int $budget_sec): bool
    {
        $policy = $this->statement_guard_policy();
        if ($policy === null) {
            return true;
        }
        $progress = $this->job->get_progress();
        $scan = is_array($progress['import_guard_scan'] ?? null) ? $progress['import_guard_scan'] : array();
        if (!empty($scan['done'])) {
            return true;
        }
        $offset = (int) ($scan['offset'] ?? 0);
        $buffer_path = trailingslashit($this->job->get_work_dir()) . self::GUARD_SCAN_BUFFER_FILE;
        $buffer = '';
        if ($offset > 0 && Rmmigrate_Filesystem::exists($buffer_path)) {
            $contents = Rmmigrate_Filesystem::get_contents($buffer_path);
            $buffer = $contents !== false ? (string) $contents : '';
        }
        $parser = new Rmmigrate_Sql_Import_Stream_Parser((string) ($scan['sql_delimiter'] ?? ';'));
        $handle = Rmmigrate_Filesystem::open($sql_path, 'rb');
        if ($handle === false || $handle->seek($offset) !== 0) {
            if ($handle !== false) {
                $handle->close();
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DATABASE_SQL_UNREADABLE),
                esc_html__('Cannot read database.sql.', 'rosenheinrich-multisite-migrate')
            );
        }
        $start = microtime(true);
        $checked = (int) ($scan['statements'] ?? 0);
        try {
            while (true) {
                $chunk = $handle->read(Rmmigrate_Engine_Config::sql_import_chunk_bytes());
                if ($chunk === false || $chunk === '') {
                    $tail = trim($buffer);
                    if ($tail !== '') {
                        $this->guard_scan_statement($tail);
                        $checked++;
                    }
                    $handle->close();
                    if (Rmmigrate_Filesystem::exists($buffer_path)) {
                        Rmmigrate_Filesystem::delete($buffer_path);
                    }
                    $this->job->update_progress(array(
                        'import_guard_scan' => array('done' => true, 'offset' => $offset, 'statements' => $checked),
                    ));

                    return true;
                }
                $buffer .= $chunk;
                $extracted = $parser->extract_completed_statements($buffer);
                $buffer = $extracted['remainder'];
                foreach ($extracted['statements'] as $statement) {
                    $this->guard_scan_statement($statement);
                    $checked++;
                }
                $offset = (int) $handle->tell();
                if ((microtime(true) - $start) >= $budget_sec) {
                    break;
                }
            }
        } catch (Throwable $e) {
            $handle->close();
            if (Rmmigrate_Filesystem::exists($buffer_path)) {
                Rmmigrate_Filesystem::delete($buffer_path);
            }
            throw $e;
        }
        $handle->close();
        Rmmigrate_Filesystem::put_contents($buffer_path, $buffer);
        $this->job->update_progress(array(
            'import_guard_scan' => array(
                'done'          => false,
                'offset'        => $offset,
                'statements'    => $checked,
                'sql_delimiter' => $parser->delimiter(),
            ),
        ));

        return false;
    }

    private function guard_scan_statement(string $statement): void
    {
        // SET statements are replayed on every resumed slice regardless of the table skip list.
        $replayed_set = $this->is_set_statement($statement) && !$this->should_skip_mysqldump_session_set($statement);
        if (!$replayed_set && (!$this->is_executable($statement) || $this->should_skip_statement($statement))) {
            return;
        }
        $this->assert_statement_in_scope($this->transform_statement($statement));
    }

    /**
     * When the (signed) manifest records a database.sql digest, the extracted dump must match it.
     */
    private function preflight_database_integrity(string $sql_path): void
    {
        $progress = $this->job->get_progress();
        if (!empty($progress['import_integrity_checked'])) {
            return;
        }
        $manifest = is_array($progress['manifest'] ?? null) ? $progress['manifest'] : null;
        if ($manifest === null) {
            $manifest_path = dirname($sql_path) . '/manifest.json';
            $raw = Rmmigrate_Filesystem::exists($manifest_path) ? Rmmigrate_Filesystem::get_contents($manifest_path) : false;
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $manifest = is_array($decoded) ? $decoded : array();
        }
        $expected = strtolower((string) ($manifest['database_sha256'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $expected)) {
            $actual = (string) hash_file('sha256', $sql_path);
            if (!hash_equals($expected, $actual)) {
                Rmmigrate_Logger::log('database.sql digest mismatch: manifest ' . $expected . ', extracted ' . $actual);
                throw Rmmigrate_Job_Exception::raise(
                    sanitize_key('database_sql_integrity_mismatch'),
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    __('database.sql does not match the backup manifest. The archive may have been modified. Import stopped before any change.', 'rosenheinrich-multisite-migrate')
                );
            }
        }
        $this->job->update_progress(array('import_integrity_checked' => true));
    }

    private function is_implicit_commit_statement(string $statement): bool
    {
        $statement = $this->strip_leading_sql_comments($statement);
        return (bool) preg_match(
            '/^\s*(?:\/\*!\d+\s*)?(?:CREATE|DROP|ALTER|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i',
            $statement
        );
    }

    private function is_transactional_data_statement(string $statement): bool
    {
        return (bool) preg_match(
            '/^\s*(?:\/\*!\d+\s*)?(?:INSERT|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\b/i',
            $statement
        );
    }

    private function begin_import_transaction(): void
    {
        if ($this->transaction_open) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
        $started = $wpdb->query('START TRANSACTION');
        if ($started === false) {
            return;
        }
        $this->transaction_open = true;
    }

    private function commit_import_transaction(): void
    {
        if (!$this->transaction_open) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
        $committed = $wpdb->query('COMMIT');
        if ($committed === false) {
            return;
        }
        $this->transaction_open = false;
    }

    private function rollback_import_transaction(): void
    {
        if (!$this->transaction_open) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
        $wpdb->query('ROLLBACK');
        $this->transaction_open = false;
    }
}
