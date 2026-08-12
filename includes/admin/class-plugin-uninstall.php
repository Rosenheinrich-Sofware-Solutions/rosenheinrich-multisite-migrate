<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Plugin_Uninstall
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_plugins_screen'));
        add_action('wp_ajax_rmmigrate_deactivate', array(__CLASS__, 'ajax_deactivate'));
        add_action('wp_ajax_rmmigrate_uninstall', array(__CLASS__, 'ajax_uninstall'));
    }

    public static function enqueue_plugins_screen(string $hook): void
    {
        if ($hook !== 'plugins.php' || !self::user_can_use_modal()) {
            return;
        }

        if (!self::plugin_row_visible()) {
            return;
        }

        wp_enqueue_style(
            'rmmigrate-tokens',
            RMMIGRATE_URL . 'assets/css/multisite-migrate-tokens.css',
            array(),
            RMMIGRATE_VERSION
        );
        wp_enqueue_style(
            'rmmigrate-components',
            RMMIGRATE_URL . 'assets/css/multisite-migrate-components.css',
            array('rmmigrate-tokens'),
            RMMIGRATE_VERSION
        );

        wp_enqueue_script(
            'rmmigrate-plugin-uninstall',
            RMMIGRATE_URL . 'assets/js/plugin-uninstall.js',
            array('jquery'),
            RMMIGRATE_VERSION,
            true
        );

        wp_localize_script(
            'rmmigrate-plugin-uninstall',
            'rmmigrateUninstall',
            array(
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'deactivateNonce' => wp_create_nonce('rmmigrate_deactivate'),
                'uninstallNonce'  => wp_create_nonce('rmmigrate_uninstall'),
                'feedbackAction'  => 'rmmigrate_deactivate_feedback',
                'feedbackNonce'   => wp_create_nonce('rmmigrate_deactivate_feedback'),
                'pluginBasename'  => RMMIGRATE_BASENAME,
                'pluginBasenames' => self::visible_plugin_basenames(),
                'networkScope'    => is_multisite() && is_network_admin(),
                'canDelete'       => current_user_can('delete_plugins'),
                'reasons'         => self::deactivate_survey_reasons(),
                'i18n'            => array(
                    'deactivateTitle'         => __('Deactivate Multisite Migrate', 'rosenheinrich-multisite-migrate'),
                    'deactivateIntro'         => __('You are about to deactivate Multisite Migrate. Would you like to delete its data or keep it in place?', 'rosenheinrich-multisite-migrate'),
                    'surveyTitle'             => __('Why are you deactivating? (optional)', 'rosenheinrich-multisite-migrate'),
                    'surveyDetails'           => __('Anything else? (optional)', 'rosenheinrich-multisite-migrate'),
                    'surveyDetailsPlaceholder' => __('A short note helps us improve.', 'rosenheinrich-multisite-migrate'),
                    'deleteTitle'             => __('Delete Multisite Migrate', 'rosenheinrich-multisite-migrate'),
                    'deleteIntro'             => __('Choose which local data to delete before removing the plugin.', 'rosenheinrich-multisite-migrate'),
                    'keepAllData'             => __('Keep all Multisite Migrate data', 'rosenheinrich-multisite-migrate'),
                    'deleteBackups'           => __('Delete backup archives', 'rosenheinrich-multisite-migrate'),
                    'deleteBackupsHelp'       => __('Removes local .zip, .daf, and encrypted packages plus temporary job files.', 'rosenheinrich-multisite-migrate'),
                    'deleteLogs'              => __('Delete logs', 'rosenheinrich-multisite-migrate'),
                    'deleteLogsHelp'          => __('Removes activity, job, and system log files.', 'rosenheinrich-multisite-migrate'),
                    'deleteSettings'          => __('Delete settings and database records', 'rosenheinrich-multisite-migrate'),
                    'deleteSettingsHelp'      => __('Removes plugin options and the jobs table.', 'rosenheinrich-multisite-migrate'),
                    'removeAll'               => __('Remove all data', 'rosenheinrich-multisite-migrate'),
                    'keepAll'                 => __('Keep all data', 'rosenheinrich-multisite-migrate'),
                    'cancel'                  => __('Cancel', 'rosenheinrich-multisite-migrate'),
                    'deactivate'              => __('Deactivate', 'rosenheinrich-multisite-migrate'),
                    'deactivating'            => __('Deactivating…', 'rosenheinrich-multisite-migrate'),
                    'deactivateFailed'        => __('Could not deactivate Multisite Migrate. Try again.', 'rosenheinrich-multisite-migrate'),
                    'deletePlugin'            => __('Delete plugin', 'rosenheinrich-multisite-migrate'),
                    'deleting'                => __('Deleting…', 'rosenheinrich-multisite-migrate'),
                    'deleteFailed'            => __('Could not delete the plugin. Try again.', 'rosenheinrich-multisite-migrate'),
                ),
            )
        );
    }

    /**
     * Optional deactivate survey reasons (labels for plugins.php modal).
     *
     * @return array<int,array{id:string,label:string}>
     */
    private static function deactivate_survey_reasons(): array
    {
        return array(
            array(
                'id'    => 'temporary',
                'label' => __('Just deactivating temporarily', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'id'    => 'not_multisite',
                'label' => __('This site is not a multisite network', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'id'    => 'too_complex',
                'label' => __('Too complex or hard to use', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'id'    => 'missing_feature',
                'label' => __('Missing a feature I need', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'id'    => 'found_alternative',
                'label' => __('Found another solution', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'id'    => 'broke_site',
                'label' => __('Caused problems on my site', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'id'    => 'other',
                'label' => __('Other', 'rosenheinrich-multisite-migrate'),
            ),
        );
    }

    public static function ajax_deactivate(): void
    {
        if (!self::user_can_deactivate_plugin()) {
            wp_send_json_error(array('message' => __('You do not have permission to deactivate plugins.', 'rosenheinrich-multisite-migrate')), 403);
        }

        $nonce = Rmmigrate_Request_Input::request_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_deactivate')) {
            wp_send_json_error(array('message' => __('Your session expired. Refresh the page and try again.', 'rosenheinrich-multisite-migrate')), 403);
        }

        require_once RMMIGRATE_PATH . 'includes/class-uninstaller.php';

        $plan = self::cleanup_plan_from_request();
        self::schedule_deferred_cleanup($plan);

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin  = self::resolve_plugin_basename();
        $network = self::should_deactivate_network_wide($plugin);

        deactivate_plugins($plugin, true, $network);

        wp_send_json_success(array('redirect' => self::plugins_redirect_url('deactivate=true', $network)));
    }

    public static function ajax_uninstall(): void
    {
        if (!self::user_can_delete_plugin()) {
            wp_send_json_error(array('message' => __('You do not have permission to delete plugins.', 'rosenheinrich-multisite-migrate')), 403);
        }

        $nonce = Rmmigrate_Request_Input::request_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_uninstall')) {
            wp_send_json_error(array('message' => __('Your session expired. Refresh the page and try again.', 'rosenheinrich-multisite-migrate')), 403);
        }

        require_once RMMIGRATE_PATH . 'includes/class-uninstaller.php';

        Rmmigrate_Uninstaller::save_plan(self::cleanup_plan_from_request());

        if (!function_exists('delete_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin  = self::resolve_plugin_basename();
        $network = self::should_deactivate_network_wide($plugin);

        if (is_plugin_active($plugin) || ($network && is_plugin_active_for_network($plugin))) {
            deactivate_plugins($plugin, true, $network);
        }

        $deleted = delete_plugins(array($plugin));
        if (is_wp_error($deleted)) {
            wp_send_json_error(array('message' => $deleted->get_error_message()), 500);
        }
        if ($deleted === false) {
            wp_send_json_error(array('message' => __('Could not delete the plugin files.', 'rosenheinrich-multisite-migrate')), 500);
        }

        $redirect = self::plugins_redirect_url('deleted=true', $network);

        wp_send_json_success(array('redirect' => $redirect));
    }

    /**
     * @param array{delete_backups: bool, delete_logs: bool, delete_settings: bool} $plan
     */
    private static function schedule_deferred_cleanup(array $plan): void
    {
        if (!self::cleanup_plan_has_deletions($plan)) {
            return;
        }

        register_shutdown_function(
            static function () use ($plan): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                if (!class_exists('Rmmigrate_Uninstaller', false)) {
                    require_once RMMIGRATE_PATH . 'includes/class-uninstaller.php';
                }

                Rmmigrate_Uninstaller::apply_cleanup($plan);
            }
        );
    }

    /**
     * @param array{delete_backups: bool, delete_logs: bool, delete_settings: bool} $plan
     */
    private static function cleanup_plan_has_deletions(array $plan): bool
    {
        return !empty($plan['delete_backups'])
            || !empty($plan['delete_logs'])
            || !empty($plan['delete_settings']);
    }

    private static function plugins_redirect_url(string $query, bool $network): string
    {
        if (is_multisite() && (is_network_admin() || $network)) {
            return network_admin_url('plugins.php?' . $query);
        }

        return admin_url('plugins.php?' . $query);
    }

    /**
     * @return array{delete_backups: bool, delete_logs: bool, delete_settings: bool}
     */
    private static function cleanup_plan_from_request(): array
    {
        return array(
            'delete_backups'  => Rmmigrate_Request_Input::post_bool('delete_backups'),
            'delete_logs'     => Rmmigrate_Request_Input::post_bool('delete_logs'),
            'delete_settings' => Rmmigrate_Request_Input::post_bool('delete_settings'),
        );
    }

    private static function user_can_use_modal(): bool
    {
        if (current_user_can('delete_plugins')) {
            return true;
        }

        if (is_multisite() && is_network_admin()) {
            return current_user_can('manage_network_plugins');
        }

        return current_user_can('activate_plugins');
    }

    private static function user_can_delete_plugin(): bool
    {
        return current_user_can('delete_plugins');
    }

    private static function user_can_deactivate_plugin(): bool
    {
        if (is_multisite() && is_network_admin()) {
            return current_user_can('manage_network_plugins');
        }

        return current_user_can('activate_plugins');
    }

    private static function plugin_row_visible(): bool
    {
        return self::visible_plugin_basenames() !== array();
    }

    /**
     * @return array<int,string>
     */
    private static function visible_plugin_basenames(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed = get_plugins();
        $visible = array();
        foreach (self::allowed_plugin_basenames() as $basename) {
            if (isset($installed[$basename])) {
                $visible[] = $basename;
            }
        }

        return $visible;
    }

    private static function resolve_plugin_basename(): string
    {
        $requested = Rmmigrate_Request_Input::request_text('plugin');
        if ($requested !== '' && in_array($requested, self::allowed_plugin_basenames(), true)) {
            return $requested;
        }

        return RMMIGRATE_BASENAME;
    }

    /**
     * @return array<int,string>
     */
    private static function allowed_plugin_basenames(): array
    {
        return array(RMMIGRATE_BASENAME);
    }

    private static function should_deactivate_network_wide(string $plugin): bool
    {
        if (!is_multisite()) {
            return false;
        }

        if (Rmmigrate_Request_Input::post_bool('network_scope')) {
            return true;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network($plugin);
    }
}
