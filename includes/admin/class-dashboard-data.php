<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Dashboard_Data
{
    /**
     * @return array<string,mixed>
     */
    public static function snapshot(bool $is_network): array
    {
        $scope_args = self::scope_args($is_network);

        $recent_args = array_merge(
            $scope_args,
            array(
                'limit'        => 3,
                'status_group' => 'completed',
            )
        );
        $failed_args = array_merge(
            $scope_args,
            array(
                'limit'        => 100,
                'status_group' => 'failed',
            )
        );
        $last_args = array_merge(
            $scope_args,
            array(
                'limit'        => 1,
                'status_group' => 'completed',
            )
        );

        $recent_jobs = Rmmigrate_Job::list_jobs($recent_args);
        $failed_jobs = Rmmigrate_Job::list_jobs($failed_args);
        $last_jobs = Rmmigrate_Job::list_jobs($last_args);
        $last_job = $last_jobs[0] ?? null;
        $active_job = Rmmigrate_Job::resolve_admin_active_job($is_network, 0);

        $archives_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), $is_network);
        $failed_url = Rmmigrate_Admin_Router::admin_url(
            'multisite-migrate-archives',
            array('tab' => 'failed'),
            $is_network
        );

        $sections = array(
            array(
                'icon'  => 'dashicons-warning',
                'count' => (string) count($failed_jobs),
                'label' => __('Failed jobs', 'rosenheinrich-multisite-migrate'),
                'url'   => $failed_url,
            ),
            array(
                'icon'  => 'dashicons-backup',
                'count' => '',
                'label' => __('Open backups', 'rosenheinrich-multisite-migrate'),
                'url'   => $archives_url,
            ),
        );

        $sections = apply_filters('rmmigrate_dashboard_widget_sections', $sections, $is_network);

        return array(
            'is_network'      => $is_network,
            'last_job'        => $last_job,
            'recent_jobs'     => $recent_jobs,
            'failed_count'    => count($failed_jobs),
            'active_job'      => $active_job,
            'can_create'      => Rmmigrate_Access::can_create_backup(),
            'urls'            => array(
                'dashboard' => $archives_url,
                'create'    => $archives_url . '#mm-toggle-create',
                'archives'  => $archives_url,
                'failed'    => $failed_url,
                'upgrade'   => Rmmigrate_Links::pricing_url('dashboard_widget'),
            ),
            'sections'        => $sections,
            'show_pro_upsell'   => true,
            'text_domain'       => 'rosenheinrich-multisite-migrate',
            'widget_title'      => __('Multisite Migrate', 'rosenheinrich-multisite-migrate'),
            'time_class'        => 'Rmmigrate_Time',
        );
    }

    /**
     * @return array<string,int|string>
     */
    private static function scope_args(bool $is_network): array
    {
        if (!$is_network && is_multisite()) {
            return array(
                'scope'   => Rmmigrate_Multisite_Scope::SCOPE_SUBSITE,
                'blog_id' => get_current_blog_id(),
            );
        }

        return array();
    }
}
