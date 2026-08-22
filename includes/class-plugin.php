<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Free-edition bootstrap.
 *
 * Loads the shared engine (manual backup/restore, local import/export,
 * search-replace, AES-256 encryption, health dashboard, full multisite support)
 * and the free-only upsell surfaces. There is no license layer, no
 * auto-updater, and no Pro-only modules in this build.
 */
final class Rmmigrate_Plugin
{
    /** @var self|null */
    private static $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->load_dependencies();
    }

    private function require_if(string $path, ?string $class = null): void
    {
        if ($class !== null && class_exists($class)) {
            return;
        }
        if (is_file($path)) {
            require_once $path;
        }
    }

    private function load_dependencies(): void
    {
        $includes = RMMIGRATE_PATH . 'includes/';

        $this->require_if($includes . 'class-activator.php', 'Rmmigrate_Activator');
        $this->require_if($includes . 'class-deactivator.php', 'Rmmigrate_Deactivator');
        require_once $includes . 'class-upgrader.php';
        require_once $includes . 'class-capabilities.php';
        require_once $includes . 'class-settings.php';
        require_once $includes . 'class-schedules.php';
        require_once $includes . 'class-scheduler.php';
        require_once $includes . 'class-notifications.php';
        require_once $includes . 'class-access.php';
        require_once $includes . 'class-request-input.php';
        require_once $includes . 'class-time.php';
        require_once $includes . 'class-engine-config.php';
        require_once $includes . 'class-filesystem.php';
        require_once $includes . 'class-process.php';
        require_once $includes . 'class-io-helpers.php';
        require_once $includes . 'class-user-error-messages.php';
        require_once $includes . 'class-error-codes.php';
        require_once $includes . 'class-job-exception.php';
        require_once $includes . 'class-hosting-detection.php';
        require_once $includes . 'class-post-migration.php';
        require_once $includes . 'class-engine-maintenance.php';
        require_once $includes . 'class-log-rotator.php';
        require_once $includes . 'class-crypto.php';
        require_once $includes . 'class-path-safety.php';
        require_once $includes . 'class-logger.php';
        require_once $includes . 'class-local-storage.php';
        require_once $includes . 'class-multisite-scope.php';
        require_once $includes . 'class-backup-job.php';
        require_once $includes . 'class-job-cleanup.php';
        require_once $includes . 'class-build-steps.php';
        require_once $includes . 'class-restore-build-steps.php';
        require_once $includes . 'shared/progress.php';
        require_once $includes . 'shared/restore-topology/bootstrap.php';
        require_once $includes . 'class-manifest.php';
        require_once $includes . 'class-archive-index.php';
        require_once $includes . 'class-file-list.php';
        require_once $includes . 'class-snap-db.php';
        require_once $includes . 'class-mu-legacy.php';
        require_once $includes . 'class-db-dumper.php';
        require_once $includes . 'class-zip-archiver.php';
        require_once $includes . 'class-daf-archiver.php';
        require_once $includes . 'class-archive-factory.php';
        require_once $includes . 'class-archive-encryption.php';
        require_once $includes . 'class-destination-probe.php';
        require_once $includes . 'class-recovery-points.php';
        require_once $includes . 'class-local-archive.php';
        require_once $includes . 'class-links.php';
        require_once $includes . 'class-brand.php';
        require_once $includes . 'class-install-cleanup.php';
        require_once $includes . 'class-safety-snapshot.php';
        require_once $includes . 'class-health.php';
        require_once $includes . 'class-diagnostic-export.php';
        require_once $includes . 'shared/extract-engine.php';
        require_once $includes . 'shared/zip-extract-core.php';
        require_once $includes . 'class-zip-extractor.php';
        require_once $includes . 'class-archive-extractor.php';
        require_once $includes . 'class-search-replace.php';
        require_once $includes . 'class-post-import-search-replace.php';
        require_once $includes . 'class-sql-importer.php';
        require_once $includes . 'class-file-restorer.php';
        require_once $includes . 'class-backup-validator.php';
        require_once $includes . 'class-backup-runner.php';
        require_once $includes . 'class-restore-runner.php';
        require_once $includes . 'class-backup-retention.php';
        require_once $includes . 'class-activity-log.php';
        require_once $includes . 'admin/class-admin-router.php';
        require_once $includes . 'admin/class-admin-menu.php';
        require_once $includes . 'admin/class-admin-assets.php';
        require_once $includes . 'admin/class-wizard-controller.php';
        require_once $includes . 'admin/class-setup-wizard.php';
        require_once $includes . 'admin/class-admin-save.php';
        require_once $includes . 'admin/class-review-nudge.php';
        require_once $includes . 'admin/class-feedback.php';
        require_once $includes . 'admin/class-telemetry.php';
        require_once $includes . 'admin/class-ajax-base.php';
        require_once $includes . 'admin/class-ajax-backup.php';
        require_once $includes . 'admin/class-ajax-restore.php';
        require_once $includes . 'admin/class-ajax-import.php';
        require_once $includes . 'admin/class-ajax-activity.php';
        require_once $includes . 'admin/class-ajax-cleanup.php';
        require_once $includes . 'admin/class-ajax-handler.php';
        require_once $includes . 'admin/class-network-admin.php';
        require_once $includes . 'admin/class-site-admin.php';
        require_once $includes . 'admin/class-plugin-links.php';
        require_once $includes . 'admin/class-plugin-uninstall.php';
        require_once $includes . 'admin/class-upgrade-page.php';
        require_once $includes . 'admin/class-dashboard-data.php';
        require_once $includes . 'admin/class-dashboard-widget.php';
        require_once $includes . 'admin/class-pro-hints.php';
        require_once $includes . 'admin/class-admin-help-tip.php';
        require_once $includes . 'class-uninstaller.php';
        require_once $includes . 'services/class-service-exception.php';
        require_once $includes . 'services/class-job-preflight.php';
        require_once $includes . 'services/class-restore-service.php';
        if (!class_exists('Rmmigrate_Import_Service')) {
            require_once $includes . 'services/class-import-service.php';
        }
        require_once $includes . 'services/class-backup-service.php';
        require_once $includes . 'services/class-cleanup-service.php';
        require_once $includes . 'ai-agents/oauth/class-oauth-scopes.php';
        require_once $includes . 'ai-agents/oauth/class-oauth-store.php';
        require_once $includes . 'ai-agents/oauth/class-oauth-bearer-auth.php';
        require_once $includes . 'ai-agents/oauth/class-oauth-authorize-ui.php';
        require_once $includes . 'ai-agents/oauth/class-oauth-server.php';
        require_once $includes . 'ai-agents/class-ai-agents-rest.php';
        require_once $includes . 'abilities/class-abilities.php';
        require_once $includes . 'admin/class-mcp-page.php';
    }

    public function run(): void
    {
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        // WP 6.7+: load translations on init (not plugins_loaded) to avoid JIT notices.
        add_action('init', array($this, 'load_textdomain'), 0);
        add_action('plugins_loaded', array($this, 'init'));
    }

    /**
     * Bundled language files under languages/ (wp.org loads translations automatically).
     */
    public function load_textdomain(): void
    {
        // wp.org loads plugin translations automatically (Plugin Check discourages load_plugin_textdomain).
    }

    /**
     * @param array<string,array{interval:int,display:string}> $schedules
     * @return array<string,array{interval:int,display:string}>
     */
    public function add_cron_schedules(array $schedules): array
    {
        // Do not translate here — cron_schedules can run before init (WP 6.7 JIT notice).
        $schedules['rmmigrate_5min'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 Minutes',
        );
        return $schedules;
    }

    public function init(): void
    {
        Rmmigrate_Upgrader::maybe_upgrade();

        Rmmigrate_Admin_Router::register();
        Rmmigrate_Admin_Assets::register();
        Rmmigrate_Ajax_Handler::register();
        Rmmigrate_Hosting_Detection::register();
        Rmmigrate_Retention::register();
        Rmmigrate_Job_Cleanup::register();
        Rmmigrate_Scheduler::register();
        Rmmigrate_Admin_Save::register();
        Rmmigrate_Setup_Wizard::register();
        Rmmigrate_Review_Nudge::register();
        Rmmigrate_Feedback::register();
        Rmmigrate_Telemetry::register();
        Rmmigrate_Plugin_Links::register();
        Rmmigrate_Plugin_Uninstall::register();
        Rmmigrate_Upgrade_Page::register();
        Rmmigrate_Pro_Hints::register();
        Rmmigrate_Dashboard_Widget::register();
        Rmmigrate_Restore_Runner::register_maintenance_hooks();
        add_action('admin_init', array('Rmmigrate_Restore_Runner', 'recover_stale_maintenance'), 1);

        // Pro owns Ops-MCP when the commercial plugin is active (standalone or addon).
        if (!defined('RMMIGRATE_PRO_VERSION')) {
            Rmmigrate_OAuth_Store::ensure_tables();
            Rmmigrate_OAuth_Bearer_Auth::register();
            Rmmigrate_OAuth_Server::register();
            Rmmigrate_Ai_Agents_Rest::register();
            Rmmigrate_Abilities::register();
            Rmmigrate_Mcp_Page::register();
            add_action('init', array(__CLASS__, 'maybe_flush_oauth_rewrites'), 99);
        }

        if (is_multisite()) {
            Rmmigrate_Network_Admin::register();
            Rmmigrate_Site_Admin::register();
        } else {
            Rmmigrate_Site_Admin::register();
        }
    }

    /**
     * Flush OAuth discovery rewrite once after activation / schema install.
     */
    public static function maybe_flush_oauth_rewrites(): void
    {
        $flag = (int) get_site_option('rmmigrate_oauth_flush_rewrite', 0)
            + (int) get_option('rmmigrate_oauth_flush_rewrite', 0);
        if ($flag < 1) {
            return;
        }
        flush_rewrite_rules(false);
        delete_site_option('rmmigrate_oauth_flush_rewrite');
        delete_option('rmmigrate_oauth_flush_rewrite');
    }

    /**
     * Absolute path to wp-content/multisite-migrate-archives
     */
    public static function backups_dir(): string
    {
        if (isset($GLOBALS['rmmigrate_test_backup_dir'])) {
            return (string) $GLOBALS['rmmigrate_test_backup_dir'];
        }
        return Rmmigrate_Engine_Config::resolve_local_storage_path();
    }

    /**
     * Ensure backup root exists with protection files.
     */
    public static function ensure_backup_root(): bool
    {
        $dir = self::backups_dir();
        if (!Rmmigrate_Filesystem::ensure_directory($dir)) {
            return false;
        }
        foreach (array('logs', 'jobs', 'imports', 'recover') as $sub) {
            if (!Rmmigrate_Filesystem::ensure_directory($dir . '/' . $sub)) {
                return false;
            }
        }

        $htaccess = $dir . '/.htaccess';
        if (Rmmigrate_Engine_Config::storage_htaccess_enabled()) {
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
}
