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
        <?php $rmmigrate_import_on_network = (!empty($rmmigrate_is_network) && is_multisite() && is_network_admin()); ?>
        <h2><?php echo esc_html($rmmigrate_import_on_network ? __('Move a network here, or restore a backup file', 'rosenheinrich-multisite-migrate') : __('Move a site here, or restore a backup file', 'rosenheinrich-multisite-migrate')); ?></h2>
        <ol class="mm-import-steps">
            <li><?php esc_html_e('On the old site: open Multisite Migrate → Backups and click Download.', 'rosenheinrich-multisite-migrate'); ?></li>
            <li><?php esc_html_e('Here: choose that file below.', 'rosenheinrich-multisite-migrate'); ?></li>
            <li><?php echo esc_html($rmmigrate_import_on_network ? __('Click Restore. Web addresses of all subsites are updated for this network automatically.', 'rosenheinrich-multisite-migrate') : __('Click Restore. Web addresses are updated for this site automatically.', 'rosenheinrich-multisite-migrate')); ?></li>
        </ol>
        <p id="mm-import-passphrase-wrap" class="mm-hidden">
            <label for="mm-import-passphrase"><?php esc_html_e('Archive passphrase (.venc)', 'rosenheinrich-multisite-migrate'); ?></label>
            <input type="password" class="regular-text" id="mm-import-passphrase" autocomplete="off">
        </p>
        <?php
        $rmmigrate_preflight_op = 'migration';
        include RMMIGRATE_PATH . 'admin/partials/components/preflight-ampel.php';
        ?>
        <div class="mm-dropzone" id="mm-import-dropzone">
            <span class="dashicons dashicons-upload mm-dropzone__icon" aria-hidden="true"></span>
            <strong><?php esc_html_e('Drop your backup here', 'rosenheinrich-multisite-migrate'); ?></strong>
            <span><?php esc_html_e('Use the backup file you downloaded (.zip, .daf or .venc). Large files are fine.', 'rosenheinrich-multisite-migrate'); ?></span>
            <input type="file" id="mm-import-local-file" accept=".zip,.daf,.venc" class="screen-reader-text">
            <label for="mm-import-local-file" class="button button-primary mm-btn-teal mm-dropzone__browse" id="mm-import-browse"><?php esc_html_e('Choose file', 'rosenheinrich-multisite-migrate'); ?></label>
        </div>
        <p><span id="mm-import-local-status"></span></p>
        <?php
        Rmmigrate_Pro_Hints::render('import_pro_cloud', array(
            'title'     => __('Import from the cloud, or move to an empty server (Pro)', 'rosenheinrich-multisite-migrate'),
            'text'      => __('Pro imports backups straight from Google Drive, Dropbox, S3 or a download link, and can set up your site on a new server that has no WordPress yet.', 'rosenheinrich-multisite-migrate'),
            'cta_label' => __('See what Pro adds', 'rosenheinrich-multisite-migrate'),
            'placement' => 'plugin_intent_migrate',
        ));
        ?>
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
        // Right after a restore or move finished: say so plainly instead of "not ready".
        $rmmigrate_restore_done_job = ($rmmigrate_imported_job_id > 0 && Rmmigrate_Request_Input::get_text('mm_restore_ok') === '1')
            ? Rmmigrate_Job::get($rmmigrate_imported_job_id)
            : null;
        $rmmigrate_restore_done = $rmmigrate_restore_done_job !== null
            && $rmmigrate_restore_done_job->is_restore()
            && $rmmigrate_restore_done_job->get_status() === Rmmigrate_Job::STATUS_COMPLETE;
        $rmmigrate_empty_title = $rmmigrate_import_restore_ready
            ? __('Backup archive ready to restore', 'rosenheinrich-multisite-migrate')
            : __('Import not ready to restore', 'rosenheinrich-multisite-migrate');
        $rmmigrate_empty_message = $rmmigrate_import_restore_ready
            ? __('Your backup file is uploaded and checked. Click Restore to put it on this site.', 'rosenheinrich-multisite-migrate')
            : __('No verified import archive is available yet. Upload a backup on the Import tab, then return here to restore.', 'rosenheinrich-multisite-migrate');
        $rmmigrate_empty_icon = 'dashicons-yes-alt';
        $rmmigrate_empty_actions = '';
        if ($rmmigrate_import_restore_ready) {
            $rmmigrate_empty_actions = '<button type="button" class="button button-primary mm-btn-teal mm-restore-backup" id="mm-import-restore-now" data-job-id="' . esc_attr((string) $rmmigrate_imported_job_id) . '">' . esc_html__('Restore this backup', 'rosenheinrich-multisite-migrate') . '</button>';
        }
        $rmmigrate_empty_actions .= '<a class="button button-secondary" href="' . esc_url(Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), $rmmigrate_is_network)) . '">' . esc_html__('View backups', 'rosenheinrich-multisite-migrate') . '</a>';
        if ($rmmigrate_restore_done) {
            $rmmigrate_empty_title = is_multisite()
                ? __('Done — the network is now on this server', 'rosenheinrich-multisite-migrate')
                : __('Done — the site is now on this server', 'rosenheinrich-multisite-migrate');
            $rmmigrate_empty_message = __('Everything from the backup was restored here and web addresses were updated automatically. Open the site to check it.', 'rosenheinrich-multisite-migrate');
            $rmmigrate_empty_actions = '<a class="button button-primary mm-btn-teal" href="' . esc_url(home_url('/')) . '">' . esc_html__('Visit site', 'rosenheinrich-multisite-migrate') . '</a>'
                . '<a class="button button-secondary" href="' . esc_url(Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), $rmmigrate_is_network)) . '">' . esc_html__('View backups', 'rosenheinrich-multisite-migrate') . '</a>';
        }
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
<?php if (class_exists('Rmmigrate_Expert_Mode', false) && Rmmigrate_Expert_Mode::enabled()) : // Upload tuning is for experts only. ?>
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
<?php endif; ?>
