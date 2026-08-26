<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_type_filter = Rmmigrate_Request_Input::get_key('type');
$rmmigrate_date_from = Rmmigrate_Activity_Log::normalize_date_filter(
    Rmmigrate_Request_Input::get_text('date_from')
);
$rmmigrate_date_to = Rmmigrate_Activity_Log::normalize_date_filter(
    Rmmigrate_Request_Input::get_text('date_to')
);
$rmmigrate_activity_page = max(1, Rmmigrate_Request_Input::get_int('paged', 1));
$rmmigrate_activity_per_page = Rmmigrate_Engine_Config::activity_page_size();
$rmmigrate_activity_result = Rmmigrate_Activity_Log::list_entries(
    $rmmigrate_activity_per_page,
    $rmmigrate_activity_page,
    $rmmigrate_type_filter,
    $rmmigrate_date_from,
    $rmmigrate_date_to
);
$rmmigrate_entries = $rmmigrate_activity_result['entries'];
$rmmigrate_activity_total = (int) $rmmigrate_activity_result['total'];
$rmmigrate_activity_total_pages = (int) $rmmigrate_activity_result['total_pages'];
$rmmigrate_activity_total_estimated = !empty($rmmigrate_activity_result['total_estimated']);
$rmmigrate_activity_start = $rmmigrate_activity_total > 0
    ? (($rmmigrate_activity_page - 1) * $rmmigrate_activity_per_page) + 1
    : 0;
