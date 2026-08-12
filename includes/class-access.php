<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authorization for the plugin.
 *
 * Backups and restores are administrator operations: on multisite the whole
 * network is managed by super admins (manage_network) from Network Admin, and
 * on single-site installs by administrators (manage_options). Subsite
 * administrators with manage_options can back up and restore their own site.
 */
class Rmmigrate_Access
{
    /**
     * Admin pages hidden from subsite administrators (network-only surfaces).
     *
     * @return string[]
     */
    private static function subsite_hidden_pages(): array
    {
        return array(
            'multisite-migrate-advanced',
            'multisite-migrate-mcp',
            'multisite-migrate-setup',
        );
    }

    /**
     * @return string[]
     */
    private static function subsite_allowed_pages(): array
    {
        return array(
            'multisite-migrate-archives',
            'multisite-migrate-migrate',
            'multisite-migrate-activity',
            'multisite-migrate-health',
        );
    }

    public static function is_subsite_admin_context(): bool
    {
        return is_multisite() && is_admin() && !is_network_admin();
    }

    /**
     * A non-super multisite user acting in site context. Retained for defensive
     * scope checks used by restore/import guards.
     */
    public static function is_subsite_scoped_operation(): bool
    {
        return is_multisite()
            && !self::is_super_admin()
            && current_user_can('manage_options');
    }

    public static function subsite_page_visible(string $slug): bool
    {
        if (!self::is_subsite_admin_context() || !current_user_can('manage_options')) {
            return false;
        }

        if (in_array($slug, self::subsite_hidden_pages(), true)) {
            return false;
        }

        if ($slug === 'multisite-migrate-subsite') {
            return self::subsite_any_page_visible();
        }

        return in_array($slug, self::subsite_allowed_pages(), true);
    }

    public static function subsite_any_page_visible(): bool
    {
        if (!self::is_subsite_admin_context() || !current_user_can('manage_options')) {
            return false;
        }

        foreach (self::subsite_allowed_pages() as $slug) {
            if (self::subsite_page_visible($slug)) {
                return true;
            }
        }

        return false;
    }

    public static function subsite_menu_visible(): bool
    {
        return self::subsite_any_page_visible();
    }

    public static function assert_subsite_restore_source(Rmmigrate_Job $source): void
    {
        if (!self::is_subsite_scoped_operation()) {
            return;
        }

        if ($source->get_scope() !== Rmmigrate_Multisite_Scope::SCOPE_SUBSITE
            || $source->get_blog_id() !== get_current_blog_id()
        ) {
            throw Rmmigrate_Service_Exception::forbidden(
                esc_html(__('You can only restore backups created for this site.', 'rosenheinrich-multisite-migrate'))
            );
        }
    }

    public static function assert_subsite_import_archive(string $path): void
    {
        if (!self::is_subsite_scoped_operation()) {
            return;
        }

        $manifest = Rmmigrate_Manifest::read_from_archive($path);
        $scope = is_array($manifest) && !empty($manifest['scope'])
            ? sanitize_text_field((string) $manifest['scope'])
            : Rmmigrate_Multisite_Scope::SCOPE_NETWORK;
        $blog_id = is_array($manifest) ? (int) ($manifest['blog_id'] ?? 0) : 0;

        if ($scope !== Rmmigrate_Multisite_Scope::SCOPE_SUBSITE
            || $blog_id !== get_current_blog_id()
        ) {
            Rmmigrate_Filesystem::delete($path);
            throw Rmmigrate_Service_Exception::forbidden(
                esc_html(__('You can only import backups created for this site.', 'rosenheinrich-multisite-migrate'))
            );
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function subsite_restore_params(array $params): array
    {
        if (!self::is_subsite_scoped_operation()) {
            return $params;
        }

        $params['restore_type'] = Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER;
        $params['topology_import'] = 'subsite_overwrite';
        $params['topology_target_blog_id'] = (string) get_current_blog_id();
        $params['topology_target_url'] = '';
        $params['migration_map'] = array();
        $params['blog_domains'] = null;
        $params['old_site_url'] = '';
        $params['new_site_url'] = '';
        $params['old_home_url'] = '';
        $params['new_home_url'] = '';
        $params['old_url'] = '';
        $params['new_url'] = '';

        return $params;
    }

    public static function is_super_admin(): bool
    {
        return current_user_can('manage_network');
    }

    public static function can_view_job(Rmmigrate_Job $job): bool
    {
        if (!is_multisite()) {
            return current_user_can('manage_options');
        }

        if (self::is_super_admin()) {
            return true;
        }

        if (self::is_subsite_scoped_operation()) {
            if ($job->get_scope() !== Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
                return false;
            }

            return $job->get_blog_id() === get_current_blog_id()
                && current_user_can('manage_options');
        }

        return false;
    }

    public static function can_create_backup(): bool
    {
        if (self::is_super_admin()) {
            return true;
        }

        if (!is_multisite()) {
            return current_user_can('manage_options');
        }

        if (is_network_admin()) {
            return current_user_can('manage_network');
        }

        return self::is_subsite_scoped_operation();
    }

    public static function can_delete_backup(): bool
    {
        if (self::is_super_admin()) {
            return true;
        }

        if (!is_multisite()) {
            return current_user_can('manage_options');
        }

        if (is_network_admin()) {
            return current_user_can('manage_network');
        }

        return self::is_subsite_scoped_operation();
    }

    public static function can_current_site(string $action): bool
    {
        return self::can(get_current_blog_id(), $action);
    }

    public static function can(int $blog_id, string $action): bool
    {
        unset($action);

        if (self::is_super_admin()) {
            return true;
        }

        if (!is_multisite()) {
            return current_user_can('manage_options');
        }

        if (!current_user_can('manage_options')) {
            return false;
        }

        if (self::is_subsite_scoped_operation()) {
            return $blog_id === get_current_blog_id();
        }

        return false;
    }
}
