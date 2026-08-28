<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runtime helpers for Advanced / Cloud / Engine settings saved in Rmmigrate_Settings.
 */
class Rmmigrate_Engine_Config
{
    /** @var bool */
    private static $http_hooks_registered = false;

    public static function register_http_hooks(): void
    {
        if (self::$http_hooks_registered) {
            return;
        }
        self::$http_hooks_registered = true;
        add_action('http_api_curl', array(__CLASS__, 'filter_http_api_curl'), 10, 3);
    }

    /**
     * @param resource $handle
     */
    public static function filter_http_api_curl($handle, $request, $url): void
    {
        unset($request, $url);
        if (!self::force_ip_resolve_v4()) {
            return;
        }
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Plugin: WP http_api_curl handle; CURLOPT_IPRESOLVE has no wp_remote_* equivalent.
            curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
    }

    public static function sql_import_transactions(): bool
    {
        return !empty(Rmmigrate_Settings::get()['sql_import_transactions']);
    }

    public static function sql_import_chunk_bytes(): int
    {
        $size = (int) (Rmmigrate_Settings::get()['sql_import_chunk_bytes'] ?? 1048576);
        return max(262144, min(4194304, $size));
    }

    public static function post_import_search_replace(): bool
    {
        return !empty(Rmmigrate_Settings::get()['post_import_search_replace']);
    }

    public static function force_ip_resolve_v4(): bool
    {
        return !empty(Rmmigrate_Settings::get()['force_ip_resolve_v4']);
    }

    public static function ssl_ca_path(): string
    {
        return trim((string) (Rmmigrate_Settings::get()['ssl_ca_path'] ?? ''));
    }

