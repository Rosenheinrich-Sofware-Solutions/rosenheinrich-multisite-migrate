<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persisted restore-complete banner (?mm_restore_ok=1&job_id=).
 *
 * @var Rmmigrate_Job|null $rmmigrate_restore_success
 * @var Rmmigrate_Job|null $rmmigrate_active
 * @var Rmmigrate_Job|null $rmmigrate_active_job
 */

$rmmigrate_restore_success = $rmmigrate_restore_success ?? null;
if (!$rmmigrate_restore_success instanceof Rmmigrate_Job) {
    return;
}

$rmmigrate_active_job = $rmmigrate_active_job ?? ($rmmigrate_active ?? null);
if ($rmmigrate_active_job instanceof Rmmigrate_Job) {
    return;
}

$rmmigrate_file_restore = is_array($rmmigrate_restore_success->get_progress()['file_restore'] ?? null)
    ? $rmmigrate_restore_success->get_progress()['file_restore']
    : array();
$rmmigrate_restore_skipped = (int) ($rmmigrate_file_restore['skipped_count'] ?? 0);

if ($rmmigrate_restore_skipped > 0) {
    $rmmigrate_banner = array(
        'variant' => 'warning',
        'class'   => 'mm-restore-success-banner',
        'icon'    => 'warning',
        'title'   => __('Restore finished with warnings', 'rosenheinrich-multisite-migrate'),
        'text'    => __('The database was restored, but some files could not be copied. Check the job log for details.', 'rosenheinrich-multisite-migrate'),
        'actions' => array(
            array(
                'type'   => 'dismiss',
                'class'  => 'mm-dismiss-restore-success',
                'button' => true,
            ),
        ),
    );
} else {
    $rmmigrate_banner = array(
        'variant' => 'success',
        'class'   => 'mm-restore-success-banner',
        'icon'    => 'yes-alt',
        'title'   => __('Restore complete', 'rosenheinrich-multisite-migrate'),
        'text'    => __('Your site was restored from the backup archive.', 'rosenheinrich-multisite-migrate'),
        'actions' => array(
            array(
                'type'   => 'dismiss',
                'class'  => 'mm-dismiss-restore-success',
                'button' => true,
            ),
        ),
    );
}

include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
