<?php

require_once dirname(__DIR__) . '/safe-logger.php';

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.RestrictedClasses.mysql__PDO -- Standalone installer: installer globals and PDO without WP core.

class Rmmigrate_Restore_Topology_Standalone
{
    /**
     * @return string[]
     */
    private static function multisite_define_names(): array
    {
        return array(
            'WP_ALLOW_MULTISITE',
            'MULTISITE',
            'SUBDOMAIN_INSTALL',
            'DOMAIN_CURRENT_SITE',
            'PATH_CURRENT_SITE',
            'SITE_ID_CURRENT_SITE',
            'BLOG_ID_CURRENT_SITE',
            'COOKIE_DOMAIN',
            'SUNRISE',
            'NOBLOGREDIRECT',
        );
    }

    public static function strip_multisite_wp_config(string $contents): string
    {
        $eol = "\n";
        if (preg_match('/\r\n/', $contents)) {
            $eol = "\r\n";
        } elseif (preg_match('/\r(?!\n)/', $contents)) {
            $eol = "\r";
        }

        $lines = preg_split('/\R/', $contents);
        if (!is_array($lines)) {
            return $contents;
        }

        $filtered = array();
        $skipping_define = false;
        $paren_balance = 0;
        $skip_base_assignment = false;
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (!$skipping_define && self::line_is_multisite_define($trimmed)) {
                $skipping_define = true;
                $paren_balance = self::paren_balance_delta($trimmed);
                $skip_base_assignment = true;
                if ($paren_balance <= 0) {
                    $skipping_define = false;
                    $paren_balance = 0;
                }
                continue;
            }
            if ($skipping_define) {
                $paren_balance += self::paren_balance_delta($trimmed);
                if ($paren_balance <= 0) {
                    $skipping_define = false;
                    $paren_balance = 0;
                }
                continue;
            }
            if ($skip_base_assignment && preg_match('/^\$base\s*=/', $trimmed)) {
                continue;
            }
            if ($skip_base_assignment && $trimmed !== '' && !preg_match('/^\s*(\/\/|\/\*|\*)/', $trimmed)) {
                $skip_base_assignment = false;
            }
            $filtered[] = $line;
        }

