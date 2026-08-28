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

        $prefix = (string) ($context['db_prefix'] ?? $context['target_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
        $base_prefix = Rmmigrate_Restore_Topology_Standalone::base_table_prefix($prefix);
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
                $check = $pdo->prepare('SELECT blog_id FROM ' . self::quote_table_name($blogs_table) . ' WHERE blog_id = ?');
                $check->execute(array($target_blog_id));
                if ($check->fetchColumn() !== false) {
                    $stmt = $pdo->prepare('UPDATE ' . self::quote_table_name($blogs_table) . ' SET domain = ?, path = ? WHERE blog_id = ?');
                    $stmt->execute(array($host, $path, $target_blog_id));
                    if ($stmt->rowCount() > 0) {
                        $report['blogs_updated']++;
                    }
                    continue;
                }
            }

            $existing_id = self::find_blog_id($pdo, $blogs_table, $host, $path);
            if ($existing_id > 0) {
                $stmt = $pdo->prepare('UPDATE ' . self::quote_table_name($blogs_table) . ' SET domain = ?, path = ? WHERE blog_id = ?');
                $stmt->execute(array($host, $path, $existing_id));
                if ($stmt->rowCount() > 0) {
                    $report['blogs_updated']++;
                }
                continue;
            }

            if (self::insert_blog_row($pdo, $blogs_table, $host, $path)) {
                $report['blogs_created']++;
            }
        }

        $migration_map = is_array($context['migration_map'] ?? null) ? $context['migration_map'] : array();
        $report['urls_updated'] = Rmmigrate_Restore_Topology_Standalone::update_site_urls_public($pdo, $prefix, $migration_map);

        return $report;
    }

    private static function find_blog_id(PDO $pdo, string $blogs_table, string $host, string $path): int
    {
        $stmt = $pdo->prepare('SELECT blog_id FROM ' . self::quote_table_name($blogs_table) . ' WHERE domain = ? AND path = ? LIMIT 1');
        $stmt->execute(array($host, $path));
        $blog_id = (int) $stmt->fetchColumn();

        return $blog_id > 0 ? $blog_id : 0;
    }

    private static function insert_blog_row(PDO $pdo, string $blogs_table, string $host, string $path): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT MAX(blog_id) FROM ' . self::quote_table_name($blogs_table));
            $stmt->execute();
            $next_id = (int) $stmt->fetchColumn() + 1;
            $insert = $pdo->prepare(
                'INSERT INTO ' . self::quote_table_name($blogs_table) . ' (blog_id, site_id, domain, path, registered, last_updated, public, archived, mature, spam, deleted, lang_id)
                 VALUES (?, 1, ?, ?, NOW(), NOW(), 1, 0, 0, 0, 0, 0)'
            );

            return (bool) $insert->execute(array($next_id, $host, $path));
        } catch (Throwable $e) {
            if (!self::is_duplicate_blog_insert($e)) {
                return false;
            }
            $existing_id = self::find_blog_id($pdo, $blogs_table, $host, $path);
            if ($existing_id <= 0) {
                return false;
            }
            $update = $pdo->prepare('UPDATE ' . self::quote_table_name($blogs_table) . ' SET domain = ?, path = ? WHERE blog_id = ?');
            $update->execute(array($host, $path, $existing_id));

            return true;
        }
    }

    private static function is_duplicate_blog_insert(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $code = (string) $e->getCode();

        return $code === '23000' || stripos($e->getMessage(), 'duplicate') !== false;
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