$rmmigrate_activity_end = min(
    $rmmigrate_activity_total,
    $rmmigrate_activity_page * $rmmigrate_activity_per_page
);
$rmmigrate_pagination_base = add_query_arg(
    array(
        'page'      => $rmmigrate_current_page ?? 'multisite-migrate-activity',
        'type'      => $rmmigrate_type_filter,
        'date_from' => $rmmigrate_date_from,
        'date_to'   => $rmmigrate_date_to,
    ),
    $rmmigrate_is_network ? network_admin_url('admin.php') : admin_url('admin.php')
);
$rmmigrate_filters_active = (
    $rmmigrate_type_filter !== ''
    || $rmmigrate_date_from !== ''
    || $rmmigrate_date_to !== ''
);
$rmmigrate_clear_url = wp_nonce_url(
    add_query_arg(array('action' => 'rmmigrate_clear_activity_log'), $rmmigrate_is_network ? network_admin_url('edit.php') : admin_url('admin-post.php')),
    'rmmigrate_clear_activity_log'
);
$rmmigrate_reset_url = Rmmigrate_Admin_Router::admin_url(
    'multisite-migrate-activity',
    array(),
    $rmmigrate_is_network
);
?>
<div class="mm-form-section mm-activity-log">
    <h2><?php esc_html_e('Activity log', 'rosenheinrich-multisite-migrate'); ?></h2>
    <div class="mm-toolbar mm-activity-toolbar">
        <form method="get" action="" class="mm-activity-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr($rmmigrate_current_page ?? 'multisite-migrate-activity'); ?>">
            <div class="mm-activity-filters__fields">
                <label class="mm-activity-filter">
                    <span class="mm-activity-filter__label"><?php esc_html_e('Type', 'rosenheinrich-multisite-migrate'); ?></span>
                    <select name="type">
                        <option value=""><?php esc_html_e('All types', 'rosenheinrich-multisite-migrate'); ?></option>
                        <option value="backup" <?php selected($rmmigrate_type_filter, 'backup'); ?>><?php esc_html_e('Backup', 'rosenheinrich-multisite-migrate'); ?></option>
                        <option value="restore" <?php selected($rmmigrate_type_filter, 'restore'); ?>><?php esc_html_e('Restore', 'rosenheinrich-multisite-migrate'); ?></option>
                        <option value="import" <?php selected($rmmigrate_type_filter, 'import'); ?>><?php esc_html_e('Import', 'rosenheinrich-multisite-migrate'); ?></option>
                        <option value="system" <?php selected($rmmigrate_type_filter, 'system'); ?>><?php esc_html_e('System', 'rosenheinrich-multisite-migrate'); ?></option>
                    </select>
                </label>
                <label class="mm-activity-filter">
                    <span class="mm-activity-filter__label"><?php esc_html_e('From', 'rosenheinrich-multisite-migrate'); ?></span>
                    <input type="date" name="date_from" value="<?php echo esc_attr($rmmigrate_date_from); ?>">
                </label>
                <label class="mm-activity-filter">
                    <span class="mm-activity-filter__label"><?php esc_html_e('To', 'rosenheinrich-multisite-migrate'); ?></span>
                    <input type="date" name="date_to" value="<?php echo esc_attr($rmmigrate_date_to); ?>">
                </label>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Apply filters', 'rosenheinrich-multisite-migrate'); ?></button>
                <?php if ($rmmigrate_filters_active) : ?>
                    <a class="button button-link" href="<?php echo esc_url($rmmigrate_reset_url); ?>"><?php esc_html_e('Reset', 'rosenheinrich-multisite-migrate'); ?></a>
                <?php endif; ?>
            </div>
        </form>
        <div class="mm-toolbar-actions">
            <button type="button" class="button" id="mm-clear-activity-log" data-url="<?php echo esc_url($rmmigrate_clear_url); ?>" data-message="<?php echo esc_attr(__('Clear activity log and all log files on disk?', 'rosenheinrich-multisite-migrate')); ?>"><?php esc_html_e('Clear log', 'rosenheinrich-multisite-migrate'); ?></button>
        </div>
    </div>

    <?php if (empty($rmmigrate_entries)) : ?>
        <div class="mm-empty-section">
        <?php
        $rmmigrate_empty_title = $rmmigrate_filters_active
            ? __('No matching activity', 'rosenheinrich-multisite-migrate')
            : __('No activity yet', 'rosenheinrich-multisite-migrate');
        $rmmigrate_empty_message = $rmmigrate_filters_active
            ? __('Try a wider date range or another type filter.', 'rosenheinrich-multisite-migrate')
            : '';
        $rmmigrate_empty_icon = $rmmigrate_filters_active
            ? 'dashicons-search'
            : 'dashicons-list-view';
        $rmmigrate_empty_class = 'mm-empty-state--activity';
        include RMMIGRATE_PATH . 'admin/partials/components/empty-state.php';
        ?>
        </div>
    <?php else : ?>
    <div class="mm-table-scroll-wrap mm-table-cards-mobile">
    <table class="mm-premium-table mm-data-table mm-activity-table">
        <thead>
            <tr>
                <th class="column-date"><?php
                    printf(
                        /* translators: %s: site timezone label, e.g. America/New_York. */
                        esc_html__('Time (%s)', 'rosenheinrich-multisite-migrate'),
                        esc_html(Rmmigrate_Time::tz_label())
                    );
                ?></th>
                <th class="column-type"><?php esc_html_e('Type', 'rosenheinrich-multisite-migrate'); ?></th>
                <th class="column-status"><?php esc_html_e('Status', 'rosenheinrich-multisite-migrate'); ?></th>
                <th class="column-message"><?php esc_html_e('Message', 'rosenheinrich-multisite-migrate'); ?></th>
                <th class="column-actions"><?php esc_html_e('Actions', 'rosenheinrich-multisite-migrate'); ?></th>
            </tr>
        </thead>
        <tbody>
                <?php foreach ($rmmigrate_entries as $rmmigrate_entry) :
                    $rmmigrate_entry_id = $rmmigrate_entry['entry_id'] ?? '';
                    $rmmigrate_entry_status = (string) ($rmmigrate_entry['status'] ?? 'info');
                    $rmmigrate_entry_type = (string) ($rmmigrate_entry['type'] ?? '');
                    $rmmigrate_entry_job_id = (int) ($rmmigrate_entry['job_id'] ?? 0);
                    $rmmigrate_type_label = Rmmigrate_Activity_Log::type_label($rmmigrate_entry_type);
                    $rmmigrate_detail_title = sprintf(
                        /* translators: 1: event type label, 2: job id */
                        __('%1$s job #%2$d', 'rosenheinrich-multisite-migrate'),
                        $rmmigrate_type_label,
                        $rmmigrate_entry_job_id
                    );
                    if ($rmmigrate_entry_job_id <= 0) {
                        $rmmigrate_detail_title = $rmmigrate_type_label;
                    }
                    ?>
                    <tr data-entry-id="<?php echo esc_attr($rmmigrate_entry_id); ?>">
                        <td class="column-date mm-activity-time" data-label="<?php /* translators: %s: timezone label */ echo esc_attr(sprintf(__('Time (%s)', 'rosenheinrich-multisite-migrate'), Rmmigrate_Time::tz_label())); ?>">
                            <code><?php echo esc_html(Rmmigrate_Activity_Log::format_display_time((string) ($rmmigrate_entry['time'] ?? ''))); ?></code>
                        </td>
                        <td class="column-type" data-label="<?php echo esc_attr(__('Type', 'rosenheinrich-multisite-migrate')); ?>">
                            <span class="mm-status-chip mm-activity-type"><?php echo esc_html($rmmigrate_type_label); ?></span>
                        </td>
                        <td class="column-status" data-label="<?php echo esc_attr(__('Status', 'rosenheinrich-multisite-migrate')); ?>">
                            <span class="<?php echo esc_attr(Rmmigrate_Activity_Log::status_pill_class($rmmigrate_entry_status)); ?>">
                                <?php echo esc_html(Rmmigrate_Activity_Log::status_label($rmmigrate_entry_status)); ?>
                            </span>
                        </td>
                        <td class="column-message mm-activity-message" data-label="<?php echo esc_attr(__('Message', 'rosenheinrich-multisite-migrate')); ?>" title="<?php echo esc_attr((string) ($rmmigrate_entry['message'] ?? '')); ?>">
                            <?php echo esc_html($rmmigrate_entry['message'] ?? ''); ?>
                            <?php
                            $rmmigrate_bundled_count = (int) ($rmmigrate_entry['bundled_count'] ?? 0);
                            if ($rmmigrate_bundled_count > 1) :
                                ?>
                                <span class="mm-activity-bundled-hint">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %d: number of process events bundled into this row */
                                            __('(%d events — open Details)', 'rosenheinrich-multisite-migrate'),
                                            $rmmigrate_bundled_count
                                        )
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="column-actions mm-row-actions" data-label="<?php echo esc_attr(__('Actions', 'rosenheinrich-multisite-migrate')); ?>">
                            <button type="button" class="button button-small button-primary mm-btn-teal mm-activity-detail-btn" data-entry-id="<?php echo esc_attr($rmmigrate_entry_id); ?>" data-job-id="<?php echo esc_attr((string) $rmmigrate_entry_job_id); ?>" data-title="<?php echo esc_attr($rmmigrate_detail_title); ?>"><?php esc_html_e('Details', 'rosenheinrich-multisite-migrate'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($rmmigrate_activity_total_pages > 1) : ?>
        <div class="tablenav bottom mm-activity-pagination">
            <div class="tablenav-pages">
                <?php
                echo wp_kses_post(
                    paginate_links(
                        array(
                            'base'      => add_query_arg('paged', '%#%', $rmmigrate_pagination_base),
                            'format'    => '',
                            'current'   => $rmmigrate_activity_page,
                            'total'     => $rmmigrate_activity_total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        )
                    )
                );
                ?>
            </div>
        </div>
    <?php endif; ?>

    <p class="mm-table-footer">
        <?php
        if ($rmmigrate_activity_total > 0) {
            $rmmigrate_total_label = $rmmigrate_activity_total_estimated
                ? sprintf('%d+', $rmmigrate_activity_total)
                : (string) $rmmigrate_activity_total;
            printf(
                /* translators: 1: first row number, 2: last row number, 3: total events */
                esc_html__('Showing %1$s–%2$s of %3$s events', 'rosenheinrich-multisite-migrate'),
                (int) $rmmigrate_activity_start,
                (int) $rmmigrate_activity_end,
                esc_html($rmmigrate_total_label)
            );
            echo ' | ';
        }
        printf(
            /* translators: %s: site timezone label, e.g. America/New_York. */
            esc_html__('Times: %s (UTC date filters)', 'rosenheinrich-multisite-migrate'),
            esc_html(Rmmigrate_Time::tz_label())
        );
        ?>
    </p>
    <?php endif; ?>

    <p class="mm-table-footer mm-activity-storage-note">
        <?php
        echo wp_kses(
            sprintf(
                /* translators: %s: logs directory path wrapped in <code> */
                __('Logs are stored in %s. Large files rotate automatically. Adjust limits under Settings → Tools & logs.', 'rosenheinrich-multisite-migrate'),
                '<code class="mm-activity-storage-path">' . esc_html(wp_normalize_path(Rmmigrate_Activity_Log::logs_dir())) . '</code>'
            ),
            array(
                'code' => array('class' => true),
            )
        );
        ?>
    </p>
</div>

<?php include RMMIGRATE_PATH . 'admin/partials/activity-detail-modal.php'; ?>