        return implode($eol, $filtered);
    }

    private static function paren_balance_delta(string $line): int
    {
        $delta = 0;
        $in_single = false;
        $in_double = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($in_single) {
                if ($ch === '\\' && ($i + 1) < $len) {
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $in_single = false;
                }
                continue;
            }
            if ($in_double) {
                if ($ch === '\\' && ($i + 1) < $len) {
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $in_double = false;
                }
                continue;
            }
            if ($ch === "'") {
                $in_single = true;
                continue;
            }
            if ($ch === '"') {
                $in_double = true;
                continue;
            }
            if ($ch === '(') {
                $delta++;
            } elseif ($ch === ')') {
                $delta--;
            }
        }

        return $delta;
    }

    private static function line_is_multisite_define(string $line): bool
    {
        foreach (self::multisite_define_names() as $name) {
            $quoted = preg_quote($name, '/');
            if (preg_match("/define\\s*\\(\\s*(?:['\"]{$quoted}['\"]|{$quoted})\\s*,/", $line)) {
                return true;
            }
        }

        return false;
    }

    public static function base_table_prefix(string $subsite_prefix): string
    {
        if (preg_match('/^(.+)_\d+_$/', $subsite_prefix, $matches)) {
            return $matches[1] . '_';
        }

        return $subsite_prefix;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function finish(PDO $pdo, array $manifest, array $context): array
    {
        $restore_mode = Rmmigrate_Restore_Topology_Manifest::restore_mode($manifest, $context);
        if ($restore_mode === 'subsite_on_network') {
            $report = Rmmigrate_Restore_Topology_Network_Import::finalize($pdo, $manifest, $context);
            $report['user_merge'] = Rmmigrate_Restore_Topology_User_Merge::run($pdo, $manifest, $context);
            $report['caps_meta_remapped'] = $report['user_merge']['caps_meta_remapped']
                ?? Rmmigrate_Restore_Topology_User_Merge::remap_caps_meta_without_user_merge(
                    $pdo,
                    $manifest,
                    $context
                );

            return $report;
        }
        if ($restore_mode === 'network_to_subsite') {
            return Rmmigrate_Restore_Topology_Network_To_Subsite::finalize($pdo, $manifest, $context);
        }
        if (!Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest)) {
            if (Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
                return self::finalize_network_domains($pdo, $manifest, $context);
            }

            return array();
        }

        $prefix = (string) ($context['db_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
        $base_prefix = self::base_table_prefix($prefix);
        $report = array(
            'network_tables_dropped'     => 0,
            'conflicting_tables_dropped' => 0,
            'usermeta_rows_purged'       => 0,
            'urls_updated'               => false,
            'option_names_remapped'      => 0,
        );

        if ($base_prefix !== '' && $base_prefix !== $prefix) {
            foreach (array('users', 'usermeta') as $g_table) {
                $old_g = $base_prefix . $g_table;
                $new_g = $prefix . $g_table;
                if (self::table_exists($pdo, $old_g)) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $new_g) . '`');
                    $pdo->exec('RENAME TABLE `' . str_replace('`', '``', $old_g) . '` TO `' . str_replace('`', '``', $new_g) . '`');
                }
            }
        }

        $prefix_remap_applied = false;
        if (Rmmigrate_Restore_Topology_Prefix_Remap::should_remap($manifest, $context)) {
            $resolved_prefix = Rmmigrate_Restore_Topology_Prefix_Remap::resolve_remap_target($manifest, $context);
            $remap = Rmmigrate_Restore_Topology_Prefix_Remap::remap_tables($pdo, $prefix, $resolved_prefix);
            $report = array_merge($report, $remap);
            $prefix = $resolved_prefix;
            $prefix_remap_applied = true;
        }

        $base_prefix = self::base_table_prefix($prefix);

        foreach (self::network_table_names($base_prefix) as $table) {
            if (self::table_exists($pdo, $table)) {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
                $report['network_tables_dropped']++;
            }
        }

        $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
        $dest_prefix = (string) ($destination['table_prefix'] ?? '');
        if ($dest_prefix !== '' && $dest_prefix !== $prefix) {
            $report['conflicting_tables_dropped'] += self::drop_prefixed_tables($pdo, $dest_prefix);
        }

        if (!$prefix_remap_applied) {
            $report['usermeta_rows_purged'] = self::purge_foreign_usermeta($pdo, $prefix, $base_prefix);
            $report['option_names_remapped'] = self::update_options_table($pdo, $prefix, $base_prefix);
        }
        $report['urls_updated'] = self::update_site_urls($pdo, $prefix, self::migration_map($context));

        return $report;
    }

    /**
     * Rewrite the network topology tables (site, blogs, sitemeta) for a full
     * multisite network restore when the primary domain changes.
     *
     * The standalone installer otherwise leaves wp_blogs.domain / wp_site.domain
     * on the source host (the post-import URL scan only touches
     * options/posts/postmeta/comments/usermeta), so the destination has no blog
     * row matching the requested host and WordPress redirects to
     * wp-signup.php?new=<host>. Mirrors the in-plugin restore path
     * (class-restore-runner.php blog-domain remap).
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function apply_migration_network_urls_early(PDO $pdo, array $manifest, array $context): array
    {
        if (!Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            return array();
        }
        if (!empty($context['migration_network_domains_applied'])) {
            return array('migration_network_domains_applied' => true);
        }

        $report = self::finalize_network_domains($pdo, $manifest, $context);
        $report['migration_network_domains_applied'] = true;

        return $report;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function finalize_network_domains(PDO $pdo, array $manifest, array $context): array
    {
        $report = array(
            'network_blogs_updated' => 0,
            'network_site_updated'  => 0,
            'network_meta_updated'  => 0,
            'urls_updated'          => false,
        );
        if (!empty($context['migration_network_domains_applied'])) {
            return $report;
        }

        $prefix = (string) ($context['db_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
        $base_prefix = self::base_table_prefix($prefix);

        // Primary site siteurl/home (blog 1 options table).
        $report['urls_updated'] = self::update_site_urls($pdo, $prefix, self::migration_map($context));

        $path_report = self::finalize_network_paths($pdo, $manifest, $context, $base_prefix);
        $report = array_merge($report, $path_report);

        $map = is_array($context['migration_map'] ?? null) ? $context['migration_map'] : array();
        if (class_exists('Rmmigrate_Migration_Preflight', false)) {
            $map = Rmmigrate_Migration_Preflight::enrich_migration_map_paths($map, $manifest);
        }
        $old_path = class_exists('Rmmigrate_Migration_Preflight', false)
            ? Rmmigrate_Migration_Preflight::normalize_site_path((string) ($map['site_path']['old'] ?? '/'))
            : '/';
        $new_path = class_exists('Rmmigrate_Migration_Preflight', false)
            ? Rmmigrate_Migration_Preflight::normalize_site_path((string) ($map['site_path']['new'] ?? '/'))
            : '/';
        $sitemeta = $base_prefix . 'sitemeta';
        if ($old_path !== $new_path && self::table_exists($pdo, $sitemeta)) {
            $report['network_meta_updated'] += self::update_network_siteurl_paths(
                $pdo,
                $sitemeta,
                $old_path,
                $new_path
            );
        }

        $old_host = self::url_host((string) ($map['site_url']['old'] ?? $map['old_url'] ?? ''));
        $new_host = self::url_host((string) ($map['site_url']['new'] ?? $map['new_url'] ?? ''));
        if ($old_host === '' || $new_host === '' || strcasecmp($old_host, $new_host) === 0) {
            return $report;
        }

        // Remap exact host or *.old_host subdomains only — never substring hosts like notoldhost.com.
        $blogs = $base_prefix . 'blogs';
        if (self::table_exists($pdo, $blogs)) {
            $report['network_blogs_updated'] = self::update_network_domain_column($pdo, $blogs, $old_host, $new_host);
        }

        $site = $base_prefix . 'site';
        if (self::table_exists($pdo, $site)) {
            $report['network_site_updated'] = self::update_network_domain_column($pdo, $site, $old_host, $new_host);
        }

        $sitemeta = $base_prefix . 'sitemeta';
        if (self::table_exists($pdo, $sitemeta)) {
            $report['network_meta_updated'] += self::update_network_siteurl_meta($pdo, $sitemeta, $old_host, $new_host);
        }

        return $report;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return array<string,int|bool>
     */
    private static function finalize_network_paths(PDO $pdo, array $manifest, array $context, string $base_prefix): array
    {
        $report = array(
            'network_paths_updated' => 0,
        );
        if (!empty($context['migration_network_paths_applied'])) {
            return $report;
        }
        if (!empty($manifest['subdomain_install'])) {
            return $report;
        }
        $map = is_array($context['migration_map'] ?? null) ? $context['migration_map'] : array();
        if (class_exists('Rmmigrate_Migration_Preflight', false)) {
            $map = Rmmigrate_Migration_Preflight::enrich_migration_map_paths($map, $manifest);
        }
        $old_path = class_exists('Rmmigrate_Migration_Preflight', false)
            ? Rmmigrate_Migration_Preflight::normalize_site_path((string) ($map['site_path']['old'] ?? '/'))
            : '/';
        $new_path = class_exists('Rmmigrate_Migration_Preflight', false)
            ? Rmmigrate_Migration_Preflight::normalize_site_path((string) ($map['site_path']['new'] ?? '/'))
            : '/';
        if ($old_path === $new_path) {
            return $report;
        }

        $blogs = $base_prefix . 'blogs';
        if (self::table_exists($pdo, $blogs)) {
            $report['network_paths_updated'] += self::update_network_path_column($pdo, $blogs, $old_path, $new_path);
        }
        $site = $base_prefix . 'site';
        if (self::table_exists($pdo, $site)) {
            $report['network_paths_updated'] += self::update_network_path_column($pdo, $site, $old_path, $new_path);
        }

        if ($report['network_paths_updated'] > 0) {
            $report['migration_network_paths_applied'] = true;
        }

        return $report;
    }

    private static function update_network_path_column(PDO $pdo, string $table, string $old_path, string $new_path): int
    {
        if ($old_path === $new_path) {
            return 0;
        }
        $table_q = self::quote_sql_identifier($table);
        $pk = self::network_table_row_key_column($pdo, $table);
        $select = $pdo->query("SELECT {$pk} AS row_id, path FROM {$table_q}");
        if ($select === false) {
            return 0;
        }
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === array()) {
            return 0;
        }
        $paths_by_id = array();
        foreach ($rows as $row) {
            $paths_by_id[(int) ($row['row_id'] ?? 0)] = (string) ($row['path'] ?? '');
        }
        $update = $pdo->prepare("UPDATE {$table_q} SET path = ? WHERE {$pk} = ?");
        $updated = 0;
        foreach ($rows as $row) {
            $row_id = (int) ($row['row_id'] ?? 0);
            $path = (string) ($row['path'] ?? '');
            if ($row_id <= 0 || $path === '') {
                continue;
            }
            $mapped = self::remap_network_path_once($path, $old_path, $new_path);
            if ($mapped === $path) {
                continue;
            }
            if (self::network_path_collision($paths_by_id, $row_id, $mapped)) {
                continue;
            }
            $update->execute(array($mapped, $row_id));
            $updated += $update->rowCount();
            $paths_by_id[$row_id] = $mapped;
        }

        return $updated;
    }

    /**
     * @param array<int,string> $paths_by_id
     */
    private static function network_path_collision(array $paths_by_id, int $blog_id, string $candidate): bool
    {
        $candidate_norm = self::normalize_network_path_for_compare($candidate);
        foreach ($paths_by_id as $id => $path) {
            if ((int) $id === $blog_id) {
                continue;
            }
            if (self::normalize_network_path_for_compare($path) === $candidate_norm) {
                return true;
            }
        }

        return false;
    }

    private static function normalize_network_path_for_compare(string $path): string
    {
        if (class_exists('Rmmigrate_Migration_Preflight', false)) {
            return Rmmigrate_Migration_Preflight::normalize_site_path($path);
        }
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return rtrim($path, '/') . '/';
    }

    private static function network_table_row_key_column(PDO $pdo, string $table): string
    {
        $table_q = self::quote_sql_identifier($table);
        try {
            $probe = $pdo->query("SELECT * FROM {$table_q} LIMIT 0");
            if ($probe !== false) {
                $columns = array();
                for ($i = 0; $i < $probe->columnCount(); $i++) {
                    $meta = $probe->getColumnMeta($i);
                    if (is_array($meta) && isset($meta['name'])) {
                        $columns[] = strtolower((string) $meta['name']);
                    }
                }
                if (in_array('blog_id', $columns, true)) {
                    return 'blog_id';
                }
                if (in_array('id', $columns, true)) {
                    return 'id';
                }
            }
        } catch (Throwable $e) {
            rmmigrate_shared_log_best_effort_failure($e);
        }

        return 'blog_id';
    }

    private static function remap_network_path_once(string $path, string $old_path, string $new_path): string
    {
        if ($path === $old_path) {
            return $new_path;
        }
        if ($new_path !== '/' && strlen($new_path) > strlen($old_path) && strncmp($path, $new_path, strlen($new_path)) === 0) {
            return $path;
        }
        if ($old_path === '/' && strncmp($path, '/', 1) === 0 && $path !== '/') {
            return $new_path . ltrim($path, '/');
        }
        if ($old_path !== '/' && strncmp($path, $old_path, strlen($old_path)) === 0) {
            return $new_path . substr($path, strlen($old_path));
        }

        return $path;
    }

    private static function host_matches_migration(string $host, string $old_host): bool
    {
        if ($host === '' || $old_host === '') {
            return false;
        }
        if (strcasecmp($host, $old_host) === 0) {
            return true;
        }
        $suffix = '.' . $old_host;
        if (strlen($host) <= strlen($suffix)) {
            return false;
        }

        return strcasecmp(substr($host, -strlen($suffix)), $suffix) === 0;
    }

    private static function remap_host(string $host, string $old_host, string $new_host): string
    {
        if (strcasecmp($host, $old_host) === 0) {
            return $new_host;
        }

        return substr($host, 0, -strlen($old_host) - 1) . '.' . $new_host;
    }

    private static function replace_url_host(string $url, string $old_host, string $new_host): string
    {
        $host = self::url_host($url);
        if ($host === '' || !self::host_matches_migration($host, $old_host)) {
            return $url;
        }

        $replacement = self::remap_host($host, $old_host, $new_host);

        return (string) preg_replace('/' . preg_quote($host, '/') . '/i', $replacement, $url, 1);
    }

    private static function update_network_domain_column(PDO $pdo, string $table, string $old_host, string $new_host): int
    {
        $variants = self::migration_old_host_variants($old_host);
        $table_q = self::quote_sql_identifier($table);
        $pk = self::network_table_row_key_column($pdo, $table);
        $select = $pdo->query("SELECT {$pk} AS row_id, domain FROM {$table_q}");
        if ($select === false) {
            return 0;
        }
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === array()) {
            return 0;
        }
        $update = $pdo->prepare("UPDATE {$table_q} SET domain = ? WHERE {$pk} = ?");
        $updated = 0;
        foreach ($rows as $row) {
            $row_id = (int) ($row['row_id'] ?? 0);
            $original = (string) ($row['domain'] ?? '');
            if ($row_id <= 0 || $original === '') {
                continue;
            }
            $mapped = $original;
            foreach ($variants as $variant) {
                $candidate = self::remap_network_domain($original, $variant, $new_host);
                if ($candidate !== $original) {
                    $mapped = $candidate;
                    break;
                }
            }
            if ($mapped === $original) {
                continue;
            }
            $update->execute(array($mapped, $row_id));
            $updated += $update->rowCount();
        }

        return $updated;
    }

    /**
     * @return string[]
     */
    private static function migration_old_host_variants(string $old_host): array
    {
        $variants = array($old_host);
        if (preg_match('/^([^:]+):\d+$/', $old_host, $matches)) {
            $variants[] = (string) ($matches[1] ?? '');
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private static function update_network_domain_column_for_host(PDO $pdo, string $table, string $old_host, string $new_host): int
    {
        $table_q = self::quote_sql_identifier($table);
        $pk = self::network_table_row_key_column($pdo, $table);
        $select = $pdo->query("SELECT {$pk} AS row_id, domain FROM {$table_q}");
        if ($select === false) {
            return 0;
        }
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === array()) {
            return 0;
        }
        $update = $pdo->prepare("UPDATE {$table_q} SET domain = ? WHERE {$pk} = ?");
        $updated = 0;
        foreach ($rows as $row) {
            $row_id = (int) ($row['row_id'] ?? 0);
            $domain = (string) ($row['domain'] ?? '');
            if ($row_id <= 0 || $domain === '') {
                continue;
            }
            $mapped = self::remap_network_domain($domain, $old_host, $new_host);
            if ($mapped === $domain) {
                continue;
            }
            $update->execute(array($mapped, $row_id));
            $updated += $update->rowCount();
        }

        return $updated;
    }

    private static function remap_network_domain(string $domain, string $old_host, string $new_host): string
    {
        if (strcasecmp($domain, $old_host) === 0) {
            return $new_host;
        }
        $suffix = '.' . $old_host;
        if (strlen($domain) > strlen($suffix) && strcasecmp(substr($domain, -strlen($suffix)), $suffix) === 0) {
            return substr($domain, 0, -strlen($suffix)) . '.' . $new_host;
        }

        return $domain;
    }

    private static function update_network_siteurl_paths(
        PDO $pdo,
        string $table,
        string $old_path,
        string $new_path
    ): int {
        if ($old_path === $new_path) {
            return 0;
        }
        $table_q = self::quote_sql_identifier($table);
        $select = $pdo->prepare("SELECT meta_id, meta_value FROM {$table_q} WHERE meta_key = 'siteurl'");
        $select->execute();
        $updated = 0;
        $update = $pdo->prepare("UPDATE {$table_q} SET meta_value = ? WHERE meta_id = ?");
        while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
            $meta_id = (int) ($row['meta_id'] ?? 0);
            $value = (string) ($row['meta_value'] ?? '');
            if ($meta_id <= 0 || $value === '') {
                continue;
            }
            $new_value = self::replace_url_path($value, $old_path, $new_path);
            if ($new_value === $value) {
                continue;
            }
            $update->execute(array($new_value, $meta_id));
            $updated += $update->rowCount();
        }

        return $updated;
    }

    private static function replace_url_path(string $url, string $old_path, string $new_path): string
    {
        $parsed = function_exists('wp_parse_url')
            ? wp_parse_url($url)
            : parse_url($url); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- installer may run without WP.
        if (!is_array($parsed)) {
            return $url;
        }
        $path = (string) ($parsed['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }
        $mapped = self::remap_network_path_once($path, $old_path, $new_path);
        if ($mapped === $path) {
            return $url;
        }
        $parsed['path'] = $mapped;
        $rebuilt = '';
        if (!empty($parsed['scheme'])) {
            $rebuilt .= $parsed['scheme'] . '://';
        }
        if (!empty($parsed['user'])) {
            $rebuilt .= $parsed['user'];
            if (!empty($parsed['pass'])) {
                $rebuilt .= ':' . $parsed['pass'];
            }
            $rebuilt .= '@';
        }
        if (!empty($parsed['host'])) {
            $rebuilt .= $parsed['host'];
        }
        if (!empty($parsed['port'])) {
            $rebuilt .= ':' . (int) $parsed['port'];
        }
        $rebuilt .= $mapped;
        if (!empty($parsed['query'])) {
            $rebuilt .= '?' . $parsed['query'];
        }
        if (!empty($parsed['fragment'])) {
            $rebuilt .= '#' . $parsed['fragment'];
        }

        return $rebuilt;
    }

    private static function update_network_siteurl_meta(PDO $pdo, string $table, string $old_host, string $new_host): int
    {
        $table_q = self::quote_sql_identifier($table);
        $select = $pdo->prepare("SELECT meta_id, meta_value FROM {$table_q} WHERE meta_key = 'siteurl'");
        $select->execute();
        $updated = 0;
        $update = $pdo->prepare("UPDATE {$table_q} SET meta_value = ? WHERE meta_id = ?");
        while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
            $meta_id = (int) ($row['meta_id'] ?? 0);
            $value = (string) ($row['meta_value'] ?? '');
            if ($meta_id <= 0 || $value === '') {
                continue;
            }
            $new_value = self::replace_url_host($value, $old_host, $new_host);
            if ($new_value === $value) {
                continue;
            }
            $update->execute(array($new_value, $meta_id));
            $updated += $update->rowCount();
        }

        return $updated;
    }

    private static function url_host(string $url): string
    {
        if (class_exists('Rmmigrate_Migration_Preflight', false)) {
            return Rmmigrate_Migration_Preflight::network_domain_from_url($url);
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '//') === false) {
            $url = '//' . $url;
        }
        $parsed = function_exists('wp_parse_url')
            ? wp_parse_url($url)
            : parse_url($url); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- standalone restore runs without WP; wp_parse_url() unavailable, used when present.
        if (!is_array($parsed)) {
            return '';
        }
        $host = (string) ($parsed['host'] ?? '');
        if ($host === '') {
            return '';
        }
        $port = isset($parsed['port']) ? (int) $parsed['port'] : 0;

        return $port > 0 ? $host . ':' . $port : $host;
    }

    public static function update_options_table(PDO $pdo, string $prefix, string $base_prefix): int
    {
        if ($prefix === $base_prefix) {
            return 0;
        }

        $table = $prefix . 'options';
        if (!self::table_exists($pdo, $table)) {
            return 0;
        }

        $table_q = self::quote_sql_identifier($table);
        $stmt = $pdo->prepare(
            "UPDATE {$table_q} SET option_name = ? WHERE option_name = ?"
        );
        $stmt->execute(array($base_prefix . 'user_roles', $prefix . 'user_roles'));

        return $stmt->rowCount();
    }

    /**
     * @return string[]
     */
    private static function network_table_names(string $base_prefix): array
    {
        $suffixes = array('site', 'blogs', 'blogmeta', 'sitemeta', 'registration_log', 'signups');

        return array_map(
            static function (string $suffix) use ($base_prefix): string {
                return $base_prefix . $suffix;
            },
            $suffixes
        );
    }

    private static function table_exists(PDO $pdo, string $table): bool
    {
        if (class_exists('Rmmigrate_Topology_Db_Connection', false)) {
            return Rmmigrate_Topology_Db_Connection::table_exists($pdo, $table);
        }

        return false;
    }

    private static function escape_like_literal(string $value): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
    }

    private static function quote_sql_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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
            // When prefix is the network base (wp_), never drop other blogs' wp_N_* tables.
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
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : array();
        } else {
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
     * Plugin job tables must survive standalone drops (same patterns as Db_Action).
     */
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

    private static function purge_foreign_usermeta(PDO $pdo, string $prefix, string $base_prefix): int
    {
        $table = $prefix . 'usermeta';
        if (!self::table_exists($pdo, $table)) {
            return 0;
        }

        $base_len = strlen($base_prefix);
        $prefix_len = strlen($prefix);
        $base_like = self::escape_sql_like($base_prefix) . '%';
        $table_q = self::quote_sql_identifier($table);

        $stmt = $pdo->prepare(
            "DELETE FROM {$table_q} WHERE SUBSTRING(`meta_key`, 1, {$base_len}) = ? AND SUBSTRING(`meta_key`, 1, {$prefix_len}) <> ? AND `meta_key` LIKE ? ESCAPE '\\\\'"
        );
        $stmt->execute(array($base_prefix, $prefix, $base_like));
        $purged = $stmt->rowCount();

        $update = $pdo->prepare(
            "UPDATE {$table_q} SET `meta_key` = CONCAT(?, SUBSTRING(`meta_key`, ?)) WHERE SUBSTRING(`meta_key`, 1, {$prefix_len}) = ?"
        );
        $update->execute(array($base_prefix, $prefix_len + 1, $prefix));

        return $purged;
    }

    private static function escape_sql_like(string $value): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function migration_map(array $context): array
    {
        $map = is_array($context['migration_map'] ?? null) ? $context['migration_map'] : array();
        if (!empty($map['site_url']['new'])) {
            return array('new_url' => (string) $map['site_url']['new']);
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $migration_map
     */
    public static function update_site_urls_public(PDO $pdo, string $prefix, array $migration_map): bool
    {
        return self::update_site_urls($pdo, $prefix, $migration_map);
    }

    /**
     * @param array<string,mixed> $migration_map
     */
    private static function update_site_urls(PDO $pdo, string $prefix, array $migration_map): bool
    {
        $new_url = (string) ($migration_map['new_url'] ?? $migration_map['new_site_url'] ?? '');
        if ($new_url === '' && !empty($migration_map['site_url']['new'])) {
            $new_url = (string) $migration_map['site_url']['new'];
        }
        if ($new_url === '') {
            return false;
        }

        $options = $prefix . 'options';
        if (!self::table_exists($pdo, $options)) {
            return false;
        }

        $options_q = self::quote_sql_identifier($options);
        $stmt = $pdo->prepare("UPDATE {$options_q} SET option_value = ? WHERE option_name IN ('siteurl', 'home')");
        $stmt->execute(array(function_exists('untrailingslashit') ? untrailingslashit($new_url) : rtrim($new_url, '/')));

        return $stmt->rowCount() > 0;
    }
}
