<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps OAuth Bearer access tokens to WP users for REST / MCP Adapter calls.
 */
final class Rmmigrate_OAuth_Bearer_Auth
{
    public static function register(): void
    {
        add_filter('rest_authentication_errors', array(__CLASS__, 'authenticate'), 15);
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public static function authenticate($result)
    {
        if (is_wp_error($result)) {
            return $result;
        }

        $header = self::get_authorization_header();
        if ($header === '' || stripos($header, 'Bearer ') !== 0) {
            return $result;
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return $result;
        }

        // Foreign Bearer (e.g. other plugins' JWTs) must not be claimed.
        if (substr_count($token, '.') === 2) {
            return $result;
        }

        // Only fail closed for MM-shaped opaque access tokens (64 hex chars).
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return $result;
        }

        $row = Rmmigrate_OAuth_Store::find_token($token, 'access');
        if ($row === null) {
            return new WP_Error(
                'rmmigrate_oauth_invalid_token',
                __('Invalid OAuth access token.', 'rosenheinrich-multisite-migrate'),
                array('status' => 401)
            );
        }

        $user_id = (int) $row['user_id'];
        if ($user_id <= 0 || !get_userdata($user_id)) {
            return new WP_Error(
                'rmmigrate_oauth_invalid_user',
                __('OAuth token user is invalid.', 'rosenheinrich-multisite-migrate'),
                array('status' => 401)
            );
        }

        wp_set_current_user($user_id);
        $scopes = preg_split('/\s+/', (string) ($row['scopes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        Rmmigrate_OAuth_Scopes::set_current_scopes(is_array($scopes) ? $scopes : array());

        return true;
    }

    private static function sanitize_authorization_header(string $header): string
    {
        return trim((string) wp_unslash($header));
    }

    private static function get_authorization_header(): string
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return self::sanitize_authorization_header(sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION'])));
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return self::sanitize_authorization_header(sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])));
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strtolower((string) $key) === 'authorization') {
                        return self::sanitize_authorization_header((string) $value);
                    }
                }
            }
        }
        return '';
    }
}
