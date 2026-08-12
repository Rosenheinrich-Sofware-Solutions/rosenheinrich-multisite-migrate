<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Settings
{
    const OPTION_KEY = 'rmmigrate_settings';

    /** @var array<string,mixed>|null */
    private static $cache = null;

    public static function defaults(): array
    {
        return array(
            'mysqldump_path'            => '',
            'db_mode'                   => 'auto',
            'db_insert_query_limit'     => 131072,
            'sql_import_chunk_bytes'    => 1048576,
        'exclude_log_tables'        => false,
        'exclude_revisions'         => false,
        'default_exclude_tables'    => array(),
            'archive_mode'              => 'daf',
            'include_wp_core'           => false,
            'encrypt_archives'          => false,
            'archive_passphrase'        => '',
            'safety_snapshot_enabled'   => true,
            'default_scope'             => 'network',
            'default_excluded_blogs'    => array(),
            'default_included_blogs'    => array(),
            'default_backup_profile'    => 'full',
            'default_custom_paths'      => '',
            'retention_network'         => 0,
            'retention_subsite'         => 0,
            'import_chunk_size'         => 2097152,
            'exclude_paths'             => array(),
            'ssl_verify'                => true,
            'sql_import_transactions'   => false,
            'post_import_search_replace' => false,
            'force_ip_resolve_v4'       => false,
            'ssl_ca_path'               => '',
            'storage_filter_self'       => true,
            'max_build_minutes'         => 90,
            'max_archive_file_bytes'    => 536870912,
            'server_throttle'           => 'off',
            'log_retention_months'      => 6,
            'log_max_bytes'             => 2097152,
            'log_max_rotated_files'     => 5,
            'activity_max_entries'      => 2000,
            'activity_page_size'        => 25,
            'log_view_lines'            => 200,
            'admin_safe_mode'           => false,
            'local_storage_path'        => '',
            'storage_htaccess'          => true,
            'installer_brand_name'      => '',
            'installer_brand_logo'      => '',
            'kickoff_mode'              => 'auto',
            'lock_mode'                 => 'auto',
            'archive_mode_manual'       => false,
            'exclude_deleted_subsite_tables' => false,
        );
    }

    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $settings = get_site_option(self::OPTION_KEY, array());
        if (!is_array($settings)) {
            $settings = array();
        }
        $merged = array_merge(self::defaults(), $settings);

        self::$cache = $merged;
        return self::$cache;
    }

    public static function clear_cache(): void
    {
        self::$cache = null;
    }

    public static function save(array $settings): void
    {
        $current = get_site_option(self::OPTION_KEY, array());
        if (!is_array($current)) {
            $current = array();
        }
        $prev = array_merge(self::defaults(), $current);
        $merged = array_merge(self::defaults(), $settings);

        $secret_key = 'archive_passphrase';
        if (($merged[$secret_key] ?? '') === '********') {
            $merged[$secret_key] = $current[$secret_key] ?? '';
        }
        if (!empty($merged[$secret_key]) && strpos($merged[$secret_key], 'enc:') !== 0) {
            $merged[$secret_key] = 'enc:' . Rmmigrate_Crypto::encrypt($merged[$secret_key]);
        }

        update_site_option(self::OPTION_KEY, $merged);
        if (self::hosting_relevant_changed($prev, $merged)) {
            delete_site_option(Rmmigrate_Hosting_Detection::OPTION_KEY);
        }

        self::$cache = null;
    }

    /**
     * @param array<string,mixed> $prev
     * @param array<string,mixed> $merged
     */
    private static function hosting_relevant_changed(array $prev, array $merged): bool
    {
        foreach (array('kickoff_mode', 'archive_mode', 'archive_mode_manual', 'lock_mode') as $key) {
            if (($prev[$key] ?? null) != ($merged[$key] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private static function decrypt_secret(string $value): string
    {
        if (strpos($value, 'enc:') === 0) {
            return Rmmigrate_Crypto::decrypt(substr($value, 4));
        }
        return $value;
    }

    public static function get_archive_passphrase(): string
    {
        return self::decrypt_secret(self::get()['archive_passphrase'] ?? '');
    }

    /**
     * Max bytes for a single file packed into the archive. 0 disables the skip.
     * Filter: rmmigrate_max_archive_file_bytes.
     */
    public static function get_max_archive_file_bytes(): int
    {
        $settings = self::get();
        $default  = (int) ($settings['max_archive_file_bytes'] ?? 536870912);
        /** @var int $filtered */
        $filtered = (int) apply_filters('rmmigrate_max_archive_file_bytes', $default);
        return max(0, $filtered);
    }

    /**
     * Merge POST data into settings for a specific admin section/tab.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $post
     */
    public static function merge_post(array $current, array $post, string $section): array
    {
        $merged = $current;

        if ($section === 'scope') {
            $scope = sanitize_text_field(wp_unslash($post['default_scope'] ?? 'network'));
            if (!in_array($scope, array('network', 'network_filtered', 'network_included', 'subsite'), true)) {
                $scope = 'network';
            }
            $allowed_profiles = array('full', 'db', 'files', 'media', 'plugins', 'themes', 'custom');
            $profile = sanitize_text_field(wp_unslash($post['default_backup_profile'] ?? 'full'));
            if (!in_array($profile, $allowed_profiles, true)) {
                $profile = 'full';
            }
            $excluded = array();
            $included = array();
            if ($scope === 'network_filtered' && !empty($post['excluded_blogs']) && is_array($post['excluded_blogs'])) {
                $excluded = array_map('intval', $post['excluded_blogs']);
            }
            if ($scope === 'network_included' && !empty($post['included_blogs']) && is_array($post['included_blogs'])) {
                $included = array_map('intval', $post['included_blogs']);
            }
            $merged = array_merge($merged, array(
                'default_scope'          => $scope,
                'default_excluded_blogs' => $excluded,
                'default_included_blogs' => $included,
                'default_backup_profile' => $profile,
                'default_custom_paths'   => sanitize_textarea_field(wp_unslash($post['default_custom_paths'] ?? '')),
            ));
        } elseif ($section === 'backups') {
            // archive_mode + include_wp_core: set only via backup wizard (last-used).
            $merged = array_merge($merged, array(
                'encrypt_archives'        => !empty($post['encrypt_archives']),
                'archive_passphrase'      => self::secret_field($post, 'archive_passphrase', $current),
                'safety_snapshot_enabled' => !empty($post['safety_snapshot_enabled']),
                'exclude_paths'           => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($post['exclude_paths'] ?? ''))))),
            ));
        } elseif ($section === 'import') {
            $merged['import_chunk_size'] = max(1048576, (int) ($post['import_chunk_size'] ?? 2097152));
            $merged['sql_import_chunk_bytes'] = max(262144, min(4194304, (int) ($post['sql_import_chunk_bytes'] ?? ($current['sql_import_chunk_bytes'] ?? 1048576))));
        } elseif ($section === 'database') {
            // exclude_log_tables: set only via backup wizard (last-used).
            $merged = array_merge($merged, array(
                'db_mode'                 => sanitize_text_field(wp_unslash($post['db_mode'] ?? 'auto')),
                'mysqldump_path'          => sanitize_text_field(wp_unslash($post['mysqldump_path'] ?? '')),
                'db_insert_query_limit'   => max(32768, min(1048576, (int) ($post['db_insert_query_limit'] ?? 131072))),
                'sql_import_chunk_bytes'  => max(262144, min(4194304, (int) ($post['sql_import_chunk_bytes'] ?? ($current['sql_import_chunk_bytes'] ?? 1048576)))),
                'sql_import_transactions' => !empty($post['sql_import_transactions']),
                'post_import_search_replace' => !empty($post['post_import_search_replace']),
            ));
        } elseif ($section === 'engine') {
            // archive_mode + include_wp_core: set only via backup wizard (last-used).
            $merged = array_merge($merged, array(
                'encrypt_archives'        => !empty($post['encrypt_archives']),
                'archive_passphrase'      => self::secret_field($post, 'archive_passphrase', $current),
                'safety_snapshot_enabled' => !empty($post['safety_snapshot_enabled']),
                'exclude_paths'           => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($post['exclude_paths'] ?? ''))))),
                'db_mode'                 => sanitize_text_field(wp_unslash($post['db_mode'] ?? $current['db_mode'] ?? 'auto')),
                'mysqldump_path'          => sanitize_text_field(wp_unslash($post['mysqldump_path'] ?? '')),
                'db_insert_query_limit'   => max(32768, min(1048576, (int) ($post['db_insert_query_limit'] ?? $current['db_insert_query_limit'] ?? 131072))),
                'sql_import_chunk_bytes'  => max(262144, min(4194304, (int) ($post['sql_import_chunk_bytes'] ?? ($current['sql_import_chunk_bytes'] ?? 1048576)))),
                // exclude_log_tables: set only via backup wizard (last-used).
                'server_throttle'         => sanitize_key(wp_unslash($post['server_throttle'] ?? 'off')),
                'max_build_minutes'       => max(5, (int) ($post['max_build_minutes'] ?? 90)),
                'local_storage_path'      => sanitize_text_field(wp_unslash($post['local_storage_path'] ?? '')),
                'storage_htaccess'        => !empty($post['storage_htaccess']),
                'kickoff_mode'            => sanitize_key(wp_unslash($post['kickoff_mode'] ?? 'auto')),
                'lock_mode'               => sanitize_key(wp_unslash($post['lock_mode'] ?? 'auto')),
                'archive_mode_manual'     => !empty($post['archive_mode_manual']),
                'sql_import_transactions' => !empty($post['sql_import_transactions']),
                'post_import_search_replace' => !empty($post['post_import_search_replace']),
                'retention_network'      => max(0, (int) ($post['retention_network'] ?? 0)),
                'retention_subsite'      => max(0, (int) ($post['retention_subsite'] ?? 0)),
                'exclude_deleted_subsite_tables' => !empty($post['exclude_deleted_subsite_tables']),
            ));
        } elseif ($section === 'system') {
            $merged['admin_safe_mode'] = !empty($post['admin_safe_mode']);
            $merged['log_retention_months'] = max(1, (int) ($post['log_retention_months'] ?? 6));
            $merged['log_max_bytes'] = max(262144, min(52428800, (int) ($post['log_max_bytes'] ?? 2097152)));
            $merged['log_max_rotated_files'] = max(2, min(20, (int) ($post['log_max_rotated_files'] ?? 5)));
            $merged['activity_max_entries'] = max(500, min(10000, (int) ($post['activity_max_entries'] ?? 2000)));
            $merged['activity_page_size'] = max(10, min(50, (int) ($post['activity_page_size'] ?? 25)));
            $merged['log_view_lines'] = max(50, min(500, (int) ($post['log_view_lines'] ?? 200)));
        } elseif ($section === 'access') {
            unset($merged['installer_brand_name'], $merged['installer_brand_logo']);
        }

        return $merged;
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $current
     */
    private static function secret_field(array $post, string $key, array $current): string
    {
        $val = sanitize_text_field(wp_unslash($post[$key] ?? '********'));
        if ($val === '' || $val === '********') {
            return $current[$key] ?? '';
        }
        return $val;
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public static function parse_post(array $post, array $current): array
    {
        $tab = sanitize_key($post['settings_tab'] ?? 'engine');
        if ($tab === 'general') {
            $tab = 'engine';
        }
        if ($tab === 'engine') {
            return self::merge_post($current, $post, 'engine');
        }
        if ($tab === 'system') {
            return self::merge_post($current, $post, 'system');
        }
        return self::merge_post($current, $post, $tab);
    }
}

add_action(
    'update_site_option_' . Rmmigrate_Settings::OPTION_KEY,
    static function (): void {
        Rmmigrate_Settings::clear_cache();
    }
);
