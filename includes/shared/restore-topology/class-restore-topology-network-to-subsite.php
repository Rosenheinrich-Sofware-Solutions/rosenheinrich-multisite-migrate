<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer: PDO required for migration DDL; no $wpdb without WP core.

class Rmmigrate_Restore_Topology_Network_To_Subsite
{
    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function finalize(PDO $pdo, array $manifest, array $context): array
    {
        $report = array(
            'target_blog_updated' => false,
            'urls_updated'        => false,
            'prefix_remapped'     => 0,
        );

        $target_blog_id = (int) ($context['target_blog_id'] ?? $context['destination_blog_id'] ?? 0);
        $target_prefix = (string) ($context['target_prefix'] ?? $context['db_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
        $source_prefix = Rmmigrate_Restore_Topology_Manifest::source_blog_prefix($manifest);
        if ($source_prefix !== $target_prefix) {
            $dest_base = Rmmigrate_Restore_Topology_Standalone::base_table_prefix($target_prefix);
            $preserve = Rmmigrate_Restore_Topology_Db_Action::network_global_table_names($dest_base);
            $remap = Rmmigrate_Restore_Topology_Prefix_Remap::remap_tables(
                $pdo,
                $source_prefix,
                $target_prefix,
                $preserve
            );
            $report['prefix_remapped'] = (int) ($remap['tables_renamed'] ?? 0);
        }

        $base_prefix = Rmmigrate_Restore_Topology_Standalone::base_table_prefix($target_prefix);
        $blogs_table = $base_prefix . 'blogs';
        $migration_map = is_array($context['migration_map'] ?? null) ? $context['migration_map'] : array();
        $new_url = (string) ($migration_map['site_url']['new'] ?? '');
        if ($target_blog_id > 0 && $new_url !== '' && self::table_exists($pdo, $blogs_table)) {
            $host = (string) wp_parse_url($new_url, PHP_URL_HOST);
            $path = (string) (wp_parse_url($new_url, PHP_URL_PATH) ?? '/');
            if ($host !== '') {
                $stmt = $pdo->prepare('UPDATE ' . self::quote_table_name($blogs_table) . ' SET domain = ?, path = ? WHERE blog_id = ?');
                $stmt->execute(array($host, self::trailingslashit($path), $target_blog_id));
                $report['target_blog_updated'] = $stmt->rowCount() > 0;
            }
        }

        $report['urls_updated'] = Rmmigrate_Restore_Topology_Standalone::update_site_urls_public(
            $pdo,
            $target_prefix,
            $migration_map
        );

        return $report;
    }

    private static function quote_table_name(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }

    private static function table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array(self::escape_like_literal($table)));

        return (bool) $stmt->fetchColumn();
    }

    private static function escape_like_literal(string $value): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
    }

    private static function trailingslashit(string $path): string
    {
        return rtrim($path, '/') . '/';
    }
}
