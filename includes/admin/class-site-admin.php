<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Site_Admin
{
    public static function register(): void
    {
        if (is_multisite()) {
            add_action('admin_menu', array(__CLASS__, 'menu'));
        } else {
            add_action('admin_menu', array(__CLASS__, 'menu_single'));
        }
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function menu(): void
    {
        if (class_exists('Rmmigrate_Pro_Edition_Exclusion', false)
            && method_exists('Rmmigrate_Pro_Edition_Exclusion', 'should_hide_engine_admin_menu')
            && Rmmigrate_Pro_Edition_Exclusion::should_hide_engine_admin_menu()
        ) {
            return;
        }

        if (!Rmmigrate_Access::subsite_menu_visible()) {
            return;
        }

        Rmmigrate_Admin_Menu::register_subsite_top_level(array(__CLASS__, 'render_page'));
    }

    public static function menu_single(): void
    {
        if (class_exists('Rmmigrate_Pro_Edition_Exclusion', false)
            && method_exists('Rmmigrate_Pro_Edition_Exclusion', 'should_hide_engine_admin_menu')
            && Rmmigrate_Pro_Edition_Exclusion::should_hide_engine_admin_menu()
        ) {
            return;
        }

        Rmmigrate_Admin_Menu::register_top_level('manage_options', array(__CLASS__, 'render_page'));
    }

    public static function render_page(): void
    {
        $slug = Rmmigrate_Admin_Menu::page_from_request();
        if ($slug === 'multisite-migrate-subsite') {
            $slug = 'multisite-migrate-archives';
        }
        Rmmigrate_Admin_Router::render($slug, false);
    }

    public static function assets(string $hook): void
    {
        if (is_network_admin()) {
            return;
        }
        Rmmigrate_Admin_Assets::enqueue($hook, false);
    }
}
