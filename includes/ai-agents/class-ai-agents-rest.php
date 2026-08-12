<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST endpoints for MCP Adapter install, Application Passwords, and OAuth clients.
 */
final class Rmmigrate_Ai_Agents_Rest
{
    public const GITHUB_LATEST_RELEASE_URL = 'https://api.github.com/repos/WordPress/mcp-adapter/releases/latest';

    public const RELEASE_CACHE_KEY = 'rmmigrate_mcp_adapter_release';

    public const APP_PASSWORD_NAME = 'Multisite Migrate AI Agents';

    public const APP_ID = 'mm-mcp';

    public static function register(): void
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes(): void
    {
        $ns = 'multisite-migrate/v1';

        register_rest_route(
            $ns,
            '/ai-agents/mcp-adapter/release',
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'get_mcp_adapter_release'),
                'permission_callback' => array(__CLASS__, 'can_manage_mcp'),
            )
        );

        register_rest_route(
            $ns,
            '/ai-agents/install-mcp-adapter',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'install_mcp_adapter'),
                'permission_callback' => array(__CLASS__, 'can_install_plugins'),
            )
        );

        register_rest_route(
            $ns,
            '/ai-agents/generate-app-password',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'generate_app_password'),
                'permission_callback' => array(__CLASS__, 'can_manage_mcp'),
            )
        );

        register_rest_route(
            $ns,
            '/ai-agents/app-passwords',
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'list_app_passwords'),
                'permission_callback' => array(__CLASS__, 'can_manage_mcp'),
            )
        );

        register_rest_route(
            $ns,
            '/ai-agents/app-passwords/(?P<uuid>[a-zA-Z0-9-]+)',
            array(
                'methods'             => 'DELETE',
                'callback'            => array(__CLASS__, 'delete_app_password'),
                'permission_callback' => array(__CLASS__, 'can_manage_mcp'),
            )
        );

        register_rest_route(
            $ns,
            '/ai-agents/oauth/generate-client',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'generate_oauth_client'),
                'permission_callback' => array(__CLASS__, 'can_manage_mcp'),
            )
        );

        register_rest_route(
            $ns,
            '/ai-agents/oauth/clients',
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'list_oauth_clients'),
                'permission_callback' => array(__CLASS__, 'can_manage_mcp'),
            )
        );

        register_rest_route(
            $ns,
            '/ai-agents/oauth/clients/(?P<id>[a-zA-Z0-9_]+)',
            array(
                'methods'             => 'DELETE',
                'callback'            => array(__CLASS__, 'delete_oauth_client'),
                'permission_callback' => array(__CLASS__, 'can_manage_mcp'),
            )
        );

        register_rest_route(
            $ns,
            '/ai-agents/boot',
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'boot_data'),
                'permission_callback' => array(__CLASS__, 'can_manage_mcp'),
            )
        );
    }

    public static function can_manage_mcp(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        if (is_multisite()) {
            return current_user_can('manage_network') || current_user_can('manage_options');
        }
        return current_user_can('manage_options');
    }

    public static function can_install_plugins(): bool
    {
        return self::can_manage_mcp()
            && current_user_can('install_plugins')
            && current_user_can('activate_plugins');
    }

    /**
     * @return WP_REST_Response
     */
    public static function get_mcp_adapter_release()
    {
        $cached = get_transient(self::RELEASE_CACHE_KEY);
        if (false !== $cached && is_array($cached)) {
            return new WP_REST_Response($cached, 200);
        }

        $response = wp_remote_get(
            self::GITHUB_LATEST_RELEASE_URL,
            array(
                'timeout' => 10,
                'headers' => array('Accept' => 'application/vnd.github+json'),
            )
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Could not fetch the latest MCP Adapter release from GitHub.', 'rosenheinrich-multisite-migrate'),
                ),
                502
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name']) || empty($body['assets']) || !is_array($body['assets'])) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('GitHub response did not include a downloadable release asset.', 'rosenheinrich-multisite-migrate'),
                ),
                502
            );
        }

        $download_url = self::pick_zip_download_url($body['assets']);
        if ($download_url === '') {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('GitHub response did not include a downloadable release asset.', 'rosenheinrich-multisite-migrate'),
                ),
                502
            );
        }

        $payload = array(
            'success'      => true,
            'version'      => ltrim((string) $body['tag_name'], 'v'),
            'download_url' => esc_url_raw($download_url),
            'html_url'     => isset($body['html_url']) ? esc_url_raw($body['html_url']) : '',
        );
        set_transient(self::RELEASE_CACHE_KEY, $payload, HOUR_IN_SECONDS);

        return new WP_REST_Response($payload, 200);
    }

    public static function get_installed_mcp_adapter_file(): string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (array_keys(get_plugins()) as $plugin_file) {
            if (0 === strpos($plugin_file, 'mcp-adapter/')) {
                return $plugin_file;
            }
        }
        return '';
    }

    public static function is_mcp_adapter_active(): bool
    {
        return class_exists('\\WP\\MCP\\Core\\McpAdapter');
    }

    /**
     * @return WP_REST_Response
     */
    public static function install_mcp_adapter()
    {
        if (self::is_mcp_adapter_active()) {
            return new WP_REST_Response(
                array(
                    'success'         => true,
                    'already_present' => true,
                    'message'         => __('MCP Adapter is already active on this site.', 'rosenheinrich-multisite-migrate'),
                ),
                200
            );
        }

        $installed = self::get_installed_mcp_adapter_file();
        if ($installed !== '') {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $activated = activate_plugin($installed, '', is_multisite());
            if (is_wp_error($activated)) {
                return new WP_REST_Response(
                    array(
                        'success' => false,
                        'message' => $activated->get_error_message(),
                    ),
                    500
                );
            }
            return new WP_REST_Response(
                array(
                    'success' => true,
                    'plugin'  => $installed,
                    'message' => __('MCP Adapter activated.', 'rosenheinrich-multisite-migrate'),
                ),
                200
            );
        }

        $release = self::get_mcp_adapter_release();
        $body = $release->get_data();
        if (empty($body['success']) || empty($body['download_url'])) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => isset($body['message']) ? $body['message'] : __('Could not resolve the MCP Adapter download URL.', 'rosenheinrich-multisite-migrate'),
                ),
                502
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->install($body['download_url']);

        if (is_wp_error($result)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                ),
                500
            );
        }
        if (false === $result || !$upgrader->plugin_info()) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('MCP Adapter install failed. Check site write permissions and try again.', 'rosenheinrich-multisite-migrate'),
                ),
                500
            );
        }

        $plugin_file = $upgrader->plugin_info();
        $activated = activate_plugin($plugin_file, '', is_multisite());
        if (is_wp_error($activated)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $activated->get_error_message(),
                ),
                500
            );
        }

        return new WP_REST_Response(
            array(
                'success' => true,
                'version' => $body['version'],
                'plugin'  => $plugin_file,
                'message' => sprintf(
                    /* translators: %s: installed mcp-adapter version. */
                    __('MCP Adapter %s installed and activated.', 'rosenheinrich-multisite-migrate'),
                    $body['version']
                ),
            ),
            200
        );
    }

    /**
     * @return WP_REST_Response
     */
    public static function generate_app_password()
    {
        if (!class_exists('WP_Application_Passwords')) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Application Passwords are not available on this site.', 'rosenheinrich-multisite-migrate'),
                ),
                500
            );
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('You must be logged in to generate an Application Password.', 'rosenheinrich-multisite-migrate'),
                ),
                403
            );
        }

        if (!wp_is_application_passwords_available_for_user($user_id)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Application Passwords are not available for your account. Please contact a site administrator.', 'rosenheinrich-multisite-migrate'),
                ),
                403
            );
        }

        $created = WP_Application_Passwords::create_new_application_password(
            $user_id,
            array(
                'name'   => self::APP_PASSWORD_NAME,
                'app_id' => self::APP_ID,
            )
        );

        if (is_wp_error($created)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $created->get_error_message(),
                ),
                500
            );
        }

        $user = wp_get_current_user();
        $password = is_array($created) ? (string) ($created[0] ?? '') : '';

        return new WP_REST_Response(
            array(
                'success'   => true,
                'password'  => $password,
                'username'  => $user->user_login,
                'passwords' => self::list_current_user_application_passwords(),
                'message'   => __('Application Password generated. Copy it now — it will not be shown again.', 'rosenheinrich-multisite-migrate'),
            ),
            200
        );
    }

    /**
     * Public-safe list of Application Passwords for the current user.
     *
     * @return array<int, array{uuid:string,name:string,app_id:string,is_mcp:bool,created:string,last_used:string}>
     */
    public static function list_current_user_application_passwords(): array
    {
        $user_id = get_current_user_id();
        if (!$user_id || !class_exists('WP_Application_Passwords')) {
            return array();
        }

        $out = array();
        foreach (WP_Application_Passwords::get_user_application_passwords($user_id) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $created_raw = isset($item['created']) ? (int) $item['created'] : 0;
            $last_raw    = isset($item['last_used']) && $item['last_used'] ? (int) $item['last_used'] : 0;
            $app_id      = (string) ($item['app_id'] ?? '');
            $out[]       = array(
                'uuid'      => (string) ($item['uuid'] ?? ''),
                'name'      => (string) ($item['name'] ?? ''),
                'app_id'    => $app_id,
                'is_mcp'    => $app_id === self::APP_ID,
                'created'   => $created_raw > 0 ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $created_raw) : '',
                'last_used' => $last_raw > 0 ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_raw) : '',
            );
        }

        return $out;
    }

    /**
     * @return WP_REST_Response
     */
    public static function list_app_passwords()
    {
        return new WP_REST_Response(
            array(
                'success'   => true,
                'passwords' => self::list_current_user_application_passwords(),
            ),
            200
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function delete_app_password($request)
    {
        if (!class_exists('WP_Application_Passwords')) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Application Passwords are not available on this site.', 'rosenheinrich-multisite-migrate'),
                ),
                500
            );
        }

        $user_id = get_current_user_id();
        $uuid    = sanitize_text_field((string) $request['uuid']);
        if (!$user_id || $uuid === '') {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Invalid Application Password.', 'rosenheinrich-multisite-migrate'),
                ),
                400
            );
        }

        $result = WP_Application_Passwords::delete_application_password($user_id, $uuid);
        if (is_wp_error($result)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                ),
                404
            );
        }

        return new WP_REST_Response(
            array(
                'success'   => true,
                'passwords' => self::list_current_user_application_passwords(),
                'message'   => __('Application Password revoked.', 'rosenheinrich-multisite-migrate'),
            ),
            200
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function generate_oauth_client($request)
    {
        Rmmigrate_OAuth_Store::ensure_tables();
        $name = sanitize_text_field((string) $request->get_param('name'));
        if ($name === '') {
            $name = 'ChatGPT MCP';
        }
        $redirects = $request->get_param('redirect_uris');
        $uris = array();
        if (is_array($redirects)) {
            foreach ($redirects as $u) {
                $uris[] = (string) $u;
            }
        }

        $created = Rmmigrate_OAuth_Store::create_client($name, $uris, get_current_user_id());
        if (is_wp_error($created)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $created->get_error_message(),
                ),
                500
            );
        }

        $discovery = Rmmigrate_OAuth_Server::discovery_document();

        return new WP_REST_Response(
            array(
                'success'                 => true,
                'client_id'               => $created['client_id'],
                'client_secret'           => $created['client_secret'],
                'authorization_endpoint'  => $discovery['authorization_endpoint'],
                'token_endpoint'          => $discovery['token_endpoint'],
                'discovery_url'           => home_url('/.well-known/oauth-authorization-server'),
                'message'                 => __('OAuth credentials generated. Copy the client secret now — it will not be shown again.', 'rosenheinrich-multisite-migrate'),
            ),
            200
        );
    }

    /**
     * @return WP_REST_Response
     */
    public static function list_oauth_clients()
    {
        $rows = Rmmigrate_OAuth_Store::list_clients();
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'client_id'  => (string) ($row['client_id'] ?? ''),
                'name'       => (string) ($row['name'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            );
        }
        return new WP_REST_Response(array('success' => true, 'clients' => $out), 200);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function delete_oauth_client($request)
    {
        $id = sanitize_text_field((string) $request['id']);
        $ok = Rmmigrate_OAuth_Store::delete_client($id);
        return new WP_REST_Response(
            array(
                'success' => $ok,
                'message' => $ok
                    ? __('OAuth client revoked.', 'rosenheinrich-multisite-migrate')
                    : __('Client not found.', 'rosenheinrich-multisite-migrate'),
            ),
            $ok ? 200 : 404
        );
    }

    public static function current_user_has_mcp_app_password(): bool
    {
        $user_id = get_current_user_id();
        if (!$user_id || !class_exists('WP_Application_Passwords')) {
            return false;
        }
        foreach (WP_Application_Passwords::get_user_application_passwords($user_id) as $item) {
            if (($item['app_id'] ?? '') === self::APP_ID) {
                return true;
            }
        }
        return false;
    }

    public static function application_passwords_available(): bool
    {
        if (!function_exists('wp_is_application_passwords_available')) {
            return false;
        }
        $user_id = get_current_user_id();
        if ($user_id && function_exists('wp_is_application_passwords_available_for_user')) {
            return wp_is_application_passwords_available_for_user($user_id);
        }
        return wp_is_application_passwords_available();
    }

    /**
     * Prefer a .zip asset from a GitHub release payload.
     *
     * @param array<int,mixed> $assets
     */
    public static function pick_zip_download_url(array $assets): string
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($url !== '' && (substr($name, -4) === '.zip' || strpos($url, '.zip') !== false)) {
                return $url;
            }
        }
        if (!empty($assets[0]) && is_array($assets[0]) && !empty($assets[0]['browser_download_url'])) {
            return (string) $assets[0]['browser_download_url'];
        }
        return '';
    }

    /**
     * @return WP_REST_Response
     */
    public static function boot_data()
    {
        $user = wp_get_current_user();
        $mcp_endpoint = rest_url('mcp/mcp-adapter-default-server');
        $discovery = Rmmigrate_OAuth_Server::discovery_document();

        return new WP_REST_Response(
            array(
                'abilitiesApiAvailable'           => function_exists('wp_register_ability'),
                'mcpAdapterActive'                => self::is_mcp_adapter_active(),
                'mcpAdapterInstalled'             => self::get_installed_mcp_adapter_file() !== '',
                'applicationPasswordsAvailable'   => self::application_passwords_available(),
                'currentUserHasMcpAppPassword'    => self::current_user_has_mcp_app_password(),
                'applicationPasswords'            => self::list_current_user_application_passwords(),
                'registeredAbilities'             => Rmmigrate_Abilities::boot_registered_list(),
                'siteUrl'                         => home_url('/'),
                'userLogin'                       => $user->user_login,
                'mcpEndpoint'                     => $mcp_endpoint,
                'restNonce'                       => wp_create_nonce('wp_rest'),
                'restBase'                        => rest_url('multisite-migrate/v1'),
                'oauthAuthorizationEndpoint'      => $discovery['authorization_endpoint'],
                'oauthTokenEndpoint'              => $discovery['token_endpoint'],
                'oauthDiscoveryUrl'               => home_url('/.well-known/oauth-authorization-server'),
                'proUpsellUrl'                    => class_exists('Rmmigrate_Links') ? Rmmigrate_Links::pricing_url('mcp_tab') : '',
                'proAbilitiesHint'                => __('Pro adds schedule, cloud, restore, staging, and empty-server abilities.', 'rosenheinrich-multisite-migrate'),
            ),
            200
        );
    }
}
