<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth client + token storage (dbDelta tables).
 */
final class Rmmigrate_OAuth_Store
{
    public const PURGE_CRON_HOOK = 'rmmigrate_oauth_purge_expired';

    /** Grace period after expiry before purging revoked refresh tokens (replay detection). */
    private const REVOKED_REFRESH_PURGE_GRACE_SEC = 7 * DAY_IN_SECONDS;

    /** @var bool */
    private static $schema_ready = false;

    public static function clients_table(): string
    {
        global $wpdb;
        return $wpdb->base_prefix . 'mm_oauth_clients';
    }

    public static function tokens_table(): string
    {
        global $wpdb;
        return $wpdb->base_prefix . 'mm_oauth_tokens';
    }

    public static function maybe_install_tables(): void
    {
        global $wpdb;
        $clients = self::clients_table();
        $tokens = self::tokens_table();
        $charset = $wpdb->get_charset_collate();

        if (!function_exists('dbDelta') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (!function_exists('dbDelta')) {
            return;
        }

        $sql_clients = "CREATE TABLE {$clients} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(64) NOT NULL,
            client_secret_hash varchar(255) NOT NULL,
            name varchar(191) NOT NULL DEFAULT '',
            redirect_uris longtext NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id)
        ) {$charset};";

        $sql_tokens = "CREATE TABLE {$tokens} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            token_hash varchar(64) NOT NULL,
            token_type varchar(16) NOT NULL,
            scopes text NOT NULL,
            code_challenge varchar(128) NOT NULL DEFAULT '',
            redirect_uri text NOT NULL,
            expires_at datetime NOT NULL,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY client_id (client_id),
            KEY token_type (token_type)
        ) {$charset};";

        dbDelta($sql_clients);
        dbDelta($sql_tokens);
        update_site_option('rmmigrate_oauth_db_version', 1);
        self::$schema_ready = true;
        self::schedule_purge_cron();
    }

    public static function ensure_tables(): void
    {
        if (self::$schema_ready) {
            return;
        }

        if ((int) get_site_option('rmmigrate_oauth_db_version', 0) >= 1) {
            if (self::oauth_tables_exist()) {
                self::$schema_ready = true;
                return;
            }
            self::maybe_install_tables();
            return;
        }

        self::maybe_install_tables();
    }

    private static function oauth_tables_exist(): bool
    {
        global $wpdb;
        $clients = self::clients_table();
        $tokens = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence probe.
        $found_clients = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($clients)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence probe.
        $found_tokens = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($tokens)));

        return $found_clients === $clients && $found_tokens === $tokens;
    }

    public static function register_cron(): void
    {
        add_action(self::PURGE_CRON_HOOK, array(__CLASS__, 'purge_expired_tokens'));
    }

    public static function schedule_purge_cron(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (!wp_next_scheduled(self::PURGE_CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::PURGE_CRON_HOOK);
        }
    }

    public static function purge_expired_tokens(): void
    {
        global $wpdb;
        if ((int) get_site_option('rmmigrate_oauth_db_version', 0) < 1) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $revoked_refresh_cutoff = gmdate('Y-m-d H:i:s', time() - self::REVOKED_REFRESH_PURGE_GRACE_SEC);
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin: OAuth token table; identifier escaped via %i placeholder.
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE (revoked = 0 AND expires_at < %s)'
                . ' OR (revoked = 1 AND token_type <> %s AND expires_at < %s)'
                . ' OR (revoked = 1 AND token_type = %s AND expires_at < %s)',
                $table,
                $now,
                'refresh',
                $now,
                'refresh',
                $revoked_refresh_cutoff
            )
        );
    }

    /**
     * @param array<int,string> $redirect_uris
     * @return array{client_id:string,client_secret:string}|WP_Error
     */
    public static function create_client(string $name, array $redirect_uris, int $user_id)
    {
        self::ensure_tables();
        global $wpdb;

        $client_id = 'mm_' . bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $raw_uris = array_values(array_filter($redirect_uris, 'is_string'));
        $uris = array();
        foreach ($raw_uris as $uri) {
            $clean = esc_url_raw($uri);
            if ($clean !== '') {
                $uris[] = $clean;
            }
        }
        if ($raw_uris !== array() && $uris === array()) {
            return new WP_Error(
                'rmmigrate_oauth_client',
                __('All redirect URIs were invalid.', 'rosenheinrich-multisite-migrate')
            );
        }
        if ($uris === array()) {
            // OpenAI / ChatGPT connectors use dynamic redirects; store placeholder allow-any marker.
            $uris = array('*');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth client creation.
        $ok = $wpdb->insert(
            self::clients_table(),
            array(
                'client_id'          => $client_id,
                'client_secret_hash' => wp_hash_password($secret),
                'name'               => sanitize_text_field($name !== '' ? $name : 'ChatGPT MCP'),
                'redirect_uris'      => wp_json_encode($uris),
                'created_by'         => $user_id,
                'created_at'         => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );

        if (!$ok) {
            return new WP_Error('rmmigrate_oauth_client', __('Could not create OAuth client.', 'rosenheinrich-multisite-migrate'));
        }

        return array(
            'client_id'     => $client_id,
            'client_secret' => $secret,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_client(string $client_id): ?array
    {
        self::ensure_tables();
        global $wpdb;
        $table = self::clients_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query via %i placeholder.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE client_id = %s LIMIT 1', $table, $client_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function list_clients(): array
    {
        self::ensure_tables();
        global $wpdb;
        $table = self::clients_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query via %i placeholder.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, client_id, name, redirect_uris, created_by, created_at FROM %i ORDER BY id DESC', $table), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function delete_client(string $client_id): bool
    {
        self::ensure_tables();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation on client delete.
        $wpdb->update(
            self::tokens_table(),
            array('revoked' => 1),
            array('client_id' => $client_id),
            array('%d'),
            array('%s')
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth client delete.
        return (bool) $wpdb->delete(self::clients_table(), array('client_id' => $client_id), array('%s'));
    }

    public static function verify_secret(array $client, string $secret): bool
    {
        $hash = (string) ($client['client_secret_hash'] ?? '');
        return $hash !== '' && wp_check_password($secret, $hash);
    }

    public static function redirect_allowed(array $client, string $redirect_uri): bool
    {
        $raw = (string) ($client['redirect_uris'] ?? '[]');
        $uris = json_decode($raw, true);
        if (!is_array($uris)) {
            return false;
        }
        if (in_array('*', $uris, true)) {
            return self::wildcard_redirect_allowed($redirect_uri);
        }
        return in_array($redirect_uri, $uris, true);
    }

    /**
     * Wildcard clients only accept documented connector OAuth callback paths over HTTPS.
     */
    private static function wildcard_redirect_allowed(string $redirect_uri): bool
    {
        if ($redirect_uri === '') {
            return false;
        }
        $parts = wp_parse_url($redirect_uri);
        if (is_array($parts)) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (($scheme === 'http' || $scheme === 'https') && $host === 'localhost') {
                return self::allow_localhost_redirect();
            }
        }
        if (strpos($redirect_uri, 'https://') !== 0) {
            return false;
        }
        $host = strtolower((string) wp_parse_url($redirect_uri, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $allowed_hosts = array(
            'chatgpt.com',
            'chat.openai.com',
            'openai.com',
            'platform.openai.com',
        );
        $host_ok = false;
        foreach ($allowed_hosts as $allowed) {
            $suffix = '.' . $allowed;
            if ($host === $allowed || (strlen($host) >= strlen($suffix) && substr($host, -strlen($suffix)) === $suffix)) {
                $host_ok = true;
                break;
            }
        }
        if (!$host_ok) {
            return false;
        }

        return self::wildcard_redirect_path_allowed($redirect_uri, $host);
    }

    /**
     * Documented ChatGPT / OpenAI connector OAuth callback paths only.
     */
    private static function wildcard_redirect_path_allowed(string $redirect_uri, string $host): bool
    {
        $path = (string) wp_parse_url($redirect_uri, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        if ($host === 'platform.openai.com') {
            return $path === '/apps-manage/oauth';
        }
        if ($path === '/connector_platform_oauth_redirect') {
            return true;
        }
        return preg_match('#^/connector/oauth/[A-Za-z0-9_-]+/?$#', $path) === 1;
    }

    private static function allow_localhost_redirect(): bool
    {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type() === 'local';
    }

    /**
     * @param array<string> $scopes
     */
    public static function store_token(
        string $client_id,
        int $user_id,
        string $plain_token,
        string $token_type,
        array $scopes,
        int $ttl_seconds,
        string $code_challenge = '',
        string $redirect_uri = '',
        int $parent_id = 0
    ): int {
        self::ensure_tables();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth token insert.
        $ok = $wpdb->insert(
            self::tokens_table(),
            array(
                'client_id'      => $client_id,
                'user_id'        => $user_id,
                'token_hash'     => hash('sha256', $plain_token),
                'token_type'     => $token_type,
                'scopes'         => implode(' ', $scopes),
                'code_challenge' => $code_challenge,
                'redirect_uri'   => $redirect_uri,
                'expires_at'     => gmdate('Y-m-d H:i:s', time() + $ttl_seconds),
                'revoked'        => 0,
                'parent_id'      => $parent_id,
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        if (!$ok) {
            Rmmigrate_Logger::log(
                sprintf(
                    /* translators: 1: OAuth token type, 2: OAuth client ID */
                    __('Could not store OAuth %1$s token for client %2$s.', 'rosenheinrich-multisite-migrate'),
                    $token_type,
                    $client_id
                )
            );
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find_token(string $plain_token, string $token_type): ?array
    {
        return self::find_token_row($plain_token, $token_type, false);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find_token_row(string $plain_token, string $token_type, bool $include_revoked = false): ?array
    {
        self::ensure_tables();
        global $wpdb;
        $table = self::tokens_table();
        $hash = hash('sha256', $plain_token);
        if ($include_revoked) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin: custom OAuth table; identifier via %i placeholder.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE token_hash = %s AND token_type = %s LIMIT 1',
                    $table,
                    $hash,
                    $token_type
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin: custom OAuth table; identifier via %i placeholder.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE token_hash = %s AND token_type = %s AND revoked = 0 LIMIT 1',
                    $table,
                    $hash,
                    $token_type
                ),
                ARRAY_A
            );
        }
        if (!is_array($row)) {
            return null;
        }
        if (!$include_revoked && !empty($row['revoked'])) {
            return null;
        }
        if (!$include_revoked && strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            return null;
        }
        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find_revoked_token(string $plain_token, string $token_type): ?array
    {
        self::ensure_tables();
        global $wpdb;
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query via %i placeholder.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE token_hash = %s AND token_type = %s AND revoked = 1 LIMIT 1',
                $table,
                hash('sha256', $plain_token),
                $token_type
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function token_family_root_id(array $row): int
    {
        $parent_id = (int) ($row['parent_id'] ?? 0);
        if ($parent_id > 0) {
            return $parent_id;
        }

        return (int) ($row['id'] ?? 0);
    }

    public static function revoke_token_family(int $token_id): void
    {
        if ($token_id <= 0) {
            return;
        }

        self::ensure_tables();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token family revocation.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET revoked = 1 WHERE revoked = 0 AND (id = %d OR parent_id = %d)',
                self::tokens_table(),
                $token_id,
                $token_id
            )
        );
    }

    public static function revoke_token_id(int $id): bool
    {
        self::ensure_tables();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation (atomic).
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET revoked = 1 WHERE id = %d AND revoked = 0',
                self::tokens_table(),
                $id
            )
        );
        return (int) $updated === 1;
    }

    public static function revoke_all_for_client(string $client_id): void
    {
        self::ensure_tables();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation for client.
        $wpdb->update(self::tokens_table(), array('revoked' => 1), array('client_id' => $client_id), array('%d'), array('%s'));
    }
}
