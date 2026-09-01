<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Database export reads site tables and writes SQL dump files.

class Rmmigrate_DB_Dumper
{
    const PHP_CHUNK_SEC              = 5;
    const INSERT_BATCH               = 500;
    const INSERT_BATCH_LOW_MEMORY    = 25;
    const PHP_MAX_CELL_BYTES         = 16777216;
    const INSERT_QUERY_LIMIT_DEFAULT = 131072;
    const MYSQLDUMP_MEMORY_BUFFER    = 52428800;
    const MYSQLDUMP_SIZE_TOLERANCE   = 52428800;
    const PROGRESS_ROWS_INTERVAL     = 1000;
    const PROGRESS_BYTES_INTERVAL    = 1048576;
    const MYSQLDUMP_DATA_BULK_MAX_BYTES = 134217728;
    const MYSQLDUMP_DATA_BULK_MAX_TABLES  = 30;
    const MYSQLDUMP_DATA_BATCH_DEFAULT  = 25;
    const MYSQLDUMP_DATA_BULK_BUDGET_SEC = 600;
    const MYSQLDUMP_DATA_BATCH_BUDGET_SEC = 120;
    const MYSQLDUMP_SCHEMA_BULK_BUDGET_SEC = 180;
    const PROGRESS_BACKUP_FILE       = 'database.progress.bak.json';
    const TABLE_META_FILE            = 'database.table_meta.json';

    /** @var Rmmigrate_Job */
    private $job;

    /** @var Rmmigrate_Multisite_Scope */
    private $scope;

    /** @var string */
    private $sql_path;

    public function __construct(Rmmigrate_Job $job, Rmmigrate_Multisite_Scope $scope)
    {
        $this->job = $job;
        $this->scope = $scope;
        $this->sql_path = trailingslashit($job->get_work_dir()) . 'database.sql';
    }

    public function run_slice(int $budget_sec): bool
    {
        Rmmigrate_Local_Storage::ensure_job_work_dir($this->job, false);

        $this->restore_db_progress_from_backup();

        $progress = $this->job->get_progress();
        $db = $progress['database'] ?? array();

        if (!empty($db['tables'])) {
            $normalized = $this->ensure_database_progress_defaults($db);
            if ($normalized !== $db) {
                $this->job->replace_progress_section('database', $normalized);
                $db = $normalized;
            }
        }

        if (empty($db['tables'])) {
            $tables = self::order_export_tables($this->scope->get_tables());
            $mode = $this->detect_mode($tables);
            $this->ensure_php_session_timeouts();
            $this->job->replace_progress_section('database', $this->fresh_database_progress($tables, $mode));
            $db = $this->job->get_progress()['database'] ?? array();
        }

        if (empty($db['mode'])) {
            $tables = $db['tables'] ?? self::order_export_tables($this->scope->get_tables());
            $mode = $this->detect_mode($tables);
            $this->job->update_progress(array('database' => array('mode' => $mode)));
            $db['mode'] = $mode;
        }

        if ($db['mode'] === 'mysqldump') {
            $tables = $db['tables'] ?? self::order_export_tables($this->scope->get_tables());
            $done = $this->run_mysqldump_table_slice($budget_sec);
            if ($done) {
                if (!$this->validate_mysqldump_size($tables)) {
                    return $this->fallback_php_dump($tables);
                }
                $this->append_eof_marker();
                $this->sync_sql_bytes_written();
                $this->job->update_progress(array('database' => array('phase' => 'done')));
            }
            return $done;
        }

        $start = microtime(true);
        $phase = $db['phase'] ?? 'creates';

        if ($phase === 'creates') {
            if (!$this->run_php_creates($budget_sec)) {
                return false;
            }
            $this->sync_sql_bytes_written();
            $this->job->update_progress(array('database' => array('phase' => 'inserts', 'table_index' => 0, 'pk_offset' => null, 'table_rows_done' => 0, 'insert_incomplete' => false, 'create_index' => 0)));
            $phase = 'inserts';
        }

        if ($phase === 'inserts') {
            $this->migrate_table_meta_from_progress();
            while ((microtime(true) - $start) < $budget_sec) {
                Rmmigrate_Runner::refresh_sql_lock($this->job->get_id());
                $remaining_budget = max(1, (int) floor($budget_sec - (microtime(true) - $start)));
                $done = $this->run_php_inserts_batch($remaining_budget);
                if ($done) {
                    $this->append_eof_marker();
                    $this->sync_sql_bytes_written();
                    $this->job->update_progress(array('database' => array('phase' => 'done', 'insert_incomplete' => false)));
                    return true;
                }
            }
            return false;
        }

        return ($db['phase'] ?? '') === 'done';
    }

    /**
     * @param string[] $tables
     */
    private function detect_mode(array $tables): string
    {
        $settings = Rmmigrate_Settings::get();
        $mode = $settings['db_mode'] ?? 'auto';

        if ($mode === 'php') {
            return 'php';
        }
        if ($mode === 'mysqldump') {
            return 'mysqldump';
        }

        // mysqldump streams in a subprocess — prefer it whenever available (Auto mode).
        if ($this->can_mysqldump()) {
            return 'mysqldump';
        }

        return 'php';
    }

