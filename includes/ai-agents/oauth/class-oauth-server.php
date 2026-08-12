<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth 2.1 Authorization Server endpoints for ChatGPT MCP connectors.
 */
final class Rmmigrate_OAuth_Server
{
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
            if (strpos($uri, '/.well-known/oauth-authorization-server') === false) {
                return;
            }
        }

        if (!is_ssl() && !self::allow_insecure_local()) {
            status_header(403);
            echo esc_html__('OAuth requires HTTPS.', 'rosenheinrich-multisite-migrate');
            exit;
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(self::discovery_document());
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
                'methods'             => 'GET',
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
        $method = sanitize_text_field((string) $request->get_param('code_challenge_method'));
        $scope = sanitize_text_field((string) $request->get_param('scope'));
        $response_type = sanitize_text_field((string) $request->get_param('response_type'));

        if ($response_type !== 'code') {
            return new WP_Error('unsupported_response_type', 'response_type must be code', array('status' => 400));
        }
        if ($method !== '' && strtoupper($method) !== 'S256') {
            return new WP_Error('invalid_request', 'code_challenge_method must be S256', array('status' => 400));
        }
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
            $authorize_url = add_query_arg($request->get_query_params(), rest_url('multisite-migrate/v1/oauth/authorize'));
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
        if ($decision === 'deny') {
            $deny = add_query_arg(
                array(
                    'error' => 'access_denied',
                    'state' => $state,
                ),
                $redirect_uri
            );
            wp_safe_redirect($deny);
            exit;
        }

        if ($decision === 'allow') {
            $nonce = (string) $request->get_param('_wpnonce');
            if (!wp_verify_nonce($nonce, 'rmmigrate_oauth_consent_' . $client_id)) {
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
                    'method'       => $method !== '' ? $method : 'S256',
                )
            );
            exit;
        }

        $scopes = self::normalize_scopes($scope);
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
        wp_safe_redirect($ok);
        exit;
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
        $client = Rmmigrate_OAuth_Store::get_client($client_id);
        if ($client === null || !Rmmigrate_OAuth_Store::verify_secret($client, $client_secret)) {
            return new WP_Error('invalid_client', 'Client authentication failed', array('status' => 401));
        }

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

        Rmmigrate_OAuth_Store::revoke_token_id((int) $row['id']);

        $scopes = preg_split('/\s+/', (string) $row['scopes'], -1, PREG_SPLIT_NO_EMPTY);
        $scopes = is_array($scopes) ? $scopes : array(Rmmigrate_OAuth_Scopes::SCOPE_READ);
        $user_id = (int) $row['user_id'];

        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        Rmmigrate_OAuth_Store::store_token($client_id, $user_id, $access, 'access', $scopes, HOUR_IN_SECONDS);
        $has_offline = in_array(Rmmigrate_OAuth_Scopes::SCOPE_OFFLINE, $scopes, true);
        if ($has_offline) {
            Rmmigrate_OAuth_Store::store_token($client_id, $user_id, $refresh, 'refresh', $scopes, 30 * DAY_IN_SECONDS);
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

        return rest_ensure_response($body);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    private static function refresh($request, string $client_id)
    {
        $refresh = (string) $request->get_param('refresh_token');
        $row = Rmmigrate_OAuth_Store::find_token($refresh, 'refresh');
        if ($row === null || (string) $row['client_id'] !== $client_id) {
            return new WP_Error('invalid_grant', 'Invalid refresh token', array('status' => 400));
        }

        Rmmigrate_OAuth_Store::revoke_token_id((int) $row['id']);
        $scopes = preg_split('/\s+/', (string) $row['scopes'], -1, PREG_SPLIT_NO_EMPTY);
        $scopes = is_array($scopes) ? $scopes : array(Rmmigrate_OAuth_Scopes::SCOPE_READ);
        $user_id = (int) $row['user_id'];

        $access = bin2hex(random_bytes(32));
        $new_refresh = bin2hex(random_bytes(32));
        Rmmigrate_OAuth_Store::store_token($client_id, $user_id, $access, 'access', $scopes, HOUR_IN_SECONDS);
        Rmmigrate_OAuth_Store::store_token($client_id, $user_id, $new_refresh, 'refresh', $scopes, 30 * DAY_IN_SECONDS);

        return rest_ensure_response(
            array(
                'access_token'  => $access,
                'token_type'    => 'Bearer',
                'expires_in'    => HOUR_IN_SECONDS,
                'refresh_token' => $new_refresh,
                'scope'         => implode(' ', $scopes),
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function revoke($request)
    {
        list($client_id, $client_secret) = self::client_credentials($request);
        $client = Rmmigrate_OAuth_Store::get_client($client_id);
        if ($client === null || !Rmmigrate_OAuth_Store::verify_secret($client, $client_secret)) {
            return new WP_Error('invalid_client', 'Client authentication failed', array('status' => 401));
        }

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
        if ($id === '' && stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                list($id, $secret) = explode(':', $decoded, 2);
            }
        }
        return array(sanitize_text_field($id), $secret);
    }

    /**
     * @return array<int,string>
     */
    private static function normalize_scopes(string $scope): array
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
                if (in_array($p, $allowed, true)) {
                    $out[] = $p;
                }
            }
        }
        if ($out === array()) {
            $out = array(Rmmigrate_OAuth_Scopes::SCOPE_READ, Rmmigrate_OAuth_Scopes::SCOPE_OFFLINE);
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
        return defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'local';
    }
}
