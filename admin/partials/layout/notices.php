<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!empty($rmmigrate_last_error['message'])) {
    $rmmigrate_activity_url = Rmmigrate_Admin_Router::admin_url(
        'multisite-migrate-activity',
        array(),
        !empty($rmmigrate_is_network)
    );
    $rmmigrate_error_job_type = sanitize_key((string) ($rmmigrate_last_error['job_type'] ?? ''));
    $rmmigrate_error_context  = 'other';
    if ($rmmigrate_error_job_type === 'backup') {
        $rmmigrate_error_context = 'backup_fail';
    } elseif ($rmmigrate_error_job_type === 'restore') {
        $rmmigrate_error_context = 'restore_fail';
    } elseif ($rmmigrate_error_job_type === 'import') {
        $rmmigrate_error_context = 'import_fail';
    }
    $rmmigrate_error_message = Rmmigrate_User_Error_Messages::for_admin_banner($rmmigrate_last_error);
    $rmmigrate_health_url = Rmmigrate_Admin_Router::admin_url(
        'multisite-migrate-health',
        array(),
        !empty($rmmigrate_is_network)
    );
    $rmmigrate_error_job_id = (int) ($rmmigrate_last_error['job_id'] ?? 0);
    $rmmigrate_banner_title   = sprintf(
        /* translators: %d: job ID. */
        __('Last job #%d did not finish', 'rosenheinrich-multisite-migrate'),
        $rmmigrate_error_job_id
    );
    if ($rmmigrate_error_job_type === 'restore') {
        $rmmigrate_banner_title = sprintf(
            /* translators: %d: job ID. */
            __('Last restore #%d did not finish', 'rosenheinrich-multisite-migrate'),
            $rmmigrate_error_job_id
        );
    } elseif ($rmmigrate_error_job_type === 'backup') {
        $rmmigrate_banner_title = sprintf(
            /* translators: %d: job ID. */
            __('Last backup #%d did not finish', 'rosenheinrich-multisite-migrate'),
            $rmmigrate_error_job_id
        );
    } elseif ($rmmigrate_error_job_type === 'import') {
        $rmmigrate_banner_title = sprintf(
            /* translators: %d: job ID. */
            __('Last import #%d did not finish', 'rosenheinrich-multisite-migrate'),
            $rmmigrate_error_job_id
        );
    }
    $rmmigrate_banner = array(
        'variant' => 'error',
        'class'   => 'mm-last-error-notice',
        'title'   => $rmmigrate_banner_title,
        'text'    => $rmmigrate_error_message,
        'actions' => array(
            array(
                'type'    => 'link',
                'label'   => __('Check hosting', 'rosenheinrich-multisite-migrate'),
                'url'     => $rmmigrate_health_url,
                'primary' => true,
            ),
            array(
                'type'  => 'link',
                'label' => __('Activity log', 'rosenheinrich-multisite-migrate'),
                'url'   => $rmmigrate_activity_url,
            ),
            array(
                'type'  => 'link',
                'label' => __('Report a problem', 'rosenheinrich-multisite-migrate'),
                'url'   => '#',
                'class' => 'mm-feedback-open-error',
                'attrs' => array(
                    'data-context'       => $rmmigrate_error_context,
                    'data-job-type'      => $rmmigrate_error_job_type,
                    'data-error-code'    => sanitize_key((string) ($rmmigrate_last_error['code'] ?? $rmmigrate_last_error['error_code'] ?? '')),
                    'data-error-message' => $rmmigrate_error_message,
                ),
            ),
            array(
                'type'   => 'dismiss',
                'class'  => 'mm-dismiss-last-error',
                'button' => true,
            ),
        ),
    );
    include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
}

if (!empty($rmmigrate_migration_notice['lines'])) {
    $rmmigrate_migration_html = '';
    foreach ((array) $rmmigrate_migration_notice['lines'] as $rmmigrate_line) {
        $rmmigrate_migration_html .= '<code>' . esc_html((string) $rmmigrate_line) . '</code><br>';
    }
    $rmmigrate_banner = array(
        'variant'     => 'warning',
        'title'       => __('Migration: update wp-config.php', 'rosenheinrich-multisite-migrate'),
        'text'        => $rmmigrate_migration_html,
        'allow_html'  => true,
    );
    include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
}

if (Rmmigrate_Request_Input::get_exists('updated')) {
    $rmmigrate_banner = array(
        'variant' => 'success',
        'text'    => __('Settings saved.', 'rosenheinrich-multisite-migrate'),
        'simple'  => true,
    );
    include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
}

if (Rmmigrate_Request_Input::get_exists('email_sent')) {
    $rmmigrate_banner = array(
        'variant' => 'success',
        'text'    => __('Test email sent.', 'rosenheinrich-multisite-migrate'),
        'simple'  => true,
    );
    include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
}

if (Rmmigrate_Request_Input::get_exists('email_failed')) {
    $rmmigrate_email_error = get_transient('rmmigrate_test_email_error_' . get_current_user_id());
    if (is_string($rmmigrate_email_error) && $rmmigrate_email_error !== '') {
        delete_transient('rmmigrate_test_email_error_' . get_current_user_id());
        $rmmigrate_banner = array(
            'variant' => 'error',
            'text'    => sprintf(
                /* translators: %s: mailer error */
                __('Test email could not be sent: %s', 'rosenheinrich-multisite-migrate'),
                $rmmigrate_email_error
            ),
            'simple'  => true,
        );
    } else {
        $rmmigrate_banner = array(
            'variant' => 'error',
            'text'    => __('Test email could not be sent. Check your wp_mail / SMTP configuration.', 'rosenheinrich-multisite-migrate'),
            'simple'  => true,
        );
    }
    include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
}

if (Rmmigrate_Request_Input::get_exists('imported')) {
    $rmmigrate_banner = array(
        'variant' => 'success',
        'title'   => __('Backup imported successfully', 'rosenheinrich-multisite-migrate'),
        'text'    => __('Your backup archive is uploaded, verified, and ready for restoration.', 'rosenheinrich-multisite-migrate'),
        'icon'    => 'yes-alt',
        'simple'  => false,
    );
    include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
}

if (Rmmigrate_Request_Input::get_exists('log_cleared')) {
    $rmmigrate_banner = array(
        'variant' => 'success',
        'text'    => __('Activity and log files cleared.', 'rosenheinrich-multisite-migrate'),
        'simple'  => true,
    );
    include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
}

if (Rmmigrate_Request_Input::get_exists('logs_deleted')) {
    $rmmigrate_banner = array(
        'variant' => 'success',
        'text'    => __('All job log files deleted.', 'rosenheinrich-multisite-migrate'),
        'simple'  => true,
    );
    include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
}
