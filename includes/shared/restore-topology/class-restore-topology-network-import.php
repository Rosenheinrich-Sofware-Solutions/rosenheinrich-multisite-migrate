<?php

require_once dirname(__DIR__) . '/safe-logger.php';

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
                if (class_exists('Rmmigrate_Migration_Preflight', false)) {
                    $host = Rmmigrate_Migration_Preflight::network_domain_from_url($url);
                } else {
                    $parsed = wp_parse_url($url);
                    if (is_array($parsed)) {
                        $host = (string) ($parsed['host'] ?? '');
                        if ($host !== '' && isset($parsed['port'])) {
                            $host .= ':' . (int) $parsed['port'];
                        }
                    }
                }
                $parsed = wp_parse_url($url);
                if (is_array($parsed)) {
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

        $target_blog_id = (int) ($context['subsite_on_network_target_blog_id'] ?? 0);
        if ($target_blog_id <= 0 && isset($map[0]) && is_array($map[0])) {
            $target_blog_id = (int) ($map[0]['target_blog_id'] ?? 0);
        }
        $options_prefix = $base_prefix;
        if ($target_blog_id > 1) {
            $options_prefix = $base_prefix . $target_blog_id . '_';
        }

        $migration_map = is_array($context['migration_map'] ?? null) ? $context['migration_map'] : array();
        $entry = is_array($map[0] ?? null) ? $map[0] : array();
        $target_url = trim((string) ($entry['target_url'] ?? ''));
        $source_url = (string) ($manifest['site_url'] ?? $manifest['url'] ?? '');
        if ($target_url !== '' && $source_url !== '') {
            $migration_map['site_url'] = array(
                'old' => $source_url,
                'new' => $target_url,
            );
        } elseif ($target_url !== '' && empty($migration_map['site_url']['new'])) {
            $migration_map['site_url'] = array('new' => $target_url);
        }
        $report['urls_updated'] = Rmmigrate_Restore_Topology_Standalone::update_site_urls_public(
            $pdo,
            $options_prefix,
            $migration_map
        );

        return $report;
    }

    public static function ensure_blog_for_url(PDO $pdo, string $blogs_table, string $site_url): int
    {
        $allocation = self::ensure_blog_allocation_for_url($pdo, $blogs_table, $site_url);

        return (int) ($allocation['blog_id'] ?? 0);
    }

    /**
     * @return array{blog_id:int,allocated:bool}
     */
    public static function ensure_blog_allocation_for_url(PDO $pdo, string $blogs_table, string $site_url): array
    {
        $parsed_host_path = self::parse_site_url_host_path($site_url);
        if ($parsed_host_path === null) {
            return array('blog_id' => 0, 'allocated' => false);
        }
        if (!self::table_exists($pdo, $blogs_table)) {
            return array('blog_id' => 0, 'allocated' => false);
        }
        $host = (string) ($parsed_host_path['host'] ?? '');
        $path = (string) ($parsed_host_path['path'] ?? '/');
        $existing = self::find_blog_id($pdo, $blogs_table, $host, $path);
        if ($existing > 0) {
            return array('blog_id' => $existing, 'allocated' => false);
        }
        if (!self::insert_blog_row($pdo, $blogs_table, $host, $path)) {
            return array('blog_id' => 0, 'allocated' => false);
        }
        $blog_id = self::find_blog_id($pdo, $blogs_table, $host, $path);

        return array(
            'blog_id'   => $blog_id,
            'allocated' => $blog_id > 0,
        );
    }

    /**
     * Remove a wp_blogs row created for a new-URL subsite target when restore fails or is cancelled.
     *
     * @param array<string,mixed> $record
     */
    public static function revert_allocated_blog_row(PDO $pdo, array $record): bool
    {
        if (empty($record['allocated'])) {
            return false;
        }
        $blog_id = (int) ($record['blog_id'] ?? 0);
        if ($blog_id <= 0) {
            return false;
        }
        $base_prefix = (string) ($record['base_prefix'] ?? '');
        if ($base_prefix === '') {
            return false;
        }
        $blogs_table = $base_prefix . 'blogs';
        if (!self::table_exists($pdo, $blogs_table)) {
            return false;
        }
        try {
            $stmt = $pdo->prepare(
                'DELETE FROM ' . self::quote_table_name($blogs_table) . ' WHERE blog_id = ?'
            );

            return (bool) $stmt->execute(array($blog_id));
        } catch (Throwable $e) {
            rmmigrate_shared_log_best_effort_failure($e);

            return false;
        }
    }

    public static function invalidate_multisite_blog_cache(int $blog_id): void
    {
        if ($blog_id <= 0) {
            return;
        }
        if (function_exists('clean_blog_cache')) {
            clean_blog_cache($blog_id);
        }
        if (function_exists('refresh_blog_details')) {
            refresh_blog_details($blog_id);
        }
    }

    /**
     * @return array{host:string,path:string}|null
     */
    private static function parse_site_url_host_path(string $site_url): ?array
    {
        $host = '';
        $path = '/';
        $parsed = wp_parse_url($site_url);
        if (is_array($parsed)) {
            $host = strtolower((string) ($parsed['host'] ?? ''));
            if ($host !== '' && isset($parsed['port'])) {
                $port = (int) $parsed['port'];
                $scheme = strtolower((string) ($parsed['scheme'] ?? 'http'));
                $default = ($scheme === 'https') ? 443 : 80;
                if ($port !== $default) {
                    $host .= ':' . $port;
                }
            }
            $path = (string) ($parsed['path'] ?? '/');
        }
        if ($host === '') {
            return null;
        }

        return array(
            'host' => $host,
            'path' => self::trailingslashit($path),
        );
    }

    private static function find_blog_id(PDO $pdo, string $blogs_table, string $host, string $path): int
    {
        $stmt = $pdo->prepare('SELECT blog_id FROM ' . self::quote_table_name($blogs_table) . ' WHERE domain = ? AND path = ? LIMIT 1');
        $stmt->execute(array($host, $path));
        $blog_id = (int) $stmt->fetchColumn();

        return $blog_id > 0 ? $blog_id : 0;
    }

    public static function allocate_next_blog_id(PDO $pdo, string $blogs_table): int
    {
        $lock_name = 'rmmigrate_blogs_' . substr(md5($blogs_table), 0, 24);
        if (!self::acquire_named_lock($pdo, $lock_name, 30)) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare('SELECT MAX(blog_id) FROM ' . self::quote_table_name($blogs_table));
            $stmt->execute();

            return (int) $stmt->fetchColumn() + 1;
        } finally {
            self::release_named_lock($pdo, $lock_name);
        }
    }

    private static function insert_blog_row(PDO $pdo, string $blogs_table, string $host, string $path): bool
    {
        try {
            $next_id = self::allocate_next_blog_id($pdo, $blogs_table);
            if ($next_id <= 0) {
                return false;
            }
            $now = gmdate('Y-m-d H:i:s');
            $site_id = self::resolve_network_site_id($pdo, $blogs_table);
            $insert = $pdo->prepare(
                'INSERT INTO ' . self::quote_table_name($blogs_table) . ' (blog_id, site_id, domain, path, registered, last_updated, public, archived, mature, spam, deleted, lang_id)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 0, 0, 0, 0, 0)'
            );

            return (bool) $insert->execute(array($next_id, $site_id, $host, $path, $now, $now));
        } catch (Throwable $e) {
            rmmigrate_shared_log_best_effort_failure($e);
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

    private static function resolve_network_site_id(PDO $pdo, string $blogs_table): int
    {
        $base = preg_replace('/blogs$/', '', $blogs_table) ?? '';
        $site_table = $base . 'site';
        if (self::table_exists($pdo, $site_table)) {
            try {
                $site_id = (int) $pdo->query('SELECT id FROM ' . self::quote_table_name($site_table) . ' LIMIT 1')->fetchColumn();
                if ($site_id > 0) {
                    return $site_id;
                }
            } catch (Throwable $e) {
                rmmigrate_shared_log_best_effort_failure($e);
            }
        }
        try {
            $site_id = (int) $pdo->query('SELECT MIN(site_id) FROM ' . self::quote_table_name($blogs_table))->fetchColumn();
            if ($site_id > 0) {
                return $site_id;
            }
        } catch (Throwable $e) {
            rmmigrate_shared_log_best_effort_failure($e);
        }

        return 1;
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
        return Rmmigrate_Topology_Db_Connection::table_exists($pdo, $table);
    }

    private static function acquire_named_lock(PDO $pdo, string $lock_name, int $timeout_sec): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                return true;
            }
        } catch (Throwable $e) {
            rmmigrate_shared_log_best_effort_failure($e);
            return true;
        }
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute(array($lock_name, max(1, $timeout_sec)));

        return (int) $stmt->fetchColumn() === 1;
    }

    private static function release_named_lock(PDO $pdo, string $lock_name): void
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                return;
            }
        } catch (Throwable $e) {
            rmmigrate_shared_log_best_effort_failure($e);
            return;
        }
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(array($lock_name));
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
