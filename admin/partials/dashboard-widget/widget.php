<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_widget = is_array($rmmigrate_widget ?? null) ? $rmmigrate_widget : array();
$rmmigrate_widget_urls = is_array($rmmigrate_widget['urls'] ?? null) ? $rmmigrate_widget['urls'] : array();
$rmmigrate_widget_sections = is_array($rmmigrate_widget['sections'] ?? null) ? $rmmigrate_widget['sections'] : array();
$rmmigrate_widget_active = $rmmigrate_widget['active_job'] ?? null;
$rmmigrate_widget_last = $rmmigrate_widget['last_job'] ?? null;
$rmmigrate_widget_recent = is_array($rmmigrate_widget['recent_jobs'] ?? null) ? $rmmigrate_widget['recent_jobs'] : array();
$rmmigrate_widget_can_create = !empty($rmmigrate_widget['can_create']);
$rmmigrate_widget_show_upsell = !empty($rmmigrate_widget['show_pro_upsell']);
$rmmigrate_widget_archives_url = (string) ($rmmigrate_widget_urls['archives'] ?? '');
$rmmigrate_widget_create_url = (string) ($rmmigrate_widget_urls['create'] ?? ($rmmigrate_widget_urls['dashboard'] ?? ''));
$rmmigrate_widget_time_class = (string) ($rmmigrate_widget['time_class'] ?? 'Rmmigrate_Time');

if (!function_exists('rmmigrate_dashboard_widget_is_job')) {
    function rmmigrate_dashboard_widget_is_job($job): bool
    {
        return is_object($job)
            && method_exists($job, 'get_id')
            && method_exists($job, 'get_display_scope')
            && method_exists($job, 'get_job_type');
    }
}
?>

