<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Admin_Menu
{
    /**
     * Top-level admin menu slug (also the default landing page: Backups).
     */
    public static function parent_slug(): string
    {
        return 'multisite-migrate-archives';
    }

    /**
     * User-facing admin page titles (sidebar + in-app header).
     *
     * @return array<string,string>
     */
    public static function page_titles(): array
    {
        return array(
            'multisite-migrate-archives' => __('Backups', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-migrate'  => __('Import & Restore', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-advanced' => __('Settings', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-activity' => __('Activity Log', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-health'   => __('Health', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-mcp'      => __('AI Agents (MCP)', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-subsite'  => __('Backups', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-setup'    => __('Setup Wizard', 'rosenheinrich-multisite-migrate'),
        );
    }

    /**
     * @return array<int,array{slug:string,label:string,callback:callable,visible?:bool}>
     */
    public static function submenu_items(callable $callback): array
    {
        $titles = self::page_titles();
        $items = array(
            array('slug' => 'multisite-migrate-archives', 'label' => $titles['multisite-migrate-archives'], 'callback' => $callback),
            array('slug' => 'multisite-migrate-migrate', 'label' => $titles['multisite-migrate-migrate'], 'callback' => $callback),
            array('slug' => 'multisite-migrate-advanced', 'label' => $titles['multisite-migrate-advanced'], 'callback' => $callback),
            array('slug' => 'multisite-migrate-activity', 'label' => $titles['multisite-migrate-activity'], 'callback' => $callback),
            array('slug' => 'multisite-migrate-health', 'label' => $titles['multisite-migrate-health'], 'callback' => $callback),
            array(
                'slug'     => 'multisite-migrate-mcp',
                'label'    => __('AI Agents', 'rosenheinrich-multisite-migrate'),
                'callback' => $callback,
            ),
        );

        return $items;
    }

    /**
     * @return array<int,array{slug:string,label:string,callback:callable,visible:bool}>
     */
    public static function subsite_submenu_items(callable $callback): array
    {
        $items = array();
        foreach (self::submenu_items($callback) as $item) {
            $item['visible'] = Rmmigrate_Access::subsite_page_visible($item['slug']);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array{menu_title?:string,menu_icon?:string} $options
     */
    public static function register_subsite_top_level(callable $callback, array $options = array()): void
    {
        $menu_title = isset($options['menu_title']) ? (string) $options['menu_title'] : Rmmigrate_Brand::menu_title();
        $menu_icon = isset($options['menu_icon']) ? (string) $options['menu_icon'] : Rmmigrate_Brand::menu_icon_url();
        $visible_items = array_values(array_filter(
            self::subsite_submenu_items($callback),
            static function (array $item): bool {
                return !empty($item['visible']);
            }
        ));

        if ($visible_items === array()) {
            return;
        }

        $first = $visible_items[0];
        add_menu_page(
            $menu_title,
            $menu_title,
            'manage_options',
            $first['slug'],
            $callback,
            $menu_icon,
            58
        );

        foreach ($visible_items as $item) {
            add_submenu_page(
                $first['slug'],
                $item['label'],
                $item['label'],
                'manage_options',
                $item['slug'],
                $callback
            );
        }
    }

    /**
     * @param array{menu_title?:string,menu_icon?:string} $options
     */
    public static function register_top_level(string $cap, callable $callback, array $options = array()): void
    {
        $menu_title = isset($options['menu_title']) ? (string) $options['menu_title'] : Rmmigrate_Brand::menu_title();
        $menu_icon = isset($options['menu_icon']) ? (string) $options['menu_icon'] : Rmmigrate_Brand::menu_icon_url();
        $parent = self::parent_slug();
        add_menu_page(
            $menu_title,
            $menu_title,
            $cap,
            $parent,
            $callback,
            $menu_icon,
            81
        );
        remove_submenu_page($parent, $parent);
        foreach (self::submenu_items($callback) as $item) {
            add_submenu_page(
                $parent,
                $item['label'],
                $item['label'],
                $cap,
                $item['slug'],
                $callback
            );
        }
        if (Rmmigrate_Setup_Wizard::menu_visible()) {
            add_submenu_page(
                $parent,
                __('Setup Wizard', 'rosenheinrich-multisite-migrate'),
                __('Setup', 'rosenheinrich-multisite-migrate'),
                $cap,
                'multisite-migrate-setup',
                $callback
            );
        } else {
            add_submenu_page(
                null,
                __('Setup Wizard', 'rosenheinrich-multisite-migrate'),
                __('Setup Wizard', 'rosenheinrich-multisite-migrate'),
                $cap,
                'multisite-migrate-setup',
                $callback
            );
        }
    }

    public static function page_from_request(): string
    {
        return Rmmigrate_Request_Input::get_key('page', self::parent_slug());
    }

    /**
     * @return array<int,string>
     */
    /**
     * @return array<int,string>
     */
    public static function script_needs(string $hook): array
    {
        $needs = array('progress');

        if (strpos($hook, 'multisite-migrate-archives') !== false || strpos($hook, 'multisite-migrate-list') !== false || strpos($hook, 'multisite-migrate-subsite') !== false) {
            $needs = array_merge($needs, array('restore', 'admin-tools', 'backup-create', 'activity-log'));
        }
        if (strpos($hook, 'multisite-migrate-migrate') !== false || strpos($hook, 'multisite-migrate-import') !== false) {
            $needs = array_merge($needs, array('restore', 'import-wizard', 'admin-tools'));
        }

        if (strpos($hook, 'multisite-migrate-advanced') !== false
            || strpos($hook, 'multisite-migrate-tools') !== false
            || strpos($hook, 'multisite-migrate-settings') !== false
        ) {
            $needs[] = 'admin-tools';
        }
        if (strpos($hook, 'multisite-migrate-setup') !== false) {
            $needs[] = 'setup';
        }
        if (strpos($hook, 'multisite-migrate-activity') !== false) {
            $needs = array_merge($needs, array('admin-tools', 'activity-log'));
        }
        if (strpos($hook, 'multisite-migrate-health') !== false) {
            $needs[] = 'admin-tools';
        }
        if (strpos($hook, 'multisite-migrate-mcp') !== false) {
            $needs[] = 'mcp';
        }
        if ($hook === 'toplevel_page_multisite-migrate-archives' || $hook === 'tools_page_multisite-migrate-archives') {
            $needs = array_merge($needs, array('admin-tools', 'backup-create'));
        }

        return array_values(array_unique($needs));
    }
}