    public static function available_php_memory_bytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            return 0;
        }
        $bytes = self::parse_memory_limit((string) $limit);
        if ($bytes <= 0) {
            return 0;
        }

        return max(0, $bytes - memory_get_usage(true));
    }

    public static function php_max_cell_bytes(): int
    {
        $settings = Rmmigrate_Settings::get();
        $configured = (int) ($settings['php_export_max_cell_bytes'] ?? self::PHP_MAX_CELL_BYTES);
        if ($configured < 1048576) {
            $configured = self::PHP_MAX_CELL_BYTES;
        }

        return min($configured, 134217728);
    }

    /**
     * @param mixed $value
     */
    public static function row_value_exceeds_php_budget($value, string $column_type): bool
    {
        if ($value === null) {
            return false;
        }

        $raw_len = strlen((string) $value);
        if ($raw_len <= self::php_max_cell_bytes()) {
            return false;
        }

        $needed = Rmmigrate_Snap_DB::is_binary_type($column_type)
            ? ($raw_len * 2) + 256
            : $raw_len + 256;
        $available = self::available_php_memory_bytes();

        return $available > 0 && $needed > (int) ($available * 0.45);
    }

    public static function insert_query_limit(): int
    {
        $settings = Rmmigrate_Settings::get();
        $limit = (int) ($settings['db_insert_query_limit'] ?? self::INSERT_QUERY_LIMIT_DEFAULT);
        if ($limit < 32768) {
            $limit = self::INSERT_QUERY_LIMIT_DEFAULT;
        }
        return min($limit, 1048576);
    }

    /**
     * @param string[] $tables
     * @return string[]
     */
    public static function order_export_tables(array $tables): array
    {
        global $wpdb;
        $priority = array($wpdb->users, $wpdb->usermeta);
        $ordered = array();
        foreach ($priority as $table) {
            if (in_array($table, $tables, true)) {
                $ordered[] = $table;
            }
        }
        foreach ($tables as $table) {
            if (!in_array($table, $ordered, true)) {
                $ordered[] = $table;
            }
        }
        return $ordered;
    }

    /**
     * @param string[] $tables
     */
    public static function estimate_database_bytes(array $tables, bool $exclude_revisions = false): int
    {
        return Rmmigrate_Snap_DB::sum_table_bytes($tables, $exclude_revisions);
    }

    /**
     * @param string[] $tables
     */
    private function estimate_job_database_bytes(array $tables): int
    {
        return self::estimate_database_bytes($tables, $this->should_exclude_revisions());
    }

    public static function mysqldump_memory_ok(int $estimated_db_bytes): bool
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            return true;
        }
        $bytes = self::parse_memory_limit((string) $limit);
        if ($bytes <= 0) {
            return true;
        }
        return ($estimated_db_bytes + self::MYSQLDUMP_MEMORY_BUFFER) <= $bytes;
    }

    public static function apply_mysqldump_line_fixes(string $line): string
    {
        $replaced = preg_replace('/^(\s*CREATE\s+TABLE)(\s+`.+`.*)$/im', '$1 IF NOT EXISTS$2', $line);
        if ($replaced !== null) {
            $line = $replaced;
        }
        $replaced = preg_replace('/^(\s*INSERT)(\s+INTO\s+`.+`.*)$/im', '$1 IGNORE$2', $line);
        if ($replaced !== null) {
            $line = $replaced;
        }
        return $line;
    }

    private static function parse_memory_limit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return 0;
        }
        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;
        switch ($unit) {
            case 'g':
                return $value * 1073741824;
            case 'm':
                return $value * 1048576;
            case 'k':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }

    private function ensure_php_session_timeouts(): void
    {
        global $wpdb;
        $wait = max(60, (int) Rmmigrate_Runner::worker_budget_sec() + 30);
        $wpdb->query($wpdb->prepare('SET SESSION wait_timeout = %d', $wait));
    }

    public static function can_use_mysqldump(): bool
    {
        $settings = Rmmigrate_Settings::get();
        $mode = $settings['db_mode'] ?? 'auto';
        if ($mode === 'php') {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            return false;
        }
        $path = self::resolve_mysqldump_path();
        return $path !== '' && is_executable($path);
    }

    private function can_mysqldump(): bool
    {
        return self::can_use_mysqldump();
    }

    private function get_mysqldump_path(): string
    {
        return self::resolve_mysqldump_path();
    }

    public static function resolve_mysqldump_path(): string
    {
        $settings = Rmmigrate_Settings::get();
        if (!empty($settings['mysqldump_path']) && is_executable($settings['mysqldump_path'])) {
            return $settings['mysqldump_path'];
        }

        // Guard: proc_open is required to run mysqldump; path probing uses is_executable() only.
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            return '';
        }

        $candidates = array(
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/usr/mysql/bin/mysqldump',
        );

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $candidates[] = 'C:/xampp/mysql/bin/mysqldump.exe';
            $candidates[] = 'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysqldump.exe';
            $candidates[] = 'C:/Program Files/MySQL/MySQL Server 5.7/bin/mysqldump.exe';
            if (defined('PHP_BINARY') && PHP_BINARY) {
                $candidates[] = str_replace('\\', '/', dirname(PHP_BINARY) . '/../mysql/bin/mysqldump.exe');
            }
        } else {
            if (defined('PHP_BINARY') && PHP_BINARY) {
                $candidates[] = dirname(PHP_BINARY) . '/../mysql/bin/mysqldump';
            }
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * One table per worker slice for mysqldump mode.
     */
    private function run_mysqldump_table_slice(int $budget_sec): bool
    {
        $db = $this->job->get_progress()['database'] ?? array();
        $tables = $db['tables'] ?? array();
        if ($tables === array()) {
            return true;
        }

        $phase = (string) ($db['mysqldump_phase'] ?? 'schema');
        $index = (int) ($db['mysqldump_table_index'] ?? 0);
        $total = count($tables);

        if ($phase === 'schema') {
            if ($index >= $total) {
                $data_start = Rmmigrate_Filesystem::exists($this->sql_path)
                    ? (int) Rmmigrate_Filesystem::filesize($this->sql_path)
                    : 0;
                $this->job->update_progress(array('database' => array(
                    'mysqldump_phase'            => 'data',
                    'mysqldump_table_index'      => 0,
                    'mysqldump_data_start_bytes' => $data_start,
                    'mysqldump_data_batch_size'  => 0,
                    'phase'                      => 'mysqldump',
                )));
                return false;
            }

            // In-flight jobs may resume legacy per-table schema slices.
            if ($index > 0) {
                return $this->run_mysqldump_schema_table_slice($tables, $index, $total, $budget_sec);
            }

            if (Rmmigrate_Filesystem::exists($this->sql_path)) {
                Rmmigrate_Filesystem::delete($this->sql_path);
            }

            Rmmigrate_Logger::log(sprintf('mysqldump schema pass for %d tables (bulk)', $total));
            $schema_budget = max(1, min($budget_sec, self::MYSQLDUMP_SCHEMA_BULK_BUDGET_SEC));
            $db_host = $this->parse_db_host();
            $result = $this->stream_mysqldump_pass(
                $this->get_mysqldump_path(),
                $db_host['host'],
                $db_host['port'],
                $db_host['socket'],
                $tables,
                array('--no-data'),
                false,
                $schema_budget
            );
            if ($result === 1) {
                Rmmigrate_Filesystem::delete($this->sql_path);
                return $this->fallback_php_dump($tables, 'bulk schema export timed out');
            }
            if ($result === 2) {
                return false;
            }
            if ($result !== 0) {
                return $this->fallback_php_dump($tables, 'bulk schema export failed');
            }
            $this->sync_sql_bytes_written();
            $data_start = Rmmigrate_Filesystem::exists($this->sql_path)
                ? (int) Rmmigrate_Filesystem::filesize($this->sql_path)
                : 0;
            $this->job->update_progress(array('database' => array(
                'mysqldump_phase'            => 'data',
                'mysqldump_table_index'      => 0,
                'mysqldump_data_start_bytes' => $data_start,
                'mysqldump_data_batch_size'  => 0,
                'phase'                      => 'mysqldump',
            )));
            return false;
        }

        if ($index >= $total) {
            return true;
        }

        return $this->run_mysqldump_data_slice($tables, $index, $total, $budget_sec);
    }

    /**
     * @param string[] $tables
     */
    private function run_mysqldump_data_slice(array $tables, int $index, int $total, int $budget_sec): bool
    {
        $db = $this->job->get_progress()['database'] ?? array();
        $data_start = (int) ($db['mysqldump_data_start_bytes'] ?? 0);

        if ($index === 0 && empty($db['mysqldump_data_mode']) && $this->can_mysqldump_data_bulk($tables) && !$this->should_exclude_revisions()) {
            return $this->run_mysqldump_data_bulk($tables, $total, $budget_sec, $data_start);
        }

        $batch_size = (int) ($db['mysqldump_data_batch_size'] ?? 0);
        if ($batch_size <= 0) {
            $batch_size = $this->initial_mysqldump_data_batch_size($tables, $index);
        }
        $batch_size = min($batch_size, $total - $index);
        // Revision filters need per-table --where; never batch those tables.
        if ($this->should_exclude_revisions()) {
            $batch_size = 1;
        }
        $batch = array_slice($tables, $index, $batch_size);
        if ($batch === array()) {
            return true;
        }

        $batch_end = $index + count($batch);
        Rmmigrate_Logger::log(
            sprintf(
                'mysqldump data pass for tables %d-%d of %d (batch %d)',
                $index + 1,
                $batch_end,
                $total,
                count($batch)
            )
        );

        $slice_start = Rmmigrate_Filesystem::exists($this->sql_path)
            ? (int) Rmmigrate_Filesystem::filesize($this->sql_path)
            : 0;
        $data_budget = max(1, min($budget_sec, self::MYSQLDUMP_DATA_BATCH_BUDGET_SEC));
        $where = '';
        if (count($batch) === 1) {
            $where = $this->revision_exclude_sql_clause($batch[0]);
        }
        $db_host = $this->parse_db_host();
        $result = $this->stream_mysqldump_pass(
            $this->get_mysqldump_path(),
            $db_host['host'],
            $db_host['port'],
            $db_host['socket'],
            $batch,
            array('--no-create-info', '--no-create-db', '--insert-ignore'),
            true,
            $data_budget,
            $where
        );

        if ($result === 1) {
            if (!self::realign_sql_file_to_checkpoint($this->sql_path, $slice_start)) {
                Rmmigrate_Logger::log('mysqldump data: SQL realign failed after budget; falling back to PHP');
                return $this->fallback_php_dump($tables, 'sql realign failed after data budget');
            }
            $this->sync_sql_bytes_written();
            Rmmigrate_Runner::refresh_sql_lock($this->job->get_id());

            if (count($batch) > 1) {
                $next_batch = max(1, (int) floor(count($batch) / 2));
                Rmmigrate_Logger::log(
                    sprintf('mysqldump data batch budget reached; reducing batch size to %d', $next_batch)
                );
                $this->job->update_progress(array('database' => array(
                    'mysqldump_data_batch_size' => $next_batch,
                    'mysqldump_data_mode'       => 'batched',
                )));
                return false;
            }

            // Single-table mysqldump cannot resume mid-table: retrying the same
            // oversized table forever stalls the job (UI frozen on "table N of M").
            // Keep completed SQL and finish remaining tables via chunked PHP inserts.
            return $this->switch_remaining_mysqldump_data_to_php(
                $tables,
                $index,
                sprintf('mysqldump timed out on table %s within worker budget', $batch[0])
            );
        }

        if ($result === 2) {
            return false;
        }

        if ($result !== 0) {
            Rmmigrate_Filesystem::delete($this->sql_path);
            return $this->fallback_php_dump($tables, 'data export failed for tables ' . ($index + 1) . '-' . $batch_end);
        }

        $this->sync_sql_bytes_written();
        $this->job->update_progress(array('database' => array(
            'mysqldump_table_index'     => $index + count($batch),
            'mysqldump_data_batch_size' => $batch_size,
            'mysqldump_data_mode'       => 'batched',
            'phase'                     => 'mysqldump',
        )));

        return false;
    }

    /**
     * @param string[] $tables
     */
    private function run_mysqldump_data_bulk(array $tables, int $total, int $budget_sec, int $data_start): bool
    {
        Rmmigrate_Logger::log(sprintf('mysqldump data pass for %d tables (bulk)', $total));
        $bulk_budget = max(1, min($budget_sec, self::MYSQLDUMP_DATA_BULK_BUDGET_SEC));
        $db_host = $this->parse_db_host();
        $result = $this->stream_mysqldump_pass(
            $this->get_mysqldump_path(),
            $db_host['host'],
            $db_host['port'],
            $db_host['socket'],
            $tables,
            array('--no-create-info', '--no-create-db', '--insert-ignore'),
            true,
            $bulk_budget
        );

        if ($result === 1) {
            if ($data_start > 0 && !self::realign_sql_file_to_checkpoint($this->sql_path, $data_start)) {
                Rmmigrate_Logger::log('mysqldump bulk: SQL realign failed after budget; falling back to PHP');
                return $this->fallback_php_dump($tables, 'sql realign failed after bulk data budget');
            }
            $this->sync_sql_bytes_written();
            Rmmigrate_Logger::log('mysqldump bulk data budget reached; switching to batched export');
            $this->job->update_progress(array('database' => array(
                'mysqldump_data_mode'      => 'batched',
                'mysqldump_data_batch_size' => $this->initial_mysqldump_data_batch_size($tables, 0),
            )));
            return false;
        }

        if ($result === 2) {
            return false;
        }

        if ($result !== 0) {
            if ($data_start > 0) {
                self::realign_sql_file_to_checkpoint($this->sql_path, $data_start);
            }
            return $this->fallback_php_dump($tables, 'bulk data export failed');
        }

        $this->sync_sql_bytes_written();
        $this->job->update_progress(array('database' => array(
            'mysqldump_table_index' => $total,
            'mysqldump_data_mode'   => 'bulk',
            'phase'                 => 'mysqldump',
        )));

        return false;
    }

    /**
     * @param string[] $tables
     */
    private function can_mysqldump_data_bulk(array $tables): bool
    {
        if (count($tables) > self::MYSQLDUMP_DATA_BULK_MAX_TABLES) {
            return false;
        }

        $db = $this->job->get_progress()['database'] ?? array();
        $estimated = (int) ($db['estimated_db_bytes'] ?? 0);

        if ($estimated <= 0) {
            return true;
        }

        return $estimated <= self::MYSQLDUMP_DATA_BULK_MAX_BYTES;
    }

    /**
     * @param string[] $tables
     */
    private function initial_mysqldump_data_batch_size(array $tables, int $index): int
    {
        $remaining = count($tables) - $index;
        if ($remaining <= 1) {
            return 1;
        }

        $db = $this->job->get_progress()['database'] ?? array();
        $estimated = (int) ($db['estimated_db_bytes'] ?? 0);
        $total_tables = count($tables);
        $avg_table_bytes = ($estimated > 0 && $total_tables > 0)
            ? (int) floor($estimated / $total_tables)
            : 0;

        if ($avg_table_bytes > 52428800) {
            return 1;
        }
        if ($avg_table_bytes > 10485760) {
            return min(5, $remaining);
        }
        if ($estimated > 536870912) {
            return min(15, $remaining);
        }

        return min(self::MYSQLDUMP_DATA_BATCH_DEFAULT, $remaining);
    }

    /**
     * Legacy per-table schema slices for in-flight jobs interrupted mid-schema.
     *
     * @param string[] $tables
     */
    private function run_mysqldump_schema_table_slice(array $tables, int $index, int $total, int $budget_sec): bool
    {
        $table = $tables[$index];
        Rmmigrate_Logger::log(
            sprintf('mysqldump schema pass for table %s (%d/%d)', $table, $index + 1, $total)
        );
        $slice_start = Rmmigrate_Filesystem::exists($this->sql_path)
            ? (int) Rmmigrate_Filesystem::filesize($this->sql_path)
            : 0;
        $db_host = $this->parse_db_host();
        $result = $this->stream_mysqldump_pass(
            $this->get_mysqldump_path(),
            $db_host['host'],
            $db_host['port'],
            $db_host['socket'],
            array($table),
            array('--no-data'),
            $index > 0,
            $budget_sec
        );
        if ($result === 1) {
            if (!self::realign_sql_file_to_checkpoint($this->sql_path, $slice_start)) {
                Rmmigrate_Logger::log(
                    sprintf('mysqldump schema: SQL realign failed for table %s; falling back to PHP', $table)
                );
                return $this->fallback_php_dump($tables, 'sql realign failed after schema budget');
            }
            $this->sync_sql_bytes_written();
            Rmmigrate_Runner::refresh_sql_lock($this->job->get_id());
            Rmmigrate_Logger::log(
                sprintf('mysqldump schema slice budget reached for table %s; resuming next worker.', $table)
            );
            return false;
        }
        if ($result === 2) {
            return false;
        }
        if ($result !== 0) {
            return $this->fallback_php_dump($tables, 'schema export failed for ' . $table);
        }
        $this->sync_sql_bytes_written();
        $this->job->update_progress(array('database' => array(
            'mysqldump_table_index' => $index + 1,
            'phase'                 => 'mysqldump',
        )));
        return false;
    }

    /**
     * @return array{host:string,port:?string,socket:?string}
     */
    private function parse_db_host(): array
    {
        $host = trim((string) DB_HOST);
        $port = null;
        $socket = null;
        if (preg_match('|^([^:]+):(\d+)$|', $host, $matches)) {
            $host = $matches[1];
            $port = $matches[2];
        } elseif (preg_match('|^([^:]+):(.+)$|', $host, $matches)) {
            $host = $matches[1];
            $socket = $matches[2];
        } elseif (preg_match('|^(.+\.sock)$|', $host)) {
            $socket = $host;
            $host = 'localhost';
        }
        return array('host' => $host, 'port' => $port, 'socket' => $socket);
    }

    /**
     * @return string|null Absolute path to a 0600 MySQL defaults-extra-file, or null on failure.
     */
    private function create_mysqldump_defaults_extra_file(): ?string
    {
        $dir = trailingslashit(get_temp_dir());
        if (!Rmmigrate_Filesystem::ensure_directory($dir)) {
            return null;
        }
        $path = $dir . 'mysqldump-' . wp_generate_password(12, false, false) . '.cnf';
        if ($path === '') {
            return null;
        }
        $escaped = str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) DB_PASSWORD);
        $content = "[client]\npassword=\"{$escaped}\"\n";
        if (Rmmigrate_Filesystem::put_contents($path, $content) === false) {
            Rmmigrate_Filesystem::delete($path);
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Temp cred file must be owner-only.
        if (!@chmod($path, 0600)) {
            Rmmigrate_Filesystem::delete($path);
            return null;
        }
        return $path;
    }

    private function delete_mysqldump_defaults_extra_file(?string $path): void
    {
        if ($path !== null && $path !== '' && Rmmigrate_Filesystem::exists($path)) {
            Rmmigrate_Filesystem::delete($path);
        }
    }

    /**
     * @param string[] $tables
     */
    private function validate_mysqldump_size(array $tables): bool
    {
        if (!Rmmigrate_Filesystem::exists($this->sql_path)) {
            return false;
        }
        $file_size = Rmmigrate_Filesystem::filesize($this->sql_path);
        $expected = $this->estimate_job_database_bytes($tables);
        if ($expected <= 0) {
            return $file_size >= 10;
        }
        $tolerance = max(
            self::MYSQLDUMP_SIZE_TOLERANCE,
            (int) round($expected * 0.15),
            1048576
        );

        return $file_size + $tolerance >= $expected;
    }

    /**
     * @param string[] $tables
     * @param string[] $extra_flags
     * @return int 0 success, 1 slice budget reached, 2 cancelled, -1 failure
     */
    private function stream_mysqldump_pass(string $bin, string $host, $port, ?string $socket, array $tables, array $extra_flags, bool $append, int $budget_sec, string $where = ''): int
    {
        $creds_file = $this->create_mysqldump_defaults_extra_file();
        if ($creds_file === null) {
            Rmmigrate_Logger::log('mysqldump failed: could not create credentials file');
            return -1;
        }

        try {
            $cmd = array_merge(
                array(
                    $bin,
                    '--defaults-extra-file=' . $creds_file,
                    '--no-tablespaces',
                    '--single-transaction',
                    '--quick',
                    '--skip-lock-tables',
                    '--extended-insert',
                    '--hex-blob',
                    '--skip-comments',
                    '--net_buffer_length=' . (string) self::insert_query_limit(),
                    '-h',
                    $host,
                    '-u',
                    DB_USER,
                ),
                $extra_flags
            );
            if ($socket !== null && $socket !== '') {
                $cmd[] = '-S';
                $cmd[] = $socket;
            } elseif ($port) {
                $cmd[] = '-P';
                $cmd[] = $port;
            }
            if ($where !== '' && count($tables) === 1) {
                $cmd[] = '--where=' . $where;
            }
            $cmd[] = DB_NAME;
            foreach ($tables as $table) {
                $cmd[] = $table;
            }

            $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = Rmmigrate_Process::open($cmd, $descriptors, $pipes, null, null, array('bypass_shell' => true));
        if (!is_resource($process)) {
            Rmmigrate_Logger::log('mysqldump failed: proc_open unavailable or disabled');
            return -1;
        }

        Rmmigrate_Filesystem::fclose_pipe($pipes[0]);

        $mode = $append ? 'a' : 'w';
        $fh = Rmmigrate_Filesystem::open($this->sql_path, $mode);
        if ($fh === false) {
            Rmmigrate_Filesystem::fclose_pipe($pipes[1]);
            Rmmigrate_Filesystem::fclose_pipe($pipes[2]);
            Rmmigrate_Process::close($process);
            Rmmigrate_Logger::log('mysqldump failed: could not open SQL export file for writing');
            return -1;
        }

        if (!$append) {
            if ($fh->write("-- Multisite Migrate SQL export (mysqldump)\n") === false) {
                Rmmigrate_Filesystem::fclose_pipe($pipes[1]);
                Rmmigrate_Filesystem::fclose_pipe($pipes[2]);
                Rmmigrate_Process::terminate($process, array($pipes[1], $pipes[2]));
                $fh->close();
                Rmmigrate_Process::close($process);
                Rmmigrate_Logger::log('mysqldump failed: could not write SQL export file');
                return -1;
            }
        } elseif ($fh->write("\n-- Multisite Migrate SQL data pass\n") === false) {
            Rmmigrate_Filesystem::fclose_pipe($pipes[1]);
            Rmmigrate_Filesystem::fclose_pipe($pipes[2]);
            Rmmigrate_Process::terminate($process, array($pipes[1], $pipes[2]));
            $fh->close();
            Rmmigrate_Process::close($process);
            Rmmigrate_Logger::log('mysqldump failed: could not append to SQL export file');
            return -1;
        }

        $line_buffer = '';
        $bytes_since_sync = 0;
        $deadline = microtime(true) + max(1, $budget_sec);
        $last_maintenance_at = 0.0;
        $stdout = $pipes[1];
        while (true) {
            if (microtime(true) >= $deadline) {
                Rmmigrate_Process::terminate($process, array($pipes[1], $pipes[2]));
                if ($bytes_since_sync > 0) {
                    $this->sync_sql_bytes_written();
                }
                $fh->close();
                Rmmigrate_Process::close($process);
                return 1;
            }

            $now = microtime(true);
            if ($now - $last_maintenance_at >= 2.0) {
                Rmmigrate_Runner::refresh_sql_lock($this->job->get_id());

                if ($this->job_is_cancelled()) {
                    if ($bytes_since_sync > 0) {
                        $this->sync_sql_bytes_written();
                    }
                    return $this->abort_mysqldump_pass($process, $pipes, $fh);
                }

                $last_maintenance_at = $now;
            }

            $read = array($stdout);
            $write = null;
            $except = null;
            $remaining = max(0.0, $deadline - microtime(true));
            $sec = (int) floor($remaining);
            $usec = (int) (($remaining - $sec) * 1000000);
            if ($sec === 0 && $usec < 100000) {
                $usec = 100000;
            }
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                $stderr = $this->drain_process_stderr($pipes[2] ?? null);
                $this->log_mysqldump_failure(
                    'stream_select',
                    -1,
                    $stderr
                );
                Rmmigrate_Process::terminate($process, array($pipes[1], $pipes[2]));
                if ($bytes_since_sync > 0) {
                    $this->sync_sql_bytes_written();
                }
                $fh->close();
                Rmmigrate_Filesystem::fclose_pipe($pipes[1]);
                if (is_resource($pipes[2])) {
                    Rmmigrate_Filesystem::fclose_pipe($pipes[2]);
                }
                Rmmigrate_Process::close($process);
                return -1;
            }
            if ($ready === 0) {
                if (feof($stdout)) {
                    break;
                }
                continue;
            }

            $chunk = Rmmigrate_Filesystem::fread_pipe($stdout, 8192);
            if (!is_string($chunk) || $chunk === '') {
                if (feof($stdout)) {
                    break;
                }
                continue;
            }
            $line_buffer .= $chunk;
            while (($pos = strpos($line_buffer, "\n")) !== false) {
                $line = substr($line_buffer, 0, $pos);
                $line_buffer = substr($line_buffer, $pos + 1);
                $fixed = self::apply_mysqldump_line_fixes($line);
                if ($fixed !== '') {
                    $payload = $fixed . "\n";
                    if ($fh->write($payload) === false) {
                        Rmmigrate_Process::terminate($process, array($pipes[1], $pipes[2]));
                        if ($bytes_since_sync > 0) {
                            $this->sync_sql_bytes_written();
                        }
                        $fh->close();
                        Rmmigrate_Process::close($process);
                        if ($this->job_is_cancelled()) {
                            return 2;
                        }
                        Rmmigrate_Logger::log('mysqldump failed: could not write SQL export chunk');
                        return -1;
                    }
                    $bytes_since_sync += strlen($payload);
                    if ($bytes_since_sync >= self::PROGRESS_BYTES_INTERVAL) {
                        $this->sync_sql_bytes_written();
                        $bytes_since_sync = 0;
                    }
                }
            }
        }
        if ($line_buffer !== '') {
            $fixed = self::apply_mysqldump_line_fixes($line_buffer);
            if ($fixed !== '') {
                $payload = $fixed . "\n";
                if ($fh->write($payload) === false) {
                    Rmmigrate_Process::terminate($process, array($pipes[1], $pipes[2]));
                    $fh->close();
                    Rmmigrate_Process::close($process);
                    if ($this->job_is_cancelled()) {
                        return 2;
                    }
                    Rmmigrate_Logger::log('mysqldump failed: could not write SQL export tail');
                    return -1;
                }
                $bytes_since_sync += strlen($payload);
            }
        }
        if ($bytes_since_sync > 0) {
            $this->sync_sql_bytes_written();
        }
        $fh->close();

        $stderr = $this->drain_process_stderr($pipes[2] ?? null);
        Rmmigrate_Filesystem::fclose_pipe($pipes[1]);
        if (is_resource($pipes[2])) {
            Rmmigrate_Filesystem::fclose_pipe($pipes[2]);
        }
        $code = Rmmigrate_Process::close($process);

        if ($code !== 0) {
            $this->log_mysqldump_failure('process_exit', $code, $stderr);
            return -1;
        }

        return 0;
        } finally {
            $this->delete_mysqldump_defaults_extra_file($creds_file);
        }
    }

    /**
     * @param resource|null $pipe
     */
    private function drain_process_stderr($pipe): string
    {
        if (!is_resource($pipe)) {
            return '';
        }

        @stream_set_blocking($pipe, false);
        $stderr = '';
        while (true) {
            $chunk = Rmmigrate_Filesystem::fread_pipe($pipe, 4096);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $stderr .= $chunk;
            if (strlen($stderr) > 2048) {
                $stderr = substr($stderr, 0, 2048);
                break;
            }
        }

        return trim($stderr);
    }

    private function log_mysqldump_failure(string $context, int $exit_code, string $stderr): void
    {
        $stderr_line = $stderr !== '' ? preg_replace('/\s+/', ' ', $stderr) : '';
        if (is_string($stderr_line) && strlen($stderr_line) > 500) {
            $stderr_line = substr($stderr_line, 0, 500) . '…';
        }

        $message = sprintf('mysqldump failed (%s): exit code %d', $context, $exit_code);
        if ($stderr_line !== '') {
            $message .= '; stderr: ' . $stderr_line;
        }

        Rmmigrate_Logger::log($message);
    }

    /**
     * @param string[] $tables
     */
    private function fallback_php_dump(array $tables, string $reason = ''): bool
    {
        $message = 'mysqldump failed, falling back to PHP';
        if ($reason !== '') {
            $message .= ' (' . $reason . ')';
        }
        Rmmigrate_Logger::log($message);
        if (Rmmigrate_Filesystem::exists($this->sql_path)) {
            Rmmigrate_Filesystem::delete($this->sql_path);
        }
        $meta_path = $this->table_meta_file_path();
        if (Rmmigrate_Filesystem::exists($meta_path)) {
            Rmmigrate_Filesystem::delete($meta_path);
        }
        $this->job->replace_progress_section('database', $this->fresh_database_progress($tables, 'php'));
        if (!$this->run_php_creates(Rmmigrate_Hosting_Detection::worker_budget_sec())) {
            return false;
        }
        $this->job->update_progress(array('database' => array('phase' => 'inserts', 'table_index' => 0, 'pk_offset' => null, 'table_rows_done' => 0, 'create_index' => 0)));
        return false;
    }

    /**
     * Keep schema + completed mysqldump data; dump remaining tables with PHP inserts.
     *
     * Used when a single table cannot finish inside the worker budget (mysqldump
     * has no mid-table resume). Avoids infinite truncate/retry on the same table.
     *
     * @param string[] $tables
     */
    private function switch_remaining_mysqldump_data_to_php(array $tables, int $index, string $reason): bool
    {
        Rmmigrate_Logger::log(
            sprintf(
                'mysqldump data handoff to PHP inserts at table %d of %d (%s)',
                $index + 1,
                count($tables),
                $reason
            )
        );

        $this->ensure_php_session_timeouts();
        $this->sync_sql_bytes_written();
        $bytes = Rmmigrate_Filesystem::exists($this->sql_path)
            ? (int) Rmmigrate_Filesystem::filesize($this->sql_path)
            : 0;

        $this->job->update_progress(array('database' => array(
            'mode'                   => 'php',
            'phase'                  => 'inserts',
            'tables'                 => $tables,
            'table_index'            => max(0, $index),
            'pk_offset'              => null,
            'table_rows_done'        => 0,
            'rows_done'              => 0,
            'insert_incomplete'      => false,
            'sql_bytes_written'      => $bytes,
            'mysqldump_hybrid_php'   => true,
            'mysqldump_phase'        => 'data',
            'mysqldump_table_index'  => $index,
        )));

        return false;
    }

    /**
     * Group INSERT value tuples into byte-limited batches (mysqldump query limit pattern).
     *
     * @param string[] $value_tuples
     * @return string[][]
     */
    public static function batch_insert_value_groups(array $value_tuples, int $max_bytes): array
    {
        $groups = array();
        $current = array();
        $current_size = 0;

        foreach ($value_tuples as $tuple) {
            $val_len = strlen($tuple);
            if (!empty($current) && ($current_size + $val_len + 2) > $max_bytes) {
                $groups[] = $current;
                $current = array();
                $current_size = 0;
            }
            $current[] = $tuple;
            $current_size += $val_len + 2;
        }

        if ($current !== array()) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function run_php_creates(int $budget_sec): bool
    {
        $db = $this->job->get_progress()['database'] ?? array();
        $tables = $db['tables'] ?? array();
        $create_index = (int) ($db['create_index'] ?? 0);
        $total = count($tables);
        $start = microtime(true);

        $mode = $create_index === 0 ? 'w' : 'a';
        $fh = Rmmigrate_Filesystem::open($this->sql_path, $mode);
        if ($fh === false) {
            return false;
        }

        if ($create_index === 0) {
            $fh->write("-- Multisite Migrate SQL export\nSET NAMES utf8mb4;\nSET foreign_key_checks = 0;\n\n");
        }

        while ($create_index < $total && (microtime(true) - $start) < $budget_sec) {
            Rmmigrate_Runner::refresh_sql_lock($this->job->get_id());
            $table = $tables[$create_index];
            $quoted_table = Rmmigrate_Snap_DB::quote_identifier($table);
            $create = Rmmigrate_Snap_DB::get_show_create_table_row($table);
            if ($create && isset($create[1])) {
                $fh->write('DROP TABLE IF EXISTS ' . $quoted_table . ";\n" . $create[1] . ";\n\n");
            }
            $create_index++;
        }

        $fh->close();
        $this->sync_sql_bytes_written();
        $this->job->update_progress(array('database' => array(
            'create_index' => $create_index,
            'phase'        => 'creates',
        )));

        return $create_index >= $total;
    }

    private function run_php_inserts_batch(?int $budget_sec = null): bool
    {
        global $wpdb;

        $db = $this->job->get_progress()['database'] ?? array();
        $tables = $db['tables'] ?? array();
        $table_index = (int) ($db['table_index'] ?? 0);
        $pk_offset = $db['pk_offset'] ?? null;

        if ($table_index >= count($tables)) {
            return true;
        }

        $this->realign_sql_file_for_progress((int) ($db['sql_bytes_written'] ?? 0));

        $table = $tables[$table_index];
        $quoted_table = Rmmigrate_Snap_DB::quote_identifier($table);
        $fh = Rmmigrate_Filesystem::open($this->sql_path, 'a');
        if ($fh === false) {
            return false;
        }

        $table_meta = $this->load_table_meta($table);
        $pk_cols = $table_meta['pk_cols'] ?? array();
        $col_types = $table_meta['col_types'] ?? array();
        $rows_done = (int) ($db['rows_done'] ?? 0);
        $table_rows_done = (int) ($db['table_rows_done'] ?? 0);
        $max_query_size = self::insert_query_limit();
        
        // Calculate exact free memory to adapt batch size (Target 10% of free memory, max 4MB)
        $mem_limit_str = ini_get('memory_limit');
        $mem_limit = ($mem_limit_str === '-1' || !$mem_limit_str) ? (256 * 1024 * 1024) : wp_convert_hr_to_bytes($mem_limit_str);
        $mem_usage = memory_get_usage(true);
        $free_memory = max(1024 * 1024, $mem_limit - $mem_usage); // Assume at least 1MB free to avoid division by zero
        $target_batch_bytes = min(4 * 1024 * 1024, (int) floor($free_memory * 0.10));
        
        $avg_row_length = max(1, (int) ($table_meta['avg_row_length'] ?? 1024));
        // Scale batch size dynamically up to 5000 rows, strictly bound by the 4MB / 10% free memory budget
        $batch_size = max(1, min(5000, (int) floor($target_batch_bytes / $avg_row_length)));

        // Keyset pagination for any primary key; offset fallback only when no PK exists.
        $use_pk_pagination = !empty($pk_cols);

        if ($use_pk_pagination) {
            $where = '';
            // Scalar/corrupt pk_offset must not build WHERE id > '' (rescans + inflates counters).
            if ($pk_offset !== null && $pk_offset !== '') {
                if (!is_array($pk_offset) || $pk_offset === array()) {
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(
                        'pk_cursor_invalid',
                        esc_html(
                            sprintf(
                                /* translators: %s: database table name */
                                __('Database export lost primary-key position for table %s. Retry the backup.', 'rosenheinrich-multisite-migrate'),
                                $table
                            )
                        )
                    );
                }
                $where = Rmmigrate_Snap_DB::build_pk_where($pk_cols, $pk_offset, $col_types);
                if ($where === '') {
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(
                        'pk_cursor_invalid',
                        esc_html(
                            sprintf(
                                /* translators: %s: database table name */
                                __('Database export lost primary-key position for table %s. Retry the backup.', 'rosenheinrich-multisite-migrate'),
                                $table
                            )
                        )
                    );
                }
            }
            $order_cols = implode(',', array_map(array('Rmmigrate_Snap_DB', 'quote_identifier'), $pk_cols));
            $rev = $this->revision_exclude_sql_clause($table);
            if ($rev !== '') {
                if ($where === '') {
                    $where = 'WHERE (' . $rev . ')';
                } else {
                    $where .= ' AND (' . $rev . ')';
                }
            }
            $sql = 'SELECT * FROM ' . $quoted_table . ($where !== '' ? ' ' . $where : '') . ' ORDER BY ' . $order_cols . ' ASC LIMIT ' . (int) $batch_size;
        } else {
            // Tables without PKs use integer offset pagination.
            $offset = (int) $table_rows_done;
            $rev = $this->revision_exclude_sql_clause($table);
            $rev_sql = $rev !== '' ? ' WHERE (' . $rev . ')' : '';
            // Prefer scalar/indexed cols; TEXT/BLOB in ORDER BY is expensive and can fail.
            // No-PK + concurrent writes can still skip/dupe rows — PK tables are safer.
            $order_cols = implode(
                ',',
                array_map(array('Rmmigrate_Snap_DB', 'quote_identifier'), Rmmigrate_Snap_DB::orderable_column_names($col_types))
            );
            if ($order_cols !== '') {
                $sql = 'SELECT * FROM ' . $quoted_table . $rev_sql . ' ORDER BY ' . $order_cols . ' ASC LIMIT ' . $offset . ', ' . (int) $batch_size;
            } else {
                $sql = 'SELECT * FROM ' . $quoted_table . $rev_sql . ' LIMIT ' . $offset . ', ' . (int) $batch_size;
            }
        }

        if ($table_rows_done === 0) {
            Rmmigrate_Logger::log(sprintf('DB export: table %s', $table));
        }

        $result = Rmmigrate_Snap_DB::unbuffered_query($sql);
        if ($result === false) {
            // Missing/ghost table (or unreadable): skip once — do not spin on the same table_index.
            $fh->close();
            $this->sync_sql_bytes_written();
            $this->remove_table_meta_entry($table);
            Rmmigrate_Logger::log(sprintf('DB export: skipping unreadable table %s', $table));
            $this->job->update_progress(array(
                'database' => array(
                    'table_index'       => $table_index + 1,
                    'pk_offset'         => null,
                    'rows_done'         => $rows_done,
                    'table_rows_done'   => 0,
                    'insert_incomplete' => false,
                ),
            ));
            return $table_index + 1 >= count($tables);
        }

        $buffer = '';
        $cols = array();
        $all_values = array();
        $current_query_size = 0;
        $budget_sec = $budget_sec ?? Rmmigrate_Hosting_Detection::worker_budget_sec();
        $start_time = microtime(true);
        $has_rows = false;
        $current_sql_bytes = (int) ($db['sql_bytes_written'] ?? 0);

        while ($row = Rmmigrate_Snap_DB::fetch_assoc($result)) {
            $has_rows = true;
            if (empty($cols)) {
                $cols = array_keys($row);
            }

            foreach ($row as $col => $val) {
                $type = $col_types[$col] ?? '';
                if (self::row_value_exceeds_php_budget($val, $type)) {
                    Rmmigrate_Snap_DB::free_result($result);
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(
                        sanitize_key(Rmmigrate_Error_Codes::ROW_TOO_LARGE),
                        esc_html(
                            sprintf(
                                /* translators: 1: table name, 2: column name. */
                                __('Database row too large for PHP export in %1$s.%2$s. Switch to Settings → Database → mysqldump, raise memory_limit, or exclude this table.', 'rosenheinrich-multisite-migrate'),
                                $table,
                                $col
                            )
                        )
                    );
                }
            }

            $values = array();
            foreach ($row as $col => $val) {
                $type = $col_types[$col] ?? '';
                $values[] = Rmmigrate_Snap_DB::sql_value($val, $type);
            }
            $val_str = '(' . implode(',', $values) . ')';
            $val_len = strlen($val_str);

            if (!empty($all_values) && ($current_query_size + $val_len + 2) > $max_query_size) {
                $buffer .= 'INSERT IGNORE INTO ' . $quoted_table . ' (`' . implode('`,`', $cols) . '`) VALUES ' . implode(',', $all_values) . ";\n";
                $fh->write($buffer);
                $current_sql_bytes += strlen($buffer);
                $buffer = '';
                $all_values = array();
                $current_query_size = 0;
            }

            $all_values[] = $val_str;
            $current_query_size += $val_len + 2;

            $rows_done++;
            $table_rows_done++;
            if ($use_pk_pagination) {
                $pk_offset = Rmmigrate_Snap_DB::extract_pk_offset($row, $pk_cols, $col_types);
                foreach ($pk_cols as $pk_col) {
                    if ($pk_offset[$pk_col] === null) {
                        Rmmigrate_Snap_DB::free_result($result);
                        $fh->close();
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        throw Rmmigrate_Job_Exception::raise(
                            'pk_cursor_invalid',
                            esc_html(
                                sprintf(
                                    /* translators: %s: database table name */
                                    __('Database export lost primary-key position for table %s. Retry the backup.', 'rosenheinrich-multisite-migrate'),
                                    $table
                                )
                            )
                        );
                    }
                }
            } else {
                $pk_offset = null;
            }

            if ($rows_done % self::PROGRESS_ROWS_INTERVAL === 0) {
                // Force flush before checkpointing to ensure file size is accurate on disk
                if (!empty($all_values)) {
                    $buffer .= 'INSERT IGNORE INTO ' . $quoted_table . ' (`' . implode('`,`', $cols) . '`) VALUES ' . implode(',', $all_values) . ";\n";
                    $all_values = array();
                    $current_query_size = 0;
                }
                if ($buffer !== '') {
                    $fh->write($buffer);
                    $current_sql_bytes += strlen($buffer);
                    $buffer = '';
                }

                // Unbuffered MYSQLI_USE_RESULT blocks other queries on the same connection.
                Rmmigrate_Snap_DB::free_result($result);
                $this->job->update_progress(array('database' => array(
                    'sql_bytes_written' => $current_sql_bytes,
                    'pk_offset'         => $pk_offset,
                    'rows_done'         => $rows_done,
                    'table_rows_done'   => $table_rows_done,
                )));
                $this->backup_db_progress();
                $fh->close();
                $this->sync_sql_bytes_written();
                return false;
            }

            Rmmigrate_Engine_Config::apply_throttle();

            if (microtime(true) - $start_time >= $budget_sec) {
                break;
            }
        }

        Rmmigrate_Snap_DB::free_result($result);

        if (!empty($all_values)) {
            $buffer .= 'INSERT IGNORE INTO ' . $quoted_table . ' (`' . implode('`,`', $cols) . '`) VALUES ' . implode(',', $all_values) . ";\n";
        }
        if ($buffer !== '') {
            $fh->write($buffer);
        }

        if (!$has_rows) {
            $fh->close();
            $this->sync_sql_bytes_written();
            $this->remove_table_meta_entry($table);
            $this->job->update_progress(array(
                'database' => array(
                    'table_index'       => $table_index + 1,
                    'pk_offset'         => null,
                    'rows_done'         => $rows_done,
                    'table_rows_done'   => 0,
                    'insert_incomplete' => false,
                ),
            ));
            return $table_index + 1 >= count($tables);
        }

        $fh->close();
        $this->sync_sql_bytes_written();

        $this->job->update_progress(array(
            'database' => array(
                'table_index'       => $table_index,
                'pk_offset'         => $pk_offset,
                'rows_done'         => $rows_done,
                'table_rows_done'   => $table_rows_done,
                'insert_incomplete' => false,
            ),
        ));
        $this->backup_db_progress();

        return false;
    }

    /**
     * @return array{pk_cols:string[],col_types:array<string,string>,avg_row_length?:int}
     */
    private function load_table_meta(string $table): array
    {
        $meta = $this->load_table_meta_file();
        $entry = $meta[$table] ?? array();
        $changed = false;
        if (empty($entry['pk_cols'])) {
            $entry['pk_cols'] = Rmmigrate_Snap_DB::get_primary_key_columns($table);
            $changed = true;
        }
        if (empty($entry['col_types'])) {
            $entry['col_types'] = Rmmigrate_Snap_DB::get_column_types($table);
            $changed = true;
        }
        if (!isset($entry['avg_row_length'])) {
            global $wpdb;
            $status = $wpdb->get_row(
                $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
                ARRAY_A
            );
            $entry['avg_row_length'] = (int) ($status['Avg_row_length'] ?? 1024);
            $changed = true;
        }
        if ($changed) {
            $meta[$table] = $entry;
            $this->save_table_meta_file($meta);
        }
        return $entry;
    }

    private function remove_table_meta_entry(string $table): void
    {
        $meta = $this->load_table_meta_file();
        if (!isset($meta[$table])) {
            return;
        }
        unset($meta[$table]);
        $this->save_table_meta_file($meta);
    }

    private function table_meta_file_path(): string
    {
        return trailingslashit($this->job->get_work_dir()) . self::TABLE_META_FILE;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function load_table_meta_file(): array
    {
        $path = $this->table_meta_file_path();
        if (!Rmmigrate_Filesystem::exists($path)) {
            return array();
        }
        $raw = Rmmigrate_Filesystem::get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string,array<string,mixed>> $meta
     */
    private function save_table_meta_file(array $meta): void
    {
        RMMIGRATE_IO::write_json_atomic($this->table_meta_file_path(), $meta);
    }

    private function migrate_table_meta_from_progress(): void
    {
        $db = $this->job->get_progress()['database'] ?? array();
        $inline = $db['table_meta'] ?? array();
        if ($inline === array()) {
            return;
        }
        $existing = $this->load_table_meta_file();
        $this->save_table_meta_file(array_replace_recursive($existing, $inline));
        unset($db['table_meta']);
        $this->job->replace_progress_section('database', $db);
    }

    /**
     * @param string[] $tables
     * @return array<string,mixed>
     */
    private function fresh_database_progress(array $tables, string $mode, string $phase = 'creates'): array
    {
        if ($phase === 'creates' && $mode === 'mysqldump') {
            $phase = 'mysqldump';
        }

        $exclude_revisions = $this->should_exclude_revisions();

        return array(
            'mode'                  => $mode,
            'phase'                 => $phase,
            'tables'                => $tables,
            'table_index'           => 0,
            'pk_offset'             => null,
            'rows_done'             => 0,
            'table_rows_done'       => 0,
            'sql_bytes_written'     => 0,
            'estimated_db_bytes'    => self::estimate_database_bytes($tables, $exclude_revisions),
            'estimated_db_bytes_excludes_revisions' => $exclude_revisions ? 1 : 0,
            'insert_incomplete'     => false,
            'mysqldump_phase'       => 'schema',
            'mysqldump_table_index' => 0,
        );
    }

    /**
     * @param array<string,mixed> $db
     * @return array<string,mixed>
     */
    private function ensure_database_progress_defaults(array $db): array
    {
        if (empty($db['tables'])) {
            return $db;
        }

        $tables = $db['tables'];
        $mode = !empty($db['mode']) ? (string) $db['mode'] : $this->detect_mode($tables);
        $defaults = $this->fresh_database_progress($tables, $mode);

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $db)) {
                $db[$key] = $value;
            }
        }

        if (empty($db['mode'])) {
            $db['mode'] = $mode;
        }

        if ($mode === 'mysqldump' && ($db['phase'] ?? '') === 'creates') {
            $db['phase'] = 'mysqldump';
        }

        $exclude_revisions = $this->should_exclude_revisions();
        $needs_revision_adjusted_estimate = $exclude_revisions && empty($db['estimated_db_bytes_excludes_revisions']);
        if (empty($db['estimated_db_bytes']) || $needs_revision_adjusted_estimate) {
            $db['estimated_db_bytes'] = self::estimate_database_bytes($tables, $exclude_revisions);
            $db['estimated_db_bytes_excludes_revisions'] = $exclude_revisions ? 1 : 0;
        }

        return $db;
    }

    /**
     * Monotonic resume ordering for database slice checkpoints.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public static function compare_db_progress_slice(array $a, array $b): int
    {
        foreach (array('sql_bytes_written', 'table_index', 'rows_done', 'table_rows_done') as $key) {
            $left = (int) ($a[$key] ?? 0);
            $right = (int) ($b[$key] ?? 0);
            if ($left !== $right) {
                return $left <=> $right;
            }
        }
        return 0;
    }

    /**
     * @param array<string,mixed> $job_db
     * @param array<string,mixed> $backup
     */
    public static function merge_db_progress(array $job_db, array $backup, string $default_mode = 'php'): array
    {
        if (empty($backup['mode'])) {
            $backup['mode'] = $default_mode;
        }

        $job_incomplete = empty($job_db['tables']) || !isset($job_db['phase']) || empty($job_db['mode']);
        if ($job_incomplete) {
            return $backup;
        }

        if (self::compare_db_progress_slice($backup, $job_db) > 0) {
            $merged = array_replace_recursive($job_db, $backup);
            // pk_offset must replace wholesale — recursive merge can leave stale composite keys.
            if (array_key_exists('pk_offset', $backup)) {
                $merged['pk_offset'] = $backup['pk_offset'];
            }
            return $merged;
        }

        $merged = array_replace_recursive($backup, $job_db);
        if (array_key_exists('pk_offset', $job_db)) {
            $merged['pk_offset'] = $job_db['pk_offset'];
        }
        return $merged;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_db_progress_backup(): ?array
    {
        $dir = $this->job->get_work_dir();
        $primary = trailingslashit($dir) . self::PROGRESS_BACKUP_FILE;
        $fallback = $primary . '.old';
        foreach (array($primary, $fallback) as $path) {
            if (!Rmmigrate_Filesystem::exists($path)) {
                continue;
            }
            $raw = Rmmigrate_Filesystem::get_contents($path);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['tables'])) {
                return $decoded;
            }
        }

        return null;
    }

    private function backup_db_progress(): void
    {
        $progress = $this->job->get_progress();
        if (empty($progress['database'])) {
            return;
        }
        $snapshot = $progress['database'];
        unset($snapshot['table_meta']);
        $path = trailingslashit($this->job->get_work_dir()) . self::PROGRESS_BACKUP_FILE;
        $backup = $path . '.old';
        if (Rmmigrate_Filesystem::exists($path)) {
            Rmmigrate_Filesystem::copy($path, $backup);
        }
        RMMIGRATE_IO::write_json_atomic($path, $snapshot);
    }

    /**
     * Remove on-disk DB export checkpoints so a new backup cannot inherit stale slice state.
     */
    public static function clear_export_checkpoint_files(string $work_dir): void
    {
        $dir = trailingslashit($work_dir);
        foreach (array(
            self::PROGRESS_BACKUP_FILE,
            self::PROGRESS_BACKUP_FILE . '.old',
            self::TABLE_META_FILE,
            'database.sql',
        ) as $file) {
            $path = $dir . $file;
            if (Rmmigrate_Filesystem::exists($path)) {
                Rmmigrate_Filesystem::delete($path);
            }
        }
    }

    /**
     * Restore database slice progress from on-disk backup when job row is empty or corrupt.
     */
    private function restore_db_progress_from_backup(): void
    {
        $job_db = $this->job->get_progress()['database'] ?? array();
        $backup = $this->load_db_progress_backup();
        if ($backup === null) {
            return;
        }

        unset($backup['table_meta']);

        $job_has_slice = !empty($job_db['tables']) && isset($job_db['phase']) && !empty($job_db['mode']);
        if ($job_has_slice && self::compare_db_progress_slice($backup, $job_db) <= 0) {
            return;
        }

        $tables = $backup['tables'] ?? $job_db['tables'] ?? self::order_export_tables($this->scope->get_tables());
        $default_mode = $this->detect_mode($tables);
        $merged = self::merge_db_progress($job_db, $backup, $default_mode);
        unset($merged['table_meta']);

        if (wp_json_encode($merged) === wp_json_encode($job_db)) {
            return;
        }

        $this->job->update_progress(array('database' => $merged));
    }

    /**
     * Truncate SQL export file to last known good byte size on resume.
     */
    public static function realign_sql_file_to_checkpoint(string $sql_path, int $expected_bytes): bool
    {
        if ($expected_bytes < 0) {
            return true;
        }
        if (!file_exists($sql_path)) {
            if ($expected_bytes !== 0) {
                Rmmigrate_Logger::log(
                    'SQL checkpoint realignment failed: file missing with expected_bytes='
                    . (string) $expected_bytes
                );
            }
            return $expected_bytes === 0;
        }
        $current = (int) filesize($sql_path);
        if ($current === $expected_bytes) {
            return true;
        }
        if ($current < $expected_bytes) {
            return false;
        }
        $fh = Rmmigrate_Filesystem::open($sql_path, 'r+');
        if ($fh === false) {
            return false;
        }
        $truncated = $fh->truncate($expected_bytes);
        $fh->close();
        if (!$truncated) {
            return false;
        }
        clearstatcache(true, $sql_path);
        return true;
    }

    private function realign_sql_file_for_progress(int $expected_bytes): void
    {
        if ($expected_bytes <= 0) {
            return;
        }
        if (!self::realign_sql_file_to_checkpoint($this->sql_path, $expected_bytes)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(
                'sql_checkpoint_realign',
                esc_html__('Database export could not realign the SQL file to the last checkpoint. Retry the backup.', 'rosenheinrich-multisite-migrate')
            );
        }
    }

    private function sync_sql_bytes_written(): void
    {
        if (!Rmmigrate_Filesystem::exists($this->sql_path)) {
            return;
        }
        $size = Rmmigrate_Filesystem::filesize($this->sql_path);
        $this->job->update_progress(array('database' => array('sql_bytes_written' => $size)));
    }

    private function job_is_cancelled(): bool
    {
        $job = Rmmigrate_Job::get($this->job->get_id());
        return $job === null || $job->get_status() === Rmmigrate_Job::STATUS_CANCELLED;
    }

    /**
     * @param resource $process
     * @param array<int,resource> $pipes
     */
    private function abort_mysqldump_pass($process, array $pipes, Rmmigrate_Filesystem_Stream $fh): int
    {
        Rmmigrate_Process::terminate($process, array($pipes[1], $pipes[2] ?? null));
        $fh->close();
        Rmmigrate_Process::close($process);
        return 2;
    }

    private function append_eof_marker(): void
    {
        Rmmigrate_Filesystem::put_contents($this->sql_path, "\n-- " . RMMIGRATE_DB_EOF . "\n", FILE_APPEND);
    }

    private function should_exclude_revisions(): bool
    {
        $progress = $this->job->get_progress();

        return !empty($progress['exclude_revisions']);
    }

    /**
     * SQL predicate (no WHERE keyword) to skip revisions / revision postmeta.
     */
    private function revision_exclude_sql_clause(string $table): string
    {
        if (!$this->should_exclude_revisions()) {
            return '';
        }
        global $wpdb;
        // Match only <base_prefix>[<blog_id>_]posts / postmeta.
        if (!preg_match('/^' . preg_quote($wpdb->base_prefix, '/') . '(?:\d+_)?(posts|postmeta)$/', $table, $m)) {
            return '';
        }
        $base = $m[1];
        if ($base === 'posts') {
            return "`post_type` <> 'revision'";
        }
        if ($base === 'postmeta') {
            $posts = preg_replace('/postmeta$/', 'posts', $table);
            $posts_q = Rmmigrate_Snap_DB::quote_identifier($posts);

            return '`post_id` NOT IN (SELECT `ID` FROM ' . $posts_q . " WHERE `post_type` = 'revision')";
        }

        return '';
    }
}
