<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_preflight_op_norm = isset($rmmigrate_preflight_op) && is_string($rmmigrate_preflight_op)
    ? $rmmigrate_preflight_op
    : 'backup';
$rmmigrate_preflight_report = (isset($rmmigrate_preflight_report) && is_array($rmmigrate_preflight_report) && ($rmmigrate_preflight_report['operation'] ?? '') === $rmmigrate_preflight_op_norm)
    ? $rmmigrate_preflight_report
    : (class_exists('Rmmigrate_Job_Preflight')
    ? Rmmigrate_Job_Preflight::evaluate($rmmigrate_preflight_op_norm)
    : array(
        'operation'        => $rmmigrate_preflight_op_norm,
        'status'           => 'green',
        'can_proceed'      => true,
        'summary'          => __('All checks passed. The server is ready.', 'rosenheinrich-multisite-migrate'),
        'recommended_mode' => array(
            'archive_mode' => 'daf',
            'db_mode'      => 'pdo',
            'kickoff_mode' => 'browser',
            'reason'       => '',
        ),
        'checks'           => array(),
    ));

$rmmigrate_ampel_status = in_array($rmmigrate_preflight_report['status'] ?? 'green', array('green', 'yellow', 'red'), true)
    ? (string) $rmmigrate_preflight_report['status']
    : 'green';

if ($rmmigrate_ampel_status === 'red') {
    $rmmigrate_ampel_badge_text = __('Blocked: fix this before you start', 'rosenheinrich-multisite-migrate');
    $rmmigrate_ampel_icon = 'dashicons-warning';
} elseif ($rmmigrate_ampel_status === 'yellow') {
    $rmmigrate_ampel_badge_text = __('Ready, with automatic adjustments', 'rosenheinrich-multisite-migrate');
    $rmmigrate_ampel_icon = 'dashicons-info-outline';
} else {
    $rmmigrate_ampel_badge_text = __('Ready', 'rosenheinrich-multisite-migrate');
    $rmmigrate_ampel_icon = 'dashicons-yes-alt';
}
$rmmigrate_ampel_summary_id = 'mm-preflight-summary-' . $rmmigrate_preflight_op_norm;

$rmmigrate_ampel_rec = is_array($rmmigrate_preflight_report['recommended_mode'] ?? null)
    ? $rmmigrate_preflight_report['recommended_mode']
    : array();
$rmmigrate_ampel_archive_label = (($rmmigrate_ampel_rec['archive_mode'] ?? 'daf') === 'daf')
    ? __('DAF (resumable)', 'rosenheinrich-multisite-migrate')
    : __('ZIP (ZipArchive)', 'rosenheinrich-multisite-migrate');
$rmmigrate_ampel_db_label = (($rmmigrate_ampel_rec['db_mode'] ?? 'pdo') === 'mysqldump')
    ? __('mysqldump (fast CLI)', 'rosenheinrich-multisite-migrate')
    : __('Chunked DB (PDO/wpdb)', 'rosenheinrich-multisite-migrate');
$rmmigrate_ampel_kickoff = (string) ($rmmigrate_ampel_rec['kickoff_mode'] ?? 'browser');
if ($rmmigrate_ampel_kickoff === 'loopback') {
    $rmmigrate_ampel_worker_label = __('Loopback worker', 'rosenheinrich-multisite-migrate');
} elseif ($rmmigrate_ampel_kickoff === 'cron') {
    $rmmigrate_ampel_worker_label = __('WP-Cron worker', 'rosenheinrich-multisite-migrate');
} else {
    $rmmigrate_ampel_worker_label = __('Browser/Cron worker', 'rosenheinrich-multisite-migrate');
}
$rmmigrate_ampel_checks = is_array($rmmigrate_preflight_report['checks'] ?? null)
    ? $rmmigrate_preflight_report['checks']
    : array();
