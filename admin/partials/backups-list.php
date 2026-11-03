<?php
if (!defined('ABSPATH')) {

    exit;

}

$rmmigrate_filter = $rmmigrate_filter ?? Rmmigrate_Request_Input::get_text('filter', 'all');

$rmmigrate_list_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), $rmmigrate_is_network);
$rmmigrate_archives_embedded = !empty($rmmigrate_archives_embedded);
$rmmigrate_show_archives_hero = $rmmigrate_archives_embedded || !empty($rmmigrate_subsite_mode);
$rmmigrate_activity_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-activity', array(), $rmmigrate_is_network);
$rmmigrate_can_backup_create = Rmmigrate_Access::can_create_backup();
$rmmigrate_can_delete_backups = Rmmigrate_Access::can_delete_backup();
$rmmigrate_list_expert = class_exists('Rmmigrate_Expert_Mode', false) && Rmmigrate_Expert_Mode::enabled();

$rmmigrate_filters = array(
    'all'       => __('All', 'rosenheinrich-multisite-migrate'),
    'failed'    => __('Failed', 'rosenheinrich-multisite-migrate'),
    'completed' => __('Completed', 'rosenheinrich-multisite-migrate'),
);

$rmmigrate_setup_state = Rmmigrate_Setup_Wizard::get_state();

$rmmigrate_setup_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-setup', array(), $rmmigrate_is_network);

$rmmigrate_highlight_job_id = $rmmigrate_highlight_job_id ?? Rmmigrate_Request_Input::get_int('job_id');

$rmmigrate_date_from = Rmmigrate_Activity_Log::normalize_date_filter(
    Rmmigrate_Request_Input::get_text('date_from')
);
$rmmigrate_date_to = Rmmigrate_Activity_Log::normalize_date_filter(
    Rmmigrate_Request_Input::get_text('date_to')
);
$rmmigrate_date_filter_active = ($rmmigrate_date_from !== '' || $rmmigrate_date_to !== '');
$rmmigrate_list_page_slug = $rmmigrate_current_page ?? 'multisite-migrate-archives';
$rmmigrate_backups_page = $rmmigrate_backups_page ?? max(1, Rmmigrate_Request_Input::get_int('paged', 1));
$rmmigrate_backups_total_pages = $rmmigrate_backups_total_pages ?? 1;
$rmmigrate_pagination_base = $rmmigrate_pagination_base ?? add_query_arg(
    array_filter(
        array(
            'page'      => $rmmigrate_list_page_slug,
            'filter'    => $rmmigrate_filter !== 'all' ? $rmmigrate_filter : null,
            'date_from' => $rmmigrate_date_from !== '' ? $rmmigrate_date_from : null,
            'date_to'   => $rmmigrate_date_to !== '' ? $rmmigrate_date_to : null,
        ),
        static function ($val) {
            return $val !== null;
        }
    ),
    $rmmigrate_is_network ? network_admin_url('admin.php') : admin_url('admin.php')
);

?>



<?php include RMMIGRATE_PATH . 'admin/partials/components/restore-success-banner.php'; ?>

<?php include RMMIGRATE_PATH . 'admin/partials/components/verify-restore-banner.php'; ?>

<?php include RMMIGRATE_PATH . 'admin/partials/components/active-job-banner.php'; ?>

<?php
if (Rmmigrate_Request_Input::get_key('intent') === 'move') {
    $rmmigrate_banner = array(
        'variant' => 'info',
        'class'   => 'mm-move-intent-notice',
        'title'   => __('Moving to another server in three steps', 'rosenheinrich-multisite-migrate'),
        'text'    => __('1. Create a full backup here. 2. Download it when it is done. 3. Install Multisite Migrate on the new site, open Move site and choose the file.', 'rosenheinrich-multisite-migrate'),
        'actions' => array(
            array(
                'type'  => 'link',
                'label' => __('Read the migration guide', 'rosenheinrich-multisite-migrate'),
                'url'   => Rmmigrate_Links::docs_url('move_intent'),
                'attrs' => array('target' => '_blank', 'rel' => 'noopener noreferrer'),
            ),
        ),
    );
    include RMMIGRATE_PATH . 'admin/partials/components/admin-banner.php';
}
?>

