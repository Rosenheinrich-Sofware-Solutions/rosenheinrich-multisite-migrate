<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * External "Upgrade to Pro" admin-menu link.
 *
 * Like Duplicator Lite, the submenu item opens the marketing-site pricing page
 * in the browser — not an in-plugin comparison screen.
 */
class Rmmigrate_Upgrade_Page
{
    public static function register(): void
    {
        add_action('admin_menu', array(__CLASS__, 'add_menu'), 100);
        add_action('network_admin_menu', array(__CLASS__, 'add_menu'), 100);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('network_admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));

        $basename = plugin_basename(RMMIGRATE_PATH . 'multisite-migrate.php');
        add_filter('plugin_action_links_' . $basename, array(__CLASS__, 'action_links'));
        add_filter('network_admin_plugin_action_links_' . $basename, array(__CLASS__, 'action_links'));
    }

    public static function enqueue_assets(): void
    {
        $cap = is_network_admin() ? 'manage_network_options' : 'manage_options';
        if (!current_user_can($cap)) {
            return;
        }

        $css = '#adminmenu .mm-upgrade-menu{color:#14b8a6 !important;font-weight:600;}'
            . '#adminmenu li:hover .mm-upgrade-menu,'
            . '#adminmenu a:focus .mm-upgrade-menu,'
            . '#adminmenu a:hover .mm-upgrade-menu{color:#5eead4 !important;}';
        wp_add_inline_style('common', $css);

        wp_register_script('rmmigrate-upgrade-menu', false, array(), RMMIGRATE_VERSION, true);
        wp_enqueue_script('rmmigrate-upgrade-menu');
        wp_add_inline_script(
            'rmmigrate-upgrade-menu',
            "(function () {
                var link = document.getElementById('mm-upgrade-menu-link');
                if (!link || !link.parentElement) {
                    return;
                }
                link.parentElement.setAttribute('target', '_blank');
                link.parentElement.setAttribute('rel', 'noopener noreferrer');
                var label = (link.textContent || '').trim();
                if (label !== '') {
                    link.setAttribute('aria-label', label + ' (opens in a new tab)');
                }
            })();"
        );
    }

    public static function add_menu(): void
    {
        $cap = is_network_admin() ? 'manage_network_options' : 'manage_options';
        add_submenu_page(
            Rmmigrate_Admin_Menu::parent_slug(),
            __('Upgrade to Pro', 'rosenheinrich-multisite-migrate'),
            '<span id="mm-upgrade-menu-link" class="mm-upgrade-menu">' . esc_html__('Upgrade to Pro', 'rosenheinrich-multisite-migrate') . '</span>',
            $cap,
            Rmmigrate_Links::pricing_url('admin_menu'),
            null
        );
    }

    /**
     * @param array<string,string> $links
     * @return array<string,string>
     */
    public static function action_links(array $links): array
    {
        $url = Rmmigrate_Links::pricing_url('plugin_row');
        $links['mm_upgrade'] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="mm-upgrade-menu">'
            . esc_html__('Upgrade to Pro', 'rosenheinrich-multisite-migrate') . '</a>';
        return $links;
    }
}
