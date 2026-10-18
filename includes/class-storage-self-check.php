<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks over HTTP whether the backup storage folder is publicly downloadable.
 *
 * The protection files (.htaccess, web.config, index.php) only work on servers that honor
 * them. nginx and many reverse proxies ignore them, so a probe file is requested through the
 * site URL. When it can be downloaded, admins get a notice with a server rule to add.
 */
final class Rmmigrate_Storage_Self_Check
{
    const OPTION = 'rmmigrate_storage_self_check';
    const HOOK = 'rmmigrate_storage_self_check';
    const INTERVAL = 86400;

    const STATUS_EXPOSED = 'exposed';
    const STATUS_PROTECTED = 'protected';
    const STATUS_NOT_PUBLIC = 'not_public';
    const STATUS_UNKNOWN = 'unknown';

    public static function register(): void
    {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('admin_init', array(__CLASS__, 'maybe_schedule'));
        add_action('admin_notices', array(__CLASS__, 'render_notice'));
        add_action('network_admin_notices', array(__CLASS__, 'render_notice'));
    }

    public static function enabled(): bool
    {
        return (bool) apply_filters('rmmigrate_storage_self_check_enabled', true);
    }

    /**
     * Queue one background check per day (or when the storage folder changed).
     */
    public static function maybe_schedule(): void
    {
        if (!self::enabled() || !self::current_user_can_manage()) {
            return;
        }
        $state = self::state();
        $fresh = (time() - (int) $state['checked_at']) < self::INTERVAL;
        if ($fresh && $state['root'] === self::storage_root()) {
            return;
        }
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 10, self::HOOK);
        }
    }

    /**
     * @return array{status:string,checked_at:int,url:string,root:string}
     */
    public static function state(): array
    {
        $raw = get_site_option(self::OPTION, array());
        $raw = is_array($raw) ? $raw : array();

        return array(
            'status'     => is_string($raw['status'] ?? null) ? (string) $raw['status'] : '',
            'checked_at' => (int) ($raw['checked_at'] ?? 0),
            'url'        => is_string($raw['url'] ?? null) ? (string) $raw['url'] : '',
            'root'       => is_string($raw['root'] ?? null) ? (string) $raw['root'] : '',
        );
    }

    /**
     * Run the probe now and store the result.
     *
     * @return array{status:string,checked_at:int,url:string,root:string}
     */
    public static function run(): array
    {
        $root = self::storage_root();
        $result = array(
            'status'     => self::STATUS_UNKNOWN,
            'checked_at' => time(),
            'url'        => '',
            'root'       => $root,
        );
        if (!self::enabled()) {
            return $result;
        }

        $base_url = self::public_url_for_path($root);
        if ($base_url === '') {
            $result['status'] = self::STATUS_NOT_PUBLIC;
            self::save($result);

            return $result;
        }
        $result['url'] = $base_url . '/';

        if (!Rmmigrate_Plugin::ensure_backup_root()) {
            self::save($result);

            return $result;
        }

        $name = 'rmmigrate-probe-' . bin2hex(random_bytes(8)) . '.txt';
        $token = bin2hex(random_bytes(16));
        $probe = $root . '/' . $name;
        if (false === Rmmigrate_Filesystem::put_contents($probe, $token)) {
            self::save($result);

            return $result;
        }

        try {
            $response = wp_remote_get(
                $base_url . '/' . $name,
                array(
                    'timeout'             => 5,
                    'redirection'         => 0,
                    'limit_response_size' => 4096,
                    // Same-host request; local certificates are often self-signed.
                    'sslverify'           => (bool) apply_filters('rmmigrate_storage_self_check_sslverify', false),
                    'headers'             => array('Cache-Control' => 'no-cache'),
                )
            );
            if (!is_wp_error($response)) {
                $code = (int) wp_remote_retrieve_response_code($response);
                $body = (string) wp_remote_retrieve_body($response);
                $result['status'] = ($code === 200 && false !== strpos($body, $token))
                    ? self::STATUS_EXPOSED
                    : self::STATUS_PROTECTED;
            }
        } finally {
            Rmmigrate_Filesystem::delete($probe);
        }

        self::save($result);

        return $result;
    }

    /**
     * Public URL for a folder below wp-content or the WordPress root, '' when not web-reachable.
     */
    public static function public_url_for_path(string $path): string
    {
        $path = untrailingslashit(wp_normalize_path($path));
        // Lets hosts with custom document roots or CDNs supply the public URL of the folder.
        $filtered = apply_filters('rmmigrate_storage_self_check_url', null, $path);
        if (is_string($filtered)) {
            return untrailingslashit($filtered);
        }
        $candidates = array();
        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = array(WP_CONTENT_DIR, content_url());
        }
        if (defined('ABSPATH')) {
            $candidates[] = array(ABSPATH, is_multisite() ? network_site_url('/') : site_url('/'));
        }
        foreach ($candidates as $candidate) {
            $base = untrailingslashit(wp_normalize_path((string) $candidate[0]));
            if ($base === '' || ($path !== $base && strpos($path, $base . '/') !== 0)) {
                continue;
            }
            $rel = ltrim(substr($path, strlen($base)), '/');
            $url = untrailingslashit((string) $candidate[1]);
            if ($rel === '') {
                return $url;
            }

            return $url . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
        }

        return '';
    }

    public static function nginx_snippet(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $path = '/' . trim($path, '/') . '/';

        return 'location ^~ ' . $path . " {\n    deny all;\n}";
    }

    public static function render_notice(): void
    {
        if (!self::current_user_can_manage() || !self::is_plugin_page()) {
            return;
        }
        $state = self::state();
        if ($state['status'] !== self::STATUS_EXPOSED || $state['root'] !== self::storage_root()) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html(sprintf(
            /* translators: %s: URL of the backup storage folder */
            __('Backup storage is publicly reachable: a test file in %s could be downloaded. Your web server ignores the protection files (.htaccess, web.config). Block this folder in the server configuration, for example on nginx:', 'rosenheinrich-multisite-migrate'),
            $state['url']
        ));
        echo '</p><pre><code>' . esc_html(self::nginx_snippet($state['url'])) . '</code></pre></div>';
    }

    private static function storage_root(): string
    {
        return untrailingslashit(wp_normalize_path(Rmmigrate_Plugin::backups_dir()));
    }

    /**
     * @param array{status:string,checked_at:int,url:string,root:string} $result
     */
    private static function save(array $result): void
    {
        update_site_option(self::OPTION, $result);
    }

    private static function current_user_can_manage(): bool
    {
        return is_multisite() ? current_user_can('manage_network_options') : current_user_can('manage_options');
    }

    private static function is_plugin_page(): bool
    {
        $page = Rmmigrate_Request_Input::get_key('page');

        return $page !== '' && isset(Rmmigrate_Admin_Router::all_page_slugs()[$page]);
    }
}
