<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_SQL_Importer
{
    const BUFFER_FILE = 'import-buffer.dat';

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

        if ($offset > 0 && $set_queries !== array() && empty($import['sets_replayed'])) {
            foreach ($set_queries as $set_sql) {
                if (is_string($set_sql) && $set_sql !== '') {
                    $this->execute_statement($set_sql);
                }
            }
            $import['sets_replayed'] = true;
            $this->job->update_progress(array('import' => array('sets_replayed' => true)));
        }

        $handle = Rmmigrate_Filesystem::open($sql_path, 'rb');
        if ($handle === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DATABASE_SQL_UNREADABLE),
                esc_html__('Cannot read database.sql.', 'rosenheinrich-multisite-migrate')
            );
        }
        $handle->seek($offset);

        $start = microtime(true);
        $executed = 0;

        if (Rmmigrate_Engine_Config::sql_import_transactions()) {
            $this->begin_import_transaction();
        }

        try {
            while ((microtime(true) - $start) < $budget_sec) {
                $chunk = $handle->read(Rmmigrate_Engine_Config::sql_import_chunk_bytes());
                if ($chunk === false || $chunk === '') {
                    if ($buffer !== '') {
                        $statement = trim($buffer);
                        if ($statement !== '' && $this->is_executable($statement) && !$this->should_skip_statement($statement)) {
                            $this->execute_statement($statement);
                            $executed++;
                        }
                    }
                    $handle->close();
                    $this->delete_buffer();
                    $this->job->update_progress(array(
                        'import' => array(
                            'done'   => true,
                            'method' => 'php',
                            'rows'   => $rows_done + $executed,
                            'offset' => $total_bytes,
                            'total'  => $total_bytes,
                        ),
                    ));
                    $this->commit_import_transaction();
                    return true;
                }

                $buffer .= $chunk;
                while (($pos = $this->find_statement_end($buffer)) !== false) {
                    $statement = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $statement = trim($statement);
                    if ($statement !== '' && $this->is_delimiter_directive($statement)) {
                        continue;
                    }
                    if ($statement !== '' && $this->is_set_statement($statement)) {
                        $set_queries[] = $statement;
                    }
                    if ($statement !== '' && $this->is_executable($statement)) {
                        if (!$this->should_skip_statement($statement)) {
                            $this->execute_statement($statement);
                            $executed++;
                        }
                    }
                }

                $offset = (int) $handle->tell();
            }

            $handle->close();
            $this->save_buffer($buffer);
            $this->job->update_progress(array(
                'import' => array(
                    'offset'      => $offset,
                    'total'       => $total_bytes,
                    'rows'        => $rows_done + $executed,
                    'set_queries' => $set_queries,
                ),
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

    private function find_statement_end(string $buffer)
    {
        $len = strlen($buffer);
        $in_string = false;
        $quote = '';
        $escaped = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $buffer[$i];
            if ($in_string) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === $quote) {
                    $in_string = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $in_string = true;
                $quote = $ch;
                $escaped = false;
                continue;
            }
            if ($ch === ';') {
                return $i;
            }
        }

        return false;
    }

    private function is_executable(string $statement): bool
    {
        $trim = ltrim($statement);
        if ($trim === '') {
            return false;
        }
        if (strpos($trim, '--') === 0 || strpos($trim, '/*') === 0) {
            return false;
        }
        if (stripos($trim, 'RMMIGRATE_DB_EOF') !== false) {
            return false;
        }
        if ($this->is_delimiter_directive($trim)) {
            return false;
        }
        return true;
    }

    private function is_set_statement(string $statement): bool
    {
        return (bool) preg_match('/^[\s\t]*(?:\/\*!\d+)?[\s\t]*SET[\s\t]/i', ltrim($statement));
    }

    private function is_delimiter_directive(string $statement): bool
    {
        return (bool) preg_match('/^DELIMITER\s+/i', ltrim($statement));
    }

    /**
     * Same-server SCOPE_SUBSITE restore must not overwrite network globals —
     * that would mutate every other site's users/blogs/site rows.
     */
    private function preserves_network_globals(): bool
    {
        $scope = (string) ($this->job->data['scope'] ?? '');
        if ($scope !== Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            return false;
        }
        $restore_type = (string) ($this->job->data['restore_type'] ?? Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER);
        if ($restore_type !== Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER) {
            return false;
        }

        return is_multisite();
    }

    private function should_skip_statement(string $statement): bool
    {
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

        return in_array($table, Rmmigrate_Restore_Topology_Db_Action::network_global_table_names($base), true);
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
        if (preg_match(
            '/^\s*(?:\/\*!\d+\s*)?(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|ALTER\s+TABLE|LOCK\s+TABLES|TRUNCATE(?:\s+TABLE)?)\s+`?([a-zA-Z0-9_]+)`?/i',
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
        $stripped = preg_replace(
            '/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+`[^`]+`\s*\([^)]*\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION|SET\s+DEFAULT))*/i',
            '',
            $statement
        );
        if (!is_string($stripped)) {
            return $statement;
        }
        $stripped = preg_replace(
            '/,?\s*FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+`[^`]+`\s*\([^)]*\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION|SET\s+DEFAULT))*/i',
            '',
            $stripped
        );
        if (!is_string($stripped)) {
            return $statement;
        }
        $cleaned = preg_replace('/,\s*([)\]])/', '$1', $stripped);

        return is_string($cleaned) ? $cleaned : $stripped;
    }

    private function execute_statement(string $statement): void
    {
        if ($this->search_replace !== null) {
            $statement = $this->search_replace->apply($statement);
        }

        $statement = $this->strip_foreign_key_constraints($statement);

        global $wpdb;
        $wpdb->suppress_errors(false);
        $result = Rmmigrate_Snap_DB::execute_import_statement($statement);
        if ($result === false) {
            $error = $wpdb->last_error ?: __('Unknown database error.', 'rosenheinrich-multisite-migrate');
            Rmmigrate_Logger::log('SQL error: ' . $error);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::SQL_IMPORT_FAILED),
                sprintf(
                    /* translators: %s: database error message */
                    esc_html__('Database restore failed: %1$s', 'rosenheinrich-multisite-migrate'),
                    esc_html($error)
                )
            );
        }
    }

    private function begin_import_transaction(): void
    {
        if ($this->transaction_open) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
        $wpdb->query('START TRANSACTION');
        $this->transaction_open = true;
    }

    private function commit_import_transaction(): void
    {
        if (!$this->transaction_open) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
        $wpdb->query('COMMIT');
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
