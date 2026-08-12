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

        $css = 'assets/admin/mcp/mcp-page.css';
        $js = 'assets/admin/mcp/mcp-page.js';
        $css_ver = is_readable(RMMIGRATE_PATH . $css) ? (string) filemtime(RMMIGRATE_PATH . $css) : RMMIGRATE_VERSION;
        $js_ver = is_readable(RMMIGRATE_PATH . $js) ? (string) filemtime(RMMIGRATE_PATH . $js) : RMMIGRATE_VERSION;

        $style_dep = wp_style_is('rmmigrate-admin', 'registered') || wp_style_is('rmmigrate-admin', 'enqueued')
            ? 'rmmigrate-admin'
            : (wp_style_is('rmmigrate-pro-admin', 'registered') || wp_style_is('rmmigrate-pro-admin', 'enqueued')
                ? 'rmmigrate-pro-admin'
                : 'rmmigrate-admin');
        $script_dep = wp_script_is('rmmigrate-admin-ux', 'registered') || wp_script_is('rmmigrate-admin-ux', 'enqueued')
            ? 'rmmigrate-admin-ux'
            : (wp_script_is('rmmigrate-pro-admin-ux', 'registered') || wp_script_is('rmmigrate-pro-admin-ux', 'enqueued')
                ? 'rmmigrate-pro-admin-ux'
                : 'rmmigrate-admin-ux');

        wp_enqueue_style(
            'rmmigrate-mcp-page',
            RMMIGRATE_URL . $css,
            array($style_dep),
            $css_ver
        );
        wp_enqueue_script(
            'rmmigrate-mcp-page',
            RMMIGRATE_URL . $js,
            array($script_dep),
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
            ),
        );
        wp_add_inline_script(
            'rmmigrate-mcp-page',
            'window.rmmigrateMcp = ' . wp_json_encode($boot) . ';',
            'before'
        );
    }
}
