<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer: installer globals and PDO without WP core.

class Rmmigrate_Restore_Topology_Destination
{
    /**
     * @param array<string,mixed> $probe
     */
    public static function enrich_probe(array $probe, ?PDO $pdo = null): array
    {
        $prefix = (string) ($probe['table_prefix'] ?? 'wp_');
        $site_url = (string) ($probe['site_url'] ?? '');
        $probe['is_multisite_subsite'] = false;
        $probe['destination_blog_id'] = 0;
        $probe['network_domain'] = (string) ($probe['network_domain'] ?? '');
        $probe['shared_db_foreign_blogs'] = 0;

        if (!empty($probe['is_multisite']) && $pdo instanceof PDO) {
            $probe['destination_blog_id'] = self::resolve_blog_id($pdo, $prefix, $site_url);
            $path = self::site_path_from_url($site_url);
            $probe['is_multisite_subsite'] = $probe['destination_blog_id'] > 1
                || ($path !== '' && $path !== '/');
            $probe['shared_db_foreign_blogs'] = self::count_foreign_prefix_families($pdo, $prefix);
        }

        return $probe;
    }

    /**
     * Build destination profile for in-plugin restore.
     *
     * @return array<string,mixed>
     */
    public static function probe_for_plugin(): array
    {
        global $wpdb;

        $probe = array(
            'has_wp_config'            => true,
            'has_wp_core'              => is_file(ABSPATH . 'wp-settings.php'),
            'health'                   => is_file(ABSPATH . 'wp-settings.php') ? 'ok' : 'partial',
            'table_prefix'             => $wpdb->prefix,
            'site_url'                 => is_multisite() ? network_site_url() : site_url(),
            'is_multisite'             => is_multisite(),
            'has_network_tables'       => is_multisite(),
            'network_domain'           => defined('DOMAIN_CURRENT_SITE') ? (string) DOMAIN_CURRENT_SITE : '',
            'db_reachable'             => true,
            'destination_blog_id'    => is_multisite() ? (int) get_current_blog_id() : 1,
            'is_multisite_subsite'     => is_multisite() && (int) get_current_blog_id() > 1,
            'shared_db_foreign_blogs'  => 0,
        );

        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASSWORD,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
            $probe = self::enrich_probe($probe, $pdo);
            $pdo = null;
        } catch (Throwable $e) {
            $probe['db_reachable'] = false;
        }

        return $probe;
    }

    /**
     * @param callable|null $read Optional reader (string $path): string. Falls back to the
     *                            plugin filesystem only when available (installer passes its own).
     * @return array<string,string>
     */
    public static function parse_wp_config(string $path, ?callable $read = null): array
    {
        if ($read !== null) {
            $contents = (string) $read($path);
        } elseif (class_exists('Rmmigrate_Filesystem')) {
            $contents = Rmmigrate_Filesystem::exists($path)
                ? (string) Rmmigrate_Filesystem::get_contents($path)
                : '';
        } else {
            $contents = '';
        }
        if ($contents === '') {
            return array();
        }

        $out = array();
        foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DOMAIN_CURRENT_SITE') as $constant) {
            $value = self::read_wp_config_constant($contents, $constant);
            if ($value !== null) {
                $out[$constant] = $value;
            }
        }
        if (preg_match('/\$table_prefix\s*=\s*(["\'])((?:\\\\.|(?!\1).)*)\1\s*;/', $contents, $prefix_match)) {
            $out['table_prefix'] = stripcslashes((string) ($prefix_match[2] ?? ''));
        }

        return $out;
    }

    public static function read_wp_config_table_prefix(string $path, ?callable $read = null): string
    {
        $parsed = self::parse_wp_config($path, $read);

        return (string) ($parsed['table_prefix'] ?? '');
    }

    public static function read_wp_config_constant(string $contents, string $name): ?string
    {
        $quoted = preg_quote($name, '/');
        if (preg_match(
            "/define\\s*\\(\\s*['\"]{$quoted}['\"]\\s*,\\s*['\"]((?:\\\\.|[^'\"\\\\])*)['\"]\\s*\\)/",
            $contents,
            $m
        )) {
            return stripcslashes($m[1]);
        }

        return null;
    }

    private static function site_path_from_url(string $url): string
    {
        if ($url === '') {
            return '/';
        }
        $parsed = wp_parse_url($url);

        return is_array($parsed) ? (string) ($parsed['path'] ?? '/') : '/';
    }

    private static function resolve_blog_id(PDO $pdo, string $prefix, string $site_url): int
    {
        $base_prefix = Rmmigrate_Restore_Topology_Standalone::base_table_prefix($prefix);
        $blogs_table = $base_prefix . 'blogs';
        if (!self::table_exists($pdo, $blogs_table)) {
            return 1;
        }

        $host = '';
        $path = '/';
        if ($site_url !== '') {
            $parsed = wp_parse_url($site_url);
            if (is_array($parsed)) {
                $host = (string) ($parsed['host'] ?? '');
                $path = (string) ($parsed['path'] ?? '/');
            }
        }
        if ($host === '') {
            return 1;
        }

        $stmt = $pdo->prepare("SELECT blog_id FROM `{$blogs_table}` WHERE domain = ? AND path = ? LIMIT 1");
        $stmt->execute(array($host, self::trailingslashit($path)));
        $blog_id = (int) $stmt->fetchColumn();

        return $blog_id > 0 ? $blog_id : 1;
    }

    private static function count_foreign_prefix_families(PDO $pdo, string $current_prefix): int
    {
        $stmt = $pdo->query('SHOW TABLES');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : array();
        $families = array();
        foreach ($rows as $row) {
            $table = (string) ($row[0] ?? '');
            if ($table === '' || !str_ends_with($table, 'options')) {
                continue;
            }
            $family = substr($table, 0, -strlen('options'));
            if ($family !== '' && $family !== $current_prefix) {
                $families[$family] = true;
            }
        }

        return count($families);
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
