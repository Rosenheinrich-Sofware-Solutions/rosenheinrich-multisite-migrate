<?php
if (!defined('ABSPATH')) {
    exit;
}
$rmmigrate_docs_restore = Rmmigrate_Capabilities::marketing_url(
    Rmmigrate_Capabilities::docs_restore_url(),
    'recovery_guide'
);
$rmmigrate_docs_hub = Rmmigrate_Capabilities::marketing_url(
    Rmmigrate_Capabilities::docs_url(),
    'recovery_docs_hub'
);
$rmmigrate_recovery_is_network = isset($rmmigrate_is_network)
    ? !empty($rmmigrate_is_network)
    : (is_multisite() && is_network_admin());
$rmmigrate_rollback_job = Rmmigrate_Safety_Snapshot::get_latest_rollback_job(
    Rmmigrate_Access::is_subsite_scoped_operation() ? get_current_blog_id() : null
);
$rmmigrate_recent_recovery = Rmmigrate_Recovery_Points::get_recent($rmmigrate_recovery_is_network);
$rmmigrate_recovery_import_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-migrate', array('tab' => 'import'), $rmmigrate_recovery_is_network);
$rmmigrate_recovery_archives_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), $rmmigrate_recovery_is_network);
$rmmigrate_recovery_listed = 0;
?>
<div class="mm-form-section mm-recovery-docs">
    <h2><?php esc_html_e('Recovery', 'rosenheinrich-multisite-migrate'); ?></h2>
    <p class="description"><?php esc_html_e('Restore guides — same-server rollback, advanced import, empty-server migration, multisite domain mapping, and disaster recovery — are on the documentation site.', 'rosenheinrich-multisite-migrate'); ?></p>
    <p>
        <a class="button button-primary mm-btn-teal" href="<?php echo esc_url($rmmigrate_docs_restore); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Read the restore guide', 'rosenheinrich-multisite-migrate'); ?></a>
        <a class="button" href="<?php echo esc_url($rmmigrate_docs_hub); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('All documentation', 'rosenheinrich-multisite-migrate'); ?></a>
    </p>

    <div class="mm-emergency-restore-section" role="region" aria-labelledby="mm-emergency-restore-title">
        <h3 id="mm-emergency-restore-title"><?php esc_html_e('Emergency restore and rollback', 'rosenheinrich-multisite-migrate'); ?></h3>
        <?php if ($rmmigrate_rollback_job instanceof Rmmigrate_Job) : ?>
            <div class="mm-rollback-card">
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: safety backup job ID */
                            __('A safety snapshot (#%d) was saved just before the last restore. You can roll back to it now.', 'rosenheinrich-multisite-migrate'),
                            $rmmigrate_rollback_job->get_id()
                        )
                    );
                    ?>
                </p>
                <p>
                    <button type="button" class="button button-primary mm-btn-teal mm-rollback-last-restore" data-safety-job-id="<?php echo esc_attr((string) $rmmigrate_rollback_job->get_id()); ?>">
                        <?php esc_html_e('Roll back last restore', 'rosenheinrich-multisite-migrate'); ?>
                    </button>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!empty($rmmigrate_recent_recovery)) : ?>
            <ul class="mm-recovery-points-list">
                <?php foreach ($rmmigrate_recent_recovery as $rmmigrate_rp_job) : ?>
                    <?php if (!Rmmigrate_Recovery_Points::is_restorable($rmmigrate_rp_job)) {
                        continue;
                    }
                    $rmmigrate_recovery_listed++;
                    ?>
                    <li class="mm-recovery-points-list__item">
                        <span class="mm-recovery-points-list__label">
                            <?php
                            echo esc_html(
                                sprintf(
                                    '#%1$d — %2$s (%3$s)',
                                    $rmmigrate_rp_job->get_id(),
                                    $rmmigrate_rp_job->get_display_scope(),
                                    size_format((int) $rmmigrate_rp_job->get_archive_size())
                                )
                            );
                            ?>
                        </span>
                        <button type="button" class="button mm-restore-backup" data-job-id="<?php echo esc_attr((string) $rmmigrate_rp_job->get_id()); ?>" aria-label="<?php echo esc_attr(sprintf(
                            /* translators: %d: backup job ID */
                            __('Emergency restore from backup #%d', 'rosenheinrich-multisite-migrate'),
                            $rmmigrate_rp_job->get_id()
                        )); ?>">
                            <?php esc_html_e('Emergency restore', 'rosenheinrich-multisite-migrate'); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($rmmigrate_recovery_listed === 0 && !($rmmigrate_rollback_job instanceof Rmmigrate_Job)) : ?>
            <p class="description"><?php esc_html_e('There is no backup on this server to restore yet. Create a backup now, or import a backup file from another server.', 'rosenheinrich-multisite-migrate'); ?></p>
            <p>
                <a class="button" href="<?php echo esc_url($rmmigrate_recovery_archives_url); ?>"><?php esc_html_e('Create a backup', 'rosenheinrich-multisite-migrate'); ?></a>
                <a class="button" href="<?php echo esc_url($rmmigrate_recovery_import_url); ?>"><?php esc_html_e('Import a backup file', 'rosenheinrich-multisite-migrate'); ?></a>
            </p>
        <?php endif; ?>
    </div>
</div>
