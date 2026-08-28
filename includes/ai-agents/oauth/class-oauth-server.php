<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth 2.1 Authorization Server endpoints for ChatGPT MCP connectors.
 */
final class Rmmigrate_OAuth_Server
{
    private const AUTH_FAIL_MAX = 5;

    private const AUTH_FAIL_WINDOW = 900;

    public static function register(): void
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('init', array(__CLASS__, 'register_well_known'));
        add_action('template_redirect', array(__CLASS__, 'serve_well_known'), 0);
    }

    public static function register_well_known(): void
    {
        add_rewrite_rule('^\.well-known/oauth-authorization-server/?$', 'index.php?rmmigrate_oauth_as=1', 'top');
        add_rewrite_tag('%rmmigrate_oauth_as%', '1');
    }

    public static function serve_well_known(): void
    {
        if ((int) get_query_var('rmmigrate_oauth_as') !== 1) {
            // Also honor direct path without rewrite flush.
            $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $path = (string) wp_parse_url($uri, PHP_URL_PATH);
            $path = $path !== '' ? rawurldecode($path) : '';
            if (!preg_match('#^/\.well-known/oauth-authorization-server/?$#', $path)) {
                return;
            }
        }

        if (!is_ssl() && !self::allow_insecure_local()) {
            status_header(403);
            echo esc_html__('OAuth requires HTTPS.', 'rosenheinrich-multisite-migrate');
            exit;
        }

        nocache_headers();
        $body = wp_json_encode(self::discovery_document());
        if (!is_string($body)) {
            status_header(500);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body.
        exit;
    }

    /**
     * @return array<string,mixed>
     */
    public static function discovery_document(): array
    {
        $base = rest_url('multisite-migrate/v1/oauth');
        return array(
            'issuer'                                => home_url('/'),
            'authorization_endpoint'                => $base . '/authorize',
            'token_endpoint'                        => $base . '/token',
            'revocation_endpoint'                   => $base . '/revoke',
            'response_types_supported'              => array('code'),
            'grant_types_supported'                 => array('authorization_code', 'refresh_token'),
            'code_challenge_methods_supported'      => array('S256'),
            'token_endpoint_auth_methods_supported' => array('client_secret_post', 'client_secret_basic'),
            'scopes_supported'                      => array(
                Rmmigrate_OAuth_Scopes::SCOPE_READ,
                Rmmigrate_OAuth_Scopes::SCOPE_WRITE,
                Rmmigrate_OAuth_Scopes::SCOPE_OFFLINE,
            ),
        );
    }

    public static function register_routes(): void
    {
        register_rest_route(
            'multisite-migrate/v1',
            '/oauth/authorize',
            array(
                'methods'             => array('GET', 'POST'),
                'callback'            => array(__CLASS__, 'authorize'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'multisite-migrate/v1',
            '/oauth/token',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'token'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'multisite-migrate/v1',
            '/oauth/revoke',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'revoke'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'multisite-migrate/v1',
            '/oauth/.well-known/oauth-authorization-server',
            array(
                'methods'             => 'GET',
                'callback'            => static function () {
                    return rest_ensure_response(self::discovery_document());
                },
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * @param array<string,string> $grant
     */
    public static function consent_nonce_action(array $grant): string
    {
        return 'rmmigrate_oauth_consent_' . hash(
            'sha256',
            implode(
                "\0",
                array(
                    (string) ($grant['client_id'] ?? ''),
                    (string) ($grant['redirect_uri'] ?? ''),
                    (string) ($grant['scope'] ?? ''),
                    (string) ($grant['challenge'] ?? ''),
                    (string) ($grant['method'] ?? 'S256'),
                )
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function authorize($request)
    {
        if (!is_ssl() && !self::allow_insecure_local()) {
            return new WP_Error('rmmigrate_oauth_https', __('OAuth requires HTTPS.', 'rosenheinrich-multisite-migrate'), array('status' => 403));
        }

        $client_id = sanitize_text_field((string) $request->get_param('client_id'));
        $redirect_uri = esc_url_raw((string) $request->get_param('redirect_uri'));
        $state = sanitize_text_field((string) $request->get_param('state'));
        $challenge = sanitize_text_field((string) $request->get_param('code_challenge'));
        $method = strtoupper(sanitize_text_field((string) $request->get_param('code_challenge_method')));
        $scope = sanitize_text_field((string) $request->get_param('scope'));
        $response_type = sanitize_text_field((string) $request->get_param('response_type'));

        if ($response_type !== 'code') {
            return new WP_Error('unsupported_response_type', 'response_type must be code', array('status' => 400));
        }
        if ($method !== '' && $method !== 'S256') {
            return new WP_Error('invalid_request', 'code_challenge_method must be S256', array('status' => 400));
        }
        $method = 'S256';
        if ($challenge === '') {
            return new WP_Error('invalid_request', 'code_challenge required', array('status' => 400));
        }

        $client = Rmmigrate_OAuth_Store::get_client($client_id);
        if ($client === null) {
            return new WP_Error('invalid_client', 'Unknown client_id', array('status' => 400));
        }
        if (!Rmmigrate_OAuth_Store::redirect_allowed($client, $redirect_uri)) {
            return new WP_Error('invalid_request', 'redirect_uri not allowed', array('status' => 400));
        }

        if (!is_user_logged_in()) {
            $authorize_params = array_merge(
                $request->get_query_params(),
                is_array($request->get_body_params()) ? $request->get_body_params() : array()
            );
            $authorize_url = add_query_arg($authorize_params, rest_url('multisite-migrate/v1/oauth/authorize'));
            wp_safe_redirect(wp_login_url($authorize_url));
            exit;
        }

        if (!self::user_may_consent()) {
            return new WP_Error(
                'access_denied',
                __('Your account cannot authorize Multisite Migrate MCP access.', 'rosenheinrich-multisite-migrate'),
                array('status' => 403)
            );
        }

        $decision = (string) $request->get_param('decision');
        $consent_grant = array(
            'client_id'    => $client_id,
            'redirect_uri' => $redirect_uri,
            'scope'        => $scope,
            'challenge'    => $challenge,
            'method'       => $method,
        );
        $consent_action = self::consent_nonce_action($consent_grant);
        if ($decision === 'deny') {
            $nonce = (string) $request->get_param('_wpnonce');
            if (!wp_verify_nonce($nonce, $consent_action)) {
                return new WP_Error('access_denied', 'Invalid consent nonce', array('status' => 403));
            }
            $deny = add_query_arg(
                array(
                    'error' => 'access_denied',
                    'state' => $state,
                ),
                $redirect_uri
            );
            self::redirect_to_client($deny);
        }

        if ($decision === 'allow') {
            $nonce = (string) $request->get_param('_wpnonce');
            if (!wp_verify_nonce($nonce, $consent_action)) {
                return new WP_Error('access_denied', 'Invalid consent nonce', array('status' => 403));
            }
        }

        if ($decision !== 'allow') {
            Rmmigrate_OAuth_Authorize_UI::render_consent(
                array(
                    'client'       => $client,
                    'client_id'    => $client_id,
                    'redirect_uri' => $redirect_uri,
                    'state'        => $state,
                    'scope'        => $scope,
                    'challenge'    => $challenge,
                    'method'       => $method,
                )
            );
            exit;
        }

        $normalized = self::normalize_scopes($scope);
        if (is_wp_error($normalized)) {
            $invalid = add_query_arg(
                array(
                    'error'             => $normalized->get_error_code(),
                    'error_description' => $normalized->get_error_message(),
                    'state'             => $state,
                ),
                $redirect_uri
            );
            self::redirect_to_client($invalid);
        }
        $scopes = $normalized;
        $code = bin2hex(random_bytes(24));
        Rmmigrate_OAuth_Store::store_token(
            $client_id,
            get_current_user_id(),
            $code,
            'code',
            $scopes,
            120,
            $challenge,
            $redirect_uri
        );

        $ok = add_query_arg(
            array(
                'code'  => $code,
                'state' => $state,
            ),
            $redirect_uri
        );
        self::redirect_to_client($ok);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function token($request)
    {
        if (!is_ssl() && !self::allow_insecure_local()) {
            return new WP_Error('rmmigrate_oauth_https', __('OAuth requires HTTPS.', 'rosenheinrich-multisite-migrate'), array('status' => 403));
        }

        $grant = sanitize_key((string) $request->get_param('grant_type'));
        list($client_id, $client_secret) = self::client_credentials($request);
        $blocked = self::auth_throttle_blocked($client_id);
        if ($blocked instanceof WP_Error) {
            return $blocked;
        }
        $client = Rmmigrate_OAuth_Store::get_client($client_id);
        if ($client === null || !Rmmigrate_OAuth_Store::verify_secret($client, $client_secret)) {
            self::auth_throttle_record_failure($client_id);
            return new WP_Error('invalid_client', 'Client authentication failed', array('status' => 401));
        }
        self::auth_throttle_clear($client_id);

        if ($grant === 'authorization_code') {
            return self::exchange_code($request, $client_id);
        }
        if ($grant === 'refresh_token') {
            return self::refresh($request, $client_id);
        }

        return new WP_Error('unsupported_grant_type', 'Unsupported grant_type', array('status' => 400));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    private static function exchange_code($request, string $client_id)
    {
        $code = (string) $request->get_param('code');
        $redirect_uri = esc_url_raw((string) $request->get_param('redirect_uri'));
        $verifier = (string) $request->get_param('code_verifier');

        $row = Rmmigrate_OAuth_Store::find_token($code, 'code');
        if ($row === null || (string) $row['client_id'] !== $client_id) {
            return new WP_Error('invalid_grant', 'Invalid authorization code', array('status' => 400));
        }
        if ((string) $row['redirect_uri'] !== $redirect_uri) {
            return new WP_Error('invalid_grant', 'redirect_uri mismatch', array('status' => 400));
        }

        $expected = self::pkce_challenge_s256($verifier);
        if (!hash_equals((string) $row['code_challenge'], $expected)) {
            return new WP_Error('invalid_grant', 'PKCE verification failed', array('status' => 400));
        }

        if (!Rmmigrate_OAuth_Store::revoke_token_id((int) $row['id'])) {
            return new WP_Error('invalid_grant', 'Invalid authorization code', array('status' => 400));
        }

        $scopes = preg_split('/\s+/', (string) $row['scopes'], -1, PREG_SPLIT_NO_EMPTY);
        $scopes = is_array($scopes) ? $scopes : array(Rmmigrate_OAuth_Scopes::SCOPE_READ);
        $user_id = (int) $row['user_id'];

        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $has_offline = in_array(Rmmigrate_OAuth_Scopes::SCOPE_OFFLINE, $scopes, true);
        $family_root = 0;
        if ($has_offline) {
            $family_root = Rmmigrate_OAuth_Store::store_token($client_id, $user_id, $refresh, 'refresh', $scopes, 30 * DAY_IN_SECONDS);
            if ($family_root === 0) {
                return self::server_error();
            }
        }
        if (Rmmigrate_OAuth_Store::store_token($client_id, $user_id, $access, 'access', $scopes, HOUR_IN_SECONDS, '', '', $family_root) === 0) {
            return self::server_error();
        }

        $body = array(
            'access_token' => $access,
            'token_type'   => 'Bearer',
            'expires_in'   => HOUR_IN_SECONDS,
            'scope'        => implode(' ', $scopes),
        );
        if ($has_offline) {
            $body['refresh_token'] = $refresh;
        }

        return self::no_store_response($body);
    }

    /**
     * @param array<string,mixed> $body
     * @return WP_REST_Response|WP_Error
     */
    private static function no_store_response(array $body)
    {
        $response = rest_ensure_response($body);
        if ($response instanceof WP_REST_Response) {
            $response->header('Cache-Control', 'no-store');
            $response->header('Pragma', 'no-cache');
        }

        return $response;
    }

    /**
     * @return WP_Error
     */
    private static function server_error()
    {
        return new WP_Error(
            'server_error',
            __('Unable to issue token.', 'rosenheinrich-multisite-migrate'),
            array('status' => 500)
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    private static function refresh($request, string $client_id)
    {
        $refresh = (string) $request->get_param('refresh_token');
        $row = Rmmigrate_OAuth_Store::find_token($refresh, 'refresh');
        if ($row === null) {
            $revoked = Rmmigrate_OAuth_Store::find_revoked_token($refresh, 'refresh');
            if ($revoked !== null && (string) $revoked['client_id'] === $client_id) {
                Rmmigrate_OAuth_Store::revoke_token_family(Rmmigrate_OAuth_Store::token_family_root_id($revoked));
                return new WP_Error('invalid_grant', 'Invalid refresh token', array('status' => 400));
            }
            return new WP_Error('invalid_grant', 'Invalid refresh token', array('status' => 400));
        }
        if ((string) $row['client_id'] !== $client_id) {
            return new WP_Error('invalid_grant', 'Invalid refresh token', array('status' => 400));
        }

        $family_root = Rmmigrate_OAuth_Store::token_family_root_id($row);
        if (!Rmmigrate_OAuth_Store::revoke_token_id((int) $row['id'])) {
            return new WP_Error('invalid_grant', 'Invalid refresh token', array('status' => 400));
        }
        $scopes = preg_split('/\s+/', (string) $row['scopes'], -1, PREG_SPLIT_NO_EMPTY);
        $scopes = is_array($scopes) ? $scopes : array(Rmmigrate_OAuth_Scopes::SCOPE_READ);
        $user_id = (int) $row['user_id'];

        $access = bin2hex(random_bytes(32));
        if (Rmmigrate_OAuth_Store::store_token($client_id, $user_id, $access, 'access', $scopes, HOUR_IN_SECONDS, '', '', $family_root) === 0) {
            return self::server_error();
        }
        $has_offline = in_array(Rmmigrate_OAuth_Scopes::SCOPE_OFFLINE, $scopes, true);
        $body = array(
            'access_token' => $access,
            'token_type'   => 'Bearer',
            'expires_in'   => HOUR_IN_SECONDS,
            'scope'        => implode(' ', $scopes),
        );
        if ($has_offline) {
            $new_refresh = bin2hex(random_bytes(32));
            if (Rmmigrate_OAuth_Store::store_token(
                $client_id,
                $user_id,
                $new_refresh,
                'refresh',
                $scopes,
                30 * DAY_IN_SECONDS,
                '',
                '',
                $family_root
            ) === 0) {
                return self::server_error();
            }
            $body['refresh_token'] = $new_refresh;
        }

        return self::no_store_response($body);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function revoke($request)
    {
        if (!is_ssl() && !self::allow_insecure_local()) {
            return new WP_Error('invalid_request', 'HTTPS required', array('status' => 403));
        }

        list($client_id, $client_secret) = self::client_credentials($request);
        $blocked = self::auth_throttle_blocked($client_id);
        if ($blocked instanceof WP_Error) {
            return $blocked;
        }
        $client = Rmmigrate_OAuth_Store::get_client($client_id);
        if ($client === null || !Rmmigrate_OAuth_Store::verify_secret($client, $client_secret)) {
            self::auth_throttle_record_failure($client_id);
            return new WP_Error('invalid_client', 'Client authentication failed', array('status' => 401));
        }
        self::auth_throttle_clear($client_id);

        $token = (string) $request->get_param('token');
        foreach (array('access', 'refresh', 'code') as $type) {
            $row = Rmmigrate_OAuth_Store::find_token($token, $type);
            if ($row !== null && (string) $row['client_id'] === $client_id) {
                Rmmigrate_OAuth_Store::revoke_token_id((int) $row['id']);
            }
        }

        return rest_ensure_response(array('revoked' => true));
    }

    /**
     * @param WP_REST_Request $request
     * @return array{0:string,1:string}
     */
    private static function client_credentials($request): array
    {
        $id = (string) $request->get_param('client_id');
        $secret = (string) $request->get_param('client_secret');
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        }
        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                list($id, $secret) = explode(':', $decoded, 2);
                $id = urldecode($id);
                $secret = urldecode($secret);
            }
        }
        return array(sanitize_text_field($id), $secret);
    }

    /**
     * @return array<int,string>|WP_Error
     */
    private static function normalize_scopes(string $scope)
    {
        $parts = preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY);
        $allowed = array(
            Rmmigrate_OAuth_Scopes::SCOPE_READ,
            Rmmigrate_OAuth_Scopes::SCOPE_WRITE,
            Rmmigrate_OAuth_Scopes::SCOPE_OFFLINE,
        );
        $out = array();
        if (is_array($parts)) {
            foreach ($parts as $p) {
                if (!in_array($p, $allowed, true)) {
                    return new WP_Error('invalid_scope', 'Unknown scope requested', array('status' => 400));
                }
                $out[] = $p;
            }
        }
        if ($out === array()) {
            $out = array(Rmmigrate_OAuth_Scopes::SCOPE_READ);
        }
        if (!in_array(Rmmigrate_OAuth_Scopes::SCOPE_READ, $out, true) && in_array(Rmmigrate_OAuth_Scopes::SCOPE_WRITE, $out, true)) {
            $out[] = Rmmigrate_OAuth_Scopes::SCOPE_READ;
        }
        return array_values(array_unique($out));
    }

    private static function user_may_consent(): bool
    {
        if (is_multisite()) {
            return current_user_can('manage_network') || current_user_can('manage_options');
        }
        return current_user_can('manage_options');
    }

    /**
     * S256 code_challenge for a PKCE code_verifier (RFC 7636).
     */
    public static function pkce_challenge_s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function allow_insecure_local(): bool
    {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type() === 'local';
    }

    /**
     * Redirect to a client redirect_uri already checked by redirect_allowed().
     */
    private static function redirect_to_client(string $url): void
    {
        $url = esc_url_raw($url);
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $allow = static function (array $hosts) use ($host): array {
                if (!in_array($host, $hosts, true)) {
                    $hosts[] = $host;
                }
                return $hosts;
            };
            add_filter('allowed_redirect_hosts', $allow);
        }
        wp_safe_redirect($url);
        exit;
    }

    private static function auth_throttle_key(string $client_id): string
    {
        $ip = '';
        if (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return 'rmmigrate_oauth_fail_' . hash('sha256', $client_id . '|' . $ip);
    }

    /**
     * @return WP_Error|null
     */
    private static function auth_throttle_blocked(string $client_id)
    {
        $key = self::auth_throttle_key($client_id);
        $until = (int) get_site_transient($key . '_lock');
        if ($until > time()) {
            return new WP_Error(
                'slow_down',
                __('Too many failed authentication attempts.', 'rosenheinrich-multisite-migrate'),
                array('status' => 429)
            );
        }

        return null;
    }

    private static function auth_throttle_record_failure(string $client_id): void
    {
        $key = self::auth_throttle_key($client_id);
        $window_key = $key . '_since';
        $now = time();
        $since = (int) get_site_transient($window_key);
        $count = (int) get_site_transient($key);

        if ($since <= 0 || ($now - $since) >= self::AUTH_FAIL_WINDOW) {
            $count = 0;
            $since = $now;
            set_site_transient($window_key, $since, self::AUTH_FAIL_WINDOW);
        }

        $count++;
        set_site_transient($key, $count, self::AUTH_FAIL_WINDOW);
        if ($count >= self::AUTH_FAIL_MAX) {
            set_site_transient($key . '_lock', $now + self::AUTH_FAIL_WINDOW, self::AUTH_FAIL_WINDOW);
            Rmmigrate_Logger::log_activity(
                'oauth',
                __('Repeated OAuth client authentication failures.', 'rosenheinrich-multisite-migrate'),
                'warning',
                array('client_id' => sanitize_key($client_id))
            );
        }
    }

    private static function auth_throttle_clear(string $client_id): void
    {
        $key = self::auth_throttle_key($client_id);
        delete_site_transient($key);
        delete_site_transient($key . '_since');
        delete_site_transient($key . '_lock');
    }
}
