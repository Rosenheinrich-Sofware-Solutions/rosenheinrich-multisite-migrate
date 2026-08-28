<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Restore_Topology_Wp_Config
{
    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $destination
     */
    public static function default_policy(array $state, array $manifest, array $destination): string
    {
        if ((string) ($state['wp_config_policy'] ?? '') === 'nothing') {
            return 'nothing';
        }

        if (empty($destination['has_wp_config'])) {
            return 'keep';
        }

        if ((string) ($state['install_mode'] ?? '') === 'overwrite') {
            return 'merge';
        }

        if (!empty($state['do_migration'])) {
            return 'merge';
        }

        if (Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest)) {
            return 'merge';
        }

        if (!empty($destination['is_multisite'])
            && !Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            return 'merge';
        }

        return 'keep';
    }

    /**
     * @param Rmmigrate_Installer_State $state
     */
    public static function apply_installer(Rmmigrate_Installer_State $state): bool
    {
        $abspath = Rmmigrate_Installer_Paths::abspath();
        $flat = $state->all();
        if (!is_array($flat['manifest'] ?? null) || $flat['manifest'] === array()) {
            if (class_exists('Rmmigrate_Installer_Manifest', false)) {
                $baked = Rmmigrate_Installer_Manifest::baked_manifest();
                if ($baked !== array()) {
                    $flat['manifest'] = $baked;
                }
            }
        }

        return self::apply(
            $flat,
            $abspath,
            static function (string $path): string {
                if (!Rmmigrate_Installer_Filesystem::exists($path)) {
                    return '';
                }

                return (string) Rmmigrate_Installer_Filesystem::get_contents($path);
            },
            static function (string $path, string $contents): bool {
                return Rmmigrate_Installer_Filesystem::put_contents($path, $contents) !== false;
            },
            Rmmigrate_Installer_Paths::extract_dir() . '/files/wp-config.php'
        );
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function apply_plugin(array $state, string $abspath, string $extracted_wp_config = ''): bool
    {
        return self::apply(
            $state,
            trailingslashit($abspath),
            static function (string $path): string {
                return Rmmigrate_Filesystem::exists($path)
                    ? (string) Rmmigrate_Filesystem::get_contents($path)
                    : '';
            },
            static function (string $path, string $contents): bool {
                return Rmmigrate_Filesystem::put_contents($path, $contents) !== false;
            },
            $extracted_wp_config
        );
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function apply(
        array $state,
        string $abspath,
        callable $read,
        callable $write,
        string $backup_wp_config_path
    ): bool {
        $policy = (string) ($state['wp_config_policy'] ?? 'keep');
        if ($policy === 'nothing') {
            return false;
        }

        $dest_path = rtrim($abspath, '/\\') . '/wp-config.php';
        $exists = is_file($dest_path);
        if ($exists && $policy === 'keep') {
            return false;
        }

        $manifest = is_array($state['manifest'] ?? null) ? $state['manifest'] : array();
        $prefix = self::resolve_table_prefix($state, $manifest);

        if ($policy === 'merge' && ($exists || is_file($backup_wp_config_path))) {
            $source = self::resolve_wp_config_source($dest_path, $backup_wp_config_path, $read, $state);
            if ($source !== '') {
                $destination_contents = $exists ? $read($dest_path) : '';
                $merged = self::merge_contents($source, $state, $manifest, $prefix, $destination_contents);
                if ($exists) {
                    if (!self::backup_existing_dest($dest_path, $read, $write)) {
                        return false;
                    }
                }
                return $write($dest_path, $merged);
            }

            return false;
        }

        if ($exists) {
            if (!self::backup_existing_dest($dest_path, $read, $write)) {
                return false;
            }
        }

        return $write($dest_path, self::build_template($state, $manifest, $prefix));
    }

    private static function backup_existing_dest(string $dest_path, callable $read, callable $write): bool
    {
        if (!is_file($dest_path)) {
            return true;
        }

        $backup_path = self::existing_dest_backup_path($dest_path);
        if ($backup_path === '') {
            return false;
        }

        if (!self::ensure_existing_dest_backup_dir(dirname($backup_path))) {
            return false;
        }

        if (is_file($backup_path)) {
            self::remove_legacy_dest_backup($dest_path);

            return true;
        }

        $legacy_path = $dest_path . '.rmmigrate-bak';
        if (is_file($legacy_path)) {
            $contents = $read($legacy_path);
            if ($contents !== '' && $write($backup_path, $contents)) {
                self::remove_legacy_dest_backup($dest_path);

                return true;
            }

            return false;
        }

        $contents = $read($dest_path);
        if ($contents !== '' && $write($backup_path, $contents)) {
            self::remove_legacy_dest_backup($dest_path);

            return true;
        }

        return false;
    }

    private static function existing_dest_backup_path(string $dest_path): string
    {
        $dir = self::existing_dest_backup_dir();
        if ($dir === '') {
            return '';
        }

        $normalized = function_exists('wp_normalize_path')
            ? wp_normalize_path($dest_path)
            : str_replace('\\', '/', $dest_path);

        return trailingslashit($dir) . 'wp-config-' . hash('sha256', $normalized) . '.bak';
    }

    private static function existing_dest_backup_dir(): string
    {
        if (class_exists('Rmmigrate_Plugin', false)) {
            if (!Rmmigrate_Plugin::ensure_backup_root()) {
                return '';
            }

            return wp_normalize_path(Rmmigrate_Plugin::backups_dir() . '/recover/wp-config');
        }

        if (class_exists('Rmmigrate_Installer_Paths', false)) {
            $dir = Rmmigrate_Installer_Paths::state_dir() . '/wp-config-backups';
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            return $dir;
        }

        return '';
    }

    private static function ensure_existing_dest_backup_dir(string $dir): bool
    {
        if ($dir === '') {
            return false;
        }

        if (class_exists('Rmmigrate_Filesystem', false)) {
            return Rmmigrate_Filesystem::ensure_directory($dir);
        }

        if (class_exists('Rmmigrate_Installer_Filesystem', false)) {
            return is_dir($dir) || wp_mkdir_p($dir);
        }

        if (is_dir($dir)) {
            return true;
        }

        return wp_mkdir_p($dir);
    }

    private static function remove_legacy_dest_backup(string $dest_path): void
    {
        $legacy_path = $dest_path . '.rmmigrate-bak';
        if (!is_file($legacy_path)) {
            return;
        }

        if (class_exists('Rmmigrate_Filesystem', false)) {
            Rmmigrate_Filesystem::delete($legacy_path);
            return;
        }

        if (class_exists('Rmmigrate_Installer_Filesystem', false)) {
            Rmmigrate_Installer_Filesystem::delete($legacy_path);
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- installer fallback when wrappers unavailable.
        @unlink($legacy_path);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $manifest
     */
    private static function resolve_table_prefix(array $state, array $manifest): string
    {
        if (Rmmigrate_Restore_Topology_Prefix_Remap::should_remap($manifest, $state)) {
            return Rmmigrate_Restore_Topology_Prefix_Remap::resolve_remap_target($manifest, $state);
        }
        if (Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest)) {
            return Rmmigrate_Restore_Topology_Manifest::source_blog_prefix($manifest);
        }
        $prefix = (string) ($state['db_prefix'] ?? 'wp_');

        return $prefix;
    }

    private static function resolve_wp_config_source(string $dest_path, string $backup_path, callable $read, array $state = array()): string
    {
        if (!empty($state['db_locked']) && is_file($dest_path)) {
            return $read($dest_path);
        }
        if (is_file($backup_path)) {
            return $read($backup_path);
        }
        if (is_file($dest_path)) {
            return $read($dest_path);
        }

        return '';
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $manifest
     */
    public static function merge_contents(
        string $contents,
        array $state,
        array $manifest,
        string $prefix,
        string $destination_contents = ''
    ): string {
        if (!Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)
            || Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest)) {
            $contents = Rmmigrate_Restore_Topology_Standalone::strip_multisite_wp_config($contents);
        }

        if (self::should_strip_environment_constants($state, $manifest)) {
            $contents = self::strip_environment_constants($contents);
        }

        $merge_constants = $state['wp_config_merge_constants'] ?? null;
        if (!is_array($merge_constants) || $merge_constants === array()) {
            $merge_constants = Rmmigrate_Restore_Topology_Ui::wp_config_merge_constant_keys();
        }

        if (empty($state['db_locked'])) {
            $creds = self::resolve_database_credentials($state, $destination_contents);
            foreach ($creds as $key => $value) {
                if (!in_array($key, $merge_constants, true)) {
                    continue;
                }
                if ($value === '' && in_array($key, array('DB_NAME', 'DB_USER', 'DB_HOST'), true)) {
                    continue; // Never corrupt existing wp-config.php by writing empty critical credentials.
                }
                $pattern = "/define\\s*\\(\\s*['\"]" . preg_quote($key, '/') . "['\"]\\s*,\\s*['\"]((?:\\\\.|[^'\"\\\\])*)['\"]\\s*\\)/";
                $replacement = "define('" . $key . "', '" . self::escape_wp_config_single_quoted($value) . "')";
                if (preg_match($pattern, $contents)) {
                    $contents = self::preg_replace_literal($pattern, $replacement, $contents, 1);
                } else {
                    $contents = self::insert_before_bootstrap($contents, $replacement . ";\n");
                }
            }
        }

        if (in_array('table_prefix', $merge_constants, true)) {
            $assignment = '$table_prefix = \'' . self::escape_wp_config_single_quoted($prefix) . '\';';
            if (preg_match('/\$table_prefix\s*=/', $contents)) {
                $contents = self::preg_replace_literal(
                    '/\$table_prefix\s*=\s*(["\'])(?:\\\\.|(?!\1).)*\1\s*;/',
                    $assignment,
                    $contents,
                    1
                );
            } else {
                $contents = self::insert_before_bootstrap($contents, $assignment . "\n");
            }
        }

        if (!empty($manifest['is_multisite']) && Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            $contents = self::ensure_multisite_constants($contents, $manifest, $state);
        }

        return $contents;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,string>
     */
    private static function resolve_database_credentials(array $state, string $destination_contents): array
    {
        $locked = !empty($state['db_locked'])
            || (string) ($state['db_creds_source'] ?? '') === 'wp_config';
        if ($locked && $destination_contents !== '') {
            $parsed = array();
            foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST') as $constant) {
                $value = Rmmigrate_Restore_Topology_Destination::read_wp_config_constant($destination_contents, $constant);
                if ($value !== null) {
                    $parsed[$constant] = $value;
                }
            }
            if ($parsed !== array()) {
                return $parsed;
            }
        }

        return array(
            'DB_NAME'     => (string) ($state['db_name'] ?? ''),
            'DB_USER'     => (string) ($state['db_user'] ?? ''),
            'DB_PASSWORD' => (string) ($state['db_pass'] ?? ''),
            'DB_HOST'     => (string) ($state['db_host'] ?? ''),
        );
    }

    /**
     * Resolve the primary network domain/path for the destination, preferring
     * the migration map's new URL (so a domain-change migration writes the
     * destination host) and falling back to the source manifest values.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $state
     * @return array{0:string,1:string} [domain, path]
     */
    private static function resolve_primary_domain_path(array $manifest, array $state): array
    {
        $map = is_array($state['migration_map'] ?? null) ? $state['migration_map'] : array();
        $domain = (string) ($manifest['domain_current_site'] ?? '');
        $path = (string) ($manifest['path_current_site'] ?? '/');
        $new_url = (string) ($map['site_url']['new'] ?? $map['new_url'] ?? $map['new_site_url'] ?? '');
        if ($new_url !== '') {
            $parsed = wp_parse_url($new_url);
            if (is_array($parsed)) {
                $host = (string) ($parsed['host'] ?? '');
                if ($host !== '') {
                    $domain = $host;
                }
                $parsed_path = (string) ($parsed['path'] ?? '');
                if ($parsed_path !== '') {
                    $path = $parsed_path;
                }
            }
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/') . '/';
        }

        return array($domain, $path);
    }

    private static function ensure_multisite_constants(string $contents, array $manifest, array $state): string
    {
        list($domain, $path) = self::resolve_primary_domain_path($manifest, $state);
        $subdomain = !empty($manifest['subdomain_install']) ? 'true' : 'false';
        $block = "\n/* Multisite Constants */\n"
            . "define('WP_ALLOW_MULTISITE', true);\n"
            . "define('MULTISITE', true);\n"
            . "define('SUBDOMAIN_INSTALL', {$subdomain});\n"
            . "define('DOMAIN_CURRENT_SITE', '" . self::escape_wp_config_single_quoted($domain) . "');\n"
            . "define('PATH_CURRENT_SITE', '" . self::escape_wp_config_single_quoted($path) . "');\n"
            . "define('SITE_ID_CURRENT_SITE', 1);\n"
            . "define('BLOG_ID_CURRENT_SITE', 1);\n";

        if (!preg_match("/define\\s*\\(\\s*['\"]MULTISITE['\"]/", $contents)) {
            $contents = self::insert_before_bootstrap($contents, $block);
        } else {
            $contents = self::upsert_define_literal($contents, 'MULTISITE', 'true');
            $contents = self::upsert_define_literal($contents, 'WP_ALLOW_MULTISITE', 'true');
            $contents = self::upsert_define_literal($contents, 'SUBDOMAIN_INSTALL', $subdomain);
            $contents = self::ensure_define($contents, 'SITE_ID_CURRENT_SITE', '1');
            $contents = self::ensure_define($contents, 'BLOG_ID_CURRENT_SITE', '1');
            if ($domain !== '') {
                $contents = self::upsert_string_define($contents, 'DOMAIN_CURRENT_SITE', $domain);
            }
            if ($path !== '') {
                $contents = self::upsert_string_define($contents, 'PATH_CURRENT_SITE', $path);
            }
        }

        return $contents;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $manifest
     */
    private static function build_template(array $state, array $manifest, string $prefix): string
    {
        $creds = self::resolve_database_credentials($state, '');
        $salts = self::random_salts();
        $ms = '';
        if (!empty($manifest['is_multisite']) && Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            $subdomain = !empty($manifest['subdomain_install']) ? 'true' : 'false';
            list($resolved_domain, $resolved_path) = self::resolve_primary_domain_path($manifest, $state);
            $domain = self::escape_wp_config_single_quoted($resolved_domain);
            $path = self::escape_wp_config_single_quoted($resolved_path);
            $ms = "\n/* Multisite Constants */\n"
                . "define('WP_ALLOW_MULTISITE', true);\n"
                . "define('MULTISITE', true);\n"
                . "define('SUBDOMAIN_INSTALL', {$subdomain});\n"
                . "define('DOMAIN_CURRENT_SITE', '{$domain}');\n"
                . "define('PATH_CURRENT_SITE', '{$path}');\n"
                . "define('SITE_ID_CURRENT_SITE', 1);\n"
                . "define('BLOG_ID_CURRENT_SITE', 1);\n\n";
        }

        return "<?php\n"
            . "define('DB_NAME', '" . self::escape_wp_config_single_quoted($creds['DB_NAME']) . "');\n"
            . "define('DB_USER', '" . self::escape_wp_config_single_quoted($creds['DB_USER']) . "');\n"
            . "define('DB_PASSWORD', '" . self::escape_wp_config_single_quoted($creds['DB_PASSWORD']) . "');\n"
            . "define('DB_HOST', '" . self::escape_wp_config_single_quoted($creds['DB_HOST']) . "');\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "define('DB_COLLATE', '');\n"
            . "\$table_prefix = '" . self::escape_wp_config_single_quoted($prefix) . "';\n"
            . $salts
            . $ms
            . "if (!defined('ABSPATH')) {\n"
            . "    define('ABSPATH', __DIR__ . '/');\n"
            . "}\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";
    }

    private static function escape_wp_config_single_quoted(string $value): string
    {
        return str_replace(array('\\', "'"), array('\\\\', "\\'"), $value);
    }

    private static function ensure_define(string $contents, string $name, string $value_literal): string
    {
        if (preg_match("/define\\s*\\(\\s*['\"]" . preg_quote($name, '/') . "['\"]/", $contents)) {
            return $contents;
        }

        return self::insert_before_bootstrap($contents, "define('{$name}', {$value_literal});\n");
    }

    private static function upsert_define_literal(string $contents, string $name, string $value_literal): string
    {
        $replacement = "define('{$name}', {$value_literal})";
        $pattern = "/define\\s*\\(\\s*['\"]" . preg_quote($name, '/') . "['\"]/";
        if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE)) {
            $start = (int) $match[0][1];
            $open_paren = strpos($contents, '(', $start);
            if ($open_paren !== false) {
                $depth = 0;
                $length = strlen($contents);
                for ($i = $open_paren; $i < $length; $i++) {
                    $char = $contents[$i];
                    if ($char === '(') {
                        $depth++;
                    } elseif ($char === ')') {
                        $depth--;
                        if ($depth === 0) {
                            return substr($contents, 0, $start) . $replacement . substr($contents, $i + 1);
                        }
                    }
                }
            }
        }

        return self::insert_before_bootstrap($contents, $replacement . ";\n");
    }

    private static function preg_replace_literal(string $pattern, string $replacement, string $contents, int $limit = -1): string
    {
        return (string) preg_replace_callback(
            $pattern,
            static function () use ($replacement): string {
                return $replacement;
            },
            $contents,
            $limit
        );
    }

    private static function upsert_string_define(string $contents, string $name, string $value): string
    {
        $replacement = "define('" . $name . "', '" . self::escape_wp_config_single_quoted($value) . "')";
        $pattern = "/define\\s*\\(\\s*['\"]" . preg_quote($name, '/') . "['\"]\\s*,\\s*['\"]((?:\\\\.|[^'\"\\\\])*)['\"]\\s*\\)/";
        if (preg_match($pattern, $contents)) {
            return self::preg_replace_literal($pattern, $replacement, $contents, 1);
        }

        return self::insert_before_bootstrap($contents, $replacement . ";\n");
    }

    private static function insert_before_bootstrap(string $contents, string $block): string
    {
        $pattern = '/^\s*require(?:_once)?\s*(?:\(\s*)?(?:ABSPATH\s*\.\s*|__DIR__\s*\.\s*[\'"]\/?)?[\'"]wp-settings\.php[\'"]/m';
        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = $matches[0][1];

            return substr($contents, 0, $offset) . rtrim($block) . "\n\n" . substr($contents, $offset);
        }

        $trimmed = rtrim($contents);
        if (preg_match('/\?>\s*$/', $trimmed)) {
            $without_close = (string) preg_replace('/\?>\s*$/', '', $trimmed);

            return rtrim($without_close) . "\n" . rtrim($block) . "\n\n?>\n";
        }

        return rtrim($contents) . "\n" . rtrim($block) . "\n";
    }

    private static function random_salts(): string
    {
        $keys = array('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT');
        $out = '';
        foreach ($keys as $key) {
            $out .= "define('{$key}', '" . bin2hex(random_bytes(32)) . "');\n";
        }

        return $out;
    }

    private static function should_strip_environment_constants(array $state, array $manifest): bool
    {
        if (Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest)) {
            return true;
        }

        if (!empty($state['do_migration']) || !empty($state['migration_map'])) {
            return true;
        }

        if (empty($state['db_locked'])) {
            return true;
        }

        return false;
    }

    public static function strip_environment_constants(string $contents): string
    {
        $constants = array(
            'WP_HOME',
            'WP_SITEURL',
            'WP_CONTENT_URL',
            'WP_PLUGIN_URL',
            'WP_CONTENT_DIR',
            'WP_PLUGIN_DIR',
            'WPMU_PLUGIN_DIR',
            'WP_TEMP_DIR',
            'COOKIE_DOMAIN',
            'COOKIEPATH',
            'SITECOOKIEPATH',
            'WP_CACHE',
            'WPCACHEHOME',
        );

        foreach ($constants as $constant) {
            $pattern = "/(define\\s*\\(\\s*['\"])" . preg_quote($constant, '/') . "(['\"])/i";
            $replacement = "$1Rmmigrate_DISABLED_" . $constant . "$2";
            $contents = (string) preg_replace($pattern, $replacement, $contents);
        }

        return $contents;
    }
}
