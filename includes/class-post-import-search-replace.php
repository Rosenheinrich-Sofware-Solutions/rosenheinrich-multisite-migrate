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

        while ((microtime(true) - $start) < $budget_sec && $target_index < count($this->targets)) {
            $target = $this->targets[$target_index];
            $table = $target['table'];
            $id_col = $target['id_col'];
            $quoted = Rmmigrate_Snap_DB::quote_identifier($table);
            $id_quoted = Rmmigrate_Snap_DB::quote_identifier($id_col);
            $col_quoted = array_map(array(Rmmigrate_Snap_DB::class, 'quote_identifier'), $target['columns']);
            $select_cols = array_unique(array_merge(array($id_quoted), $col_quoted));
            $sql = 'SELECT ' . implode(', ', $select_cols) . ' FROM ' . $quoted
                . ' ORDER BY ' . $id_quoted . ' ASC LIMIT ' . (int) $row_offset . ', ' . (int) self::BATCH_SIZE;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Batch read on validated table identifiers; LIMIT values are cast ints.
            $rows = $wpdb->get_results($sql, ARRAY_A);
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
                    $wpdb->update($table, $fields, array($id_col => $id));
                    $updated++;
                }
            }

            $row_offset += count($rows);
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
            $candidates = array_merge($candidates, $this->prefix_targets($prefix));
        }

        $out = array();
        foreach ($candidates as $candidate) {
            $like = $wpdb->esc_like($candidate['table']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            if ($found === $candidate['table']) {
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
            return Rmmigrate_Restore_Topology_Manifest::table_prefixes($manifest);
        }

        global $wpdb;

        return array($wpdb->prefix);
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
}