<?php include RMMIGRATE_PATH . 'admin/partials/backup-create-panel.php'; ?>

<div id="mm-create-host">

<?php if ($rmmigrate_show_archives_hero) : ?>
<div class="mm-archives-hero mm-archives-hero--compact">
    <h2><?php
    echo esc_html(
        (!empty($rmmigrate_is_network) && empty($rmmigrate_subsite_mode))
            ? __('Protect this network', 'rosenheinrich-multisite-migrate')
            : __('Protect this site', 'rosenheinrich-multisite-migrate')
    );
    ?></h2>
    <p class="description mm-intent-assist__hint"><?php
    echo esc_html(
        (!empty($rmmigrate_is_network) && empty($rmmigrate_subsite_mode))
            ? __('One click saves every subsite, the shared tables and all files. Backups stay on this server and you can download them anytime.', 'rosenheinrich-multisite-migrate')
            : __('One click saves the database and all files. Backups stay on this server and you can download them anytime.', 'rosenheinrich-multisite-migrate')
    );
    ?></p>
    <?php
    $rmmigrate_intent_hide_primary = empty($rmmigrate_jobs) && $rmmigrate_filter === 'all' && !$rmmigrate_date_filter_active;
    include RMMIGRATE_PATH . 'admin/partials/components/intent-assist-bar.php';
    ?>
</div>
<?php else : ?>
    <?php include RMMIGRATE_PATH . 'admin/partials/components/intent-assist-bar.php'; ?>
<?php endif; ?>

<?php
// Short lists stay simple: filters, checkboxes and bulk delete appear from 4 backups on.
$rmmigrate_list_compact = count((array) $rmmigrate_jobs) < 4 && $rmmigrate_filter === 'all' && !$rmmigrate_date_filter_active;
?>
<?php if (!$rmmigrate_list_compact && (!empty($rmmigrate_jobs) || $rmmigrate_filter !== 'all' || $rmmigrate_date_filter_active)) : ?>
<div class="mm-toolbar">

    <form method="get" action="" class="mm-activity-filters mm-backups-filters">
        <input type="hidden" name="page" value="<?php echo esc_attr($rmmigrate_list_page_slug); ?>">
        <div class="mm-activity-filters__fields">
            <label class="mm-activity-filter">
                <span class="mm-activity-filter__label"><?php esc_html_e('Status', 'rosenheinrich-multisite-migrate'); ?></span>
                <select name="filter">
                    <?php foreach ($rmmigrate_filters as $rmmigrate_key => $rmmigrate_label) : ?>
                        <option value="<?php echo esc_attr($rmmigrate_key); ?>" <?php selected($rmmigrate_filter, $rmmigrate_key); ?>><?php echo esc_html($rmmigrate_label); ?></option>
                    <?php endforeach; ?>
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
            <?php if ($rmmigrate_filter !== 'all' || $rmmigrate_date_filter_active) : ?>
                <a class="button button-link" href="<?php echo esc_url($rmmigrate_list_url); ?>"><?php esc_html_e('Reset', 'rosenheinrich-multisite-migrate'); ?></a>
            <?php endif; ?>
        </div>
    </form>

    <div class="mm-toolbar-actions">
        <?php if (!empty($rmmigrate_jobs) && $rmmigrate_can_delete_backups) : ?>
            <button type="button" class="button mm-bulk-delete-backups" id="mm-bulk-delete-backups" disabled>
                <?php esc_html_e('Delete selected', 'rosenheinrich-multisite-migrate'); ?>
            </button>
        <?php endif; ?>
    </div>

</div>
<?php endif; ?>

