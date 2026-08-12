<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer: PDO required for migration DDL; no $wpdb without WP core.

class Rmmigrate_Restore_Topology_Network_Import
{
    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function finalize(PDO $pdo, array $manifest, array $context): array
    {
        $report = array(
            'blogs_updated' => 0,
            'blogs_created' => 0,
            'urls_updated'  => false,
        );

        $map = is_array($context['site_owr_map'] ?? null) ? $context['site_owr_map'] : array();
        if ($map === array()) {
            return $report;
        }

        $base_prefix = Rmmigrate_Restore_Topology_Manifest::source_base_prefix($manifest);
        $blogs_table = $base_prefix . 'blogs';
        if (!self::table_exists($pdo, $blogs_table)) {
            return $report;
        }

        foreach ($map as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $target_blog_id = (int) ($entry['target_blog_id'] ?? 0);
            $url = (string) ($entry['target_url'] ?? $entry['url'] ?? '');
            $host = '';
            $path = '/';
            if ($url !== '') {
                $parsed = wp_parse_url($url);
                if (is_array($parsed)) {
                    $host = (string) ($parsed['host'] ?? '');
                    $path = (string) ($parsed['path'] ?? '/');
                }
            }
            if ($host === '') {
                continue;
            }
            $path = self::trailingslashit($path);

            if ($target_blog_id > 0) {
                $stmt = $pdo->prepare("UPDATE `{$blogs_table}` SET domain = ?, path = ? WHERE blog_id = ?");
                $stmt->execute(array($host, $path, $target_blog_id));
                if ($stmt->rowCount() > 0) {
                    $report['blogs_updated']++;
                }
                continue;
            }

            $stmt = $pdo->prepare("SELECT MAX(blog_id) FROM `{$blogs_table}`");
            $stmt->execute();
            $next_id = (int) $stmt->fetchColumn() + 1;
            $insert = $pdo->prepare(
                "INSERT INTO `{$blogs_table}` (blog_id, site_id, domain, path, registered, last_updated, public, archived, mature, spam, deleted, lang_id)
                 VALUES (?, 1, ?, ?, NOW(), NOW(), 1, 0, 0, 0, 0, 0)"
            );
            if ($insert->execute(array($next_id, $host, $path))) {
                $report['blogs_created']++;
            }
        }

        $migration_map = is_array($context['migration_map'] ?? null) ? $context['migration_map'] : array();
        $prefix = (string) ($context['db_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
        $report['urls_updated'] = Rmmigrate_Restore_Topology_Standalone::update_site_urls_public($pdo, $prefix, $migration_map);

        return $report;
    }

    private static function table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($table));

        return (bool) $stmt->fetchColumn();
    }

    private static function trailingslashit(string $path): string
    {
        return rtrim($path, '/') . '/';
    }
}
