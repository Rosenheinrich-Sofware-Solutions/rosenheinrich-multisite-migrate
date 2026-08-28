<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Network_Admin
{
    public static function register(): void
    {
        add_action('network_admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('network_admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function menu(): void
    {
        if (class_exists('Rmmigrate_Pro_Edition_Exclusion', false)
            && method_exists('Rmmigrate_Pro_Edition_Exclusion', 'should_hide_engine_admin_menu')
            && Rmmigrate_Pro_Edition_Exclusion::should_hide_engine_admin_menu()
        ) {
            return;
        }

        Rmmigrate_Admin_Menu::register_top_level('manage_network', array(__CLASS__, 'render_page'));
    }

    public static function render_page(): void
    {
        $slug = Rmmigrate_Admin_Menu::page_from_request();
        Rmmigrate_Admin_Router::render($slug, true);
    }

    public static function assets(string $hook): void
    {
        if (!is_network_admin()) {
            return;
        }
        Rmmigrate_Admin_Assets::enqueue($hook, true);
    }
}
