<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Post-import batch scan on site tables; identifiers validated via quote_identifier.

/**
 * Table scan for URL/domain replacement after SQL import (serialized-safe fallback).
 */
class Rmmigrate_Post_Import_Search_Replace
{
    const BATCH_SIZE = 200;

    /** @var Rmmigrate_Job */
    private $job;

    /** @var Rmmigrate_Search_Replace */
    private $engine;

    /**
     * @var array<int,array{table:string,columns:string[],id_col:string}>
     */
    private $targets = array();

    public function __construct(Rmmigrate_Job $job)
    {
        $this->job = $job;
        $this->engine = new Rmmigrate_Search_Replace($job->get_migration_map());
        $this->targets = $this->build_targets();
    }

    public function run_slice(int $budget_sec): bool
    {
        if (!Rmmigrate_Engine_Config::post_import_search_replace()) {
            return true;
        }
        if ($this->targets === array()) {
            return true;
        }

        global $wpdb;
        $progress = $this->job->get_progress()['post_import_sr'] ?? array();
        $target_index = (int) ($progress['target_index'] ?? 0);
        $row_offset = (int) ($progress['row_offset'] ?? 0);
        $start = microtime(true);
        $updated = 0;
        $first_batch = true;

        while (($first_batch || (microtime(true) - $start) < $budget_sec) && $target_index < count($this->targets)) {
            $first_batch = false;
            $target = $this->targets[$target_index];
            $table = $target['table'];
            $id_col = $target['id_col'];
            Rmmigrate_Snap_DB::quote_identifier($table);
            Rmmigrate_Snap_DB::quote_identifier($id_col);
            $select_names = array_values(array_unique(array_merge(array($id_col), $target['columns'])));
            $cache_parent_col = $this->cache_parent_column_for_table($table);
            if ($cache_parent_col !== null && !in_array($cache_parent_col, $select_names, true)) {
                $select_names[] = $cache_parent_col;
            }
            foreach ($select_names as $select_name) {
                Rmmigrate_Snap_DB::quote_identifier($select_name);
            }
            $last_id = max(0, $row_offset);
            $select_ph = implode(', ', array_fill(0, count($select_names), '%i'));
            $prepare_args = array_merge(
                $select_names,
                array($table, $id_col, $last_id, $id_col, self::BATCH_SIZE)
            );
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin: dynamic %i column list; identifiers validated via quote_identifier(); remaining args prepared.
            $sql = $wpdb->prepare(
                'SELECT ' . $select_ph . ' FROM %i WHERE %i > %d ORDER BY %i LIMIT %d',
                ...$prepare_args
            );
            $rows = $wpdb->get_results($sql, ARRAY_A);
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ($wpdb->last_error !== '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                throw Rmmigrate_Job_Exception::raise(
                    sanitize_key(Rmmigrate_Error_Codes::SQL_IMPORT_FAILED),
                    esc_html($wpdb->last_error)
                );
            }
            if (empty($rows)) {
                $target_index++;
                $row_offset = 0;
                continue;
            }

            foreach ($rows as $row) {
                $id = $row[$id_col] ?? null;
                if ($id === null) {
                    continue;
                }
                $fields = array();
                foreach ($target['columns'] as $column) {
                    if (!array_key_exists($column, $row) || !is_string($row[$column]) || $row[$column] === '') {
                        continue;
                    }
                    $replaced = $this->engine->apply($row[$column]);
                    if ($replaced !== $row[$column]) {
                        $fields[$column] = $replaced;
                    }
                }
                if ($fields !== array()) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
                    $result = $wpdb->update($table, $fields, array($id_col => $id));
                    if ($result === false) {
                        Rmmigrate_Logger::log(
                            'Post-import search-replace update failed on ' . $table . ' #' . (string) $id
                            . ': ' . $wpdb->last_error
                        );
                        continue;
                    }
                    $this->invalidate_updated_row_cache($table, $row);
                    $updated++;
                }
            }

