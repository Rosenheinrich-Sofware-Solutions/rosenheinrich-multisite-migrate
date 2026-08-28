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

        if ($offset > 0 && $set_queries !== array()) {
            foreach ($set_queries as $set_sql) {
                if (is_string($set_sql) && $set_sql !== '') {
                    $this->execute_statement($set_sql);
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
        $handle->seek($offset);

        $start = microtime(true);
        $executed = 0;

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
                $scan_pos = 0;
                while (($pos = $this->find_statement_end($buffer, $scan_pos)) !== false) {
                    $statement = substr($buffer, $scan_pos, $pos - $scan_pos);
                    $scan_pos = $pos + 1;
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
                if ($scan_pos > 0) {
                    $buffer = substr($buffer, $scan_pos);
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
                    'set_queries' => array_values(array_unique($set_queries)),
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

    private function find_statement_end(string $buffer, int $start = 0)
    {
        $len = strlen($buffer);
        $in_string = false;
        $quote = '';
        $escaped = false;
        $in_line_comment = false;
        $in_block_comment = false;

        for ($i = max(0, $start); $i < $len; $i++) {
            $ch = $buffer[$i];
            if ($in_line_comment) {
                if ($ch === "\n") {
                    $in_line_comment = false;
                }
                continue;
            }
            if ($in_block_comment) {
                if ($ch === '*' && $i + 1 < $len && $buffer[$i + 1] === '/') {
                    $in_block_comment = false;
                    $i++;
                }
                continue;
            }
            if ($in_string) {
                if ($quote === '`') {
                    if ($ch === '`') {
                        if ($i + 1 < $len && $buffer[$i + 1] === '`') {
                            $i++;
                            continue;
                        }
                        $in_string = false;
                    }
                    continue;
                }
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
            if (!$in_string && $ch === '-' && $i + 1 < $len && $buffer[$i + 1] === '-') {
                $in_line_comment = true;
                $i++;
                continue;
            }
            if (!$in_string && $ch === '/' && $i + 1 < $len && $buffer[$i + 1] === '*') {
                $in_block_comment = true;
                $i++;
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
            if ($trim[$i] === '-' && $i + 1 < $len && $trim[$i + 1] === '-') {
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

    private function execute_statement(string $statement): void
    {
        if ($this->is_implicit_commit_statement($statement)) {
            $this->commit_import_transaction();
        } elseif (
            Rmmigrate_Engine_Config::sql_import_transactions()
            && $this->is_transactional_data_statement($statement)
        ) {
            $this->begin_import_transaction();
        }

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