<?php if (empty($rmmigrate_jobs)) : ?>
<div class="mm-form-section mm-backups-empty-section">
    <div class="mm-empty-section">
    <?php
    $rmmigrate_empty_actions = '';
    if ($rmmigrate_filter === 'failed') {
        $rmmigrate_empty_icon = 'dashicons-dismiss';
        $rmmigrate_empty_title = __('No failed jobs in the list', 'rosenheinrich-multisite-migrate');
        if (!empty($rmmigrate_last_error['message'])) {
            $rmmigrate_empty_message = sprintf(
                /* translators: 1: job ID, 2: error message. */
                __('Latest error (job #%1$d): %2$s', 'rosenheinrich-multisite-migrate'),
                (int) ($rmmigrate_last_error['job_id'] ?? 0),
                Rmmigrate_User_Error_Messages::for_admin_banner($rmmigrate_last_error)
            );
            $rmmigrate_empty_actions = '<a href="' . esc_url(Rmmigrate_Admin_Router::admin_url('multisite-migrate-activity', array(), $rmmigrate_is_network)) . '">' . esc_html__('View activity log', 'rosenheinrich-multisite-migrate') . '</a>';
        } else {
            $rmmigrate_empty_message = __('No failed backup or restore jobs.', 'rosenheinrich-multisite-migrate');
        }
        } elseif ($rmmigrate_filter === 'completed') {
            $rmmigrate_empty_icon = 'dashicons-yes-alt';
            $rmmigrate_empty_title = __('No completed jobs', 'rosenheinrich-multisite-migrate');
            $rmmigrate_empty_message = __('Completed backups and restores will appear here.', 'rosenheinrich-multisite-migrate');
        } else {
        $rmmigrate_empty_icon = 'dashicons-shield-alt';
        $rmmigrate_empty_title = __('No backups yet', 'rosenheinrich-multisite-migrate');
        $rmmigrate_empty_message = (!empty($rmmigrate_is_network) && empty($rmmigrate_subsite_mode))
            ? __('Your first backup covers every subsite, the shared tables, and all files. It is saved on this server and you can download it anytime.', 'rosenheinrich-multisite-migrate')
            : __('Your first backup covers the database and all files. It is saved on this server and you can download it anytime.', 'rosenheinrich-multisite-migrate');
        if ($rmmigrate_filter === 'all' && $rmmigrate_can_backup_create) {
            $rmmigrate_empty_actions = '<button type="button" class="button button-primary button-hero mm-btn-teal" id="mm-toggle-create-empty">'
                . esc_html(
                    (!empty($rmmigrate_is_network) && empty($rmmigrate_subsite_mode))
                        ? __('Back up the network now', 'rosenheinrich-multisite-migrate')
                        : __('Back up this site now', 'rosenheinrich-multisite-migrate')
                )
                . '</button>'
                . '<p class="description mm-empty-hint">' . esc_html__('Starts right away with safe defaults. Takes a few minutes; you can keep working.', 'rosenheinrich-multisite-migrate') . '</p>';
        }
    }
    include RMMIGRATE_PATH . 'admin/partials/components/empty-state.php';
    ?>
    </div>
</div>

<?php else : ?>

