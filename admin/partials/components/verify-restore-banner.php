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

$rmmigrate_verify_actions = array();
if (!empty($rmmigrate_verify_job->data['local_path'])) {
    $rmmigrate_verify_actions[] = array(
        'type'    => 'link',
        'label'   => __('Download backup', 'rosenheinrich-multisite-migrate'),
        'primary' => true,
        'url'     => add_query_arg(array(
            'action' => 'rmmigrate_download',
            'job_id' => $rmmigrate_verify_job_id,
            'nonce'  => wp_create_nonce('rmmigrate_admin'),
        ), admin_url('admin-ajax.php')),
    );
}
$rmmigrate_verify_actions[] = array(
    'type'     => 'link',
    'label'    => __('Back up to the cloud automatically (Plus)', 'rosenheinrich-multisite-migrate'),
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
    'title'   => __('Your backup is ready', 'rosenheinrich-multisite-migrate'),
    'text'    => __('It is saved on this server. Download a copy and keep it somewhere else too (your computer or cloud storage) — if this server fails, a copy elsewhere is what saves you. To undo changes later, click Restore next to the backup.', 'rosenheinrich-multisite-migrate'),
    'actions' => $rmmigrate_verify_actions,
);
include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
