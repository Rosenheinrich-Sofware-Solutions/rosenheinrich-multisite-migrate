<?php

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
    public static function prepare_overwrite(PDO $pdo, string $db_action, array $manifest, array $context = array()): void
    {
        if ($db_action === 'skip') {
            return;
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
        } finally {
            if ($is_mysql) {
                // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer DDL.
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    private static function is_mysql(PDO $pdo): bool
    {
        try {
            return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     */
    private static function run_prepare_overwrite(PDO $pdo, string $db_action, array $manifest, array $context = array()): void
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
        if (Rmmigrate_Restore_Topology_Compatibility::is_subsite_full_overwrite_scenario($gate_state, $manifest, $destination)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::NETWORK_OVERWRITE_DB_ACTION),
                esc_html__('Replacing an entire multisite installation requires Empty entire database.', 'rosenheinrich-multisite-migrate')
            );
        }

        $restore_mode = Rmmigrate_Restore_Topology_Manifest::restore_mode($manifest, $context);
        if ($restore_mode === 'network_to_subsite') {
            $target_prefix = (string) ($context['target_prefix'] ?? $context['db_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
            self::drop_prefixed_tables($pdo, $target_prefix);

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
        if (class_exists('Rmmigrate_Multisite_Scope', false)) {
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
    private static function drop_network_overwrite_tables(PDO $pdo, array $manifest, array $context, array $manifest_tables): void
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

        foreach (array_keys($drop) as $table) {
            if (self::is_plugin_runtime_table($table)) {
                continue;
            }
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
        if (!empty($destination['base_prefix'])) {
            return (string) $destination['base_prefix'];
        }
        if (!empty($destination['table_prefix'])) {
            return (string) $destination['table_prefix'];
        }
        if (!empty($context['db_prefix'])) {
            return (string) $context['db_prefix'];
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
            $base_prefix . 'site',
            $base_prefix . 'sitemeta',
            $base_prefix . 'registration_log',
            $base_prefix . 'signups',
        );
    }

    public static function infer_base_prefix(string $blog_prefix, int $blog_id): string
    {
        if ($blog_id > 1) {
            $suffix = $blog_id . '_';
            if ($suffix !== '' && substr($blog_prefix, -strlen($suffix)) === $suffix) {
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

        return $blog_prefix !== '' && strpos($table, $blog_prefix) === 0;
    }

    private static function drop_prefixed_tables(PDO $pdo, string $prefix): int
    {
        if ($prefix === '') {
            return 0;
        }

        $dropped = 0;
        foreach (self::list_database_tables($pdo) as $table) {
            if (strpos($table, $prefix) !== 0) {
                continue;
            }
            // Guard: when prefix is the network base (wp_), never drop other blogs' tables.
            if (preg_match('/^' . preg_quote($prefix, '/') . '\d+_/', $table)) {
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
}
