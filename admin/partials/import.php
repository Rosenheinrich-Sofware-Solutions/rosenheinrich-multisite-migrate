<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_import_step = Rmmigrate_Request_Input::get_key('import_step', 'transfer');
if ($rmmigrate_import_step === 'source') {
    $rmmigrate_import_step = 'transfer';
}
$rmmigrate_import_subsite = !empty($rmmigrate_subsite_mode) || Rmmigrate_Access::is_subsite_admin_context();
?>

<div class="mm-form-section mm-import-wizard mm-import-wizard--direct">
    <?php if ($rmmigrate_import_step !== 'done') : ?>
        <h2><?php esc_html_e('Upload backup archive', 'rosenheinrich-multisite-migrate'); ?></h2>
        <p class="mm-import-source-lead"><?php esc_html_e('Upload a .zip, .daf, or .enc backup from your computer to continue.', 'rosenheinrich-multisite-migrate'); ?></p>
        <?php
        Rmmigrate_Pro_Hints::render('import_pro_cloud', array(
            'title'     => __('Remote import & empty-server migrate (Pro)', 'rosenheinrich-multisite-migrate'),
            'text'      => __('Pull archives from cloud/URL and run the empty-server installer with safe URL replace — fewer broken links after restore. Separate Multisite Migrate Pro plugin.', 'rosenheinrich-multisite-migrate'),
            'cta_label' => __('Explore Pro migrate tools', 'rosenheinrich-multisite-migrate'),
            'placement' => 'plugin_intent_migrate',
        ));
        ?>
        <p id="mm-import-passphrase-wrap" class="mm-hidden">
            <label for="mm-import-passphrase"><?php esc_html_e('Archive passphrase (.enc)', 'rosenheinrich-multisite-migrate'); ?></label>
            <input type="password" class="regular-text" id="mm-import-passphrase" autocomplete="off">
        </p>
        <div class="mm-dropzone" id="mm-import-dropzone">
            <span class="dashicons dashicons-upload mm-dropzone__icon" aria-hidden="true"></span>
            <strong><?php esc_html_e('Drop your backup here', 'rosenheinrich-multisite-migrate'); ?></strong>
            <span><?php esc_html_e('Supports .zip, .daf, .enc — large files upload in chunks.', 'rosenheinrich-multisite-migrate'); ?></span>
            <input type="file" id="mm-import-local-file" accept=".zip,.daf,.enc" class="screen-reader-text">
            <label for="mm-import-local-file" class="button button-primary mm-btn-teal mm-dropzone__browse" id="mm-import-browse"><?php esc_html_e('Choose file', 'rosenheinrich-multisite-migrate'); ?></label>
        </div>
        <p><span id="mm-import-local-status"></span></p>
        <div id="mm-import-chunk-progress" class="mm-hidden">
            <div class="mm-progress-bar"><div class="mm-progress-fill" style="width:0%"></div></div>
            <p class="mm-import-progress-text">0%</p>
        </div>
    <?php else : ?>
        <div class="mm-empty-section">
        <?php
        $rmmigrate_imported_job_id = Rmmigrate_Request_Input::get_int('job_id');
        $rmmigrate_import_restore_ready = false;
        if ($rmmigrate_imported_job_id > 0) {
            $rmmigrate_import_job = Rmmigrate_Job::get($rmmigrate_imported_job_id);
            if ($rmmigrate_import_job !== null
                && $rmmigrate_import_job->get_status() === Rmmigrate_Job::STATUS_COMPLETE
                && $rmmigrate_import_job->get_job_type() === Rmmigrate_Job::JOB_TYPE_BACKUP
                && (string) ($rmmigrate_import_job->data['triggered_by'] ?? '') === 'import'
                && Rmmigrate_Access::can_view_job($rmmigrate_import_job)
            ) {
                $rmmigrate_import_archive = trailingslashit(Rmmigrate_Plugin::backups_dir()) . ltrim($rmmigrate_import_job->get_local_path(), '/');
                if (Rmmigrate_Filesystem::is_file($rmmigrate_import_archive)) {
                    $rmmigrate_import_restore_ready = true;
                }
            }
        }
        $rmmigrate_empty_title = $rmmigrate_import_restore_ready
            ? __('Backup archive ready to restore', 'rosenheinrich-multisite-migrate')
            : __('Import not ready to restore', 'rosenheinrich-multisite-migrate');
        $rmmigrate_empty_message = $rmmigrate_import_restore_ready
            ? __('Your backup archive has been uploaded and verified. Click below to restore it to this site with one click.', 'rosenheinrich-multisite-migrate')
            : __('No verified import archive is available yet. Upload a backup on the Import tab, then return here to restore.', 'rosenheinrich-multisite-migrate');
        $rmmigrate_empty_icon = 'dashicons-yes-alt';
        $rmmigrate_empty_actions = '';
        if ($rmmigrate_import_restore_ready) {
            $rmmigrate_empty_actions = '<button type="button" class="button button-primary mm-btn-teal mm-restore-backup" id="mm-import-restore-now" data-job-id="' . esc_attr((string) $rmmigrate_imported_job_id) . '">' . esc_html__('Restore backup now', 'rosenheinrich-multisite-migrate') . '</button>';
        }
        $rmmigrate_empty_actions .= '<a class="button button-secondary" href="' . esc_url(Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), $rmmigrate_is_network)) . '">' . esc_html__('View backups', 'rosenheinrich-multisite-migrate') . '</a>';
        include RMMIGRATE_PATH . 'admin/partials/components/empty-state.php';
        include RMMIGRATE_PATH . 'admin/partials/restore-dialog.php';
        ?>
        </div>
        <div id="mm-import-restore-progress" class="mm-hidden mm-form-section">
            <h2><?php esc_html_e('Restore in progress', 'rosenheinrich-multisite-migrate'); ?></h2>
            <div class="mm-progress-bar"><div class="mm-progress-fill" id="mm-import-restore-fill" style="width:0%"></div></div>
            <p id="mm-import-restore-text">0%</p>
        </div>
    <?php endif; ?>
</div>

<?php
$rmmigrate_import_save_url = $rmmigrate_is_network
    ? network_admin_url('edit.php?action=rmmigrate_settings')
    : admin_url('admin-post.php');
?>
<p><button type="button" class="button-link" id="mm-toggle-import-settings"><?php esc_html_e('Show import settings', 'rosenheinrich-multisite-migrate'); ?></button></p>
<div class="mm-form-section mm-import-advanced mm-hidden" id="mm-import-settings-panel">
    <form method="post" action="<?php echo esc_url($rmmigrate_import_save_url); ?>">
        <?php wp_nonce_field('rmmigrate_settings'); ?>
        <input type="hidden" name="settings_tab" value="import">
        <?php if (!$rmmigrate_is_network) : ?>
            <input type="hidden" name="action" value="rmmigrate_settings">
        <?php endif; ?>
        <?php include RMMIGRATE_PATH . 'admin/partials/settings/tab-import.php'; ?>
        <?php submit_button(__('Save import settings', 'rosenheinrich-multisite-migrate'), 'secondary'); ?>
    </form>
</div>
