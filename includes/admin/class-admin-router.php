<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Admin_Router
{
    public static function register(): void
    {
        add_action('admin_init', array(__CLASS__, 'maybe_redirect_legacy_page'), 1);
        add_action('admin_init', array(__CLASS__, 'maybe_redirect_legacy_section'), 2);
    }

    /**
     * @return array<string,array<string,bool>>
     */
    public static function header_action_kses(): array
    {
        return array(
            'a'      => array(
                'class' => true,
                'href'  => true,
            ),
            'button' => array(
                'type'        => true,
                'class'       => true,
                'id'          => true,
                'data-job-id' => true,
            ),
        );
    }

    /**
     * @return array<string,array{page:string,args?:array<string,string>}>
     */
    public static function legacy_redirects(): array
    {
        return array(
            'multisite-migrate'          => array('page' => 'multisite-migrate-archives'),
            'multisite-migrate-list'     => array('page' => 'multisite-migrate-archives'),
            'multisite-migrate-subsite'  => array('page' => 'multisite-migrate-archives'),
            'multisite-migrate-import'   => array('page' => 'multisite-migrate-migrate', 'args' => array('tab' => 'import')),
            'multisite-migrate-tools'    => array('page' => 'multisite-migrate-advanced', 'args' => array('tab' => 'system')),
            'multisite-migrate-settings' => array('page' => 'multisite-migrate-advanced', 'args' => array('tab' => 'engine')),
        );
    }

    public static function maybe_redirect_legacy_page(): void
    {
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }

        $rmmigrate_page = Rmmigrate_Request_Input::get_key('page');
        $redirects = self::legacy_redirects();
        if (!isset($redirects[$rmmigrate_page])) {
            return;
        }

        if (!self::user_can_access_admin()) {
            return;
        }

        $target = $redirects[$rmmigrate_page];
        $rmmigrate_args = array('page' => $target['page']);
        if (!empty($target['args']) && is_array($target['args'])) {
            $rmmigrate_args = array_merge($rmmigrate_args, $target['args']);
        }

        foreach (array('filter', 'job_id', 'view', 'type', 'import_step', 'step', 'log') as $preserve) {
            $value = Rmmigrate_Request_Input::get_text($preserve);
            if ($value !== '') {
                $rmmigrate_args[$preserve] = $value;
            }
        }

        if ($rmmigrate_page === 'multisite-migrate-tools' && Rmmigrate_Request_Input::get_key('tab') !== '') {
            $old_tab = Rmmigrate_Request_Input::get_key('tab');
            $tools_map = array(
                'general'       => 'system',
                'server'        => 'system',
                'logs'          => 'system',
                'recovery'      => 'system',
                'searchreplace' => 'migrate',
            );
            if ($old_tab === 'searchreplace') {
                $rmmigrate_args = array('page' => 'multisite-migrate-migrate', 'tab' => 'searchreplace');
            } elseif (isset($tools_map[$old_tab])) {
                $rmmigrate_args['tab'] = $tools_map[$old_tab];
                if ($old_tab === 'logs') {
                    $rmmigrate_args = array('page' => 'multisite-migrate-activity');
                    $log = Rmmigrate_Request_Input::get_text('log');
                    if ($log !== '') {
                        $rmmigrate_args['log'] = $log;
                    }
                }
            }
        }

        if ($rmmigrate_page === 'multisite-migrate-settings' && Rmmigrate_Request_Input::get_key('tab') !== '') {
            $old_tab = Rmmigrate_Request_Input::get_key('tab');
            $settings_map = array(
                'general'       => 'engine',
                'license'       => 'engine',
                'backups'       => 'engine',
                'database'      => 'engine',
                'notifications' => 'notifications',
                'import'        => 'migrate',
            );
            if ($old_tab === 'import') {
                $rmmigrate_args = array('page' => 'multisite-migrate-migrate', 'tab' => 'import');
            } elseif (isset($settings_map[$old_tab])) {
                $rmmigrate_args['tab'] = $settings_map[$old_tab];
            }
        }

        wp_safe_redirect(add_query_arg($rmmigrate_args, self::admin_base_url()));
        exit;
    }

    public static function maybe_redirect_legacy_section(): void
    {
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }

        if (!self::user_can_access_admin()) {
            return;
        }

        $rmmigrate_page = Rmmigrate_Request_Input::get_key('page');

        if ($rmmigrate_page === 'multisite-migrate-archives'
            && Rmmigrate_Request_Input::get_key('tab') === 'activity'
        ) {
            $rmmigrate_args = array('page' => 'multisite-migrate-activity');
            foreach (array('type', 'log', 'log_cleared', 'updated') as $preserve) {
                $value = Rmmigrate_Request_Input::get_text($preserve);
                if ($value !== '') {
                    $rmmigrate_args[$preserve] = $value;
                }
            }
            wp_safe_redirect(add_query_arg($rmmigrate_args, self::admin_base_url()));
            exit;
        }

        if ($rmmigrate_page !== 'multisite-migrate-advanced') {
            return;
        }

        $section = Rmmigrate_Request_Input::get_key('section');
        if ($section === 'server') {
            wp_safe_redirect(add_query_arg(
                array('page' => 'multisite-migrate-advanced', 'tab' => 'system'),
                self::admin_base_url()
            ));
            exit;
        }

        if ($section === 'logs') {
            $rmmigrate_args = array(
                'page' => 'multisite-migrate-activity',
            );
            $log = Rmmigrate_Request_Input::get_text('log');
            if ($log !== '') {
                $rmmigrate_args['log'] = $log;
            }
            wp_safe_redirect(add_query_arg($rmmigrate_args, self::admin_base_url()));
            exit;
        }
    }

    private static function admin_base_url(): string
    {
        if (is_multisite() && is_network_admin()) {
            return network_admin_url('admin.php');
        }
        return admin_url('admin.php');
    }

    private static function user_can_access_admin(): bool
    {
        return current_user_can('manage_network') || current_user_can('manage_options');
    }

    public static function is_subsite_scoped_context(): bool
    {
        return is_multisite() && !is_network_admin();
    }

    /**
     * @return array{scope:string,blog_id:int}
     */
    public static function subsite_job_filters(): array
    {
        return array(
            'scope'   => Rmmigrate_Multisite_Scope::SCOPE_SUBSITE,
            'blog_id' => get_current_blog_id(),
        );
    }

    /**
     * @return array<string,array{title:string,partial:string,scripts?:array<int,string>}>
     */
    public static function pages(): array
    {
        $titles = Rmmigrate_Admin_Menu::page_titles();
        $pages = array(
            'multisite-migrate-archives'  => array('title' => $titles['multisite-migrate-archives'], 'partial' => 'archives.php', 'scripts' => array('restore', 'backup-create')),
            'multisite-migrate-migrate'   => array('title' => $titles['multisite-migrate-migrate'], 'partial' => 'migrate.php', 'scripts' => array('restore')),
            'multisite-migrate-schedule'  => array('title' => $titles['multisite-migrate-schedule'], 'partial' => 'schedule.php', 'scripts' => array('schedule')),
            'multisite-migrate-advanced'  => array('title' => $titles['multisite-migrate-advanced'], 'partial' => 'advanced.php'),
            'multisite-migrate-activity'  => array('title' => $titles['multisite-migrate-activity'], 'partial' => 'activity-log-page.php', 'scripts' => array('activity-log')),
            'multisite-migrate-health'    => array('title' => $titles['multisite-migrate-health'], 'partial' => 'health.php', 'scripts' => array('admin-tools')),
            'multisite-migrate-mcp'       => array('title' => $titles['multisite-migrate-mcp'], 'partial' => 'mcp-page.php'),
            'multisite-migrate-setup'     => array('title' => $titles['multisite-migrate-setup'], 'partial' => 'wizards/setup/index.php', 'scripts' => array('setup')),
        );

        return $pages;
    }

    public static function admin_url(string $rmmigrate_page, array $rmmigrate_args = array(), bool $rmmigrate_is_network = false): string
    {
        $remap = self::legacy_redirects();
        if (isset($remap[$rmmigrate_page])) {
            $target = $remap[$rmmigrate_page];
            $rmmigrate_page = $target['page'];
            if (!empty($target['args'])) {
                $rmmigrate_args = array_merge($target['args'], $rmmigrate_args);
            }
        }
        $rmmigrate_args['page'] = $rmmigrate_page;
        return add_query_arg($rmmigrate_args, $rmmigrate_is_network ? network_admin_url('admin.php') : admin_url('admin.php'));
    }

    public static function render(string $rmmigrate_page_slug, bool $rmmigrate_is_network): void
    {
        $redirects = self::legacy_redirects();
        if (isset($redirects[$rmmigrate_page_slug])) {
            $rmmigrate_page_slug = $redirects[$rmmigrate_page_slug]['page'];
        }

        if (self::is_subsite_scoped_context() && !Rmmigrate_Access::subsite_page_visible($rmmigrate_page_slug)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'rosenheinrich-multisite-migrate'));
        }

        $pages = self::pages();
        if (!isset($pages[$rmmigrate_page_slug])) {
            wp_die(esc_html__('Page not found.', 'rosenheinrich-multisite-migrate'));
        }

        $rmmigrate_page = $pages[$rmmigrate_page_slug];
        $rmmigrate_settings = Rmmigrate_Settings::get();
        $rmmigrate_highlight_job_id = Rmmigrate_Request_Input::get_int('job_id');
        Rmmigrate_Job::recover_stale_active();
        $rmmigrate_active = Rmmigrate_Job::resolve_admin_active_job(
            $rmmigrate_is_network,
            $rmmigrate_highlight_job_id
        );
        $rmmigrate_restore_success = Rmmigrate_Job::resolve_admin_restore_success_job(
            $rmmigrate_is_network,
            $rmmigrate_highlight_job_id
        );
        $rmmigrate_last = Rmmigrate_Job::get_last_completed();
        $rmmigrate_last_error = Rmmigrate_Job::resolve_admin_last_error($rmmigrate_is_network);
        $rmmigrate_migration_notice = get_site_option('rmmigrate_migration_notice', array());
        $rmmigrate_page_title = $rmmigrate_page['title'];
        $rmmigrate_current_page = $rmmigrate_page_slug;
        $rmmigrate_subsite_mode = self::is_subsite_scoped_context();
        $page_context = array(
            'slug'    => $rmmigrate_page_slug,
            'title'   => $rmmigrate_page_title,
            'partial' => $rmmigrate_page['partial'],
        );

        $rmmigrate_header_action = '';

        if (in_array($rmmigrate_page_slug, array('multisite-migrate-archives', 'multisite-migrate-migrate'), true)) {
            $rmmigrate_filter = Rmmigrate_Request_Input::get_text('filter', 'all');
            if ($rmmigrate_page_slug === 'multisite-migrate-archives' && Rmmigrate_Request_Input::get_key('tab', 'all') === 'failed') {
                $rmmigrate_filter = 'failed';
            }
            $rmmigrate_args = array('limit' => $rmmigrate_is_network ? 100 : 50);
            if (self::is_subsite_scoped_context()) {
                $rmmigrate_args = array_merge($rmmigrate_args, self::subsite_job_filters());
            }
            if ($rmmigrate_filter === 'failed') {
                $rmmigrate_args['status_group'] = 'failed';
            } elseif ($rmmigrate_filter === 'completed') {
                $rmmigrate_args['status_group'] = 'completed';
            }
            $rmmigrate_date_from = Rmmigrate_Activity_Log::normalize_date_filter(
                Rmmigrate_Request_Input::get_text('date_from')
            );
            $rmmigrate_date_to = Rmmigrate_Activity_Log::normalize_date_filter(
                Rmmigrate_Request_Input::get_text('date_to')
            );
            if ($rmmigrate_date_from !== '') {
                $rmmigrate_args['date_from'] = $rmmigrate_date_from;
            }
            if ($rmmigrate_date_to !== '') {
                $rmmigrate_args['date_to'] = $rmmigrate_date_to;
            }
            if ($rmmigrate_page_slug === 'multisite-migrate-archives') {
                Rmmigrate_Job_Cleanup::reconcile_missing_local_archives();
            }
            $rmmigrate_jobs = Rmmigrate_Job::list_jobs($rmmigrate_args);
            if ($rmmigrate_highlight_job_id === 0 && $rmmigrate_filter === 'failed' && !empty($rmmigrate_last_error['job_id'])) {
                $rmmigrate_highlight_job_id = (int) $rmmigrate_last_error['job_id'];
            }
            if ($rmmigrate_highlight_job_id > 0) {
                $rmmigrate_highlight_job = Rmmigrate_Job::get($rmmigrate_highlight_job_id);
                // Keep restore / safety snapshots out of the backups table entirely.
                if ($rmmigrate_highlight_job !== null
                    && !$rmmigrate_highlight_job->is_restore()
                    && !$rmmigrate_highlight_job->is_safety()
                ) {
                    $rmmigrate_jobs = Rmmigrate_Job::ensure_job_in_list($rmmigrate_jobs, $rmmigrate_highlight_job_id);
                }
            }
        }

        if ($rmmigrate_page_slug === 'multisite-migrate-advanced') {
            $rmmigrate_advanced_tab = Rmmigrate_Request_Input::get_key('tab', 'engine');
            if (!in_array($rmmigrate_advanced_tab, array('engine', 'notifications', 'privacy', 'system'), true)) {
                $rmmigrate_advanced_tab = 'engine';
            }
        }

        if ($rmmigrate_page_slug === 'multisite-migrate-migrate') {
            $rmmigrate_migrate_tab = Rmmigrate_Request_Input::get_key('tab', 'import');
            if (!in_array($rmmigrate_migrate_tab, array('import', 'recovery', 'searchreplace'), true)) {
                $rmmigrate_migrate_tab = 'import';
            }
        }

        if ($rmmigrate_page_slug === 'multisite-migrate-archives') {
            $rmmigrate_archives_tab = Rmmigrate_Request_Input::get_key('tab', 'all');
            if (!in_array($rmmigrate_archives_tab, array('all', 'failed'), true)) {
                $rmmigrate_archives_tab = 'all';
            }
        }

        include RMMIGRATE_PATH . 'admin/partials/layout/shell-open.php';
        include RMMIGRATE_PATH . 'admin/partials/layout/header.php';
        include RMMIGRATE_PATH . 'admin/partials/layout/below-header.php';
        include RMMIGRATE_PATH . 'admin/partials/' . $rmmigrate_page['partial'];
        include RMMIGRATE_PATH . 'admin/partials/layout/shell-close.php';
    }

    /**
     * All plugin page slugs for notice detection.
     *
     * @return array<string,bool>
     */
    public static function all_page_slugs(): array
    {
        $slugs = array_fill_keys(array_keys(self::pages()), true);
        foreach (array_keys(self::legacy_redirects()) as $legacy_slug) {
            $slugs[$legacy_slug] = true;
        }

        return $slugs;
    }
}
