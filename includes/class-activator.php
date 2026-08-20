<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Activator
{
    /**
     * @param bool $network_wide Whether the plugin is being network-activated.
     */
    public static function activate($network_wide = false): void
    {
        unset($network_wide);

        ob_start();
        self::run_activate();
        ob_end_clean();
    }

    /**
     * Create or upgrade plugin-owned database tables via dbDelta.
     * Call from activation / version-gated upgrade only — not every request.
     */
    public static function ensure_schema(): void
    {
        if (!function_exists('dbDelta') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        global $wpdb;

        $table = $wpdb->base_prefix . 'rmmigrate_jobs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            scope VARCHAR(20) NOT NULL DEFAULT 'network',
            job_type VARCHAR(10) NOT NULL DEFAULT 'backup',
            source_job_id BIGINT UNSIGNED NULL,
            restore_mode VARCHAR(10) NULL,
            restore_type VARCHAR(15) NOT NULL DEFAULT 'same_server',
            migration_map LONGTEXT NULL,
            backup_profile VARCHAR(10) NOT NULL DEFAULT 'full',
            backup_type VARCHAR(15) NOT NULL DEFAULT 'full',
            parent_job_id BIGINT UNSIGNED NULL,
            is_safety TINYINT(1) NOT NULL DEFAULT 0,
            destination VARCHAR(20) NOT NULL DEFAULT 'local',
            status SMALLINT NOT NULL DEFAULT 0,
            progress LONGTEXT NULL,
            excluded_blogs TEXT NULL,
            local_path VARCHAR(512) NULL,
            file_size BIGINT UNSIGNED NULL,
            error_message TEXT NULL,
            triggered_by VARCHAR(20) NOT NULL DEFAULT 'manual',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY blog_id (blog_id),
            KEY job_type (job_type),
            KEY created_at (created_at),
            KEY backup_type (backup_type),
            KEY is_safety (is_safety)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    private static function run_activate(): void
    {
        require_once RMMIGRATE_PATH . 'includes/class-access.php';
        require_once RMMIGRATE_PATH . 'includes/class-settings.php';
        require_once RMMIGRATE_PATH . 'includes/class-filesystem.php';

        self::ensure_schema();

        require_once RMMIGRATE_PATH . 'includes/ai-agents/oauth/class-oauth-store.php';
        Rmmigrate_OAuth_Store::maybe_install_tables();
        update_site_option('rmmigrate_oauth_flush_rewrite', 1);
        update_option('rmmigrate_oauth_flush_rewrite', 1);

        if (!get_site_option('rmmigrate_settings')) {
            update_site_option('rmmigrate_settings', Rmmigrate_Settings::defaults());
        }

        /** Paid editions seed their license storage defaults on activation. */
        do_action('rmmigrate_activate_seed_defaults');

        require_once RMMIGRATE_PATH . 'includes/admin/class-review-nudge.php';
        Rmmigrate_Review_Nudge::ensure_first_activated_timestamp();

        require_once RMMIGRATE_PATH . 'includes/admin/class-setup-wizard.php';
        Rmmigrate_Setup_Wizard::queue_post_activation_redirect();

        require_once RMMIGRATE_PATH . 'includes/class-engine-config.php';
        // Activation runs before plugins_loaded — Scheduler is not loaded yet.
        require_once RMMIGRATE_PATH . 'includes/class-scheduler.php';

        self::ensure_backup_root();

        // Register 5-minute interval before scheduling (Plugin::add_cron_schedules not hooked yet).
        add_filter('cron_schedules', array(__CLASS__, 'register_cron_schedules'));

        if (!wp_next_scheduled('rmmigrate_deferred_hosting_detect')) {
            wp_schedule_single_event(time() + 30, 'rmmigrate_deferred_hosting_detect');
        }
        if (!wp_next_scheduled(Rmmigrate_Scheduler::HOOK)) {
            wp_schedule_event(time(), 'rmmigrate_5min', Rmmigrate_Scheduler::HOOK);
        }
    }

    /**
     * Cron interval used on activation (Plugin cron_schedules filter not registered yet).
     *
     * @param array<string,array{interval:int,display:string}> $schedules
     * @return array<string,array{interval:int,display:string}>
     */
    public static function register_cron_schedules(array $schedules): array
    {
        $interval = defined('MINUTE_IN_SECONDS') ? (5 * MINUTE_IN_SECONDS) : 300;
        $schedules['rmmigrate_5min'] = array(
            'interval' => $interval,
            // Do not translate — can run before init (WP 6.7+ JIT notice).
            'display'  => 'Every 5 Minutes',
        );

        return $schedules;
    }

    private static function ensure_backup_root(): bool
    {
        $dir = self::resolve_backup_dir();
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        foreach (array('logs', 'jobs') as $sub) {
            wp_mkdir_p($dir . '/' . $sub);
        }

        $settings = get_site_option('rmmigrate_settings', array());
        $htaccess_enabled = is_array($settings) && !empty($settings['storage_htaccess']);

        $htaccess = $dir . '/.htaccess';
        if ($htaccess_enabled) {
            if (!Rmmigrate_Filesystem::exists($htaccess)) {
                $rules = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
                Rmmigrate_Filesystem::put_contents($htaccess, $rules);
            }
        } elseif (Rmmigrate_Filesystem::exists($htaccess)) {
            Rmmigrate_Filesystem::delete($htaccess);
        }

        $index = $dir . '/index.php';
        if (!Rmmigrate_Filesystem::exists($index)) {
            Rmmigrate_Filesystem::put_contents($index, "<?php\nif (!defined('ABSPATH')) {\n    exit;\n}\n// Silence is golden.\n");
        }

        return true;
    }

    private static function resolve_backup_dir(): string
    {
        if (isset($GLOBALS['rmmigrate_test_backup_dir']) && is_string($GLOBALS['rmmigrate_test_backup_dir']) && $GLOBALS['rmmigrate_test_backup_dir'] !== '') {
            return wp_normalize_path($GLOBALS['rmmigrate_test_backup_dir']);
        }

        $settings = get_site_option('rmmigrate_settings', array());
        $custom = is_array($settings) ? trim((string) ($settings['local_storage_path'] ?? '')) : '';
        if ($custom === '') {
            // Default to the uploads directory per the WordPress plugin guidelines.
            return Rmmigrate_Engine_Config::default_local_storage_path();
        }
        if ($custom[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $custom)) {
            return wp_normalize_path($custom);
        }

        $basedir = Rmmigrate_Engine_Config::uploads_basedir();

        return wp_normalize_path(trailingslashit($basedir) . ltrim($custom, '/'));
    }
}