<div class="mm-table-scroll-wrap mm-table-cards-mobile">
<table class="multisite-migrate-archives-table mm-premium-table mm-data-table<?php echo $rmmigrate_list_compact ? ' mm-list-compact' : ''; ?>">

    <thead>

        <tr>

            <?php if ($rmmigrate_can_delete_backups) : ?>
            <th scope="col" class="manage-column column-cb check-column">
                <input type="checkbox" id="mm-select-all-backups" aria-label="<?php esc_attr_e('Select all deletable backups', 'rosenheinrich-multisite-migrate'); ?>">
            </th>
            <?php endif; ?>

                    <th class="column-date"><?php
                        printf(
                            /* translators: %s: site timezone label, e.g. America/New_York. */
                            esc_html__('Date (%s)', 'rosenheinrich-multisite-migrate'),
                            esc_html(Rmmigrate_Time::tz_label())
                        );
                    ?></th>

            <th class="column-type"><?php esc_html_e('Type', 'rosenheinrich-multisite-migrate'); ?></th>

            <?php if ($rmmigrate_list_expert) : ?>
            <th class="column-format"><?php esc_html_e('Format', 'rosenheinrich-multisite-migrate'); ?></th>
            <?php endif; ?>

            <th class="column-scope"><?php esc_html_e('Scope', 'rosenheinrich-multisite-migrate'); ?></th>

            <th class="column-status"><?php esc_html_e('Status', 'rosenheinrich-multisite-migrate'); ?></th>

            <th class="column-size"><?php esc_html_e('Size', 'rosenheinrich-multisite-migrate'); ?></th>

            <th class="column-local"><?php esc_html_e('Stored', 'rosenheinrich-multisite-migrate'); ?></th>

            <th class="column-actions"><?php esc_html_e('Actions', 'rosenheinrich-multisite-migrate'); ?></th>

        </tr>

    </thead>

    <tbody>

            <?php foreach ($rmmigrate_jobs as $rmmigrate_job) : ?>

                <?php

                $rmmigrate_is_complete = $rmmigrate_job->get_status() === Rmmigrate_Job::STATUS_COMPLETE;

                $rmmigrate_error = trim((string) ($rmmigrate_job->data['error_message'] ?? ''));

                $rmmigrate_is_failed = $rmmigrate_job->get_status() === Rmmigrate_Job::STATUS_ERROR
                    || ($rmmigrate_is_complete && $rmmigrate_error !== '');

                $rmmigrate_is_active = $rmmigrate_job->get_status() >= 0 && $rmmigrate_job->get_status() < 100;

                $rmmigrate_is_highlight = $rmmigrate_highlight_job_id > 0 && $rmmigrate_job->get_id() === $rmmigrate_highlight_job_id;

                $rmmigrate_cancel_notice = '';
                if ($rmmigrate_job->get_status() === Rmmigrate_Job::STATUS_CANCELLED && $rmmigrate_job->is_restore()) {
                    $rmmigrate_job_progress = $rmmigrate_job->get_progress();
                    $rmmigrate_cancel_notice = (string) ($rmmigrate_job_progress['cancel_notice'] ?? '');
                }

                $rmmigrate_row_class = trim(($rmmigrate_is_active ? 'mm-job-row-active' : '') . ($rmmigrate_is_highlight ? ' mm-job-row-highlight' : ''));

                $rmmigrate_job_manifest = $rmmigrate_job->get_progress()['manifest'] ?? array();
                $rmmigrate_has_core = !empty($rmmigrate_job_manifest['has_wp_core_files']);

                ?>

                <tr<?php if ($rmmigrate_row_class !== '') {
                    echo ' class="' . esc_attr($rmmigrate_row_class) . '"';
                } ?><?php if ($rmmigrate_is_active) {
                    echo ' data-job-id="' . esc_attr((string) $rmmigrate_job->get_id()) . '"';
                } ?>>

                    <?php if ($rmmigrate_can_delete_backups) : ?>
                    <th scope="row" class="check-column" data-label="<?php esc_attr_e('Select', 'rosenheinrich-multisite-migrate'); ?>">
                        <?php if (!$rmmigrate_is_active) : ?>
                            <input type="checkbox" class="mm-backup-select" name="job_ids[]" value="<?php echo esc_attr((string) $rmmigrate_job->get_id()); ?>" aria-label="<?php echo esc_attr(sprintf(
                                /* translators: %d: job ID */
                                __('Select backup #%d', 'rosenheinrich-multisite-migrate'),
                                (int) $rmmigrate_job->get_id()
                            )); ?>">
                        <?php endif; ?>
                    </th>
                    <?php endif; ?>

                    <td data-label="<?php /* translators: %s: timezone label */ echo esc_attr(sprintf(__('Date (%s)', 'rosenheinrich-multisite-migrate'), Rmmigrate_Time::tz_label())); ?>"><?php
                        $rmmigrate_job_date = current(array_filter(array($rmmigrate_job->data['completed_at'] ?? '', $rmmigrate_job->data['created_at'] ?? '')));
                        echo esc_html($rmmigrate_job_date ? Rmmigrate_Time::local((string) $rmmigrate_job_date) : '');
                    ?></td>

                    <td data-label="<?php echo esc_attr(__('Type', 'rosenheinrich-multisite-migrate')); ?>"><?php echo esc_html($rmmigrate_job->get_display_job_type()); ?></td>

                    <?php if ($rmmigrate_list_expert) : ?>
                    <td data-label="<?php echo esc_attr(__('Format', 'rosenheinrich-multisite-migrate')); ?>"><?php
                        $rmmigrate_job_format = strtoupper($rmmigrate_job->get_progress()['archive']['format'] ?? '');
                        if ($rmmigrate_job_format === '') {
                            $rmmigrate_mm_path = Rmmigrate_Runner::resolve_local_path($rmmigrate_job);
                            $rmmigrate_mm_ext = strtolower(pathinfo($rmmigrate_mm_path, PATHINFO_EXTENSION));
                            if ($rmmigrate_mm_ext === 'daf' || $rmmigrate_mm_ext === 'zip') {
                                $rmmigrate_job_format = strtoupper($rmmigrate_mm_ext);
                            } else {
                                $rmmigrate_job_format = strtoupper(
                                    Rmmigrate_Hosting_Detection::effective_archive_mode_for_job($rmmigrate_job)
                                );
                            }
                        }
                        echo esc_html($rmmigrate_job_format);
                    ?></td>
                    <?php endif; ?>

                    <td data-label="<?php echo esc_attr(__('Scope', 'rosenheinrich-multisite-migrate')); ?>"><?php echo esc_html($rmmigrate_job->get_display_scope()); ?></td>

                    <td data-label="<?php echo esc_attr(__('Status', 'rosenheinrich-multisite-migrate')); ?>">

                        <span class="mm-status-pill mm-status-<?php echo esc_attr($rmmigrate_is_failed ? 'error' : ($rmmigrate_is_complete ? 'ok' : 'active')); ?><?php echo $rmmigrate_is_active ? ' mm-live-job-status' : ''; ?>"<?php echo $rmmigrate_is_active ? ' aria-live="polite"' : ''; ?>>

                            <?php echo esc_html($rmmigrate_job->get_display_status()); ?>

                        </span>

                        <?php if ($rmmigrate_is_active) : ?>
                            <?php
                            $rmmigrate_live_phase = (string) $rmmigrate_job->get_progress_message();
                            ?>
                            <span class="description mm-live-job-phase" aria-live="polite"<?php echo $rmmigrate_live_phase !== '' ? ' title="' . esc_attr($rmmigrate_live_phase) . '"' : ''; ?>><?php echo esc_html($rmmigrate_live_phase); ?></span>
                        <?php endif; ?>

                        <?php if ($rmmigrate_is_failed && $rmmigrate_error !== '') : ?>

                            <button type="button" class="button-link mm-activity-detail-btn" data-job-id="<?php echo esc_attr((string) $rmmigrate_job->get_id()); ?>" data-title="<?php esc_attr_e('Error details', 'rosenheinrich-multisite-migrate'); ?>"><?php esc_html_e('View error', 'rosenheinrich-multisite-migrate'); ?></button>

                        <?php endif; ?>

                        <?php if ($rmmigrate_cancel_notice !== '') : ?>
                            <p class="description mm-cancel-notice"><?php echo esc_html($rmmigrate_cancel_notice); ?></p>
                        <?php endif; ?>

                    </td>

                    <td data-label="<?php echo esc_attr(__('Size', 'rosenheinrich-multisite-migrate')); ?>"><?php echo esc_html(Rmmigrate_Local_Storage::format_bytes((int) ($rmmigrate_job->data['file_size'] ?? 0))); ?></td>

                    <td class="column-local" data-label="<?php echo esc_attr(__('Stored', 'rosenheinrich-multisite-migrate')); ?>"><?php
                    $rmmigrate_has_local = !empty($rmmigrate_job->data['local_path']);
                    $rmmigrate_dest = strtolower((string) ($rmmigrate_job->data['destination'] ?? 'local'));
                    $rmmigrate_local_dest = ($rmmigrate_dest === '' || $rmmigrate_dest === 'local');
                    // local_path is set after archive write — local-dest jobs in progress are still on this server.
                    $rmmigrate_show_local = $rmmigrate_has_local || ($rmmigrate_local_dest && $rmmigrate_is_active);
                    if ($rmmigrate_show_local) {
                        echo '<span class="mm-local-badge" title="' . esc_attr__('Backup archive is stored on this server', 'rosenheinrich-multisite-migrate') . '">';
                        echo '<span class="dashicons dashicons-database" aria-hidden="true"></span>';
                        echo '<span class="mm-local-badge__text">' . esc_html__('On this server', 'rosenheinrich-multisite-migrate') . '</span>';
                        echo '</span>';
                    } else {
                        echo '<span class="mm-local-none" aria-hidden="true">&mdash;</span>';
                    }
                    ?></td>

                    <td class="mm-row-actions column-actions" data-label="<?php echo esc_attr(__('Actions', 'rosenheinrich-multisite-migrate')); ?>">
                        <div class="mm-row-buttons">
                        <?php if ($rmmigrate_is_complete && $rmmigrate_job->get_job_type() === Rmmigrate_Job::JOB_TYPE_BACKUP && Rmmigrate_Recovery_Points::is_restorable($rmmigrate_job)) : ?>
                            <?php if (!empty($rmmigrate_job->data['local_path'])) : ?>
                                <a class="button mm-download-backup" href="<?php echo esc_url(add_query_arg(array(
	'action' => 'rmmigrate_download',
	'job_id' => $rmmigrate_job->get_id(),
	'nonce'  => wp_create_nonce('rmmigrate_admin'),
), admin_url('admin-ajax.php'))); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e('Download', 'rosenheinrich-multisite-migrate'); ?></a>
                            <?php endif; ?>
                            <button type="button" class="button mm-restore-backup" data-job-id="<?php echo esc_attr($rmmigrate_job->get_id()); ?>"><span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e('Restore', 'rosenheinrich-multisite-migrate'); ?></button>
                        <?php endif; ?>
                        <?php if ($rmmigrate_is_active) : ?>
                            <a href="#mm-active-job-banner" class="button mm-view-job-progress" data-job-id="<?php echo esc_attr((string) $rmmigrate_job->get_id()); ?>" data-job-type="<?php echo esc_attr($rmmigrate_job->get_job_type()); ?>"><?php esc_html_e('View progress', 'rosenheinrich-multisite-migrate'); ?></a>
                        <?php endif; ?>
                        <?php if (!$rmmigrate_is_active && $rmmigrate_can_delete_backups) : ?>
                            <div class="mm-dropdown mm-row-more">
                                <button type="button" class="button mm-dropdown-toggle" aria-expanded="false" aria-label="<?php esc_attr_e('More actions', 'rosenheinrich-multisite-migrate'); ?>" title="<?php esc_attr_e('More actions', 'rosenheinrich-multisite-migrate'); ?>"><?php esc_html_e('More', 'rosenheinrich-multisite-migrate'); ?></button>
                                <div class="mm-dropdown-menu">
                                    <span class="mm-action-item mm-action-danger">
                                        <button type="button" class="button-link mm-delete-backup" data-job-id="<?php echo esc_attr($rmmigrate_job->get_id()); ?>"><?php esc_html_e('Delete', 'rosenheinrich-multisite-migrate'); ?></button>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                        </div>
                    </td>

                </tr>

            <?php endforeach; ?>

    </tbody>

