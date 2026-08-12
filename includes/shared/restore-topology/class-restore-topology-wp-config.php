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
     * @param array<string,mixed> $state
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
        $prefix = self::resolve_table_prefix($state, $manifest, $dest_path, $read);

        if ($policy === 'merge' && ($exists || is_file($backup_wp_config_path))) {
            $source = self::resolve_wp_config_source($dest_path, $backup_wp_config_path, $read, $state);
            if ($source !== '') {
                $destination_contents = $exists ? $read($dest_path) : '';
                $merged = self::merge_contents($source, $state, $manifest, $prefix, $destination_contents);
                return $write($dest_path, $merged);
            }
        }

        return $write($dest_path, self::build_template($state, $manifest, $prefix));
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $manifest
     */
    private static function resolve_table_prefix(array $state, array $manifest, string $dest_path, callable $read): string
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
        if (Rmmigrate_Restore_Topology_Manifest::is_subsite_standalone_restore($manifest)) {
            $contents = Rmmigrate_Restore_Topology_Standalone::strip_multisite_wp_config($contents);
        } elseif (!Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            $contents = Rmmigrate_Restore_Topology_Standalone::strip_multisite_wp_config($contents);
        }

        if (self::should_strip_environment_constants($state, $manifest)) {
            $contents = self::strip_environment_constants($contents);
        }

        if (empty($state['db_locked'])) {
            $merge_constants = $state['wp_config_merge_constants'] ?? null;
            if (!is_array($merge_constants) || $merge_constants === array()) {
                $merge_constants = array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST');
            }
            $creds = self::resolve_database_credentials($state, $destination_contents);
            foreach ($creds as $key => $value) {
                if (!in_array($key, $merge_constants, true)) {
                    continue;
                }
                if ($value === '' && in_array($key, array('DB_NAME', 'DB_USER', 'DB_HOST'), true)) {
                    continue; // Never corrupt existing wp-config.php by writing empty critical credentials.
                }
                $pattern = "/define\\s*\\(\\s*['\"]" . preg_quote($key, '/') . "['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)/";
                $replacement = "define('" . $key . "', '" . addslashes($value) . "')";
                if (preg_match($pattern, $contents)) {
                    $contents = (string) preg_replace($pattern, $replacement, $contents, 1);
                }
            }
        }

        $merge_constants = is_array($state['wp_config_merge_constants'] ?? null)
            ? $state['wp_config_merge_constants']
            : array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'table_prefix');
        if (in_array('table_prefix', $merge_constants, true) && preg_match('/\$table_prefix\s*=/', $contents)) {
            $contents = (string) preg_replace(
                '/\$table_prefix\s*=\s*(["\'])(?:\\\\.|(?!\1).)*\1\s*;/',
                '$table_prefix = \'' . addslashes($prefix) . '\';',
                $contents,
                1
            );
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
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $state
     */
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
            . "define('DOMAIN_CURRENT_SITE', '" . addslashes($domain) . "');\n"
            . "define('PATH_CURRENT_SITE', '" . addslashes($path) . "');\n"
            . "define('SITE_ID_CURRENT_SITE', 1);\n"
            . "define('BLOG_ID_CURRENT_SITE', 1);\n";

        if (strpos($contents, 'MULTISITE') === false) {
            $contents = rtrim($contents) . $block;
        } elseif ($domain !== '' && strpos($contents, 'DOMAIN_CURRENT_SITE') !== false) {
            $contents = (string) preg_replace(
                "/define\\s*\\(\\s*['\"]DOMAIN_CURRENT_SITE['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)/",
                "define('DOMAIN_CURRENT_SITE', '" . addslashes($domain) . "')",
                $contents,
                1
            );
        }
        if ($path !== '' && strpos($contents, 'PATH_CURRENT_SITE') !== false) {
            $contents = (string) preg_replace(
                "/define\\s*\\(\\s*['\"]PATH_CURRENT_SITE['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)/",
                "define('PATH_CURRENT_SITE', '" . addslashes($path) . "')",
                $contents,
                1
            );
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
            $domain = addslashes($resolved_domain);
            $path = addslashes($resolved_path);
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
            . "define('DB_NAME', '" . addslashes($creds['DB_NAME']) . "');\n"
            . "define('DB_USER', '" . addslashes($creds['DB_USER']) . "');\n"
            . "define('DB_PASSWORD', '" . addslashes($creds['DB_PASSWORD']) . "');\n"
            . "define('DB_HOST', '" . addslashes($creds['DB_HOST']) . "');\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "define('DB_COLLATE', '');\n"
            . "\$table_prefix = '" . addslashes($prefix) . "';\n"
            . $salts
            . $ms
            . "if (!defined('ABSPATH')) {\n"
            . "    define('ABSPATH', __DIR__ . '/');\n"
            . "}\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";
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
