<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth client + token storage (dbDelta tables).
 */
final class Rmmigrate_OAuth_Store
{
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

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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
    }

    public static function ensure_tables(): void
    {
        if ((int) get_site_option('rmmigrate_oauth_db_version', 0) >= 1) {
            return;
        }
        self::maybe_install_tables();
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
        $uris = array_values(array_filter(array_map('esc_url_raw', $redirect_uris)));
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
            return $redirect_uri !== '' && (strpos($redirect_uri, 'https://') === 0 || strpos($redirect_uri, 'http://localhost') === 0);
        }
        return in_array($redirect_uri, $uris, true);
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
        $wpdb->insert(
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
        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find_token(string $plain_token, string $token_type): ?array
    {
        self::ensure_tables();
        global $wpdb;
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query via %i placeholder.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE token_hash = %s AND token_type = %s AND revoked = 0 LIMIT 1', $table, hash('sha256', $plain_token), $token_type), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            return null;
        }
        return $row;
    }

    public static function revoke_token_id(int $id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation.
        $wpdb->update(self::tokens_table(), array('revoked' => 1), array('id' => $id), array('%d'), array('%d'));
    }

    public static function revoke_all_for_client(string $client_id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation for client.
        $wpdb->update(self::tokens_table(), array('revoked' => 1), array('client_id' => $client_id), array('%d'), array('%s'));
    }
}
