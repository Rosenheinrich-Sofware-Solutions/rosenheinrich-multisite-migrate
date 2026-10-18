<?php

require_once dirname(__DIR__) . '/safe-logger.php';

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer: PDO required for migration DDL; no $wpdb without WP core.

class Rmmigrate_Restore_Topology_Db_Action
{
    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     */
    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed> Context (may include network_quarantine after network overwrite).
     */
    public static function prepare_overwrite(PDO $pdo, string $db_action, array $manifest, array $context = array()): array
    {
        $restore_mode = Rmmigrate_Restore_Topology_Manifest::restore_mode($manifest, $context);
        $is_slot_restore = in_array($restore_mode, array('subsite_on_network', 'network_to_subsite'), true);
        if ($db_action === 'skip' && !$is_slot_restore) {
            return $context;
        }
        if ($db_action === 'empty' && $is_slot_restore) {
            // Emptying the database would destroy every other blog and the network globals.
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key(Rmmigrate_Error_Codes::NETWORK_OVERWRITE_DB_ACTION),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                __('Restoring a single site onto a network cannot empty the entire database. Use "Drop backup tables only".', 'rosenheinrich-multisite-migrate')
            );
        }

        // Disable FK checks while dropping tables so a referenced parent table
        // (e.g. WooCommerce/HPOS or plugin InnoDB foreign keys) can be dropped
        // without a 1451 "Cannot delete or update a parent row" error.
        $is_mysql = self::is_mysql($pdo);
        if ($is_mysql) {
            // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer DDL.
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            self::run_prepare_overwrite($pdo, $db_action, $manifest, $context);
        } catch (Throwable $e) {
            // The caller never receives $context on failure, so a blog row allocated for a new
            // target URL would leak into wp_blogs. Revert it before rethrowing.
            self::revert_subsite_on_network_allocation($pdo, $context);
            throw $e;
        } finally {
            if ($is_mysql) {
                // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer DDL.
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return $context;
    }

    /**
     * Remove a wp_blogs row this prepare step allocated when the step fails afterwards.
     *
     * @param array<string,mixed> $context
     */
    private static function revert_subsite_on_network_allocation(PDO $pdo, array &$context): void
    {
        $record = $context['subsite_on_network_allocated_blog'] ?? null;
        if (!is_array($record) || empty($record['allocated'])) {
            return;
        }
        try {
            if (!class_exists('Rmmigrate_Restore_Topology_Network_Import', false)) {
                require_once __DIR__ . '/class-restore-topology-network-import.php';
            }
            Rmmigrate_Restore_Topology_Network_Import::revert_allocated_blog_row($pdo, $record);
        } catch (Throwable $revert_error) {
            rmmigrate_shared_log_best_effort_failure($revert_error);
        }
        unset($context['subsite_on_network_allocated_blog']);
    }

    private static function is_mysql(PDO $pdo): bool
    {
        try {
            return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        } catch (Throwable $e) {
            rmmigrate_shared_log_best_effort_failure($e);
            return false;
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     */
    /**
     * @param array<string,mixed> $context
     */
    private static function run_prepare_overwrite(PDO $pdo, string $db_action, array $manifest, array &$context = array()): void
    {
        if ($db_action === 'empty') {
            foreach (self::list_database_tables($pdo) as $table) {
                if (self::is_plugin_runtime_table($table)) {
                    continue;
                }
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            }

            return;
        }

        $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
        $gate_state = array_merge(
            $context,
            array(
                'install_mode' => (string) ($context['install_mode'] ?? 'overwrite'),
                'db_action'    => $db_action,
            )
        );
        if (
            Rmmigrate_Restore_Topology_Compatibility::is_subsite_full_overwrite_scenario($gate_state, $manifest, $destination)
            && !Rmmigrate_Restore_Topology_Compatibility::allows_subsite_on_network_slot_restore($gate_state, $manifest, $destination)
        ) {
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key(Rmmigrate_Error_Codes::NETWORK_OVERWRITE_DB_ACTION),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                __('Replacing an entire multisite installation requires Empty entire database.', 'rosenheinrich-multisite-migrate')
            );
        }

        $restore_mode = Rmmigrate_Restore_Topology_Manifest::restore_mode($manifest, $context);
        if ($restore_mode === 'subsite_on_network') {
            $source_blog_id = (int) ($manifest['blog_id'] ?? 0);
            $base_prefix = self::destination_network_base_prefix($destination, $manifest);
            $map = is_array($context['site_owr_map'] ?? null) ? $context['site_owr_map'] : array();
            if ($map === array()) {
                $topology_gate = Rmmigrate_Restore_Topology_Compatibility::validate_topology($gate_state, $manifest, $destination);
                if ($topology_gate !== null) {
                    throw Rmmigrate_Job_Exception::raise(
                        sanitize_key((string) $topology_gate['gate']),
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        (string) $topology_gate['message']
                    );
                }
            }
            $entry = is_array($map[0] ?? null) ? $map[0] : array();
            $requested_blog_id = (int) ($entry['target_blog_id'] ?? 0);
            $target_url = trim((string) ($entry['target_url'] ?? ''));
            if ($requested_blog_id > 0 && !self::network_blog_id_exists($pdo, $base_prefix, $requested_blog_id)) {
                self::throw_subsite_on_network_target_unresolved();
            }
            if ($target_url !== '' && !self::is_parseable_site_url($target_url)) {
                self::throw_subsite_on_network_target_unresolved();
            }
            $blogs_table = $base_prefix . 'blogs';
            if ($target_url !== '' && !self::table_exists($pdo, $blogs_table)) {
                self::throw_subsite_on_network_target_unresolved();
            }
            $target_blog_id = self::resolve_subsite_on_network_target_blog_id($pdo, $context, $manifest, $destination);
            if ($target_blog_id <= 0 && $target_url !== '') {
                $target_blog_id = self::ensure_subsite_on_network_blog_for_url(
                    $pdo,
                    $context,
                    $base_prefix,
                    $target_url
                );
            }
            if ($target_blog_id <= 0 || $target_blog_id === 1) {
                if ($target_blog_id === 1) {
                    throw Rmmigrate_Job_Exception::raise(
                        sanitize_key('topology_subsite_on_network_main'),
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        __('Subsite backups cannot target the network main site (blog 1). Choose another blog slot or use a network-scope backup.', 'rosenheinrich-multisite-migrate')
                    );
                }
                self::throw_subsite_on_network_target_unresolved();
            }
            if ($target_blog_id > 0) {
                $context['subsite_on_network_target_blog_id'] = $target_blog_id;
                if ($map === array()) {
                    $map = array(array());
                }
                if (!is_array($map[0] ?? null)) {
                    $map[0] = array();
                }
                $map[0]['target_blog_id'] = $target_blog_id;
                if ($target_url !== '' && trim((string) ($map[0]['target_url'] ?? '')) === '') {
                    $map[0]['target_url'] = $target_url;
                }
                $context['site_owr_map'] = $map;
            }
            $gate_state = array_merge($gate_state, array(
                'site_owr_map'                      => $context['site_owr_map'] ?? $map,
                'subsite_on_network_target_blog_id' => $target_blog_id,
            ));
            $topology_gate = Rmmigrate_Restore_Topology_Compatibility::validate_topology($gate_state, $manifest, $destination);
            if ($topology_gate !== null) {
                throw Rmmigrate_Job_Exception::raise(
                    sanitize_key((string) $topology_gate['gate']),
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    (string) $topology_gate['message']
                );
            }
            $rewrite = self::subsite_on_network_sql_prefix_rewrite($base_prefix, $source_blog_id, $target_blog_id);
            $manifest_class = 'Rmmigrate_Restore_Topology_Manifest';
            if ($rewrite === null && $target_blog_id > 0 && !$manifest_class::restore_as_multisite($manifest)) {
                $from = (string) ($manifest['db_prefix'] ?? $base_prefix);
                $to = self::blog_table_prefix($base_prefix, $target_blog_id);
                if ($from !== '' && $to !== '' && $from !== $to) {
                    $rewrite = array('from' => $from, 'to' => $to);
                }
            }
            if (
                $rewrite === null
                && self::subsite_import_would_hit_live_source_without_rewrite(
                    $pdo,
                    $base_prefix,
                    $source_blog_id,
                    $target_blog_id,
                    $manifest
                )
            ) {
                self::throw_subsite_on_network_target_unresolved();
            }
            if ($rewrite !== null) {
                self::load_sql_prefix_rewrite_class();
                if (!class_exists('Rmmigrate_Sql_Import_Prefix_Rewrite', false)) {
                    throw Rmmigrate_Job_Exception::raise(
                        sanitize_key('sql_prefix_rewrite_missing'),
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        __('SQL prefix rewrite is required for this restore but the rewrite helper is missing.', 'rosenheinrich-multisite-migrate')
                    );
                }
                $context['sql_table_prefix_rewrite'] = $rewrite;
            }
            if ($target_blog_id > 0) {
                $context['subsite_on_network_target_blog_id'] = $target_blog_id;
                $map = is_array($context['site_owr_map'] ?? null) ? $context['site_owr_map'] : array();
                if ($map === array()) {
                    $map = array(array());
                }
                if (!is_array($map[0] ?? null)) {
                    $map[0] = array();
                }
                $map[0]['target_blog_id'] = $target_blog_id;
                if ($target_url !== '' && trim((string) ($map[0]['target_url'] ?? '')) === '') {
                    $map[0]['target_url'] = $target_url;
                }
                $context['site_owr_map'] = $map;
            }
            $target_prefix = (string) ($context['target_prefix'] ?? '');
            if ($target_prefix === '') {
                $base_prefix = self::destination_network_base_prefix($destination, $manifest);
                $target_prefix = ($target_blog_id > 1)
                    ? $base_prefix . $target_blog_id . '_'
                    : $base_prefix;
            } else {
                $base_prefix = self::infer_base_prefix($target_prefix, $target_blog_id);
            }
            if ($db_action !== 'skip') {
                if ($target_blog_id === 1) {
                    throw Rmmigrate_Job_Exception::raise(
                        sanitize_key('topology_subsite_on_network_main'),
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        __('Subsite backups cannot target the network main site (blog 1). Choose another blog slot or use a network-scope backup.', 'rosenheinrich-multisite-migrate')
                    );
                }
                self::drop_prefixed_tables($pdo, $target_prefix, $base_prefix, $target_blog_id);
            }

            return;
        }
        if ($restore_mode === 'network_to_subsite') {
            $target_blog_id = Rmmigrate_Restore_Topology_Manifest::resolve_target_blog_id($context, $destination);
            if ($target_blog_id <= 0) {
                throw Rmmigrate_Job_Exception::raise(
                    sanitize_key('topology_network_to_subsite_target'),
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    __('Network-to-subsite import requires a target blog on this network.', 'rosenheinrich-multisite-migrate')
                );
            }
            $target_prefix = (string) ($context['target_prefix'] ?? '');
            if ($target_prefix === '') {
                $base_prefix = (string) ($destination['base_prefix'] ?? $context['db_prefix'] ?? '');
                if ($base_prefix === '') {
                    $base_prefix = 'wp_';
                }
                $target_prefix = ($target_blog_id > 1)
                    ? $base_prefix . $target_blog_id . '_'
                    : $base_prefix;
            } else {
                $base_prefix = self::infer_base_prefix($target_prefix, $target_blog_id);
            }
            if ($db_action !== 'skip') {
                self::drop_prefixed_tables($pdo, $target_prefix, $base_prefix, $target_blog_id);
            }

            return;
        }

        // SCOPE_SUBSITE onto a live network: drop only that blog's tables.
        // Never LIKE-drop the base prefix (would wipe wp_2_*, wp_3_*, …)
        // and never drop network globals (users/blogs/site/sitemeta).
        if (Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest)) {
            self::drop_subsite_owned_manifest_tables($pdo, $manifest, $context);

            return;
        }

        $manifest_tables = $manifest['tables'] ?? array();
        if (!is_array($manifest_tables)) {
            $manifest_tables = array();
        }

        // Full network restore: overwrite the whole network. Drop every table in
        // the backup (globals + all blogs) AND any destination tables under the
        // network base prefix (orphan blogs not present in the archive).
        // Do not rely on LIKE prefix wipes alone — manifest blog entries often
        // omit per-blog db_prefix, and the sibling guard would skip wp_N_*.
        if ($restore_mode === 'network') {
            self::drop_network_overwrite_tables($pdo, $manifest, $context, $manifest_tables);

            return;
        }

        if ($restore_mode === 'network_included' || $restore_mode === 'network_filtered') {
            self::drop_manifest_tables_only($pdo, $manifest_tables, $context);

            return;
        }

        foreach ($manifest_tables as $table) {
            $table = (string) $table;
            if ($table === '' || self::is_plugin_runtime_table($table)) {
                continue;
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
    }

    /**
     * Plugin job tables must survive overwrite — restore progress writes them mid-import,
     * and dumps intentionally omit them ({@see Multisite_Scope::plugin_runtime_table_patterns}).
     */
    public static function is_plugin_runtime_table(string $table): bool
    {
        if ($table === '') {
            return false;
        }
        $patterns = array('*rmmigrate_jobs', '*rmmigrate_pro_jobs');
        if (class_exists('Rmmigrate_Multisite_Scope')) {
            $patterns = Rmmigrate_Multisite_Scope::plugin_runtime_table_patterns();
        }
        foreach ($patterns as $pattern) {
            if (fnmatch((string) $pattern, $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop all tables that a full network overwrite must replace.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @param array<int,mixed>    $manifest_tables
     */
    /**
     * @param array<string,mixed> $context
     */
    private static function drop_network_overwrite_tables(PDO $pdo, array $manifest, array &$context, array $manifest_tables): void
    {
        $tables = self::collect_network_overwrite_tables($pdo, $manifest, $context, $manifest_tables);
        $use_quarantine = !empty($context['network_quarantine']['enabled'])
            && class_exists('Rmmigrate_Network_Restore_Quarantine', false);
        if ($use_quarantine) {
            $token = (string) ($context['network_quarantine']['token'] ?? '');
            if ($token === '') {
                $token = Rmmigrate_Network_Restore_Quarantine::make_token((int) ($context['job_id'] ?? 0));
            }
            self::merge_network_quarantine_state($pdo, $context, $tables, $token);

            return;
        }

        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
    }

    /**
     * @param array<string,mixed> $context
     * @param string[]            $tables
     */
    private static function merge_network_quarantine_state(
        PDO $pdo,
        array &$context,
        array $tables,
        string $token
    ): void {
        try {
            $context['network_quarantine'] = array_merge(
                is_array($context['network_quarantine'] ?? null) ? $context['network_quarantine'] : array(),
                Rmmigrate_Network_Restore_Quarantine::quarantine_tables($pdo, $tables, $token)
            );
        } catch (Throwable $e) {
            $partial = Rmmigrate_Network_Restore_Quarantine::consume_interrupted_quarantine_state();
            if ($partial !== null) {
                $context['network_quarantine'] = array_merge(
                    is_array($context['network_quarantine'] ?? null) ? $context['network_quarantine'] : array(),
                    $partial
                );
            }
            throw $e;
        }
        $context['network_quarantine']['enabled'] = true;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @param array<int,mixed>    $manifest_tables
     * @return string[]
     */
    public static function collect_network_overwrite_tables(PDO $pdo, array $manifest, array $context, array $manifest_tables): array
    {
        $base_prefix = self::network_overwrite_base_prefix($manifest, $context);
        $drop = array();

        foreach ($manifest_tables as $table) {
            $table = (string) $table;
            if ($table !== '') {
                $drop[$table] = true;
            }
        }

        if ($base_prefix !== '') {
            foreach (self::list_database_tables($pdo) as $table) {
                if (strpos($table, $base_prefix) === 0) {
                    $drop[$table] = true;
                }
            }
        }

        $tables = array();
        foreach (array_keys($drop) as $table) {
            if (self::is_plugin_runtime_table($table)) {
                continue;
            }
            $tables[] = $table;
        }

        return $tables;
    }

    /**
     * Drop or quarantine only tables listed in the backup manifest (partial network scopes).
     *
     * @param array<int,mixed>    $manifest_tables
     * @param array<string,mixed> $context
     */
    private static function drop_manifest_tables_only(PDO $pdo, array $manifest_tables, array &$context): void
    {
        $tables = array();
        foreach ($manifest_tables as $table) {
            $table = (string) $table;
            if ($table === '' || self::is_plugin_runtime_table($table)) {
                continue;
            }
            $tables[] = $table;
        }
        if ($tables === array()) {
            return;
        }

        $use_quarantine = !empty($context['network_quarantine']['enabled'])
            && class_exists('Rmmigrate_Network_Restore_Quarantine', false);
        if ($use_quarantine) {
            $token = (string) ($context['network_quarantine']['token'] ?? '');
            if ($token === '') {
                $token = Rmmigrate_Network_Restore_Quarantine::make_token((int) ($context['job_id'] ?? 0));
            }
            self::merge_network_quarantine_state($pdo, $context, $tables, $token);

            return;
        }

        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     */
    private static function network_overwrite_base_prefix(array $manifest, array $context): string
    {
        $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();

        return self::destination_network_base_prefix($destination, $manifest);
    }

    /**
     * @param array<string,mixed> $destination
     * @param array<string,mixed> $manifest
     */
    private static function destination_network_base_prefix(array $destination, array $manifest): string
    {
        if (!empty($destination['base_prefix'])) {
            return (string) $destination['base_prefix'];
        }
        if (!empty($destination['table_prefix'])) {
            return Rmmigrate_Restore_Topology_Standalone::base_table_prefix((string) $destination['table_prefix']);
        }

        return Rmmigrate_Restore_Topology_Manifest::source_base_prefix($manifest);
    }

    /**
     * @return string[]
     */
    private static function list_database_tables(PDO $pdo): array
    {
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            rmmigrate_shared_log_best_effort_failure($e);
            $driver = 'mysql';
        }

        if ($driver === 'sqlite') {
            // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Test/SQLite listing.
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : array();
        } else {
            // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer DDL.
            $stmt = $pdo->query('SHOW TABLES');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : array();
        }

        $tables = array();
        foreach ($rows as $row) {
            $name = (string) ($row[0] ?? '');
            if ($name !== '') {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * Drop only tables owned by the subsite being restored.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     */
    private static function drop_subsite_owned_manifest_tables(PDO $pdo, array $manifest, array $context): void
    {
        $tables = $manifest['tables'] ?? array();
        if (!is_array($tables) || $tables === array()) {
            return;
        }

        $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
        $blog_prefix = (string) ($destination['table_prefix'] ?? $context['db_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
        $blog_id = (int) ($destination['destination_blog_id'] ?? $manifest['blog_id'] ?? 0);
        if ($blog_id <= 0) {
            $blog_id = 1;
        }
        $base_prefix = self::infer_base_prefix($blog_prefix, $blog_id);

        foreach ($tables as $table) {
            $table = (string) $table;
            if ($table === '' || self::is_plugin_runtime_table($table) || !self::is_blog_owned_table($table, $blog_prefix, $base_prefix, $blog_id)) {
                continue;
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
    }

    /**
     * @return string[]
     */
    public static function network_global_table_names(string $base_prefix): array
    {
        return array(
            $base_prefix . 'users',
            $base_prefix . 'usermeta',
            $base_prefix . 'blogs',
            $base_prefix . 'blogmeta',
            $base_prefix . 'blog_versions',
            $base_prefix . 'site',
            $base_prefix . 'sitemeta',
            $base_prefix . 'sitecategories',
            $base_prefix . 'registration_log',
            $base_prefix . 'signups',
        );
    }

    public static function infer_base_prefix(string $blog_prefix, int $blog_id): string
    {
        if ($blog_id > 1) {
            $suffix = $blog_id . '_';
            if (substr($blog_prefix, -strlen($suffix)) === $suffix) {
                return substr($blog_prefix, 0, -strlen($suffix));
            }
        }

        return $blog_prefix !== '' ? $blog_prefix : 'wp_';
    }

    public static function is_blog_owned_table(string $table, string $blog_prefix, string $base_prefix, int $blog_id): bool
    {
        if (in_array($table, self::network_global_table_names($base_prefix), true)) {
            return false;
        }

        if ($blog_id <= 1 || $blog_prefix === $base_prefix) {
            if (strpos($table, $base_prefix) !== 0) {
                return false;
            }
            // Main site uses base_prefix; never treat other blogs' wp_N_* as owned.
            if (preg_match('/^' . preg_quote($base_prefix, '/') . '\d+_/', $table)) {
                return false;
            }

            return true;
        }

        if ($blog_prefix === '' || strpos($table, $blog_prefix) !== 0) {
            return false;
        }
        if (preg_match('/^' . preg_quote($blog_prefix, '/') . '\d+_/', $table)) {
            return false;
        }

        return true;
    }

    private static function drop_prefixed_tables(PDO $pdo, string $prefix, string $base_prefix = '', int $blog_id = 0): int
    {
        if ($prefix === '') {
            return 0;
        }

        $dropped = 0;
        foreach (self::list_database_tables($pdo) as $table) {
            if (strpos($table, $prefix) !== 0) {
                continue;
            }
            if ($base_prefix !== '') {
                if (!self::is_blog_owned_table($table, $prefix, $base_prefix, $blog_id > 0 ? $blog_id : 1)) {
                    continue;
                }
            } elseif (preg_match('/^' . preg_quote($prefix, '/') . '\d+_/', $table)) {
                // Guard: when prefix is the network base (wp_), never drop other blogs' tables.
                continue;
            }
            if (self::is_plugin_runtime_table($table)) {
                continue;
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            $dropped++;
        }

        return $dropped;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $destination
     */
    public static function blog_table_prefix(string $base_prefix, int $blog_id): string
    {
        if ($blog_id > 1) {
            return $base_prefix . $blog_id . '_';
        }

        return $base_prefix;
    }

    /**
     * @return array{from:string,to:string}|null
     */
    public static function subsite_on_network_sql_prefix_rewrite(
        string $base_prefix,
        int $source_blog_id,
        int $target_blog_id
    ): ?array {
        if ($source_blog_id <= 0 || $target_blog_id <= 0 || $source_blog_id === $target_blog_id) {
            return null;
        }
        $from = self::blog_table_prefix($base_prefix, $source_blog_id);
        $to = self::blog_table_prefix($base_prefix, $target_blog_id);
        if ($from === $to) {
            return null;
        }

        return array('from' => $from, 'to' => $to);
    }

    public static function resolve_blog_id_from_site_url(PDO $pdo, string $base_prefix, string $site_url): int
    {
        return self::resolve_blog_id_from_site_url_internal($pdo, $base_prefix, $site_url);
    }

    private static function network_has_blog_tables(PDO $pdo, string $base_prefix, int $blog_id): bool
    {
        $prefix = self::blog_table_prefix($base_prefix, $blog_id);
        if ($prefix === '') {
            return false;
        }
        foreach (self::list_database_tables($pdo) as $table) {
            if (strncmp((string) $table, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function resolve_subsite_on_network_target_blog_id(
        PDO $pdo,
        array $context,
        array $manifest,
        array $destination
    ): int {
        $map = is_array($context['site_owr_map'] ?? null) ? $context['site_owr_map'] : array();
        $entry = is_array($map[0] ?? null) ? $map[0] : array();
        $base_prefix = self::network_overwrite_base_prefix($manifest, $context);

        $candidate = (int) ($entry['target_blog_id'] ?? $context['target_blog_id'] ?? $context['destination_blog_id'] ?? 0);
        if ($candidate > 0) {
            if (self::network_blog_id_exists($pdo, $base_prefix, $candidate)) {
                return $candidate;
            }
            self::throw_subsite_on_network_target_unresolved();
        }

        $target_url = trim((string) ($entry['target_url'] ?? ''));
        if ($target_url !== '') {
            return self::resolve_blog_id_from_site_url($pdo, $base_prefix, $target_url);
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private static function subsite_import_would_hit_live_source_without_rewrite(
        PDO $pdo,
        string $base_prefix,
        int $source_blog_id,
        int $target_blog_id,
        array $manifest
    ): bool {
        if ($source_blog_id <= 0 || $target_blog_id <= 0 || $source_blog_id === $target_blog_id) {
            return false;
        }
        $manifest_prefix = (string) ($manifest['db_prefix'] ?? '');
        if ($manifest_prefix === '') {
            return false;
        }
        $live_source_prefix = self::blog_table_prefix($base_prefix, $source_blog_id);
        if ($manifest_prefix !== $live_source_prefix) {
            return false;
        }

        return self::network_has_blog_tables($pdo, $base_prefix, $source_blog_id);
    }

    private static function is_parseable_site_url(string $site_url): bool
    {
        $parsed = wp_parse_url($site_url);
        if (!is_array($parsed)) {
            return false;
        }

        return trim((string) ($parsed['host'] ?? '')) !== '';
    }

    /**
     * @return never
     */
    private static function throw_subsite_on_network_target_unresolved(): void
    {
        if (class_exists('Rmmigrate_Job_Exception')) {
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key('topology_subsite_on_network_target_unresolved'),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                __('Subsite-on-network import could not resolve the target blog. Check the blog ID and URL.', 'rosenheinrich-multisite-migrate')
            );
        }
        throw new RuntimeException('topology_subsite_on_network_target_unresolved');
    }

    private static function network_blog_id_exists(PDO $pdo, string $base_prefix, int $blog_id): bool
    {
        if ($blog_id <= 0) {
            return false;
        }
        $blogs_table = $base_prefix . 'blogs';
        if (!self::table_exists($pdo, $blogs_table)) {
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT blog_id FROM ' . self::quote_table_name($blogs_table) . ' WHERE blog_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->execute(array($blog_id));

        return (int) $stmt->fetchColumn() === $blog_id;
    }

    private static function resolve_blog_id_from_site_url_internal(PDO $pdo, string $base_prefix, string $site_url): int
    {
        $blogs_table = $base_prefix . 'blogs';
        if (!self::table_exists($pdo, $blogs_table)) {
            return 0;
        }

        $host = '';
        $path = '/';
        $parsed = wp_parse_url($site_url);
        if (is_array($parsed)) {
            $host = (string) ($parsed['host'] ?? '');
            if ($host !== '' && isset($parsed['port'])) {
                $host .= ':' . (int) $parsed['port'];
            }
            $path = (string) ($parsed['path'] ?? '/');
        }
        if ($host === '') {
            return 0;
        }

        $stmt = $pdo->prepare(
            'SELECT blog_id FROM ' . self::quote_table_name($blogs_table) . ' WHERE domain = ? AND path = ? LIMIT 1'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->execute(array($host, self::trailingslashit($path)));
        $blog_id = (int) $stmt->fetchColumn();

        return $blog_id > 0 ? $blog_id : 0;
    }

    private static function table_exists(PDO $pdo, string $table): bool
    {
        if (class_exists('Rmmigrate_Topology_Db_Connection', false)) {
            return Rmmigrate_Topology_Db_Connection::table_exists($pdo, $table);
        }

        return false;
    }

    private static function quote_table_name(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }

    private static function trailingslashit(string $path): string
    {
        return rtrim($path, '/') . '/';
    }

    /**
     * @return string[]
     */
    private static function list_blog_prefixed_tables(PDO $pdo, string $base_prefix, int $blog_id): array
    {
        $prefix = self::blog_table_prefix($base_prefix, $blog_id);
        if ($prefix === '') {
            return array();
        }
        $out = array();
        foreach (self::list_database_tables($pdo) as $table) {
            if (strncmp((string) $table, $prefix, strlen($prefix)) === 0) {
                $out[] = (string) $table;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function ensure_subsite_on_network_blog_for_url(
        PDO $pdo,
        array &$context,
        string $base_prefix,
        string $target_url
    ): int {
        if (!class_exists('Rmmigrate_Restore_Topology_Network_Import', false)) {
            require_once __DIR__ . '/class-restore-topology-network-import.php';
        }
        $blogs_table = $base_prefix . 'blogs';
        $allocation = Rmmigrate_Restore_Topology_Network_Import::ensure_blog_allocation_for_url(
            $pdo,
            $blogs_table,
            $target_url
        );
        $blog_id = (int) ($allocation['blog_id'] ?? 0);
        if ($blog_id <= 0) {
            return 0;
        }
        if (!empty($allocation['allocated'])) {
            $context['subsite_on_network_allocated_blog'] = array(
                'blog_id'     => $blog_id,
                'base_prefix' => $base_prefix,
                'allocated'   => true,
            );
        }
        $map = is_array($context['site_owr_map'] ?? null) ? $context['site_owr_map'] : array();
        if ($map === array()) {
            $map = array(array());
        }
        if (!is_array($map[0] ?? null)) {
            $map[0] = array();
        }
        $map[0]['target_blog_id'] = $blog_id;
        $map[0]['target_url'] = $target_url;
        $context['site_owr_map'] = $map;
        $context['subsite_on_network_target_blog_id'] = $blog_id;
        $context['target_blog_id'] = $blog_id;

        return $blog_id;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     */
    public static function verify_subsite_on_network_restore(PDO $pdo, array $manifest, array $context): ?string
    {
        $map = is_array($context['site_owr_map'] ?? null) ? $context['site_owr_map'] : array();
        $entry = is_array($map[0] ?? null) ? $map[0] : array();
        $target_url = trim((string) ($entry['target_url'] ?? ''));
        $target_blog_id = (int) ($context['subsite_on_network_target_blog_id'] ?? 0);
        if ($target_blog_id <= 0) {
            $target_blog_id = (int) ($context['target_blog_id'] ?? 0);
        }
        if ($target_blog_id <= 0 && $target_url !== '') {
            $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
            $base_prefix = self::destination_network_base_prefix($destination, $manifest);
            $target_blog_id = self::resolve_blog_id_from_site_url($pdo, $base_prefix, $target_url);
        }
        $rewrite = is_array($context['sql_table_prefix_rewrite'] ?? null)
            ? $context['sql_table_prefix_rewrite']
            : array();
        $to_prefix = (string) ($rewrite['to'] ?? '');
        if ($to_prefix === '' && $target_blog_id > 0) {
            $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
            $base_prefix = self::destination_network_base_prefix($destination, $manifest);
            $to_prefix = self::blog_table_prefix($base_prefix, $target_blog_id);
        }
        if ($to_prefix === '' && $target_url !== '' && isset($GLOBALS['wpdb'])) {
            global $wpdb;
            $host = '';
            $path = '/';
            $parsed = wp_parse_url($target_url);
            if (is_array($parsed)) {
                $host = (string) ($parsed['host'] ?? '');
                if ($host !== '' && isset($parsed['port'])) {
                    $host .= ':' . (int) $parsed['port'];
                }
                $path = (string) ($parsed['path'] ?? '/');
            }
            if ($host !== '') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verify fallback when job progress omitted blog id.
                $target_blog_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s AND path = %s LIMIT 1",
                        $host,
                        self::trailingslashit($path)
                    )
                );
                if ($target_blog_id > 0) {
                    $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
                    $base_prefix = self::destination_network_base_prefix($destination, $manifest);
                    $to_prefix = self::blog_table_prefix($base_prefix, $target_blog_id);
                }
            }
        }
        if ($to_prefix === '') {
            return __('Subsite restore finished without a target table prefix.', 'rosenheinrich-multisite-migrate');
        }
        $options = $to_prefix . 'options';
        if (!self::table_exists($pdo, $options)) {
            return sprintf(
                /* translators: %s: expected options table name */
                __('Subsite restore finished but expected table %s is missing.', 'rosenheinrich-multisite-migrate'),
                $options
            );
        }
        if ($target_url === '') {
            return null;
        }
        $want = self::normalize_restore_site_url($target_url);
        $stmt = $pdo->prepare(
            'SELECT option_value FROM ' . self::quote_table_name($options) . " WHERE option_name = 'siteurl' LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->execute();
        $siteurl = (string) $stmt->fetchColumn();
        $have = self::normalize_restore_site_url($siteurl);
        if ($have !== '' && $want !== '' && $have !== $want) {
            return sprintf(
                /* translators: 1: actual siteurl, 2: expected URL */
                __('Subsite restore finished but siteurl is still %1$s (expected %2$s).', 'rosenheinrich-multisite-migrate'),
                $siteurl,
                $want
            );
        }

        return null;
    }

    private static function normalize_restore_site_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parsed = wp_parse_url($url);
        if (!is_array($parsed)) {
            return function_exists('untrailingslashit') ? untrailingslashit($url) : rtrim($url, '/');
        }
        $scheme = strtolower((string) ($parsed['scheme'] ?? 'http'));
        $host = strtolower(rtrim((string) ($parsed['host'] ?? ''), '.'));
        $port = isset($parsed['port']) ? (int) $parsed['port'] : 0;
        if ($port > 0) {
            $default = ($scheme === 'https') ? 443 : 80;
            if ($port === $default) {
                $port = 0;
            }
        }
        $path = (string) ($parsed['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $path = function_exists('untrailingslashit') ? untrailingslashit($path) : rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }
        $authority = $host;
        if ($port > 0) {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $authority . ($path === '/' ? '' : $path);
    }

    private static function load_sql_prefix_rewrite_class(): void
    {
        if (class_exists('Rmmigrate_Sql_Import_Prefix_Rewrite', false)) {
            return;
        }
        $candidates = array();
        if (defined('RMMIGRATE_PATH')) {
            $candidates[] = RMMIGRATE_PATH . 'includes/class-sql-import-prefix-rewrite.php';
        }
        $candidates[] = dirname(__DIR__, 2) . '/class-sql-import-prefix-rewrite.php';
        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path)) {
                require_once $path;
                return;
            }
        }
    }
}
