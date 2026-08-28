<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Agents (MCP) admin page — assets + boot data.
 *
 * Menu + shell render via {@see Rmmigrate_Admin_Menu} / {@see Rmmigrate_Admin_Router}.
 */
final class Rmmigrate_Mcp_Page
{
    public const SLUG = 'multisite-migrate-mcp';

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('network_admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
    }

    public static function enqueue(string $hook): void
    {
        if (strpos($hook, self::SLUG) === false) {
            return;
        }

        $required = is_multisite() && is_network_admin() ? 'manage_network' : 'manage_options';
        if (!current_user_can($required)) {
            return;
        }

        $css = 'assets/admin/mcp/mcp-page.css';
        $js = 'assets/admin/mcp/mcp-page.js';
        $css_ver = self::asset_version($css);
        $js_ver = self::asset_version($js);

        $style_deps = array();
        foreach (array('rmmigrate-admin', 'rmmigrate-pro-admin') as $handle) {
            if (wp_style_is($handle, 'registered') || wp_style_is($handle, 'enqueued')) {
                $style_deps = array($handle);
                break;
            }
        }
        $script_deps = array();
        foreach (array('rmmigrate-admin-ux', 'rmmigrate-pro-admin-ux') as $handle) {
            if (wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued')) {
                $script_deps = array($handle);
                break;
            }
        }

        wp_enqueue_style(
            'rmmigrate-mcp-page',
            RMMIGRATE_URL . $css,
            $style_deps,
            $css_ver
        );
        wp_enqueue_script(
            'rmmigrate-mcp-page',
            RMMIGRATE_URL . $js,
            $script_deps,
            $js_ver,
            true
        );

        $user = wp_get_current_user();
        $is_pro = defined('RMMIGRATE_PRO_VERSION');
        $boot = array(
            'abilitiesApiAvailable'         => function_exists('wp_register_ability'),
            'mcpAdapterActive'              => Rmmigrate_Ai_Agents_Rest::is_mcp_adapter_active(),
            'mcpAdapterInstalled'           => Rmmigrate_Ai_Agents_Rest::get_installed_mcp_adapter_file() !== '',
            'applicationPasswordsAvailable' => Rmmigrate_Ai_Agents_Rest::application_passwords_available(),
            'applicationPasswordsSiteOk'    => function_exists('wp_is_application_passwords_available')
                ? (bool) wp_is_application_passwords_available()
                : false,
            'isSsl'                         => is_ssl(),
            'currentUserHasMcpAppPassword'  => Rmmigrate_Ai_Agents_Rest::current_user_has_mcp_app_password(),
            'applicationPasswords'          => Rmmigrate_Ai_Agents_Rest::list_current_user_application_passwords(),
            'registeredAbilities'           => Rmmigrate_Abilities::boot_registered_list(),
            'proAbilityIds'                 => Rmmigrate_Abilities::pro_ability_ids(),
            'isPro'                         => $is_pro,
            'siteUrl'                       => home_url('/'),
            'userLogin'                     => $user->user_login,
            'mcpEndpoint'                   => rest_url('mcp/mcp-adapter-default-server'),
            'abilitiesEndpoint'             => rest_url('wp-abilities/v1/abilities'),
            'restNonce'                     => wp_create_nonce('wp_rest'),
            'restBase'                      => rest_url('multisite-migrate/v1'),
            'oauthAuthorizationEndpoint'    => rest_url('multisite-migrate/v1/oauth/authorize'),
            'oauthTokenEndpoint'            => rest_url('multisite-migrate/v1/oauth/token'),
            'oauthDiscoveryUrl'             => home_url('/.well-known/oauth-authorization-server'),
            'proUpsellUrl'                  => $is_pro ? '' : Rmmigrate_Links::pricing_url('mcp_tab'),
            'i18n'                          => array(
                'copy'           => __('Copy', 'rosenheinrich-multisite-migrate'),
                'copied'         => __('Copied', 'rosenheinrich-multisite-migrate'),
                'testing'        => __('Testing…', 'rosenheinrich-multisite-migrate'),
                'testOk'         => __('Connection successful', 'rosenheinrich-multisite-migrate'),
                'testFail'       => __('Connection failed', 'rosenheinrich-multisite-migrate'),
                'installing'     => __('Installing…', 'rosenheinrich-multisite-migrate'),
                'installAdapter' => __('Install MCP Adapter', 'rosenheinrich-multisite-migrate'),
                'activateAdapter'=> __('Activate MCP Adapter', 'rosenheinrich-multisite-migrate'),
                'adapterActive'  => __('Active', 'rosenheinrich-multisite-migrate'),
                'generating'     => __('Generating…', 'rosenheinrich-multisite-migrate'),
                'oauthGenerateCredentials' => __('Generate ChatGPT OAuth credentials', 'rosenheinrich-multisite-migrate'),
                'needPassword'   => __('Generate an Application Password first.', 'rosenheinrich-multisite-migrate'),
                'wp69'           => __('Requires WordPress 6.9+ (Abilities API).', 'rosenheinrich-multisite-migrate'),
                'appPwDisabled'  => __('Application Passwords are turned off for this site or your user.', 'rosenheinrich-multisite-migrate'),
                'appPwNoSsl'     => __('This page is not served over HTTPS. WordPress disables Application Passwords until the site uses HTTPS.', 'rosenheinrich-multisite-migrate'),
                'appPwSiteOff'   => __('WordPress reports Application Passwords unavailable site-wide — usually a security plugin or host filter.', 'rosenheinrich-multisite-migrate'),
                'appPwUserOff'   => __('Application Passwords are unavailable for your user account (role or security policy).', 'rosenheinrich-multisite-migrate'),
                'appPwExisting'  => __('Existing Application Passwords', 'rosenheinrich-multisite-migrate'),
                'appPwNone'      => __('No Application Passwords for your user yet.', 'rosenheinrich-multisite-migrate'),
                'appPwExistsHint'=> __('An mm-mcp Application Password already exists. Reuse it in your client, or revoke unused ones below before generating another.', 'rosenheinrich-multisite-migrate'),
                'appPwGenerate'  => __('Generate Application Password', 'rosenheinrich-multisite-migrate'),
                'appPwGenerateAnother' => __('Generate another Application Password', 'rosenheinrich-multisite-migrate'),
                'appPwGenerateConfirm' => __('You already have an mm-mcp Application Password. Generate another anyway? Old passwords stay valid until revoked.', 'rosenheinrich-multisite-migrate'),
                'appPwRevoke'    => __('Revoke', 'rosenheinrich-multisite-migrate'),
                'appPwRevokeConfirm' => __('Revoke this Application Password? Clients using it will stop working.', 'rosenheinrich-multisite-migrate'),
                'appPwCreated'   => __('Created', 'rosenheinrich-multisite-migrate'),
                'appPwLastUsed'  => __('Last used', 'rosenheinrich-multisite-migrate'),
                'appPwNever'     => __('Never', 'rosenheinrich-multisite-migrate'),
                'confirm'            => __('Confirm', 'rosenheinrich-multisite-migrate'),
                'cancel'             => __('Cancel', 'rosenheinrich-multisite-migrate'),
                'oauthRevokeConfirm' => __('Revoke this OAuth client and its tokens?', 'rosenheinrich-multisite-migrate'),
                'coreHeading'    => __('Core', 'rosenheinrich-multisite-migrate'),
                'proHeading'     => __('Pro', 'rosenheinrich-multisite-migrate'),
                'testAuthFail'    => __('Application Password rejected (401/403). Generate a new one or check your username.', 'rosenheinrich-multisite-migrate'),
                'testPermFail'    => __('Permission denied. Your user role may lack the required capability.', 'rosenheinrich-multisite-migrate'),
                'testNotFound'    => __('Abilities endpoint not found. Is the MCP Adapter active and WordPress 6.9+?', 'rosenheinrich-multisite-migrate'),
                'testNoAbilities' => __('Endpoint reachable but no Multisite Migrate abilities found. Deactivate and reactivate the plugin.', 'rosenheinrich-multisite-migrate'),
                'testCookieHint'  => __('(Tested via session — generate an Application Password to verify external client access.)', 'rosenheinrich-multisite-migrate'),
                'testNetworkFail' => __('Network error — check the site URL and try again.', 'rosenheinrich-multisite-migrate'),
                'appPwCopyHint'   => __('Copy the password below into your client config.', 'rosenheinrich-multisite-migrate'),
                'appPwCreateHint' => __('Create an Application Password for this WordPress user, then paste it into your AI client snippet below.', 'rosenheinrich-multisite-migrate'),
                /* translators: 1: completed step count, 2: total step count */
                'stepsComplete'   => __('%1$d of %2$d steps complete', 'rosenheinrich-multisite-migrate'),
                'stepOk'          => __('OK', 'rosenheinrich-multisite-migrate'),
                'stepWp69Short'   => __('WP 6.9+', 'rosenheinrich-multisite-migrate'),
                'stepInactive'    => __('Inactive', 'rosenheinrich-multisite-migrate'),
                'stepInstall'     => __('Install', 'rosenheinrich-multisite-migrate'),
                'stepDisabled'    => __('Disabled', 'rosenheinrich-multisite-migrate'),
                'stepGenerated'   => __('Generated', 'rosenheinrich-multisite-migrate'),
                'stepExists'      => __('Exists', 'rosenheinrich-multisite-migrate'),
                'stepNeeded'      => __('Needed', 'rosenheinrich-multisite-migrate'),
                'stepTest'        => __('Test', 'rosenheinrich-multisite-migrate'),
                /* translators: %d: number of registered abilities */
                'abilitiesRegistered' => __('%d Multisite Migrate abilities registered.', 'rosenheinrich-multisite-migrate'),
                'oauthClientsEmpty' => __('No OAuth clients yet.', 'rosenheinrich-multisite-migrate'),
                'oauthClientsLoadFail' => __('Could not load OAuth clients.', 'rosenheinrich-multisite-migrate'),
            ),
        );
        $boot_json = wp_json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($boot_json)) {
            $boot_json = '{}';
        }
        wp_add_inline_script(
            'rmmigrate-mcp-page',
            'window.rmmigrateMcp = ' . $boot_json . ';',
            'before'
        );
    }

    private static function asset_version(string $relative): string
    {
        $path = RMMIGRATE_PATH . ltrim($relative, '/');
        if (is_readable($path)) {
            $mtime = filemtime($path);
            if ($mtime !== false) {
                return (string) $mtime;
            }
        }

        return defined('RMMIGRATE_VERSION') ? (string) RMMIGRATE_VERSION : '1.0.0';
    }
}