</table>
</div>

<?php if (!empty($rmmigrate_backups_total_pages) && $rmmigrate_backups_total_pages > 1) : ?>
    <div class="tablenav bottom mm-backups-pagination">
        <div class="tablenav-pages">
            <?php
            echo wp_kses_post(
                paginate_links(
                    array(
                        'base'      => add_query_arg('paged', '%#%', $rmmigrate_pagination_base),
                        'format'    => '',
                        'current'   => $rmmigrate_backups_page,
                        'total'     => $rmmigrate_backups_total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    )
                )
            );
            ?>
        </div>
    </div>
<?php endif; ?>

<?php endif; ?>



<?php if ($rmmigrate_last) : ?>
<p class="mm-table-footer">
    <?php
    printf(
        /* translators: %1$s: last backup completion date. */
        esc_html__('Last backup: %1$s', 'rosenheinrich-multisite-migrate'),
        esc_html(Rmmigrate_Time::local_label((string) ($rmmigrate_last->data['completed_at'] ?? '')))
    );
    ?>
</p>
<?php endif; ?>

</div><!-- #mm-create-host -->

<?php include RMMIGRATE_PATH . 'admin/partials/restore-dialog.php'; ?>



<div id="mm-restore-progress" class="mm-hidden mm-form-section">

    <h2 id="mm-restore-progress-title"><?php esc_html_e('Restore in progress', 'rosenheinrich-multisite-migrate'); ?></h2>

    <div class="mm-progress-bar"><div class="mm-progress-fill" style="width:0%"></div></div>

    <p class="mm-restore-progress-text">0%</p>

    <p><button type="button" class="button" id="mm-restore-cancel-progress"><?php esc_html_e('Cancel restore', 'rosenheinrich-multisite-migrate'); ?></button></p>

</div>