    public static function ssl_verify(): bool
    {
        $settings = Rmmigrate_Settings::get();
        if (!array_key_exists('ssl_verify', $settings)) {
            return true;
        }

        return (bool) $settings['ssl_verify'];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function http_args(array $args = array()): array
    {
        if (!array_key_exists('sslverify', $args)) {
            $args['sslverify'] = self::ssl_verify();
        }
        $ca = self::ssl_ca_path();
        if ($ca !== '' && is_readable($ca) && empty($args['sslcertificates'])) {
            $args['sslcertificates'] = $ca;
        }
        return $args;
    }

    public static function throttle_us(): int
    {
        $map = array(
            'off'  => 0,
            'low'  => 250000,
            'med'  => 500000,
            'high' => 1000000,
        );
        $key = (string) (Rmmigrate_Settings::get()['server_throttle'] ?? 'off');
        return $map[$key] ?? 0;
    }

    public static function apply_throttle(): void
    {
        $us = self::throttle_us();
        if ($us > 0) {
            usleep($us);
        }
    }

    public static function max_build_seconds(): int
    {
        return max(5, (int) (Rmmigrate_Settings::get()['max_build_minutes'] ?? 90)) * MINUTE_IN_SECONDS;
    }

    public static function storage_filter_self(): bool
    {
        return !empty(Rmmigrate_Settings::get()['storage_filter_self']);
    }

    /**
     * WordPress uploads base directory (supports custom upload paths and multisite).
     */
    public static function uploads_basedir(): string
    {
        return self::uploads_basedir_for_blog(get_current_blog_id());
    }

    /**
     * Uploads base directory for a specific site (custom paths + multisite subsites).
     */
    public static function uploads_basedir_for_blog(int $blog_id): string
    {
        $switched = false;
        if ($blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id) {
            switch_to_blog($blog_id);
            $switched = true;
        }

        $uploads = wp_upload_dir(null, false);
        if (is_array($uploads) && !empty($uploads['basedir'])) {
            $basedir = wp_normalize_path($uploads['basedir']);
            if ($switched) {
                restore_current_blog();
            }

            return $basedir;
        }

        if ($switched) {
            restore_current_blog();
        }

        return '';
    }

    public static function resolve_local_storage_path(): string
    {
        if (defined('RMMIGRATE_UNIT_TEST') && RMMIGRATE_UNIT_TEST
            && isset($GLOBALS['rmmigrate_test_backup_dir'])
            && is_string($GLOBALS['rmmigrate_test_backup_dir'])
            && $GLOBALS['rmmigrate_test_backup_dir'] !== '') {
            return wp_normalize_path($GLOBALS['rmmigrate_test_backup_dir']);
        }

        $custom = trim((string) (Rmmigrate_Settings::get()['local_storage_path'] ?? ''));
        if ($custom === '') {
            return self::default_local_storage_path();
        }
        if ($custom[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $custom)) {
            return untrailingslashit(wp_normalize_path($custom));
        }
        // Relative custom paths always under main-network uploads (cron-safe).
        $basedir = self::network_uploads_basedir();

        return untrailingslashit(wp_normalize_path(trailingslashit($basedir) . ltrim($custom, '/')));
    }

    /**
     * Uploads basedir for network main site (stable across cron blog context).
     */
    public static function network_uploads_basedir(): string
    {
        if (is_multisite() && function_exists('get_main_site_id')) {
            $main = (int) get_main_site_id();
            if ($main > 0) {
                return self::uploads_basedir_for_blog($main);
            }
        }

        return self::uploads_basedir();
    }

    /**
     * Default backup archive directory.
     *
     * Per the WordPress plugin guidelines, plugin-written files live in the
     * uploads directory (resolved via wp_upload_dir()), never inside the plugin
     * folder.
     */
    public static function default_local_storage_path(): string
    {
        $basedir = self::network_uploads_basedir();
        if ($basedir === '') {
            return '';
        }

        $dir_name = defined('RMMIGRATE_DIR_NAME') ? RMMIGRATE_DIR_NAME : 'rosenheinrich-multisite-migrate';

        return untrailingslashit(wp_normalize_path(trailingslashit($basedir) . $dir_name));
    }

    /**
     * Folder names that must never be packed into a backup archive.
     *
     * @return string[]
     */
    public static function archive_dir_names(): array
    {
        $names = array(
            defined('RMMIGRATE_DIR_NAME') ? RMMIGRATE_DIR_NAME : 'rosenheinrich-multisite-migrate',
            'rosenheinrich-multisite-migrate',
            'multisite-migrate-archives',
        );

        return array_values(array_unique(array_filter(array_map('strval', $names))));
    }

    /**
     * Pre-rename / sibling storage roots (lookup + exclude).
     *
     * Free previously used uploads/multisite-migrate-archives before RMMIGRATE_DIR_NAME
     * became rosenheinrich-multisite-migrate. Pro uses multisite-migrate-archives.
     *
     * @return string[]
     */
    public static function legacy_local_storage_paths(): array
    {
        $paths = array();
        if (defined('WP_CONTENT_DIR')) {
            foreach (self::archive_dir_names() as $dir_name) {
                $paths[] = untrailingslashit(wp_normalize_path(trailingslashit(WP_CONTENT_DIR) . $dir_name));
            }
        }

        $uploads = self::network_uploads_basedir();
        if ($uploads !== '') {
            foreach (self::archive_dir_names() as $dir_name) {
                $paths[] = untrailingslashit(wp_normalize_path(trailingslashit($uploads) . $dir_name));
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * Current + legacy + per-subsite archive roots (exclude from scans + lookup).
     *
     * @return string[]
     */
    public static function self_storage_exclude_paths(): array
    {
        $paths = array(untrailingslashit(wp_normalize_path(self::resolve_local_storage_path())));
        foreach (self::legacy_local_storage_paths() as $legacy) {
            $paths[] = untrailingslashit(wp_normalize_path($legacy));
        }

        $uploads = self::network_uploads_basedir();
        if ($uploads !== '') {
            $sites_root = wp_normalize_path(trailingslashit($uploads) . 'sites');
            if (Rmmigrate_Filesystem::is_dir($sites_root)) {
                $nodes = Rmmigrate_Filesystem::list_dir($sites_root);
                if ($nodes !== array()) {
                    foreach ($nodes as $node) {
                        if (!ctype_digit((string) $node)) {
                            continue;
                        }
                        foreach (self::archive_dir_names() as $dir_name) {
                            $candidate = wp_normalize_path($sites_root . '/' . $node . '/' . $dir_name);
                            if (Rmmigrate_Filesystem::is_dir($candidate)) {
                                $paths[] = untrailingslashit($candidate);
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * True when path sits under a known archive folder name (any depth).
     */
    public static function path_looks_like_archive_storage(string $path): bool
    {
        $norm = '/' . trim(str_replace('\\', '/', wp_normalize_path($path)), '/') . '/';
        foreach (self::archive_dir_names() as $name) {
            if ($name !== '' && strpos($norm, '/' . $name . '/') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current + legacy storage roots for archive lookup / download path checks.
     *
     * @return string[]
     */
    public static function allowed_local_storage_roots(): array
    {
        return self::self_storage_exclude_paths();
    }

    /**
     * True when $path is under current or legacy storage (no mkdir side effects).
     */
    public static function is_path_under_allowed_storage(string $path): bool
    {
        $norm = str_replace('\\', '/', wp_normalize_path($path));
        foreach (explode('/', $norm) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        foreach (self::allowed_local_storage_roots() as $root) {
            if ($root === '') {
                continue;
            }
            $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
            if (strpos($norm, $prefix) === 0) {
                return true;
            }
            if ($norm === rtrim($prefix, '/')) {
                return true;
            }
        }

        return false;
    }

    public static function storage_htaccess_enabled(): bool
    {
        return !empty(Rmmigrate_Settings::get()['storage_htaccess']);
    }

    public static function log_retention_months(): int
    {
        return max(1, (int) (Rmmigrate_Settings::get()['log_retention_months'] ?? 6));
    }

    public static function log_max_bytes(): int
    {
        return max(262144, min(52428800, (int) (Rmmigrate_Settings::get()['log_max_bytes'] ?? 2097152)));
    }

    public static function log_max_rotated_files(): int
    {
        return max(2, min(20, (int) (Rmmigrate_Settings::get()['log_max_rotated_files'] ?? 5)));
    }

    public static function activity_max_entries(): int
    {
        return max(500, min(10000, (int) (Rmmigrate_Settings::get()['activity_max_entries'] ?? 2000)));
    }

    public static function activity_page_size(): int
    {
        return max(10, min(50, (int) (Rmmigrate_Settings::get()['activity_page_size'] ?? 25)));
    }

    public static function log_view_lines(): int
    {
        return max(50, min(500, (int) (Rmmigrate_Settings::get()['log_view_lines'] ?? 200)));
    }

    public static function activity_scan_budget(): int
    {
        return 10000;
    }

    public static function admin_safe_mode(): bool
    {
        return !empty(Rmmigrate_Settings::get()['admin_safe_mode']);
    }

    public static function access_cap_allowed(string $cap): bool
    {
        $map = array(
            'backup_create' => 'backup_create',
            'import_run'    => 'import_run',
        );
        if (isset($map[$cap])) {
            return Rmmigrate_Access::can_current_site($map[$cap]);
        }

        return Rmmigrate_Access::can_current_site($cap);
    }

    public static function installer_brand_name(): string
    {
        return '';
    }

    public static function installer_brand_logo(): string
    {
        return '';
    }

    public static function kickoff_mode(): string
    {
        return Rmmigrate_Hosting_Detection::settings_kickoff_mode();
    }
}
