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
            'multisite-migrate-migrate'  => __('Move site', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-schedule' => __('Schedules', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-advanced' => __('Settings', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-activity' => __('Activity Log', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-health'   => __('Server check', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-mcp'      => __('AI Agents (MCP)', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-subsite'  => __('Backups', 'rosenheinrich-multisite-migrate'),
            'multisite-migrate-setup'    => __('Setup Wizard', 'rosenheinrich-multisite-migrate'),
        );
    }

    /**
     * Sidebar entries. Only everyday pages are in the menu (`in_menu`); Activity
     * Log and Server check stay routable and are linked from Settings, AI Agents
     * appears in expert mode only.
     *
     * @return array<int,array{slug:string,label:string,callback:callable,in_menu:bool,visible?:bool}>
     */
    public static function submenu_items(callable $callback): array
    {
        $titles = self::page_titles();
        $expert = class_exists('Rmmigrate_Expert_Mode', false) && Rmmigrate_Expert_Mode::enabled();

        return array(
            array('slug' => 'multisite-migrate-archives', 'label' => $titles['multisite-migrate-archives'], 'callback' => $callback, 'in_menu' => true),
            array('slug' => 'multisite-migrate-migrate', 'label' => $titles['multisite-migrate-migrate'], 'callback' => $callback, 'in_menu' => true),
            array('slug' => 'multisite-migrate-schedule', 'label' => $titles['multisite-migrate-schedule'], 'callback' => $callback, 'in_menu' => true),
            array('slug' => 'multisite-migrate-advanced', 'label' => $titles['multisite-migrate-advanced'], 'callback' => $callback, 'in_menu' => true),
            array('slug' => 'multisite-migrate-activity', 'label' => $titles['multisite-migrate-activity'], 'callback' => $callback, 'in_menu' => false),
            array('slug' => 'multisite-migrate-health', 'label' => $titles['multisite-migrate-health'], 'callback' => $callback, 'in_menu' => false),
            array(
                'slug'     => 'multisite-migrate-mcp',
                'label'    => __('AI Agents', 'rosenheinrich-multisite-migrate'),
                'callback' => $callback,
                'in_menu'  => $expert,
            ),
        );
    }

    /**
     * Pages reached from Settings (not in the sidebar) keep "Settings" highlighted.
     */
    private static function settings_child_page(): bool
    {
        $page = self::page_from_request();
        if (in_array($page, array('multisite-migrate-activity', 'multisite-migrate-health'), true)) {
            return true;
        }

        return $page === 'multisite-migrate-mcp' && !(class_exists('Rmmigrate_Expert_Mode', false) && Rmmigrate_Expert_Mode::enabled());
    }

    /**
     * @param string $parent_file
     * @return string
     */
    public static function highlight_parent($parent_file)
    {
        return self::settings_child_page() ? self::parent_slug() : $parent_file;
    }

    /**
     * @param string|null $submenu_file
     * @return string|null
     */
    public static function highlight_submenu($submenu_file)
    {
        return self::settings_child_page() ? 'multisite-migrate-advanced' : $submenu_file;
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

        remove_submenu_page($first['slug'], $first['slug']);

        foreach ($visible_items as $item) {
            add_submenu_page(
                (!isset($item['in_menu']) || !empty($item['in_menu'])) ? $first['slug'] : '',
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
            // Hidden-but-routable pages use an empty parent (no sidebar entry).
            add_submenu_page(
                !empty($item['in_menu']) ? $parent : '',
                $item['label'],
                $item['label'],
                $cap,
                $item['slug'],
                $callback
            );
        }
        add_filter('parent_file', array(__CLASS__, 'highlight_parent'));
        add_filter('submenu_file', array(__CLASS__, 'highlight_submenu'));
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
            // Hidden-but-routable: empty parent keeps direct ?page= access without a submenu entry.
            add_submenu_page(
                '',
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
        if (strpos($hook, 'multisite-migrate-schedule') !== false || strpos($hook, 'multisite-migrate-automate') !== false) {
            $needs[] = 'schedule';
        }

        return array_values(array_unique($needs));
    }
}
