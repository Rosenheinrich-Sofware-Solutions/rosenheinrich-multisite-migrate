<?php

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
        $lines = preg_split('/\R/', $contents);
        if (!is_array($lines)) {
            return $contents;
        }

        $filtered = array();
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (preg_match('/^\$base\s*=/', $trimmed)) {
                continue;
            }
            if (self::line_is_multisite_define($trimmed)) {
                continue;
            }
            $filtered[] = $line;
        }

        return implode("\n", $filtered);
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
     * Topology code is shared with the standalone installer, which provides a
     * debug logger. Inside the plugin runtime that class is absent, so this
     * guard keeps logging a no-op instead of fataling on a class-not-found.
     */
    private static function debug_log(string $level, string $message): void
    {
        if (class_exists('Rmmigrate_Installer_Debug_Log')) {
            Rmmigrate_Installer_Debug_Log::log($level, $message);
        }
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
            return Rmmigrate_Restore_Topology_Network_Import::finalize($pdo, $manifest, $context);
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
                self::debug_log('debug', "Checking global table rename from $old_g to $new_g");
                if (self::table_exists($pdo, $old_g)) {
                    self::debug_log('debug', "$old_g exists. Renaming to $new_g");
                    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $new_g) . '`');
                    $pdo->exec('RENAME TABLE `' . str_replace('`', '``', $old_g) . '` TO `' . str_replace('`', '``', $new_g) . '`');
                } else {
                    self::debug_log('debug', "$old_g DOES NOT EXIST!");
                    // Also check if $new_g already exists just in case
                    if (self::table_exists($pdo, $new_g)) {
                        self::debug_log('debug', "$new_g ALREADY EXISTS!");
                    } else {
                        // Let's list all tables that match *users
                        $stmt = $pdo->query("SHOW TABLES LIKE '%users'");
                        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        self::debug_log('debug', "Available *users tables: " . implode(', ', $tables));
                    }
                }
            }
        }

        if (Rmmigrate_Restore_Topology_Prefix_Remap::should_remap($manifest, $context)) {
            $remap = Rmmigrate_Restore_Topology_Prefix_Remap::remap_tables(
                $pdo,
                $prefix,
                (string) ($context['prefix_remap_target'] ?? 'wp_')
            );
            $report = array_merge($report, $remap);
            $prefix = (string) ($context['prefix_remap_target'] ?? 'wp_');
        }

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

        $report['usermeta_rows_purged'] = self::purge_foreign_usermeta($pdo, $prefix, $base_prefix);
        $report['option_names_remapped'] = self::update_options_table($pdo, $prefix, $base_prefix);
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
    private static function finalize_network_domains(PDO $pdo, array $manifest, array $context): array
    {
        $report = array(
            'network_blogs_updated' => 0,
            'network_site_updated'  => 0,
            'network_meta_updated'  => 0,
            'urls_updated'          => false,
        );

        $prefix = (string) ($context['db_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
        $base_prefix = self::base_table_prefix($prefix);

        // Primary site siteurl/home (blog 1 options table).
        $report['urls_updated'] = self::update_site_urls($pdo, $prefix, self::migration_map($context));

        $map = is_array($context['migration_map'] ?? null) ? $context['migration_map'] : array();
        $old_host = self::url_host((string) ($map['site_url']['old'] ?? $map['old_url'] ?? ''));
        $new_host = self::url_host((string) ($map['site_url']['new'] ?? $map['new_url'] ?? ''));
        if ($old_host === '' || $new_host === '' || strcasecmp($old_host, $new_host) === 0) {
            return $report;
        }

        // Host-level REPLACE remaps the primary blog (pointalize.com -> new host)
        // and every subdomain subsite (sub.pointalize.com -> sub.new host) in one
        // pass without needing per-blog entries in the migration map.
        $blogs = $base_prefix . 'blogs';
        if (self::table_exists($pdo, $blogs)) {
            $stmt = $pdo->prepare("UPDATE `{$blogs}` SET domain = REPLACE(domain, ?, ?)");
            $stmt->execute(array($old_host, $new_host));
            $report['network_blogs_updated'] = $stmt->rowCount();
        }

        $site = $base_prefix . 'site';
        if (self::table_exists($pdo, $site)) {
            $stmt = $pdo->prepare("UPDATE `{$site}` SET domain = REPLACE(domain, ?, ?)");
            $stmt->execute(array($old_host, $new_host));
            $report['network_site_updated'] = $stmt->rowCount();
        }

        $sitemeta = $base_prefix . 'sitemeta';
        if (self::table_exists($pdo, $sitemeta)) {
            $stmt = $pdo->prepare(
                "UPDATE `{$sitemeta}` SET meta_value = REPLACE(meta_value, ?, ?) WHERE meta_key = 'siteurl'"
            );
            $stmt->execute(array($old_host, $new_host));
            $report['network_meta_updated'] = $stmt->rowCount();
        }

        return $report;
    }

    private static function url_host(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '//') === false) {
            $url = '//' . $url;
        }
        $host = function_exists('wp_parse_url')
            ? wp_parse_url($url, PHP_URL_HOST)
            : parse_url($url, PHP_URL_HOST); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- standalone restore runs without WP; wp_parse_url() unavailable, used when present.

        return is_string($host) ? $host : '';
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

        $stmt = $pdo->prepare(
            "UPDATE `{$table}` SET option_name = REPLACE(option_name, ?, ?) WHERE option_name LIKE ?"
        );
        $stmt->execute(array($prefix, $base_prefix, $prefix . '%'));

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
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($table));

        return (bool) $stmt->fetchColumn();
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

        $stmt = $pdo->prepare(
            "DELETE FROM `{$table}` WHERE `meta_key` LIKE ? AND `meta_key` NOT LIKE ?"
        );
        $stmt->execute(array($base_prefix . '%', $prefix . '%'));
        $purged = $stmt->rowCount();

        $update = $pdo->prepare(
            "UPDATE `{$table}` SET `meta_key` = REPLACE(`meta_key`, ?, ?) WHERE `meta_key` LIKE ?"
        );
        $update->execute(array($prefix, $base_prefix, $prefix . '%'));

        return $purged;
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
        if (!empty($map['new_url'])) {
            return $map;
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

        $stmt = $pdo->prepare("UPDATE `{$options}` SET option_value = ? WHERE option_name IN ('siteurl', 'home')");
        $stmt->execute(array(function_exists('untrailingslashit') ? untrailingslashit($new_url) : rtrim($new_url, '/')));

        return $stmt->rowCount() > 0;
    }
}
