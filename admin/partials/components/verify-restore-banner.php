<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post-backup verify + off-site upsell after successful backup (?mm_verify=1&job_id=).
 *
 * @var bool $rmmigrate_is_network
 */

$rmmigrate_verify_flag = Rmmigrate_Request_Input::get_text('mm_verify');
$rmmigrate_verify_job_id = Rmmigrate_Request_Input::get_int('job_id');
if ($rmmigrate_verify_flag !== '1' || $rmmigrate_verify_job_id <= 0) {
    return;
}

$rmmigrate_verify_job = Rmmigrate_Job::get($rmmigrate_verify_job_id);
if (
    !$rmmigrate_verify_job instanceof Rmmigrate_Job
    || $rmmigrate_verify_job->get_status() !== Rmmigrate_Job::STATUS_COMPLETE
    || $rmmigrate_verify_job->get_job_type() !== Rmmigrate_Job::JOB_TYPE_BACKUP
    || !Rmmigrate_Access::can_view_job($rmmigrate_verify_job)
) {
    return;
}

$rmmigrate_can_verify_restore = Rmmigrate_Recovery_Points::is_restorable($rmmigrate_verify_job);
$rmmigrate_verify_actions = array();
if ($rmmigrate_can_verify_restore) {
    $rmmigrate_verify_actions[] = array(
        'type'    => 'button',
        'label'   => __('Test restore', 'rosenheinrich-multisite-migrate'),
        'primary' => true,
        'class'   => 'mm-restore-backup',
        'attrs'   => array(
            'data-job-id' => (string) $rmmigrate_verify_job_id,
        ),
    );
}
$rmmigrate_verify_actions[] = array(
    'type'     => 'link',
    'label'    => __('Add off-site cloud (Plus)', 'rosenheinrich-multisite-migrate'),
    'url'      => Rmmigrate_Links::pricing_url('plugin_intent_offsite'),
    'external' => true,
);
$rmmigrate_verify_actions[] = array(
    'type'   => 'dismiss',
    'class'  => 'mm-dismiss-verify-restore',
    'button' => true,
);

$rmmigrate_banner = array(
    'variant' => 'success',
    'class'   => 'mm-verify-restore-banner',
    'icon'    => 'yes-alt',
    'title'   => __('Backup finished — verify it can restore', 'rosenheinrich-multisite-migrate'),
    'text'    => __('A backup only helps if restore works. Test restore with a safety snapshot, then move copies off this server (same host = same blast radius).', 'rosenheinrich-multisite-migrate'),
    'actions' => $rmmigrate_verify_actions,
);
include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';

Rmmigrate_Pro_Hints::render(
    'post_backup_offsite',
    array(
        'title'     => __('Same server = same risk', 'rosenheinrich-multisite-migrate'),
        'text'      => __('Local archives share the host blast radius. Plus adds BYO cloud (Drive, Dropbox, S3), and cloud schedules so silent partial backups do not go unnoticed.', 'rosenheinrich-multisite-migrate'),
        'cta_label' => __('See Plus off-site backup', 'rosenheinrich-multisite-migrate'),
        'placement' => 'plugin_intent_offsite',
    )
);
