<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Plugin_Links
{
    public static function register(): void
    {
        $basename = RMMIGRATE_BASENAME;
        add_filter("plugin_action_links_{$basename}", array(__CLASS__, 'action_links'));
        add_filter("network_admin_plugin_action_links_{$basename}", array(__CLASS__, 'action_links'));
    }

    /**
     * @param string[] $links
     * @return string[]
     */
    public static function action_links(array $links): array
    {
        if (!self::user_can_manage()) {
            return $links;
        }

        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::settings_url()),
            esc_html__('Settings', 'rosenheinrich-multisite-migrate')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    private static function user_can_manage(): bool
    {
        return current_user_can('manage_network') || current_user_can('manage_options');
    }

    private static function settings_url(): string
    {
        if (is_multisite() && (is_network_admin() || current_user_can('manage_network'))) {
            return Rmmigrate_Admin_Router::admin_url('multisite-migrate-advanced', array('tab' => 'engine'), true);
        }

        if (is_multisite()) {
            return Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), false);
        }

        return Rmmigrate_Admin_Router::admin_url('multisite-migrate-advanced', array('tab' => 'engine'), false);
    }
}
