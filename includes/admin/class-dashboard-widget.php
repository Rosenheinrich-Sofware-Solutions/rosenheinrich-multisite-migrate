<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Dashboard_Widget
{
    const WIDGET_ID = 'rmmigrate_dashboard_widget';

    public static function register(): void
    {
        if (is_multisite()) {
            add_action('wp_network_dashboard_setup', array(__CLASS__, 'register_network_widget'));
        }
        add_action('wp_dashboard_setup', array(__CLASS__, 'register_site_widget'));
    }

    public static function register_network_widget(): void
    {
        self::register_widget(true);
    }

    public static function register_site_widget(): void
    {
        if (is_network_admin()) {
            return;
        }
        self::register_widget(false);
    }

    private static function register_widget(bool $is_network): void
    {
        if (!self::user_can_view($is_network)) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            self::widget_title(),
            array(__CLASS__, 'render'),
            null,
            array('is_network' => $is_network)
        );
    }

    public static function widget_title(): string
    {
        return __('Multisite Migrate', 'rosenheinrich-multisite-migrate');
    }

    public static function user_can_view(bool $is_network): bool
    {
        if ($is_network) {
            return current_user_can('manage_network');
        }

        if (!current_user_can('manage_options')) {
            return false;
        }

        if (is_multisite()) {
            return Rmmigrate_Access::subsite_plugin_visible();
        }

        return true;
    }

    /**
     * @param mixed $post
     * @param mixed $widget
     */
    public static function render($post = null, $widget = null): void
    {
        unset($post);
        $is_network = is_network_admin();
        if (is_array($widget) && isset($widget['args']['is_network'])) {
            $is_network = (bool) $widget['args']['is_network'];
        } elseif (is_array($widget) && isset($widget['is_network'])) {
            $is_network = (bool) $widget['is_network'];
        }

        if (!self::user_can_view($is_network)) {
            return;
        }

        $rmmigrate_widget = Rmmigrate_Dashboard_Data::snapshot($is_network);
        include RMMIGRATE_PATH . 'admin/partials/dashboard-widget/widget.php';
    }
}
