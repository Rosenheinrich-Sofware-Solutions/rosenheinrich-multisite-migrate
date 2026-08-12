<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_active_job = $rmmigrate_active_job ?? ($rmmigrate_active ?? null);
if (!$rmmigrate_active_job instanceof Rmmigrate_Job) {
    return;
}
$rmmigrate_job_type = $rmmigrate_active_job->get_job_type();
$rmmigrate_titles = array(
    Rmmigrate_Job::JOB_TYPE_BACKUP  => __('Backup in progress', 'rosenheinrich-multisite-migrate'),
    Rmmigrate_Job::JOB_TYPE_RESTORE => __('Restore in progress', 'rosenheinrich-multisite-migrate'),
);
$rmmigrate_title = $rmmigrate_titles[$rmmigrate_job_type] ?? __('Job in progress', 'rosenheinrich-multisite-migrate');
$rmmigrate_pct = max(0, min(100, (int) $rmmigrate_active_job->get_percent()));
$rmmigrate_msg = (string) $rmmigrate_active_job->get_progress_message();
if ($rmmigrate_msg === '') {
	$rmmigrate_msg = $rmmigrate_pct . '%';
} elseif ($rmmigrate_pct > 0 && $rmmigrate_pct < 100) {
	$rmmigrate_msg .= ' (' . $rmmigrate_pct . '%)';
}
?>
<div id="mm-active-job-banner" class="mm-form-section mm-active-job-banner" data-job-id="<?php echo esc_attr((string) $rmmigrate_active_job->get_id()); ?>" data-job-type="<?php echo esc_attr($rmmigrate_job_type); ?>">
    <h2 id="mm-active-job-title"><?php echo esc_html($rmmigrate_title); ?></h2>
    <div class="mm-progress-bar"><div class="mm-progress-fill" id="mm-active-job-fill" style="width:<?php echo esc_attr((string) $rmmigrate_pct); ?>%"></div></div>
    <p class="mm-active-job-text" id="mm-active-job-text"><?php echo esc_html($rmmigrate_msg); ?></p>
    <p class="mm-active-job-actions">
        <?php if ($rmmigrate_job_type === Rmmigrate_Job::JOB_TYPE_BACKUP) : ?>
            <button type="button" class="button" id="multisite-migrate-cancel"><?php esc_html_e('Cancel backup', 'rosenheinrich-multisite-migrate'); ?></button>
        <?php else : ?>
            <button type="button" class="button mm-cancel-restore-progress" data-job-id="<?php echo esc_attr((string) $rmmigrate_active_job->get_id()); ?>"><?php esc_html_e('Cancel restore', 'rosenheinrich-multisite-migrate'); ?></button>
        <?php endif; ?>
    </p>
</div>
