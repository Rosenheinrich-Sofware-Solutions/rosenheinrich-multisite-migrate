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

        $completed_args = array_merge($scope_args, array('status_group' => 'completed'));
        $recent_args = array_merge($completed_args, array('limit' => 3));
        $failed_args = array_merge(
            $scope_args,
            array(
                'status_group' => 'failed',
            )
        );
        $recent_jobs = Rmmigrate_Job::list_jobs($recent_args);
        $failed_count = Rmmigrate_Snap_DB::jobs_count_rows($failed_args);
        $backup_count = Rmmigrate_Snap_DB::jobs_count_rows($completed_args);
        $last_job = $recent_jobs[0] ?? null;
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
                'count' => (string) $failed_count,
                'label' => __('Failed jobs', 'rosenheinrich-multisite-migrate'),
                'url'   => $failed_url,
            ),
            array(
                'icon'  => 'dashicons-backup',
                'count' => (string) $backup_count,
                'label' => __('Open backups', 'rosenheinrich-multisite-migrate'),
                'url'   => $archives_url,
            ),
        );

        $sections = apply_filters('rmmigrate_dashboard_widget_sections', $sections, $is_network);

        if (!is_array($sections)) {
            $sections = array();
        }
        $sections = array_map(
            static function ($section) {
                if (!is_array($section)) {
                    $section = array();
                }

                return array(
                    'icon'  => isset($section['icon']) ? (string) $section['icon'] : '',
                    'count' => isset($section['count']) ? (string) $section['count'] : '',
                    'label' => isset($section['label']) ? (string) $section['label'] : '',
                    'url'   => esc_url_raw(isset($section['url']) ? (string) $section['url'] : ''),
                );
            },
            $sections
        );

        return array(
            'is_network'      => $is_network,
            'last_job'        => $last_job,
            'recent_jobs'     => $recent_jobs,
            'failed_count'    => $failed_count,
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
