<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer: PDO required for migration DDL; no $wpdb without WP core.

class Rmmigrate_Restore_Topology_Prefix_Remap
{
    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $state
     */
    public static function should_remap(array $manifest, array $state): bool
    {
        $policy = (string) ($state['prefix_policy'] ?? $manifest['prefix_policy'] ?? 'keep_source');

        return $policy === 'remap_to_wp'
            && Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest);
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $state
     * @param array<string,mixed> $destination
     */
    public static function default_policy(array $manifest, array $state): string
    {
        $explicit = (string) ($state['prefix_policy'] ?? $manifest['prefix_policy'] ?? '');
        if ($explicit !== '') {
            return $explicit;
        }
        if ((string) ($state['install_mode'] ?? '') === 'overwrite'
            && empty($state['recovery_locked'])
            && Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest)) {
            return 'remap_to_wp';
        }

        return 'keep_source';
    }

    /**
     * @param array<string,mixed> $destination
     * @param array<string,mixed> $manifest
     */
    public static function default_remap_target(array $destination, array $manifest): string
    {
        $dest_prefix = (string) ($destination['table_prefix'] ?? '');
        $source_prefix = Rmmigrate_Restore_Topology_Manifest::source_blog_prefix($manifest);
        if ($dest_prefix !== ''
            && $dest_prefix !== $source_prefix
            && preg_match('/^[a-zA-Z0-9_]+$/', $dest_prefix)) {
            return $dest_prefix;
        }

        return 'wp_';
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $state
     */
    public static function resolve_remap_target(array $manifest, array $state): string
    {
        $target = (string) ($state['prefix_remap_target'] ?? '');
        if ($target !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $target)) {
            return $target;
        }

        return self::default_remap_target(
            is_array($state['destination'] ?? null) ? $state['destination'] : array(),
            $manifest
        );
    }

    /**
     * @param string[] $preserve_tables
     * @return array<string,int>
     */
    public static function remap_tables(PDO $pdo, string $from_prefix, string $to_prefix, array $preserve_tables = array()): array
    {
        $report = array(
            'tables_renamed'        => 0,
            'option_names_remapped' => 0,
            'usermeta_keys_remapped' => 0,
        );
        if ($from_prefix === $to_prefix) {
            return $report;
        }

        $like = str_replace(array('\\', '_', '%'), array('\\\\', '\\_', '\\%'), $from_prefix) . '%';
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($like));
        $tables = $stmt->fetchAll(PDO::FETCH_NUM);

        $preserve = array_fill_keys($preserve_tables, true);

        $to_extends_from = $to_prefix !== ''
            && strncmp($to_prefix, $from_prefix, strlen($from_prefix)) === 0;

        foreach ($tables as $row) {
            $old_table = (string) ($row[0] ?? '');
            if ($old_table === ''
                || strncmp($old_table, $from_prefix, strlen($from_prefix)) !== 0
                || ($to_extends_from && strncmp($old_table, $to_prefix, strlen($to_prefix)) === 0)
                || isset($preserve[$old_table])
                || self::is_plugin_runtime_table($old_table)) {
                continue;
            }
            $suffix = substr($old_table, strlen($from_prefix));
            $new_table = $to_prefix . $suffix;
            if ($new_table === $old_table) {
                continue;
            }
            if (self::table_exists($pdo, $new_table)) {
                if (self::is_plugin_runtime_table($new_table)) {
                    continue;
                }
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $new_table) . '`');
            }
            $pdo->exec(
                'RENAME TABLE `' . str_replace('`', '``', $old_table) . '` TO `'
                . str_replace('`', '``', $new_table) . '`'
            );
            $report['tables_renamed']++;
        }

        $report['option_names_remapped'] = self::remap_option_names($pdo, $to_prefix . 'options', $from_prefix, $to_prefix);
        $report['usermeta_keys_remapped'] = self::remap_usermeta_keys($pdo, $to_prefix . 'usermeta', $from_prefix, $to_prefix);

        return $report;
    }

    public static function remap_option_names(PDO $pdo, string $table, string $from, string $to): int
    {
        if (!self::table_exists($pdo, $table)) {
            return 0;
        }

        $quoted_table = self::quote_table_name($table);
        $from_start = strlen($from) + 1;
        $like = str_replace(array('\\', '_', '%'), array('\\\\', '\\_', '\\%'), $from) . '%';
        $stmt = $pdo->prepare(
            "UPDATE {$quoted_table} SET option_name = CONCAT(?, SUBSTRING(option_name, ?)) WHERE option_name LIKE ? AND SUBSTRING(option_name, ?) NOT REGEXP '^[0-9]+_'"
        );
        $stmt->execute(array($to, $from_start, $like, $from_start));

        return $stmt->rowCount();
    }

    private static function remap_usermeta_keys(PDO $pdo, string $table, string $from, string $to): int
    {
        if (!self::table_exists($pdo, $table)) {
            return 0;
        }

        $quoted_table = self::quote_table_name($table);
        $from_start = strlen($from) + 1;
        $like = str_replace(array('\\', '_', '%'), array('\\\\', '\\_', '\\%'), $from) . '%';
        $stmt = $pdo->prepare(
            "UPDATE {$quoted_table} SET meta_key = CONCAT(?, SUBSTRING(meta_key, ?)) WHERE meta_key LIKE ? AND SUBSTRING(meta_key, ?) NOT REGEXP '^[0-9]+_'"
        );
        $stmt->execute(array($to, $from_start, $like, $from_start));

        return $stmt->rowCount();
    }

    private static function quote_table_name(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }

    private static function table_exists(PDO $pdo, string $table): bool
    {
        try {
            $quoted = str_replace('`', '``', $table);
            $result = $pdo->query('SELECT 1 FROM `' . $quoted . '` LIMIT 1');
            if ($result === false) {
                return false;
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function is_plugin_runtime_table(string $table): bool
    {
        if ($table === '') {
            return false;
        }
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
}