<div class="mm-dashboard-widget">
    <div class="mm-dashboard-widget-status">
        <?php if (rmmigrate_dashboard_widget_is_job($rmmigrate_widget_active)) : ?>
            <p class="mm-dashboard-widget-running">
                <span class="spinner is-active"></span>
                <strong>
                    <?php
                    if ($rmmigrate_widget_active->get_job_type() === 'restore') {
                        esc_html_e('A restore is currently running.', 'rosenheinrich-multisite-migrate');
                    } else {
                        esc_html_e('A backup is currently running.', 'rosenheinrich-multisite-migrate');
                    }
                    ?>
                </strong>
            </p>
        <?php else : ?>
            <p class="mm-dashboard-widget-last">
                <strong><?php esc_html_e('Last backup:', 'rosenheinrich-multisite-migrate'); ?></strong>
                <?php
                if (rmmigrate_dashboard_widget_is_job($rmmigrate_widget_last)
                    && !empty($rmmigrate_widget_last->data['completed_at'])
                ) {
                    echo esc_html(
                        call_user_func(array($rmmigrate_widget_time_class, 'local_label'), (string) $rmmigrate_widget_last->data['completed_at'])
                    );
                } else {
                    echo '<strong>' . esc_html__('No backups have been created yet.', 'rosenheinrich-multisite-migrate') . '</strong>';
                }
                ?>
            </p>
        <?php endif; ?>

        <?php if ($rmmigrate_widget_can_create) : ?>
            <p class="mm-dashboard-widget-cta">
                <a class="button button-primary mm-dashboard-widget-create" href="<?php echo esc_url($rmmigrate_widget_create_url); ?>">
                    <?php esc_html_e('Create New', 'rosenheinrich-multisite-migrate'); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>

    <hr class="mm-dashboard-widget-separator">

    <div class="mm-dashboard-widget-recent">
        <h4><?php esc_html_e('Recent backups', 'rosenheinrich-multisite-migrate'); ?></h4>
        <?php if (empty($rmmigrate_widget_recent)) : ?>
            <p class="mm-dashboard-widget-empty"><?php esc_html_e('No backups have been created yet.', 'rosenheinrich-multisite-migrate'); ?></p>
        <?php else : ?>
            <ul class="mm-dashboard-widget-job-list">
                <?php foreach ($rmmigrate_widget_recent as $rmmigrate_widget_job) : ?>
                    <?php if (!rmmigrate_dashboard_widget_is_job($rmmigrate_widget_job)) {
                        continue;
                    } ?>
                    <li>
                        <?php if ($rmmigrate_widget_archives_url !== '') : ?>
                            <a href="<?php echo esc_url(add_query_arg('job_id', $rmmigrate_widget_job->get_id(), $rmmigrate_widget_archives_url)); ?>">
                        <?php else : ?>
                            <span class="mm-dashboard-widget-job-row">
                        <?php endif; ?>
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span>
                                <strong>#<?php echo (int) $rmmigrate_widget_job->get_id(); ?></strong>
                                &middot;
                                <?php echo esc_html($rmmigrate_widget_job->get_display_scope()); ?>
                            </span>
                            <span class="mm-dashboard-widget-job-date">
                                <?php
                                echo esc_html(
                                    call_user_func(
                                        array($rmmigrate_widget_time_class, 'local_label'),
                                        (string) ($rmmigrate_widget_job->data['completed_at']
                                            ?? $rmmigrate_widget_job->data['created_at']
                                            ?? '')
                                    )
                                );
                                ?>
                            </span>
                        <?php if ($rmmigrate_widget_archives_url !== '') : ?>
                            </a>
                        <?php else : ?>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($rmmigrate_widget_archives_url !== '') : ?>
            <p class="mm-dashboard-widget-view-all">
                <a href="<?php echo esc_url($rmmigrate_widget_archives_url); ?>">
                    <?php esc_html_e('View all', 'rosenheinrich-multisite-migrate'); ?> &rarr;
                </a>
            </p>
        <?php endif; ?>
    </div>

    <?php if (!empty($rmmigrate_widget_sections)) : ?>
        <hr class="mm-dashboard-widget-separator">
        <ul class="mm-dashboard-widget-sections">
            <?php foreach ($rmmigrate_widget_sections as $rmmigrate_widget_section) : ?>
                <?php
                if (!is_array($rmmigrate_widget_section)) {
                    continue;
                }
                $rmmigrate_widget_section_url = (string) ($rmmigrate_widget_section['url'] ?? '');
                $rmmigrate_widget_section_icon = (string) ($rmmigrate_widget_section['icon'] ?? 'dashicons-admin-generic');
                $rmmigrate_widget_section_count = (string) ($rmmigrate_widget_section['count'] ?? '');
                $rmmigrate_widget_section_label = (string) ($rmmigrate_widget_section['label'] ?? '');
                $rmmigrate_widget_section_meta = (string) ($rmmigrate_widget_section['meta'] ?? '');
                if ($rmmigrate_widget_section_label === '') {
                    continue;
                }
                ?>
                <li>
                    <?php if ($rmmigrate_widget_section_url !== '') : ?>
                        <a href="<?php echo esc_url($rmmigrate_widget_section_url); ?>">
                    <?php endif; ?>
                    <span class="dashicons <?php echo esc_attr($rmmigrate_widget_section_icon); ?>"></span>
                    <?php if ($rmmigrate_widget_section_count !== '') : ?>
                        <span class="mm-dashboard-widget-section-count"><?php echo esc_html($rmmigrate_widget_section_count); ?></span>
                    <?php endif; ?>
                    <span class="mm-dashboard-widget-section-label"><?php echo esc_html($rmmigrate_widget_section_label); ?></span>
                    <?php if ($rmmigrate_widget_section_meta !== '') : ?>
                        <span class="mm-dashboard-widget-section-meta"><?php echo esc_html($rmmigrate_widget_section_meta); ?></span>
                    <?php endif; ?>
                    <?php if ($rmmigrate_widget_section_url !== '') : ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($rmmigrate_widget_show_upsell && !empty($rmmigrate_widget_urls['upgrade'])) : ?>
        <p class="mm-dashboard-widget-upsell">
            <?php esc_html_e('Cloud storage and scheduled backups are available in Multisite Migrate Pro.', 'rosenheinrich-multisite-migrate'); ?>
            <a href="<?php echo esc_url((string) $rmmigrate_widget_urls['upgrade']); ?>" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Explore Pro features', 'rosenheinrich-multisite-migrate'); ?>
            </a>
        </p>
    <?php endif; ?>
</div>
