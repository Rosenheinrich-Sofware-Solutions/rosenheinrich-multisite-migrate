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
$rmmigrate_recovery_rows = array();
foreach ($rmmigrate_recent_recovery as $rmmigrate_rp_job) {
    if (Rmmigrate_Recovery_Points::is_restorable($rmmigrate_rp_job)) {
        $rmmigrate_recovery_rows[] = $rmmigrate_rp_job;
    }
}
$rmmigrate_rollback_id = $rmmigrate_rollback_job instanceof Rmmigrate_Job ? (int) $rmmigrate_rollback_job->get_id() : 0;
$rmmigrate_recovery_job_date = static function ($job): string {
    $date = current(array_filter(array($job->data['completed_at'] ?? '', $job->data['created_at'] ?? '')));
    return $date ? Rmmigrate_Time::local((string) $date) : '';
};
?>
<div class="mm-form-section mm-recovery-docs">
    <h2><?php esc_html_e('Recovery', 'rosenheinrich-multisite-migrate'); ?></h2>
    <p class="mm-restore-step-lead"><?php esc_html_e('Undo the last restore, or restore one of the latest backups on this server.', 'rosenheinrich-multisite-migrate'); ?></p>

    <div class="mm-emergency-restore-section" role="region" aria-labelledby="mm-emergency-restore-title">
        <h3 id="mm-emergency-restore-title"><?php esc_html_e('Emergency restore and rollback', 'rosenheinrich-multisite-migrate'); ?></h3>
        <?php if ($rmmigrate_rollback_job instanceof Rmmigrate_Job) : ?>
            <div class="mm-rollback-card">
                <p class="mm-rollback-card__title"><strong><?php esc_html_e('Undo the last restore', 'rosenheinrich-multisite-migrate'); ?></strong></p>
                <p id="mm-rollback-card-desc">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: safety backup job ID */
                            __('A safety snapshot (#%d) was saved just before the last restore. You can roll back to it now.', 'rosenheinrich-multisite-migrate'),
                            $rmmigrate_rollback_id
                        )
                    );
                    $rmmigrate_rollback_date = $rmmigrate_recovery_job_date($rmmigrate_rollback_job);
                    if ($rmmigrate_rollback_date !== '') {
                        echo ' ';
                        echo esc_html(
                            sprintf(
                                /* translators: 1: date and time, 2: timezone label */
                                __('Saved: %1$s (%2$s).', 'rosenheinrich-multisite-migrate'),
                                $rmmigrate_rollback_date,
                                Rmmigrate_Time::tz_label()
                            )
                        );
                    }
                    ?>
                </p>
                <p class="mm-recovery-actions">
                    <button type="button" class="button button-primary mm-btn-teal mm-rollback-last-restore" data-safety-job-id="<?php echo esc_attr((string) $rmmigrate_rollback_id); ?>" aria-describedby="mm-rollback-card-desc">
                        <?php esc_html_e('Roll back last restore', 'rosenheinrich-multisite-migrate'); ?>
                    </button>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($rmmigrate_recovery_rows !== array()) : ?>
            <h4 id="mm-recovery-points-title" class="mm-recovery-points-title"><?php esc_html_e('Latest backups on this server', 'rosenheinrich-multisite-migrate'); ?></h4>
            <p class="description" id="mm-recovery-points-desc"><?php esc_html_e('Restoring replaces the current database and files with the backup. You review the details and confirm before anything is overwritten.', 'rosenheinrich-multisite-migrate'); ?></p>
            <div class="mm-table-scroll-wrap mm-table-cards-mobile">
                <table class="mm-premium-table mm-data-table mm-recovery-points-table" aria-labelledby="mm-recovery-points-title" aria-describedby="mm-recovery-points-desc">
                    <thead>
                        <tr>
                            <th scope="col" class="column-date"><?php
                                /* translators: %s: timezone label */
                                echo esc_html(sprintf(__('Date (%s)', 'rosenheinrich-multisite-migrate'), Rmmigrate_Time::tz_label()));
                            ?></th>
                            <th scope="col" class="column-type"><?php esc_html_e('Type', 'rosenheinrich-multisite-migrate'); ?></th>
                            <th scope="col" class="column-scope"><?php esc_html_e('Scope', 'rosenheinrich-multisite-migrate'); ?></th>
                            <th scope="col" class="column-size"><?php esc_html_e('Size', 'rosenheinrich-multisite-migrate'); ?></th>
                            <th scope="col" class="column-actions"><?php esc_html_e('Actions', 'rosenheinrich-multisite-migrate'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rmmigrate_recovery_rows as $rmmigrate_rp_job) : ?>
                        <?php
                        $rmmigrate_rp_id = (int) $rmmigrate_rp_job->get_id();
                        $rmmigrate_rp_date = $rmmigrate_recovery_job_date($rmmigrate_rp_job);
                        ?>
                        <tr class="mm-recovery-points-row<?php echo $rmmigrate_rp_id === $rmmigrate_rollback_id ? ' is-rollback-point' : ''; ?>">
                            <td class="column-date" data-label="<?php /* translators: %s: timezone label */ echo esc_attr(sprintf(__('Date (%s)', 'rosenheinrich-multisite-migrate'), Rmmigrate_Time::tz_label())); ?>">
                                <?php echo esc_html($rmmigrate_rp_date !== '' ? $rmmigrate_rp_date : '#' . $rmmigrate_rp_id); ?>
                                <span class="mm-recovery-points-id">#<?php echo esc_html((string) $rmmigrate_rp_id); ?></span>
                            </td>
                            <td class="column-type" data-label="<?php esc_attr_e('Type', 'rosenheinrich-multisite-migrate'); ?>">
                                <?php echo esc_html($rmmigrate_rp_job->get_display_job_type()); ?>
                                <?php if ($rmmigrate_rp_id === $rmmigrate_rollback_id) : ?>
                                    <span class="mm-status-pill mm-status-ok mm-rollback-point-badge"><?php esc_html_e('Rollback point', 'rosenheinrich-multisite-migrate'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-scope" data-label="<?php esc_attr_e('Scope', 'rosenheinrich-multisite-migrate'); ?>"><?php echo esc_html($rmmigrate_rp_job->get_display_scope()); ?></td>
                            <td class="column-size" data-label="<?php esc_attr_e('Size', 'rosenheinrich-multisite-migrate'); ?>"><?php echo esc_html(size_format((int) $rmmigrate_rp_job->get_archive_size())); ?></td>
                            <td class="column-actions" data-label="<?php esc_attr_e('Actions', 'rosenheinrich-multisite-migrate'); ?>">
                                <button type="button" class="button mm-restore-backup" data-job-id="<?php echo esc_attr((string) $rmmigrate_rp_id); ?>" aria-label="<?php echo esc_attr(sprintf(
                                    /* translators: 1: backup job ID, 2: backup date and time */
                                    __('Restore backup #%1$d from %2$s', 'rosenheinrich-multisite-migrate'),
                                    $rmmigrate_rp_id,
                                    $rmmigrate_rp_date
                                )); ?>"><span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e('Restore', 'rosenheinrich-multisite-migrate'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($rmmigrate_recovery_rows === array() && !($rmmigrate_rollback_job instanceof Rmmigrate_Job)) : ?>
            <p class="description"><?php esc_html_e('There is no backup on this server to restore yet. Create a backup now, or import a backup file from another server.', 'rosenheinrich-multisite-migrate'); ?></p>
            <p class="mm-recovery-actions">
                <a class="button" href="<?php echo esc_url($rmmigrate_recovery_archives_url); ?>"><?php esc_html_e('Create a backup', 'rosenheinrich-multisite-migrate'); ?></a>
                <a class="button" href="<?php echo esc_url($rmmigrate_recovery_import_url); ?>"><?php esc_html_e('Import a backup file', 'rosenheinrich-multisite-migrate'); ?></a>
            </p>
        <?php endif; ?>
    </div>

    <div class="mm-recovery-guides" role="region" aria-labelledby="mm-recovery-guides-title">
        <h3 id="mm-recovery-guides-title"><?php esc_html_e('Restore guides', 'rosenheinrich-multisite-migrate'); ?></h3>
        <p class="description"><?php esc_html_e('Step-by-step help for rollback, import, moving to an empty server, domain mapping and disaster recovery.', 'rosenheinrich-multisite-migrate'); ?></p>
        <p class="mm-recovery-actions">
            <a class="button" href="<?php echo esc_url($rmmigrate_docs_restore); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Read the restore guide', 'rosenheinrich-multisite-migrate'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'rosenheinrich-multisite-migrate'); ?></span></a>
            <a class="button" href="<?php echo esc_url($rmmigrate_docs_hub); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('All documentation', 'rosenheinrich-multisite-migrate'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'rosenheinrich-multisite-migrate'); ?></span></a>
        </p>
    </div>
</div>