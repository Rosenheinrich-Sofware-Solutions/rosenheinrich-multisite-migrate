<?php
if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Admin_Save
{
    public static function register(): void
    {
        add_action('network_admin_edit_rmmigrate_settings', array(__CLASS__, 'save_settings'));
        add_action('network_admin_edit_rmmigrate_clear_activity_log', array(__CLASS__, 'clear_activity_log'));
        add_action('network_admin_edit_rmmigrate_export_settings', array(__CLASS__, 'export_settings'));
        add_action('network_admin_edit_rmmigrate_diagnostic_zip', array(__CLASS__, 'diagnostic_zip'));
        add_action('network_admin_edit_rmmigrate_delete_all_logs', array(__CLASS__, 'delete_all_logs'));
        add_action('network_admin_edit_rmmigrate_settings_import', array(__CLASS__, 'import_settings'));
        add_action('admin_post_rmmigrate_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_rmmigrate_clear_activity_log', array(__CLASS__, 'clear_activity_log'));
        add_action('admin_post_rmmigrate_export_settings', array(__CLASS__, 'export_settings'));
        add_action('admin_post_rmmigrate_diagnostic_zip', array(__CLASS__, 'diagnostic_zip'));
        add_action('admin_post_rmmigrate_delete_all_logs', array(__CLASS__, 'delete_all_logs'));
        add_action('admin_post_rmmigrate_settings_import', array(__CLASS__, 'import_settings'));
    }

    private static function can_save(): bool
    {
        if (is_multisite()) {
            return current_user_can('manage_network');
        }
        return current_user_can('manage_options');
    }

    public static function save_settings(): void
    {
        if (!self::can_save()) {
            wp_die(esc_html__('Permission denied.', 'rosenheinrich-multisite-migrate'));
        }
        check_admin_referer('rmmigrate_settings');
        $current = Rmmigrate_Settings::get();
        $post = Rmmigrate_Request_Input::post_array();
        $tab = sanitize_key($post['settings_tab'] ?? 'engine');
        if ($tab === 'general') {
            $tab = 'engine';
        }
        $merged = Rmmigrate_Settings::parse_post($post, $current);
        if ($tab === 'import') {
            self::persist($merged, $current, 'multisite-migrate-migrate', array('tab' => 'import'));
            return;
        }

        self::persist($merged, $current, 'multisite-migrate-advanced', array('tab' => $tab));
    }

    public static function clear_activity_log(): void
    {
        if (!self::can_save()) {
            wp_die(esc_html__('Permission denied.', 'rosenheinrich-multisite-migrate'));
        }
        check_admin_referer('rmmigrate_clear_activity_log');
        Rmmigrate_Activity_Log::clear_all();
        $url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-activity', array('log_cleared' => '1'), is_multisite() && current_user_can('manage_network'));
        wp_safe_redirect($url);
        exit;
    }

    public static function export_settings(): void
    {
        if (!self::can_save()) {
            wp_die(esc_html__('Permission denied.', 'rosenheinrich-multisite-migrate'));
        }
        check_admin_referer('rmmigrate_export_settings');
        $settings = Rmmigrate_Settings::get();
        unset($settings['archive_passphrase']);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=multisite-migrate-settings.json');
        echo wp_json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function import_settings(): void
    {
        if (!self::can_save()) {
            wp_die(esc_html__('Permission denied.', 'rosenheinrich-multisite-migrate'));
        }
        check_admin_referer('rmmigrate_settings_import');
        $tmp = Rmmigrate_Request_Input::file_tmp_name('settings_import');
        if ($tmp === '') {
            wp_die(esc_html__('No file uploaded.', 'rosenheinrich-multisite-migrate'));
        }
        $name = Rmmigrate_Request_Input::file_original_name('settings_import');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
            wp_die(esc_html__('Invalid settings file extension. Only .json files are allowed.', 'rosenheinrich-multisite-migrate'));
        }
        $raw = Rmmigrate_Filesystem::get_contents($tmp);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            wp_die(esc_html__('Invalid settings file.', 'rosenheinrich-multisite-migrate'));
        }
        $current = Rmmigrate_Settings::get();
        // Merge only keys that are in the known defaults — prevents arbitrary injection
        // of keys like mysqldump_path (exec'd) via a crafted JSON file.
        $allowed_keys = array_keys(Rmmigrate_Settings::defaults());
        $safe_decoded = array_intersect_key($decoded, array_flip($allowed_keys));
        $merged = array_merge($current, $safe_decoded);
        Rmmigrate_Settings::save($merged);
        $url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-advanced', array('tab' => 'engine', 'updated' => '1'), is_multisite() && current_user_can('manage_network'));
        wp_safe_redirect($url);
        exit;
    }

    public static function diagnostic_zip(): void
    {
        if (!self::can_save()) {
            wp_die(esc_html__('Permission denied.', 'rosenheinrich-multisite-migrate'));
        }
        check_admin_referer('rmmigrate_diagnostic_zip');
        if (!class_exists('ZipArchive')) {
            wp_die(esc_html__('ZipArchive is required for diagnostics export.', 'rosenheinrich-multisite-migrate'));
        }

        $tmp = Rmmigrate_Diagnostic_Export::build_temp_zip();
        if ($tmp === false) {
            wp_die(esc_html__('Could not create diagnostic ZIP.', 'rosenheinrich-multisite-migrate'));
        }

        Rmmigrate_Diagnostic_Export::stream_zip($tmp);
        Rmmigrate_Filesystem::delete($tmp);
        exit;
    }

    public static function delete_all_logs(): void
    {
        if (!self::can_save()) {
            wp_die(esc_html__('Permission denied.', 'rosenheinrich-multisite-migrate'));
        }
        check_admin_referer('rmmigrate_delete_all_logs');
        Rmmigrate_Activity_Log::clear_all();
        $url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-activity', array('log_cleared' => '1'), is_multisite() && current_user_can('manage_network'));
        wp_safe_redirect($url);
        exit;
    }

    /**
     * @param array<string,mixed> $merged
     * @param array<string,mixed> $current
     * @param array<string,string> $extra_args
     */
    private static function persist(array $merged, array $current, string $page, array $extra_args = array()): void
    {
        Rmmigrate_Settings::save($merged);
        $args = array_merge(array('updated' => '1'), $extra_args);
        $url = Rmmigrate_Admin_Router::admin_url($page, $args, is_multisite() && current_user_can('manage_network'));
        wp_safe_redirect($url);
        exit;
    }
}
