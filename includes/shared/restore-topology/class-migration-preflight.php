<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migration / full-network restore safety checks before destructive database work.
 */
if (!class_exists('Rmmigrate_Migration_Preflight', false)) {
class Rmmigrate_Migration_Preflight
{
    /**
     * @param array<string,mixed> $map
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public static function normalize_migration_map(array $map, array $manifest): array
    {
        $manifest_site = (string) ($manifest['site_url'] ?? '');
        $manifest_home = (string) ($manifest['home_url'] ?? $manifest_site);
        if ($manifest_site === '' && !empty($manifest['domain_current_site'])) {
            $scheme = !empty($manifest['is_ssl']) ? 'https' : 'http';
            $path = (string) ($manifest['path_current_site'] ?? '/');
            if ($path === '') {
                $path = '/';
            }
            $manifest_site = $scheme . '://' . (string) $manifest['domain_current_site'] . ($path === '/' ? '' : rtrim($path, '/'));
            $manifest_home = $manifest_site;
        }

        if (($map['site_url']['old'] ?? '') === '' && $manifest_site !== '') {
            $map['site_url']['old'] = $manifest_site;
        }
        if (($map['home_url']['old'] ?? '') === '' && $manifest_home !== '') {
            $map['home_url']['old'] = $manifest_home;
        }

        return self::enrich_migration_map_paths($map, $manifest);
    }

    /**
     * @param array<string,mixed> $map
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public static function enrich_migration_map_paths(array $map, array $manifest): array
    {
        if (!empty($manifest['subdomain_install'])) {
            return $map;
        }
        $backup_path = self::normalize_site_path((string) ($manifest['path_current_site'] ?? '/'));
        if (($map['site_path']['old'] ?? '') === '') {
            $map['site_path']['old'] = $backup_path;
        }
        $new_url = (string) ($map['site_url']['new'] ?? '');
        if (($map['site_path']['new'] ?? '') === '' && $new_url !== '') {
            $map['site_path']['new'] = self::path_from_url($new_url);
        }

        return $map;
    }

    public static function normalize_site_path(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . trim($path, '/') . '/';
    }

    public static function path_from_url(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        return self::normalize_site_path($path);
    }

    /**
     * wp_blogs.domain value (hostname, or hostname:port when non-default).
     */
    public static function network_domain_from_url(string $url): string
    {
        $variants = self::url_host_variants($url);
        if ($variants === array()) {
            return '';
        }

        return (string) $variants[array_key_last($variants)];
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function will_drop_network_tables(array $manifest): bool
    {
        if (!Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            return false;
        }
        if (Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest)) {
            return false;
        }

        $mode = Rmmigrate_Restore_Topology_Manifest::restore_mode($manifest, array());

        return in_array($mode, array('network', 'network_included', 'network_filtered'), true);
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $migration_map
     * @param array<string,mixed> $context safety_snapshot, restore_type
     * @return array{message:string,recovery_hint:string,code:string}|null
     */
    public static function validate_before_restore(array $manifest, array $migration_map, array $context): ?array
    {
        $restore_type = (string) ($context['restore_type'] ?? '');
        if ($restore_type !== 'migration') {
            return null;
        }

        $map = self::normalize_migration_map($migration_map, $manifest);
        $new_url = (string) ($map['site_url']['new'] ?? '');
        if ($new_url === '' || wp_parse_url($new_url, PHP_URL_HOST) === null) {
            return self::failure(
                __('Enter a valid new site URL with a hostname (for example https://example.com).', 'rosenheinrich-multisite-migrate'),
                __('Use the destination live URL including https:// if the site uses SSL.', 'rosenheinrich-multisite-migrate'),
                'migration_map_required'
            );
        }

        $old_url = (string) ($map['site_url']['old'] ?? '');
        $manifest_site = (string) ($manifest['site_url'] ?? '');
        if ($old_url !== '' && $manifest_site !== '' && !self::urls_compatible_for_migration($old_url, $manifest_site)) {
            return self::failure(
                sprintf(
                    /* translators: 1: old URL from the form, 2: URL recorded in the backup manifest */
                    __('The “old site URL” (%1$s) does not match this backup (%2$s).', 'rosenheinrich-multisite-migrate'),
                    $old_url,
                    $manifest_site
                ),
                __('Leave “old site URL” as prefilled from the backup, or match the URL shown in the manifest (including port if used in content URLs).', 'rosenheinrich-multisite-migrate'),
                'validation'
            );
        }

        if (self::will_drop_network_tables($manifest)) {
            $safety_id = (int) ($context['safety_job_id'] ?? 0);
            if ($safety_id <= 0 && empty($context['safety_snapshot'])) {
                return self::failure(
                    __('Full network migration replaces every database table for this installation. A safety backup is required.', 'rosenheinrich-multisite-migrate'),
                    __('Enable “Create safety backup before restore” and wait for it to finish before continuing.', 'rosenheinrich-multisite-migrate'),
                    'validation'
                );
            } elseif ($safety_id <= 0) {
                return self::failure(
                    __('Wait for the safety backup to finish before starting a full network migration.', 'rosenheinrich-multisite-migrate'),
                    __('The restore will not start until the safety snapshot job completes.', 'rosenheinrich-multisite-migrate'),
                    'validation'
                );
            }
        }

        if (is_multisite() && array_key_exists('subdomain_install', $manifest)) {
            $backup_subdomain = !empty($manifest['subdomain_install']);
            $live_subdomain = defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL;
            if ($backup_subdomain !== $live_subdomain) {
                return self::failure(
                    $backup_subdomain
                        ? __('This backup is subdomain multisite but the live server uses subdirectory multisite (or the opposite).', 'rosenheinrich-multisite-migrate')
                        : __('This backup is subdirectory multisite but the live server uses subdomain multisite (or the opposite).', 'rosenheinrich-multisite-migrate'),
                    __('Align SUBDOMAIN_INSTALL in wp-config.php with the source network, or restore to an empty server with the same multisite mode.', 'rosenheinrich-multisite-migrate'),
                    'validation'
                );
            }
        }

        $path_failure = self::validate_subdirectory_path_migration($manifest, $map);
        if ($path_failure !== null) {
            return $path_failure;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $map
     * @return array{message:string,recovery_hint:string,code:string}|null
     */
    public static function validate_subdirectory_path_migration(array $manifest, array $map): ?array
    {
        if (!empty($manifest['subdomain_install'])) {
            return null;
        }
        if (!Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            return null;
        }

        $backup_path = self::normalize_site_path((string) ($manifest['path_current_site'] ?? '/'));
        $live_path = '/';
        if (defined('PATH_CURRENT_SITE')) {
            $live_path = self::normalize_site_path((string) PATH_CURRENT_SITE);
        }
        if ($backup_path === $live_path) {
            return null;
        }

        $new_path = self::normalize_site_path((string) ($map['site_path']['new'] ?? ''));
        if ($new_path === '/') {
            $new_path = self::path_from_url((string) ($map['site_url']['new'] ?? ''));
        }
        if ($new_path === '/' && $backup_path !== '/') {
            return self::failure(
                __('The new site URL must include the destination subdirectory path (for example https://example.com/new-folder/).', 'rosenheinrich-multisite-migrate'),
                __('Set the new site URL to the full path where this network will live on the destination server.', 'rosenheinrich-multisite-migrate'),
                'validation'
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function validate_before_database_drop(array $manifest, array $migration_map, int $safety_job_id): ?array
    {
        if (!self::will_drop_network_tables($manifest)) {
            return null;
        }
        if ($safety_job_id <= 0) {
            return self::failure(
                __('Database tables cannot be replaced without a completed safety backup.', 'rosenheinrich-multisite-migrate'),
                __('Restore the safety backup from Activity, or run a new restore with safety backup enabled.', 'rosenheinrich-multisite-migrate'),
                'validation'
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $map
     * @return string|null Error message when migration left the network misconfigured.
     */
    public static function validate_after_migration(array $map, array $manifest): ?string
    {
        $error = self::validate_migration_map_for_post_check($map, $manifest);
        if ($error !== null) {
            return $error;
        }

        if (!Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            return null;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Post-migration validation.
        $rows = $wpdb->get_results('SELECT blog_id, domain, path FROM ' . $wpdb->blogs, ARRAY_A);
        if (!is_array($rows)) {
            return null;
        }

        return self::validate_wp_blogs_after_migration($rows, $map, $manifest);
    }

    /**
     * Post-migration wp_blogs check via caller-supplied PDO (installer / external DB).
     *
     * @param PDO                 $pdo          Database connection (not wpdb).
     * @param array<string,mixed> $map
     * @param array<string,mixed> $manifest
     * @param string              $base_prefix
     */
    public static function validate_after_migration_pdo(
        // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Intentional PDO for installer topology validation.
        PDO $pdo,
        array $map,
        array $manifest,
        string $base_prefix
    ): ?string {
        $error = self::validate_migration_map_for_post_check($map, $manifest);
        if ($error !== null) {
            return $error;
        }
        if ($base_prefix === '') {
            return null;
        }

        $blogs = '`' . str_replace('`', '``', $base_prefix . 'blogs') . '`';
        // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Installer / topology validation.
        $stmt = $pdo->query('SELECT blog_id, domain, path FROM ' . $blogs);
        if ($stmt === false) {
            return null;
        }
        // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- PDO::FETCH_ASSOC on installer connection.
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return null;
        }

        return self::validate_wp_blogs_after_migration($rows, $map, $manifest);
    }

    /**
     * @param array<string,mixed> $map
     */
    private static function validate_migration_map_for_post_check(array $map, array $manifest): ?string
    {
        if (empty($map['site_url']['new'])) {
            return null;
        }
        $new_host = self::network_domain_from_url((string) $map['site_url']['new']);
        if ($new_host === '') {
            return __('Migration finished but the new site URL has no hostname.', 'rosenheinrich-multisite-migrate');
        }
        if (!Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            return null;
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $map
     * @param array<string,mixed>            $manifest
     */
    private static function validate_wp_blogs_after_migration(array $rows, array $map, array $manifest): ?string
    {
        $map = self::enrich_migration_map_paths($map, $manifest);
        $old_host = self::network_domain_from_url((string) ($map['site_url']['old'] ?? ''));
        $new_host = self::network_domain_from_url((string) ($map['site_url']['new'] ?? ''));
        $old_path = self::normalize_site_path((string) ($map['site_path']['old'] ?? '/'));
        $new_path = self::normalize_site_path((string) ($map['site_path']['new'] ?? '/'));
        $explicit = self::explicit_blog_expectations($map, $new_host, $new_path);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $blog_id = (int) ($row['blog_id'] ?? 0);
            if ($blog_id <= 0) {
                continue;
            }
            $domain = (string) ($row['domain'] ?? '');
            $path = self::normalize_site_path((string) ($row['path'] ?? '/'));

            if (isset($explicit[$blog_id])) {
                $want_domain = (string) $explicit[$blog_id]['domain'];
                $want_path = (string) $explicit[$blog_id]['path'];
                if ($want_domain !== '' && strcasecmp($domain, $want_domain) !== 0) {
                    return sprintf(
                        /* translators: 1: blog ID, 2: domain in wp_blogs, 3: expected domain */
                        __('Migration finished but blog %1$d still has domain %2$s (expected %3$s).', 'rosenheinrich-multisite-migrate'),
                        $blog_id,
                        $domain,
                        $want_domain
                    );
                }
                if ($want_path !== '' && $path !== $want_path) {
                    return sprintf(
                        /* translators: 1: blog ID, 2: path in wp_blogs, 3: expected path */
                        __('Migration finished but blog %1$d still has path %2$s (expected %3$s).', 'rosenheinrich-multisite-migrate'),
                        $blog_id,
                        $path,
                        $want_path
                    );
                }
                continue;
            }

            if ($old_host !== '' && $new_host !== '' && strcasecmp($old_host, $new_host) !== 0) {
                if (strcasecmp($domain, $old_host) === 0) {
                    return sprintf(
                        /* translators: 1: blog ID, 2: old host still present */
                        __('Migration finished but blog %1$d still uses the old network host (%2$s).', 'rosenheinrich-multisite-migrate'),
                        $blog_id,
                        $domain
                    );
                }
                $suffix = '.' . $old_host;
                if (strlen($domain) > strlen($suffix)
                    && strcasecmp(substr($domain, -strlen($suffix)), $suffix) === 0) {
                    return sprintf(
                        /* translators: 1: blog ID, 2: domain still on old network host */
                        __('Migration finished but blog %1$d still uses a subdomain of the old host (%2$s).', 'rosenheinrich-multisite-migrate'),
                        $blog_id,
                        $domain
                    );
                }
            }

            if ($old_path !== $new_path && $old_path !== '/' && strpos($path, $old_path) === 0) {
                return sprintf(
                    /* translators: 1: blog ID, 2: old subdirectory prefix */
                    __('Migration finished but blog %1$d path still starts with the old subdirectory prefix (%2$s).', 'rosenheinrich-multisite-migrate'),
                    $blog_id,
                    $old_path
                );
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $map
     * @return array<int,array{domain:string,path:string}>
     */
    private static function explicit_blog_expectations(array $map, string $default_host, string $default_path): array
    {
        $out = array();
        if ($default_host !== '') {
            $out[1] = array(
                'domain' => $default_host,
                'path'   => $default_path,
            );
        }
        $blogs = $map['blogs'] ?? array();
        if (!is_array($blogs)) {
            return $out;
        }
        foreach ($blogs as $blog) {
            if (!is_array($blog)) {
                continue;
            }
            $blog_id = (int) ($blog['blog_id'] ?? 0);
            if ($blog_id <= 0) {
                continue;
            }
            $domain = (string) ($blog['new_domain'] ?? '');
            if ($domain === '' && !empty($blog['new_url'])) {
                $domain = self::network_domain_from_url((string) $blog['new_url']);
            }
            if ($domain === '' && $blog_id === 1 && $default_host !== '') {
                $domain = $default_host;
            }
            $path = isset($blog['new_path'])
                ? self::normalize_site_path((string) $blog['new_path'])
                : $default_path;
            if ($domain === '') {
                continue;
            }
            $out[$blog_id] = array(
                'domain' => $domain,
                'path'   => $path,
            );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $job_progress
     */
    public static function recovery_message_after_db_failure(array $job_progress): string
    {
        $rollback = is_array($job_progress['network_rollback_report'] ?? null)
            ? $job_progress['network_rollback_report']
            : array();
        if (!empty($rollback['restored'])) {
            return __('The failed import was rolled back automatically and your previous database tables were restored.', 'rosenheinrich-multisite-migrate');
        }

        $safety_id = (int) ($job_progress['safety_job_id'] ?? 0);
        if ($safety_id <= 0 || empty($job_progress['db_prepared'])) {
            return '';
        }

        return sprintf(
            /* translators: %d: safety backup job ID */
            __('The database was partially replaced. Restore safety backup job #%d from Activity, or contact your host for a server-level backup.', 'rosenheinrich-multisite-migrate'),
            $safety_id
        );
    }

    public static function urls_compatible_for_migration(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '') {
            return true;
        }
        if (strcasecmp(untrailingslashit($a), untrailingslashit($b)) === 0) {
            return true;
        }

        $hosts_a = self::url_host_variants($a);
        $hosts_b = self::url_host_variants($b);
        foreach ($hosts_a as $host_a) {
            foreach ($hosts_b as $host_b) {
                if (strcasecmp($host_a, $host_b) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public static function url_host_variants(string $url): array
    {
        $parsed = wp_parse_url($url);
        if (!is_array($parsed)) {
            return array();
        }
        $host = strtolower(rtrim((string) ($parsed['host'] ?? ''), '.'));
        if ($host === '') {
            return array();
        }
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $port = isset($parsed['port']) ? (int) $parsed['port'] : 0;
        if ($port <= 0) {
            if ($scheme === 'https') {
                $port = 443;
            } elseif ($scheme === 'http') {
                $port = 80;
            }
        }
        $variants = array($host);
        if ($port > 0 && !self::is_default_port($scheme, $port)) {
            $variants[] = $host . ':' . $port;
        }

        return array_values(array_unique($variants));
    }

    private static function is_default_port(string $scheme, int $port): bool
    {
        if ($port === 443 && ($scheme === 'https' || $scheme === '')) {
            return true;
        }
        if ($port === 80 && ($scheme === 'http' || $scheme === '')) {
            return true;
        }

        return false;
    }

    /**
     * @return array{message:string,recovery_hint:string,code:string}
     */
    private static function failure(string $message, string $recovery_hint, string $code): array
    {
        return array(
            'message'        => $message,
            'recovery_hint'  => $recovery_hint,
            'code'           => $code,
        );
    }
}
}