            $row_offset = (int) ($rows[count($rows) - 1][$id_col] ?? 0);
            if (count($rows) < self::BATCH_SIZE) {
                $target_index++;
                $row_offset = 0;
            }
        }

        $done = $target_index >= count($this->targets);
        $this->job->update_progress(array(
            'post_import_sr' => array(
                'target_index'  => $target_index,
                'row_offset'    => $row_offset,
                'updated'       => (int) ($progress['updated'] ?? 0) + $updated,
                'target_total'  => count($this->targets),
                'done'          => $done,
            ),
        ));

        return $done;
    }

    /**
     * @return array<int,array{table:string,columns:string[],id_col:string}>
     */
    private function build_targets(): array
    {
        global $wpdb;
        $prefixes = $this->table_prefixes();
        $candidates = array();
        foreach ($prefixes as $prefix) {
            if (!$this->is_valid_table_prefix($prefix)) {
                continue;
            }
            $candidates = array_merge($candidates, $this->prefix_targets($prefix));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables.
        $existing_tables = $wpdb->get_col('SHOW TABLES');
        $table_set = array();
        if (is_array($existing_tables)) {
            foreach ($existing_tables as $table_name) {
                $table_set[(string) $table_name] = true;
            }
        }

        $out = array();
        foreach ($candidates as $candidate) {
            if (isset($table_set[$candidate['table']])) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private function table_prefixes(): array
    {
        $manifest = $this->job->get_progress()['manifest'] ?? null;
        if (!is_array($manifest)) {
            $path = trailingslashit($this->job->get_work_dir()) . 'extracted/manifest.json';
            if (Rmmigrate_Filesystem::exists($path)) {
                $json = Rmmigrate_Filesystem::get_contents($path);
                $manifest = is_string($json) ? json_decode($json, true) : null;
            }
        }
        if (is_array($manifest) && Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            return array_values(array_filter(
                Rmmigrate_Restore_Topology_Manifest::table_prefixes($manifest),
                array($this, 'is_valid_table_prefix')
            ));
        }

        global $wpdb;

        $prefix = (string) $wpdb->prefix;
        return $this->is_valid_table_prefix($prefix) ? array($prefix) : array();
    }

    private function is_valid_table_prefix(string $prefix): bool
    {
        return $prefix !== '' && preg_match('/^[A-Za-z0-9_]+$/', $prefix) === 1;
    }

    /**
     * @return array<int,array{table:string,columns:string[],id_col:string}>
     */
    private function prefix_targets(string $prefix): array
    {
        return array(
            array('table' => $prefix . 'options', 'columns' => array('option_value'), 'id_col' => 'option_id'),
            array('table' => $prefix . 'posts', 'columns' => array('post_content', 'post_excerpt', 'guid'), 'id_col' => 'ID'),
            array('table' => $prefix . 'postmeta', 'columns' => array('meta_value'), 'id_col' => 'meta_id'),
            array('table' => $prefix . 'comments', 'columns' => array('comment_content', 'comment_author_url'), 'id_col' => 'comment_ID'),
            array('table' => $prefix . 'usermeta', 'columns' => array('meta_value'), 'id_col' => 'umeta_id'),
        );
    }

    private function cache_parent_column_for_table(string $table): ?string
    {
        if (strlen($table) >= 7 && substr($table, -7) === 'options') {
            return 'option_name';
        }
        if (strlen($table) >= 8 && substr($table, -8) === 'postmeta') {
            return 'post_id';
        }
        if (strlen($table) >= 8 && substr($table, -8) === 'usermeta') {
            return 'user_id';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function invalidate_updated_row_cache(string $table, array $row): void
    {
        if (!function_exists('wp_cache_delete')) {
            return;
        }

        if (str_ends_with($table, 'options')) {
            wp_cache_delete('alloptions', 'options');
            if (isset($row['option_name']) && is_string($row['option_name']) && $row['option_name'] !== '') {
                wp_cache_delete($row['option_name'], 'options');
            }
            return;
        }

        if (str_ends_with($table, 'postmeta') && isset($row['post_id'])) {
            wp_cache_delete((int) $row['post_id'], 'post_meta');
            return;
        }

        if (str_ends_with($table, 'usermeta') && isset($row['user_id'])) {
            wp_cache_delete((int) $row['user_id'], 'user_meta');
        }
    }
}
