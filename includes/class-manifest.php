<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Manifest
{
    /** @var string */
    private static $last_read_error = '';

    public static function get_last_read_error(): string
    {
        return self::$last_read_error;
    }

    const SIGN_CONTEXT = 'manifest-sign';

    /**
     * @return array<string,mixed>
     */
    public static function build(Rmmigrate_Job $job, Rmmigrate_Multisite_Scope $scope, int $file_count = 0, int $total_bytes = 0): array
    {
        global $wpdb;
        $progress = $job->get_progress();
        $zip_name = basename($progress['archive']['zip_path'] ?? $progress['archive']['archive_path'] ?? 'backup.zip');
        $settings = Rmmigrate_Settings::get();
        $backup_intent = (string) ($progress['backup_intent'] ?? $progress['init']['backup_intent'] ?? 'manual');
        if (!in_array($backup_intent, array('manual', 'migration'), true)) {
            $backup_intent = 'manual';
        }
        if (!empty($progress['include_wp_core_explicit']) || !empty($progress['init']['include_wp_core_explicit'])) {
            // Explicit per-backup override is authoritative (matches File_List).
            $include_wp_core = !empty($progress['include_wp_core']) || !empty($progress['init']['include_wp_core']);
        } else {
            $include_wp_core = !empty($progress['include_wp_core'])
                || !empty($progress['init']['include_wp_core'])
                || ($backup_intent === 'migration')
                || !empty($settings['include_wp_core']);
        }

        $job_scope = $job->get_scope();
        $is_network = in_array($job_scope, array(
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK,
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED,
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED,
        ), true);
        $primary_blog_id = self::primary_blog_id($job);
        $switched_blog = false;
        if ($primary_blog_id > 0 && is_multisite()) {
            switch_to_blog($primary_blog_id);
            $switched_blog = true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
        $db_collation = $wpdb->get_var("SELECT @@collation_database") ?: '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin: custom plugin tables; values use prepare().
        $db_sql_mode = $wpdb->get_var("SELECT @@sql_mode") ?: '';

        $site_url = $is_network && is_multisite()
            ? network_site_url()
            : ($primary_blog_id > 0 ? get_site_url($primary_blog_id) : site_url());
        $home_url = $is_network && is_multisite()
            ? network_home_url()
            : ($primary_blog_id > 0 ? get_home_url($primary_blog_id) : home_url());
        $db_prefix = $primary_blog_id > 0 ? $wpdb->get_blog_prefix($primary_blog_id) : $wpdb->prefix;
        $manifest_blog_id = self::manifest_blog_id($job, $primary_blog_id);

        $manifest = array(
            'plugin'         => 'multisite-migrate',
            'plugin_version' => RMMIGRATE_VERSION,
            'created_at'     => gmdate('c'),
            'include_wp_core'    => (bool) $include_wp_core,
            'has_wp_core_files'  => false,
            'backup_intent'      => $backup_intent,
            'scope'          => $job_scope,
            'blog_id'        => $manifest_blog_id,
            'site_url'       => $site_url,
            'home_url'       => $home_url,
            'network_site_url' => is_multisite() ? network_site_url() : '',
            'source_blog_label' => $primary_blog_id > 0
                ? Rmmigrate_Multisite_Scope::format_blog_label($primary_blog_id)
                : '',
            'restore_multisite' => self::restore_multisite($job),
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'is_multisite'   => is_multisite(),
            'db_prefix'      => $db_prefix,
            'source_base_prefix' => self::derive_base_prefix($db_prefix),
            'source_blog_prefix' => $db_prefix,
            'db_collation'   => $db_collation,
            'db_sql_mode'    => $db_sql_mode,
            'table_count'    => count($scope->get_tables()),
            'tables'         => $scope->get_tables(),
            'file_count'        => $file_count,
            'uncompressed_size' => $total_bytes,
            'excluded_blogs' => $job->get_excluded_blogs(),
            'included_blogs' => $job->get_included_blogs(),
            'archive_file'   => $zip_name,
            'root_files'     => self::root_files_list(),
            'rmmigrate_edition_tier' => apply_filters('rmmigrate_manifest_tier', 'free'),
            'sql_import_transactions'        => Rmmigrate_Engine_Config::sql_import_transactions(),
            'post_import_search_replace'     => Rmmigrate_Engine_Config::post_import_search_replace(),
            'sql_import_chunk_bytes'         => Rmmigrate_Engine_Config::sql_import_chunk_bytes(),
            'safety_snapshot_enabled'        => !empty($settings['safety_snapshot_enabled']),
            'excluded_tables'                => $job->get_excluded_table_patterns(),
            'exclude_log_tables'             => !empty(Rmmigrate_Settings::get()['exclude_log_tables']) || !empty($job->get_progress()['exclude_log_tables']),
            'exclude_revisions'              => !empty($job->get_progress()['exclude_revisions']),
        );

        if (is_multisite()) {
            $manifest['subdomain_install'] = (bool) (defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL);
            $manifest['domain_current_site'] = defined('DOMAIN_CURRENT_SITE') ? DOMAIN_CURRENT_SITE : '';
            $manifest['path_current_site'] = defined('PATH_CURRENT_SITE') ? PATH_CURRENT_SITE : '/';
            $manifest['blogs'] = self::blog_entries($job);
        }

        $manifest['plugins'] = self::plugin_entries();
        $manifest['admin_users'] = self::admin_user_entries();

        if ($switched_blog) {
            restore_current_blog();
        }

        return $manifest;
    }

    private static function derive_base_prefix(string $prefix): string
    {
        if (preg_match('/^(.+)_\d+_$/', $prefix, $matches)) {
            return $matches[1] . '_';
        }

        return $prefix;
    }

    public static function primary_blog_id(Rmmigrate_Job $job): int
    {
        $scope = $job->get_scope();
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            return max(0, $job->get_blog_id());
        }
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED) {
            $included = array_values(array_map('intval', $job->get_included_blogs()));
            if (count($included) === 1) {
                return (int) $included[0];
            }
        }

        return 0;
    }

    public static function manifest_blog_id(Rmmigrate_Job $job, int $primary_blog_id): int
    {
        if ($primary_blog_id > 0) {
            return $primary_blog_id;
        }

        $scope = $job->get_scope();
        if (in_array($scope, array(
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK,
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED,
            Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED,
        ), true)) {
            return 0;
        }

        return max(0, $job->get_blog_id());
    }

    public static function restore_multisite(Rmmigrate_Job $job): bool
    {
        if (!is_multisite()) {
            return false;
        }

        return $job->get_scope() !== Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
    }

    /**
     * @return int[]
     */
    public static function backed_up_blog_ids(Rmmigrate_Job $job): array
    {
        return self::backed_up_blog_ids_for_scope(
            $job->get_scope(),
            $job->get_blog_id(),
            $job->get_excluded_blogs(),
            $job->get_included_blogs(),
            Rmmigrate_Multisite_Scope::get_all_site_ids()
        );
    }

    /**
     * @param int[] $excluded
     * @param int[] $included
     * @param int[] $all_site_ids
     * @return int[]
     */
    public static function backed_up_blog_ids_for_scope(
        string $scope,
        int $blog_id,
        array $excluded,
        array $included,
        array $all_site_ids
    ): array {
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            return $blog_id > 0 ? array($blog_id) : array();
        }
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_INCLUDED) {
            $included = array_values(array_map('intval', $included));
            return $included !== array() ? $included : array_values(array_diff(
                array_map('intval', $all_site_ids),
                array_map('intval', $excluded)
            ));
        }
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK_FILTERED) {
            return array_values(array_diff(
                array_map('intval', $all_site_ids),
                array_map('intval', $excluded)
            ));
        }

        return array_values(array_map('intval', $all_site_ids));
    }

    /**
     * @return array<int,array{slug:string,name:string,active:bool,network_active:bool,version:string}>
     */
    public static function plugin_entries(): array
    {
        if (!function_exists('get_plugins')) {
            if (defined('ABSPATH') && is_readable(ABSPATH . 'wp-admin/includes/plugin.php')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
        }
        if (!function_exists('get_plugins')) {
            return array();
        }

        $all = get_plugins();
        $entries = array();
        foreach ($all as $slug => $data) {
            if (!is_array($data)) {
                continue;
            }
            $active = function_exists('is_plugin_active') && is_plugin_active($slug);
            $network_active = is_multisite()
                && function_exists('is_plugin_active_for_network')
                && is_plugin_active_for_network($slug);
            $entries[] = array(
                'slug'           => (string) $slug,
                'name'           => (string) ($data['Name'] ?? $slug),
                'active'         => $active || $network_active,
                'network_active' => $network_active,
                'version'        => (string) ($data['Version'] ?? ''),
            );
        }

        return $entries;
    }

    /**
     * @return array<int,array{id:int,login:string,email:string,display_name:string}>
     */
    public static function admin_user_entries(): array
    {
        if (!function_exists('get_users')) {
            return array();
        }
        $users = get_users(array(
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => 20,
        ));
        $entries = array();
        foreach ($users as $user) {
            if (!is_object($user) || !isset($user->ID)) {
                continue;
            }
            $entries[] = array(
                'id'           => (int) $user->ID,
                'login'        => (string) ($user->user_login ?? ''),
                'email'        => (string) ($user->user_email ?? ''),
                'display_name' => (string) ($user->display_name ?? ''),
            );
        }

        return $entries;
    }

    /**
     * Set has_wp_core_files from scanned file list.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public static function with_core_detection(array $manifest, Rmmigrate_Job $job): array
    {
        $files = Rmmigrate_File_List::load($job->get_work_dir());
        $archive_paths = array();
        foreach ($files as $file) {
            if (!empty($file['archive'])) {
                $archive_paths[] = (string) $file['archive'];
            }
        }
        $manifest['has_wp_core_files'] = Rmmigrate_Archive_Index::has_core_paths($archive_paths);
        return $manifest;
    }

    /**
     * @return array<int,array{blog_id:int,site_url:string,home_url:string,path:string}>
     */
    private static function blog_entries(Rmmigrate_Job $job): array
    {
        $blogs = array();
        foreach (self::backed_up_blog_ids($job) as $blog_id) {
            $blog_id = (int) $blog_id;
            if ($blog_id <= 0) {
                continue;
            }
            switch_to_blog($blog_id);
            $details = get_blog_details($blog_id);
            $blogs[] = array(
                'blog_id'  => $blog_id,
                'site_url' => site_url(),
                'home_url' => home_url(),
                'path'     => is_object($details) ? (string) ($details->path ?? '/') : '/',
            );
            restore_current_blog();
        }
        return $blogs;
    }

    /**
     * @return string[]
     */
    public static function root_files_list(): array
    {
        $files = array();
        if (Rmmigrate_Filesystem::exists(ABSPATH . 'wp-config.php')) {
            $files[] = 'wp-config.php';
        }
        if (Rmmigrate_Filesystem::exists(ABSPATH . '.htaccess')) {
            $files[] = '.htaccess';
        }
        return $files;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function install_token(string $archive_name, array $manifest): string
    {
        $unsigned_json = self::unsigned_json($manifest);
        return hash_hmac('sha256', $archive_name . '|' . $unsigned_json, wp_salt('auth'));
    }

    public static function installer_entry_basename(string $install_token): string
    {
        $slug = preg_replace('/[^a-z0-9]/i', '', substr($install_token, 0, 12));
        if ($slug === '') {
            $slug = bin2hex(random_bytes(6));
        }
        return 'mm-installer-' . strtolower($slug) . '.php';
    }

    public static function sign(array $manifest, string $archive_name): array
    {
        $unsigned_json = self::unsigned_json($manifest);
        $install_token = hash_hmac('sha256', $archive_name . '|' . $unsigned_json, wp_salt('auth'));
        $signing_key = hash_hmac('sha256', self::SIGN_CONTEXT, $install_token);
        $manifest['manifest_signature'] = hash_hmac('sha256', $unsigned_json, $signing_key);
        return $manifest;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function verify_signature(array $manifest, string $archive_name, string $install_token): bool
    {
        $signature = $manifest['manifest_signature'] ?? '';
        if (!is_string($signature) || $signature === '' || !preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        $unsigned_json = self::unsigned_json($manifest);
        $expected_token = hash_hmac('sha256', $archive_name . '|' . $unsigned_json, wp_salt('auth'));
        if (!hash_equals($expected_token, $install_token)) {
            return false;
        }

        $signing_key = hash_hmac('sha256', self::SIGN_CONTEXT, $install_token);
        $expected = hash_hmac('sha256', $unsigned_json, $signing_key);

        return hash_equals($expected, strtolower($signature));
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function unsigned_json(array $manifest): string
    {
        $payload = $manifest;
        unset($payload['manifest_signature']);
        ksort($payload);

        // Must match installer/lib/license.php unsigned_json (json_encode, not wp_json_encode).
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function write(string $path, array $manifest, string $archive_name = ''): void
    {
        if ($archive_name !== '') {
            $manifest = self::sign($manifest, $archive_name);
        }
        Rmmigrate_Filesystem::put_contents($path, wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function read_from_archive(string $archive_path): ?array
    {
        self::$last_read_error = '';
        if (!Rmmigrate_Filesystem::is_readable($archive_path)) {
            return null;
        }
        $ext = strtolower(pathinfo($archive_path, PATHINFO_EXTENSION));
        if ($ext === 'zip' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($archive_path) !== true) {
                return null;
            }
            $json = $zip->getFromName('manifest.json');
            $zip->close();
            if ($json === false) {
                return null;
            }
            $data = json_decode($json, true);
            return is_array($data) ? $data : null;
        }
        if ($ext === 'daf') {
            $fh = Rmmigrate_Filesystem::open($archive_path, 'rb');
            if ($fh === false) {
                return null;
            }
            $head = (string) $fh->read(8);
            if ($head !== Rmmigrate_Daf_Archiver::MAGIC) {
                $fh->close();
                $legacy = Rmmigrate_Daf_Archiver::detect_legacy_magic($head);
                if ($legacy !== '') {
                    self::$last_read_error = Rmmigrate_Daf_Archiver::legacy_error_message($legacy);
                    Rmmigrate_Logger::log(self::$last_read_error);
                }
                return null;
            }
            while (!$fh->eof()) {
                $header = $fh->read(4);
                if ($header === false || strlen($header) < 4) {
                    break;
                }
                $path_len = (int) unpack('N', $header)[1];
                if ($path_len <= 0 || $path_len > 4096) {
                    break;
                }
                $entry = $fh->read($path_len);
                if ($entry === false) {
                    break;
                }
                $want = ($entry === 'manifest.json');

                $size_header = $fh->read(9);
                if ($size_header === false || strlen($size_header) < 9) {
                    break;
                }
                $unpacked = unpack('Cflag/Juncomp', $size_header);
                $uncomp_len = (int) $unpacked['uncomp'];
                $bytes_done = 0;
                $assembled = '';
                while ($bytes_done < $uncomp_len) {
                    $bh = $fh->read(9);
                    if ($bh === false || strlen($bh) < 9) {
                        break 2;
                    }
                    $bu = unpack('Cflag/Ncomp/Nuncomp', $bh);
                    $block_flag = (int) $bu['flag'];
                    $block_comp = (int) $bu['comp'];
                    $block_uncomp = (int) $bu['uncomp'];
                    if ($want) {
                        $blob = $block_comp > 0 ? $fh->read($block_comp) : '';
                        if ($blob === false) {
                            break 2;
                        }
                        $out = $block_flag === 1 ? gzinflate($blob) : $blob;
                        if ($out === false) {
                            break 2;
                        }
                        $assembled .= $out;
                    } else {
                        $fh->seek($block_comp, SEEK_CUR);
                    }
                    $bytes_done += $block_uncomp;
                }
                if ($want) {
                    $fh->close();
                    $decoded = json_decode($assembled, true);
                    return is_array($decoded) ? $decoded : null;
                }
            }
            $fh->close();
        }
        return null;
    }
}
