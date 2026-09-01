<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Admin_Assets
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_dashboard_widget'), 20);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'maybe_safe_mode'), 9999);
    }


    /**
     * Cache-bust admin assets by file mtime (falls back to plugin version).
     */
    private static function asset_ver(string $relative): string
    {
        $path = RMMIGRATE_PATH . ltrim($relative, '/');
        if (is_readable($path)) {
            $mtime = filemtime($path);
            if ($mtime !== false) {
                return (string) $mtime;
            }
        }

        return defined('RMMIGRATE_VERSION') ? (string) RMMIGRATE_VERSION : '1.0.0';
    }

    public static function enqueue_dashboard_widget(string $hook): void
    {
        if ($hook !== 'index.php' || !class_exists('Rmmigrate_Dashboard_Widget', false)) {
            return;
        }

        $is_network = is_multisite() && is_network_admin();
        if (!Rmmigrate_Dashboard_Widget::user_can_view($is_network)) {
            return;
        }

        wp_enqueue_style(
            'rmmigrate-tokens',
            RMMIGRATE_URL . 'assets/css/multisite-migrate-tokens.css',
            array(),
            self::asset_ver('assets/css/multisite-migrate-tokens.css')
        );
        wp_enqueue_style(
            'rmmigrate-dashboard-widget',
            RMMIGRATE_URL . 'assets/css/dashboard-widget.css',
            array('rmmigrate-tokens'),
            self::asset_ver('assets/css/dashboard-widget.css')
        );
    }

    public static function maybe_safe_mode(string $hook): void
    {
        if (strpos($hook, 'multisite-migrate') === false) {
            return;
        }
        if (!Rmmigrate_Engine_Config::admin_safe_mode()) {
            return;
        }

        global $wp_scripts, $wp_styles;
        if (!($wp_scripts instanceof WP_Scripts) || !($wp_styles instanceof WP_Styles)) {
            return;
        }

        $keep_scripts = array(
            'jquery', 'jquery-core', 'jquery-migrate', 'utils', 'common',
            'wp-hooks', 'wp-i18n', 'wp-util', 'wp-a11y', 'wp-dom-ready',
        );
        $keep_styles = array(
            'common', 'forms', 'admin-menu', 'dashboard', 'list-tables',
            'edit', 'revisions', 'media', 'themes', 'about', 'nav-menus',
            'widgets', 'site-icon', 'l10n', 'code-editor',
        );

        foreach (array('heartbeat', 'wp-auth-check') as $handle) {
            wp_dequeue_script($handle);
        }
        wp_dequeue_style('wp-auth-check');

        foreach ((array) $wp_scripts->queue as $handle) {
            if (strpos((string) $handle, 'rmmigrate') === 0) {
                continue;
            }
            if (in_array($handle, $keep_scripts, true)) {
                continue;
            }
            wp_dequeue_script($handle);
        }

        foreach ((array) $wp_styles->queue as $handle) {
            if (strpos((string) $handle, 'rmmigrate') === 0) {
                continue;
            }
            if (in_array($handle, $keep_styles, true)) {
                continue;
            }
            wp_dequeue_style($handle);
        }
    }

    public static function enqueue(string $hook, bool $is_network): void
    {
        if (strpos($hook, 'multisite-migrate') === false) {
            return;
        }

        wp_enqueue_style(
            'rmmigrate-tokens',
            RMMIGRATE_URL . 'assets/css/multisite-migrate-tokens.css',
            array(),
            self::asset_ver('assets/css/multisite-migrate-tokens.css')
        );

        $needs = Rmmigrate_Admin_Menu::script_needs($hook);
        $wizard_pages = array('setup', 'import-wizard', 'restore', 'backup-create');
        $needs_wizard_css = false;
        foreach ($wizard_pages as $need) {
            if (in_array($need, $needs, true)) {
                $needs_wizard_css = true;
                break;
            }
        }
        if (strpos($hook, 'multisite-migrate-advanced') !== false) {
            $needs_wizard_css = true;
        }
        wp_enqueue_style(
            'rmmigrate-components',
            RMMIGRATE_URL . 'assets/css/multisite-migrate-components.css',
            array('rmmigrate-tokens'),
            self::asset_ver('assets/css/multisite-migrate-components.css')
        );

        if ($needs_wizard_css) {
            wp_enqueue_style(
                'rmmigrate-wizard',
                RMMIGRATE_URL . 'assets/css/multisite-migrate-wizard.css',
                array('rmmigrate-tokens', 'rmmigrate-components'),
                self::asset_ver('assets/css/multisite-migrate-wizard.css')
            );
        }

        $admin_deps = array('rmmigrate-tokens', 'rmmigrate-components');
        if ($needs_wizard_css) {
            $admin_deps[] = 'rmmigrate-wizard';
        }

        wp_enqueue_style(
            'rmmigrate-admin',
            RMMIGRATE_URL . 'assets/css/admin.css',
            $admin_deps,
            self::asset_ver('assets/css/admin.css')
        );

        if (in_array('schedule', $needs, true)) {
            wp_enqueue_style(
                'rmmigrate-schedule',
                RMMIGRATE_URL . 'assets/css/schedule.css',
                array('rmmigrate-admin'),
                self::asset_ver('assets/css/schedule.css')
            );
        }

        wp_enqueue_script(
            'rmmigrate-ui',
            RMMIGRATE_URL . 'assets/js/multisite-migrate-ui.js',
            array('jquery'),
            self::asset_ver('assets/js/multisite-migrate-ui.js'),
            true
        );
        wp_enqueue_script(
            'rmmigrate-feedback',
            RMMIGRATE_URL . 'assets/js/feedback.js',
            array('jquery', 'rmmigrate-ui'),
            self::asset_ver('assets/js/feedback.js'),
            true
        );
        wp_enqueue_script(
            'rmmigrate-admin-ux',
            RMMIGRATE_URL . 'assets/js/admin-ux.js',
            array('jquery', 'rmmigrate-ui', 'rmmigrate-feedback'),
            self::asset_ver('assets/js/admin-ux.js'),
            true
        );
        wp_enqueue_script(
            'rmmigrate-backup-progress',
            RMMIGRATE_URL . 'assets/js/backup-progress.js',
            array('jquery', 'rmmigrate-ui', 'rmmigrate-feedback'),
            self::asset_ver('assets/js/backup-progress.js'),
            true
        );

        $lang_path = RMMIGRATE_PATH . 'languages';
        wp_set_script_translations('rmmigrate-ui', 'rosenheinrich-multisite-migrate', $lang_path);
        wp_set_script_translations('rmmigrate-admin-ux', 'rosenheinrich-multisite-migrate', $lang_path);
        wp_set_script_translations('rmmigrate-feedback', 'rosenheinrich-multisite-migrate', $lang_path);
        wp_set_script_translations('rmmigrate-backup-progress', 'rosenheinrich-multisite-migrate', $lang_path);

        if (in_array('restore', $needs, true)) {
            wp_enqueue_script(
                'rmmigrate-restore-wizard',
                RMMIGRATE_URL . 'assets/js/restore-wizard.js',
                array('jquery', 'rmmigrate-ui'),
                self::asset_ver('assets/js/restore-wizard.js'),
                true
            );
            wp_enqueue_script(
                'rmmigrate-restore-progress',
                RMMIGRATE_URL . 'assets/js/restore-progress.js',
                array('jquery', 'rmmigrate-ui', 'rmmigrate-restore-wizard', 'rmmigrate-feedback'),
                self::asset_ver('assets/js/restore-progress.js'),
                true
            );
        }
        if (in_array('setup', $needs, true)) {
            wp_enqueue_script(
                'rmmigrate-setup-wizard',
                RMMIGRATE_URL . 'assets/js/setup-wizard.js',
                array('jquery', 'rmmigrate-ui'),
                self::asset_ver('assets/js/setup-wizard.js'),
                true
            );
        }
        if (in_array('import-wizard', $needs, true)) {
            wp_enqueue_script(
                'rmmigrate-import-wizard',
                RMMIGRATE_URL . 'assets/js/import-wizard.js',
                array('jquery', 'rmmigrate-ui', 'rmmigrate-feedback'),
                self::asset_ver('assets/js/import-wizard.js'),
                true
            );
        }

        if (in_array('admin-tools', $needs, true)) {
            wp_enqueue_script(
                'rmmigrate-admin-tools',
                RMMIGRATE_URL . 'assets/js/admin-tools.js',
                array('jquery', 'rmmigrate-ui'),
                self::asset_ver('assets/js/admin-tools.js'),
                true
            );
            wp_enqueue_script(
                'rmmigrate-settings-privacy',
                RMMIGRATE_URL . 'assets/js/settings-privacy.js',
                array('jquery', 'rmmigrate-ui'),
                self::asset_ver('assets/js/settings-privacy.js'),
                true
            );
            wp_set_script_translations('rmmigrate-settings-privacy', 'rosenheinrich-multisite-migrate', $lang_path);
        }
        if (in_array('activity-log', $needs, true)) {
            wp_enqueue_script(
                'rmmigrate-activity-log',
                RMMIGRATE_URL . 'assets/js/activity-log.js',
                array('jquery', 'rmmigrate-ui'),
                self::asset_ver('assets/js/activity-log.js'),
                true
            );
        }
        if (in_array('schedule', $needs, true)) {
            wp_enqueue_script(
                'rmmigrate-schedule-form',
                RMMIGRATE_URL . 'assets/js/schedule-form.js',
                array('jquery'),
                self::asset_ver('assets/js/schedule-form.js'),
                true
            );
        }

        $settings = Rmmigrate_Settings::get();
        $is_network_admin = $is_network;
        $highlight_job_id = Rmmigrate_Request_Input::get_int('job_id');
        $active_job = Rmmigrate_Job::resolve_admin_active_job($is_network, $highlight_job_id);
        /* translators: 1: source URL, 2: replacement URL. */
        $sr_preview_template = __('Would replace "%1$s" with "%2$s" during migration restore.', 'rosenheinrich-multisite-migrate');
        /* translators: %1$d: number of log lines shown. */
        $showing_lines = __('Showing %1$d lines', 'rosenheinrich-multisite-migrate');
        $scope_subsite_label = __('Subsite only', 'rosenheinrich-multisite-migrate');
        if (is_multisite()) {
            $details = get_blog_details((int) get_current_blog_id());
            if ($details && !empty($details->blogname)) {
                $scope_subsite_label .= ': ' . $details->blogname;
            } elseif ($details && !empty($details->domain)) {
                $scope_subsite_label .= ': ' . $details->domain;
            }
        }

        $server_max = (int) wp_max_upload_size();
        $safe_max = $server_max > 0
            ? min((int) max(131072, (int) floor($server_max / 2)), $server_max)
            : 524288;

        $localize = array(
            'ajaxUrl'             => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('rmmigrate_admin'),
            'siteUrl'             => home_url('/'),
            'setupDismissAction'  => 'rmmigrate_setup_wizard_dismiss_banner',
            'activityDetailAction'=> 'rmmigrate_activity_detail',
            'activityListAction'  => 'rmmigrate_activity_list',
            'logChunkAction'      => 'rmmigrate_log_chunk',
            'isNetwork'       => $is_network,
            'scope'           => is_multisite() ? Rmmigrate_Multisite_Scope::SCOPE_SUBSITE : Rmmigrate_Multisite_Scope::SCOPE_NETWORK,
            'scopeSubsiteLabel' => $scope_subsite_label,
            'confirmDelete'   => __('Delete this backup?', 'rosenheinrich-multisite-migrate'),
            'confirmDeleteBulk' => __('Delete the selected backups?', 'rosenheinrich-multisite-migrate'),
            'deletingBackup'  => __('Deleting…', 'rosenheinrich-multisite-migrate'),
            'importChunkSize' => (int) min(
                (int) ($settings['import_chunk_size'] ?? 524288),
                $safe_max
            ),
            'topologyDestination' => array(
                'is_multisite'          => is_multisite(),
                'is_multisite_subsite'  => is_multisite() && (int) get_current_blog_id() > 1,
                'destination_blog_id'   => is_multisite() ? (int) get_current_blog_id() : 1,
            ),
            'markUrl'         => Rmmigrate_Brand::mark_url(),
            'backupsUrl'      => Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), $is_network_admin),
            'setupUrl'        => Rmmigrate_Admin_Router::admin_url('multisite-migrate-setup', array(), $is_network_admin),
            'importDoneUrl'   => Rmmigrate_Admin_Router::admin_url('multisite-migrate-migrate', array('tab' => 'import', 'import_step' => 'done', 'imported' => '1'), $is_network_admin),
            'toolsUrl'        => Rmmigrate_Admin_Router::admin_url('multisite-migrate-activity', array(), $is_network_admin),
            'activityUrl'     => Rmmigrate_Admin_Router::admin_url('multisite-migrate-activity', array(), $is_network_admin),
            'failedJobsUrl'   => Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array('filter' => 'failed'), $is_network_admin),
            'loadingText'     => __('Loading…', 'rosenheinrich-multisite-migrate'),
            'kickoffMode'     => Rmmigrate_Hosting_Detection::effective_kickoff_mode(),
            'activeJob'       => null,
            'i18n'            => array(
                'cancel'              => __('Cancel', 'rosenheinrich-multisite-migrate'),
                'confirm'             => __('Confirm', 'rosenheinrich-multisite-migrate'),
                'pruneConfirm'        => __('Delete old backups according to retention rules?', 'rosenheinrich-multisite-migrate'),
                'cleanStorageConfirm' => __('Delete orphaned job work folders and partial uploads? Finished backups are kept.', 'rosenheinrich-multisite-migrate'),
                /* translators: %d: number of paths */
                'installCleanupConfirm' => __('Delete %d installation file path(s) from the web root? This cannot be undone.', 'rosenheinrich-multisite-migrate'),
                'installCleanupNone'  => __('No installation files detected.', 'rosenheinrich-multisite-migrate'),
                /* translators: %d: number of paths */
                'installCleanupFound' => __('Found %d installation file path(s).', 'rosenheinrich-multisite-migrate'),
                'installCleanupScanning' => __('Scanning for installation files…', 'rosenheinrich-multisite-migrate'),
                'installCleanupDeleting' => __('Deleting installation files…', 'rosenheinrich-multisite-migrate'),
                'installCleanupFailed' => __('Could not delete installation files.', 'rosenheinrich-multisite-migrate'),
                'installCleanupScanFailed' => __('Could not scan for installation files.', 'rosenheinrich-multisite-migrate'),
                'enterNewUrl'         => __('Enter a new URL.', 'rosenheinrich-multisite-migrate'),
                'enterNewSiteUrl'     => __('Enter a valid new site URL (https://…).', 'rosenheinrich-multisite-migrate'),
                'srPreviewTemplate'   => $sr_preview_template,
                'srNoPairs'           => __('No replacement pairs generated for these URLs.', 'rosenheinrich-multisite-migrate'),
                'failed'              => __('Failed', 'rosenheinrich-multisite-migrate'),
                'done'                => __('Done', 'rosenheinrich-multisite-migrate'),
                'requestFailed'       => __('Request failed', 'rosenheinrich-multisite-migrate'),
                'saving'              => __('Saving…', 'rosenheinrich-multisite-migrate'),
                'telemetryEnabled'    => __('Telemetry enabled.', 'rosenheinrich-multisite-migrate'),
                'telemetryDisabled'   => __('Telemetry disabled.', 'rosenheinrich-multisite-migrate'),
                'saveFailed'          => __('Save failed', 'rosenheinrich-multisite-migrate'),
                'setupComplete'       => __('Setup complete', 'rosenheinrich-multisite-migrate'),
                'activityDetails'     => __('Activity details', 'rosenheinrich-multisite-migrate'),
                'details'             => __('Details', 'rosenheinrich-multisite-migrate'),
                /* translators: %d: number of process events bundled into this row */
                'bundledEventsHint'   => __('(%d events — open Details)', 'rosenheinrich-multisite-migrate'),
                /* translators: 1: event type label, 2: job id */
                'activityJobTitle'    => __('%1$s job #%2$d', 'rosenheinrich-multisite-migrate'),
                'eventSummary'        => __('Event summary', 'rosenheinrich-multisite-migrate'),
                'file'                => __('File', 'rosenheinrich-multisite-migrate'),
                'uploadId'            => __('Upload ID', 'rosenheinrich-multisite-migrate'),
                'event'               => __('Event', 'rosenheinrich-multisite-migrate'),
                'type'                => __('Type', 'rosenheinrich-multisite-migrate'),
                'status'              => __('Status', 'rosenheinrich-multisite-migrate'),
                'activityDate'        => __('Activity date', 'rosenheinrich-multisite-migrate'),
                'pluginVersion'       => __('Plugin version', 'rosenheinrich-multisite-migrate'),
                'jobSummary'          => __('Job summary', 'rosenheinrich-multisite-migrate'),
                'destination'         => __('Destination', 'rosenheinrich-multisite-migrate'),
                'size'                => __('Size', 'rosenheinrich-multisite-migrate'),
                'duration'            => __('Duration', 'rosenheinrich-multisite-migrate'),
                'databaseArchive'     => __('Database / archive', 'rosenheinrich-multisite-migrate'),
                'phpEnvironment'      => __('PHP environment', 'rosenheinrich-multisite-migrate'),
                'jobLog'              => __('Job log', 'rosenheinrich-multisite-migrate'),
                'message'             => __('Message', 'rosenheinrich-multisite-migrate'),
                'errorContext'        => __('Error context', 'rosenheinrich-multisite-migrate'),
                'viewCompleteLog'     => __('View complete log file', 'rosenheinrich-multisite-migrate'),
                'showingLines'        => $showing_lines,
                'loadNewer'           => __('Load newer', 'rosenheinrich-multisite-migrate'),
                'loadOlder'           => __('Load older', 'rosenheinrich-multisite-migrate'),
                /* translators: %s: rotated log filename */
                'continueIn'          => __('Continue in %s', 'rosenheinrich-multisite-migrate'),
                'enterNewHomeUrl'     => __('Enter a valid new home URL or check “Same as site URL”.', 'rosenheinrich-multisite-migrate'),
                'yes'                 => __('Yes', 'rosenheinrich-multisite-migrate'),
                'no'                  => __('No', 'rosenheinrich-multisite-migrate'),
                'scopeNetwork'        => __('Full network', 'rosenheinrich-multisite-migrate'),
                'scopeIncluded'       => __('Only selected subsites', 'rosenheinrich-multisite-migrate'),
                'scopeFiltered'       => __('Network (exclude subsites)', 'rosenheinrich-multisite-migrate'),
                'scopeSubsite'        => __('Subsite only', 'rosenheinrich-multisite-migrate'),
                'labelScope'          => __('Scope', 'rosenheinrich-multisite-migrate'),
                'labelProfile'        => __('Profile', 'rosenheinrich-multisite-migrate'),
                'labelArchiveFormat'  => __('Archive format', 'rosenheinrich-multisite-migrate'),
                'labelIncludeWpCore'  => __('Include WordPress core', 'rosenheinrich-multisite-migrate'),
                'labelDestination'    => __('Destination', 'rosenheinrich-multisite-migrate'),
                'destLocal'           => __('Local — on this server', 'rosenheinrich-multisite-migrate'),
                'edit'                => __('Edit', 'rosenheinrich-multisite-migrate'),
                'selectSubsiteInclude' => __('Select at least one subsite to include.', 'rosenheinrich-multisite-migrate'),
                'selectSubsiteExclude' => __('Select at least one subsite to exclude, or choose Full network.', 'rosenheinrich-multisite-migrate'),
                'cannotExcludeAll'    => __('Cannot exclude every subsite. Use “Only selected subsites” to back up specific sites.', 'rosenheinrich-multisite-migrate'),
                'subsiteSearchEmpty'  => __('No subsites matched your search.', 'rosenheinrich-multisite-migrate'),
                'subsiteSearchAdd'    => __('Add', 'rosenheinrich-multisite-migrate'),
                'backupComplete'      => __('Backup complete', 'rosenheinrich-multisite-migrate'),
                'backupFailed'        => __('Backup failed', 'rosenheinrich-multisite-migrate'),
                /* translators: %d: progress percentage */
                'inProgressPct'       => __('In progress (%d%%)', 'rosenheinrich-multisite-migrate'),
                'complete'            => __('Complete', 'rosenheinrich-multisite-migrate'),
                'createBlockedByBackup' => __('A backup is already in progress. Wait for it to finish or cancel it before starting a new backup.', 'rosenheinrich-multisite-migrate'),
                'createBlockedByRestore' => __('A restore is already in progress. Wait for it to finish or cancel it before starting a new backup.', 'rosenheinrich-multisite-migrate'),
                'failedToStartBackup' => __('Failed to start backup', 'rosenheinrich-multisite-migrate'),
                'starting'            => __('Starting…', 'rosenheinrich-multisite-migrate'),
                'workerFailed'        => __('Backup worker stopped responding. Refresh the page or cancel and try again.', 'rosenheinrich-multisite-migrate'),
                'workerStalled'       => __('Backup is taking longer than expected. Keep this tab open or refresh to resume.', 'rosenheinrich-multisite-migrate'),
                'workerWaiting'       => __('Another operation is running — waiting…', 'rosenheinrich-multisite-migrate'),
                'cancelRestoreConfirm' => __('Cancel restore? The site may be left in an inconsistent state.', 'rosenheinrich-multisite-migrate'),
                'error'               => __('Error', 'rosenheinrich-multisite-migrate'),
                'uploading'           => __('Uploading…', 'rosenheinrich-multisite-migrate'),
                'importFailed'        => __('Import failed', 'rosenheinrich-multisite-migrate'),
                'importPassphraseRequired' => __('Enter the archive passphrase for .venc files.', 'rosenheinrich-multisite-migrate'),
                'noImportedBackup'    => __('No imported backup found', 'rosenheinrich-multisite-migrate'),
                'importRestoreConfirm' => __('Restore will overwrite your current site with the imported backup. Continue?', 'rosenheinrich-multisite-migrate'),
                'restoreComplete'     => __('Restore complete', 'rosenheinrich-multisite-migrate'),
                'restoreFailed'       => __('Restore failed', 'rosenheinrich-multisite-migrate'),
                'restoreInProgress'   => __('Restore in progress', 'rosenheinrich-multisite-migrate'),
                'restoreRequestFailed' => __('Restore request failed. Refresh and try again.', 'rosenheinrich-multisite-migrate'),
                'creatingSafetySnapshot' => __('Creating safety snapshot…', 'rosenheinrich-multisite-migrate'),
                'quickRestoreConfirm' => __('This will overwrite your current site with the selected backup. A safety snapshot will be created first. Continue?', 'rosenheinrich-multisite-migrate'),
                'prefetchFailed'      => __('Could not prepare the backup for restore.', 'rosenheinrich-multisite-migrate'),
                'manifestIncomplete'  => __('Archive metadata is missing — verify migration URLs manually.', 'rosenheinrich-multisite-migrate'),
                'installTypeMismatch' => __('Backup multisite install type (subdomain vs subdirectory) differs from this network — verify URLs and paths before restoring.', 'rosenheinrich-multisite-migrate'),
                'confirmOverwrite'    => __('Please confirm you understand data will be overwritten.', 'rosenheinrich-multisite-migrate'),
                'restoreCompletePct'  => __('100% — Complete', 'rosenheinrich-multisite-migrate'),
                'viewActivityDetails' => __('view Activity Log for details', 'rosenheinrich-multisite-migrate'),
                'jobFailed'           => __('Job failed', 'rosenheinrich-multisite-migrate'),
                'safetySnapshotFailed' => __('Safety snapshot failed', 'rosenheinrich-multisite-migrate'),
                'connectionTestFailed' => __('Connection test failed', 'rosenheinrich-multisite-migrate'),
                'clearActivityLog'    => __('Clear activity log and all log files on disk?', 'rosenheinrich-multisite-migrate'),
                'subsiteColumn'       => __('Subsite', 'rosenheinrich-multisite-migrate'),
                'oldUrlColumn'        => __('Old URL', 'rosenheinrich-multisite-migrate'),
                'newUrlColumn'        => __('New URL', 'rosenheinrich-multisite-migrate'),
                'srNoPairsShort'      => __('No replacement pairs for these URLs.', 'rosenheinrich-multisite-migrate'),
                'showImportSettings'  => __('Show import settings', 'rosenheinrich-multisite-migrate'),
                'hideImportSettings'  => __('Hide import settings', 'rosenheinrich-multisite-migrate'),
                'loading'             => __('Loading…', 'rosenheinrich-multisite-migrate'),
                'enabled'             => __('Enabled', 'rosenheinrich-multisite-migrate'),
                'notSet'              => __('Not set', 'rosenheinrich-multisite-migrate'),
                'reviewLabelType'     => __('Type', 'rosenheinrich-multisite-migrate'),
                'reviewLabelMode'     => __('Mode', 'rosenheinrich-multisite-migrate'),
                'reviewLabelSafetySnapshot' => __('Safety snapshot', 'rosenheinrich-multisite-migrate'),
                'reviewLabelNewSiteUrl' => __('New site URL', 'rosenheinrich-multisite-migrate'),
                'restoreTypeMigration' => __('Migration', 'rosenheinrich-multisite-migrate'),
                'restoreTypeSameServer' => __('Same server', 'rosenheinrich-multisite-migrate'),
                'restoreModeBoth'     => __('Database and files', 'rosenheinrich-multisite-migrate'),
                'restoreModeDb'       => __('Database only', 'rosenheinrich-multisite-migrate'),
                'restoreModeFiles'    => __('Files only', 'rosenheinrich-multisite-migrate'),
                'copied'              => __('Copied', 'rosenheinrich-multisite-migrate'),
                'close'               => __('Close', 'rosenheinrich-multisite-migrate'),
                'dismiss'             => __('Dismiss', 'rosenheinrich-multisite-migrate'),
                'testBackupComplete'  => __('Test backup complete', 'rosenheinrich-multisite-migrate'),
                /* translators: %s: completion message */
                'progressCompleteLabel' => __('100% — %s', 'rosenheinrich-multisite-migrate'),
                /* translators: %d: number of additional URL replacement pairs */
                'srMoreOverflow'      => __('… +%d more', 'rosenheinrich-multisite-migrate'),
            ),
        );
        $localize['restoreStepLabels'] = Rmmigrate_Restore_Runner::restore_step_labels();
        if ($active_job !== null) {
            $localize['activeJob'] = array(
                'id'      => $active_job->get_id(),
                'type'    => $active_job->get_job_type(),
                'percent' => (int) $active_job->get_percent(),
                'message' => $active_job->is_restore()
                    ? (string) Rmmigrate_Restore_Runner::admin_progress_label($active_job)
                    : (string) $active_job->get_progress_message(),
                'progress_step' => $active_job->is_restore()
                    ? Rmmigrate_Restore_Runner::get_status_step($active_job)
                    : '',
            );
        }

        $localize['feedback'] = Rmmigrate_Feedback::localize();

        wp_localize_script('rmmigrate-ui', 'rmmigrateAdmin', $localize);
        wp_localize_script(
            'rmmigrate-admin-ux',
            'rmmigrateAdminUx',
            array(
                'ajaxUrl'            => admin_url('admin-ajax.php'),
                'nonce'              => wp_create_nonce('rmmigrate_admin'),
                'setupDismissAction' => 'rmmigrate_setup_wizard_dismiss_banner',
            )
        );
        wp_add_inline_script(
            'rmmigrate-admin-ux',
            'document.getElementById("rmmigrate-test-email")&&document.getElementById("rmmigrate-test-email").addEventListener("submit",function(){var s=document.getElementById("email_address"),d=this.querySelector(\'input[name="email_address"]\');if(s&&d){d.value=s.value;}});'
        );
    }
}