?>
<div class="mm-preflight-ampel mm-preflight-ampel--<?php echo esc_attr($rmmigrate_ampel_status); ?>" role="region" aria-label="<?php esc_attr_e('Server check before starting', 'rosenheinrich-multisite-migrate'); ?>" data-preflight-status="<?php echo esc_attr($rmmigrate_ampel_status); ?>" data-preflight-op="<?php echo esc_attr($rmmigrate_preflight_op_norm); ?>" data-can-proceed="<?php echo !empty($rmmigrate_preflight_report['can_proceed']) ? '1' : '0'; ?>">
    <div class="mm-preflight-ampel__header">
        <span class="mm-preflight-ampel__badge mm-preflight-ampel__badge--<?php echo esc_attr($rmmigrate_ampel_status); ?>">
            <span class="dashicons <?php echo esc_attr($rmmigrate_ampel_icon); ?>" aria-hidden="true"></span>
            <strong><?php echo esc_html($rmmigrate_ampel_badge_text); ?></strong>
        </span>
        <span class="mm-preflight-ampel__summary" id="<?php echo esc_attr($rmmigrate_ampel_summary_id); ?>"><?php echo esc_html((string) ($rmmigrate_preflight_report['summary'] ?? '')); ?></span>
        <button type="button" class="button button-small mm-preflight-refresh"><?php esc_html_e('Check again', 'rosenheinrich-multisite-migrate'); ?></button>
    </div>
    <?php if (!empty($rmmigrate_ampel_checks)) : ?>
        <details class="mm-preflight-ampel__details"<?php echo $rmmigrate_ampel_status !== 'green' ? esc_attr(' open') : ''; ?>>
            <summary class="mm-preflight-ampel__toggle"><?php esc_html_e('Check details', 'rosenheinrich-multisite-migrate'); ?></summary>
            <p class="mm-preflight-ampel__mode description">
                <?php esc_html_e('Technical mode for this server:', 'rosenheinrich-multisite-migrate'); ?>
                <?php echo esc_html($rmmigrate_ampel_archive_label . ' · ' . $rmmigrate_ampel_db_label . ' · ' . $rmmigrate_ampel_worker_label); ?>
            </p>
            <ul class="mm-preflight-ampel__checks">
                <?php foreach ($rmmigrate_ampel_checks as $rmmigrate_ampel_check) : ?>
                    <?php
                    $rmmigrate_check_status = in_array($rmmigrate_ampel_check['status'] ?? 'green', array('green', 'yellow', 'red'), true)
                        ? (string) $rmmigrate_ampel_check['status']
                        : 'green';
                    if ($rmmigrate_check_status === 'red') {
                        $rmmigrate_check_status_label = __('Action required', 'rosenheinrich-multisite-migrate');
                        $rmmigrate_check_icon = 'dashicons-warning';
                    } elseif ($rmmigrate_check_status === 'yellow') {
                        $rmmigrate_check_status_label = __('Auto-adjusted', 'rosenheinrich-multisite-migrate');
                        $rmmigrate_check_icon = 'dashicons-info-outline';
                    } else {
                        $rmmigrate_check_status_label = __('OK', 'rosenheinrich-multisite-migrate');
                        $rmmigrate_check_icon = 'dashicons-yes-alt';
                    }
                    ?>
                    <li class="mm-preflight-ampel__check mm-preflight-ampel__check--<?php echo esc_attr($rmmigrate_check_status); ?>">
                        <span class="mm-preflight-ampel__check-badge">
                            <span class="dashicons <?php echo esc_attr($rmmigrate_check_icon); ?>" aria-hidden="true"></span>
                            <span class="mm-preflight-ampel__check-state"><?php echo esc_html($rmmigrate_check_status_label); ?></span>
                        </span>
                        <strong class="mm-preflight-ampel__check-label"><?php echo esc_html((string) ($rmmigrate_ampel_check['label'] ?? '')); ?>:</strong>
                        <span class="mm-preflight-ampel__check-detail"><?php echo esc_html((string) ($rmmigrate_ampel_check['detail'] ?? '')); ?></span>
                        <?php if (!empty($rmmigrate_ampel_check['hint'])) : ?>
                            <span class="mm-preflight-ampel__check-hint description"><?php echo esc_html((string) $rmmigrate_ampel_check['hint']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
    <p class="screen-reader-text mm-preflight-ampel__live" aria-live="polite"></p>
</div>
