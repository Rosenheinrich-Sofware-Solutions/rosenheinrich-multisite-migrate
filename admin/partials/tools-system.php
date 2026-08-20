<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_diag_url = wp_nonce_url(
    add_query_arg('action', 'rmmigrate_diagnostic_zip', $rmmigrate_is_network ? network_admin_url('edit.php') : admin_url('admin-post.php')),
    'rmmigrate_diagnostic_zip'
);
$rmmigrate_activity_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-activity', array(), $rmmigrate_is_network);
$rmmigrate_health_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-health', array(), $rmmigrate_is_network);
$rmmigrate_log_max_mb = (int) round((Rmmigrate_Engine_Config::log_max_bytes()) / 1048576);
?>

<div class="mm-form-section mm-tools-panel">
    <div class="mm-tools-panel__head">
        <div class="mm-tools-panel__head-text">
            <h2><?php esc_html_e('Tools & logs', 'rosenheinrich-multisite-migrate'); ?></h2>
            <p class="description"><?php esc_html_e('Housekeeping for backups and temporary files. Server health checks live on the Health page.', 'rosenheinrich-multisite-migrate'); ?></p>
        </div>
        <a class="mm-panel-header-link" href="<?php echo esc_url($rmmigrate_health_url); ?>"><?php esc_html_e('Open Health', 'rosenheinrich-multisite-migrate'); ?> &rarr;</a>
    </div>

    <div class="mm-tools-grid" role="list">
        <article class="mm-tool-card" role="listitem">
            <div class="mm-tool-card__top">
                <span class="mm-tool-card__icon" aria-hidden="true"><span class="dashicons dashicons-trash"></span></span>
                <div class="mm-tool-card__heading">
                    <h3 class="mm-tool-card__title"><?php esc_html_e('Retention prune', 'rosenheinrich-multisite-migrate'); ?></h3>
                    <span class="mm-tool-card__pill"><?php esc_html_e('Backups', 'rosenheinrich-multisite-migrate'); ?></span>
                </div>
            </div>
            <p class="mm-tool-card__desc"><?php esc_html_e('Delete finished backups that exceed your retention settings.', 'rosenheinrich-multisite-migrate'); ?></p>
            <p class="mm-tool-card__status" id="mm-prune-status" role="status" aria-live="polite" hidden></p>
            <div class="mm-tool-card__footer">
                <button type="button" class="button button-primary mm-btn-teal" id="mm-prune-now"><?php esc_html_e('Run now', 'rosenheinrich-multisite-migrate'); ?></button>
            </div>
        </article>

        <article class="mm-tool-card" role="listitem">
            <div class="mm-tool-card__top">
                <span class="mm-tool-card__icon" aria-hidden="true"><span class="dashicons dashicons-database-remove"></span></span>
                <div class="mm-tool-card__heading">
                    <h3 class="mm-tool-card__title"><?php esc_html_e('Temporary storage', 'rosenheinrich-multisite-migrate'); ?></h3>
                    <span class="mm-tool-card__pill"><?php esc_html_e('Cleanup', 'rosenheinrich-multisite-migrate'); ?></span>
                </div>
            </div>
            <p class="mm-tool-card__desc"><?php esc_html_e('Removes orphaned job work folders and partial uploads. Finished backups are kept.', 'rosenheinrich-multisite-migrate'); ?></p>
            <p class="mm-tool-card__status" id="mm-clean-storage-status" role="status" aria-live="polite" hidden></p>
            <div class="mm-tool-card__footer">
                <button type="button" class="button button-primary mm-btn-teal" id="mm-clean-storage"><?php esc_html_e('Clean now', 'rosenheinrich-multisite-migrate'); ?></button>
            </div>
        </article>
    </div>

    <div class="mm-tools-resources">
        <h3 class="mm-tools-resources__title"><?php esc_html_e('Resources', 'rosenheinrich-multisite-migrate'); ?></h3>
        <div class="mm-tools-resources__grid">
            <a class="mm-tool-link-card mm-tool-link-card--action" href="<?php echo esc_url($rmmigrate_diag_url); ?>">
                <span class="mm-tool-link-card__icon" aria-hidden="true"><span class="dashicons dashicons-download"></span></span>
                <span class="mm-tool-link-card__body">
                    <strong class="mm-tool-link-card__title"><?php esc_html_e('Download diagnostic ZIP', 'rosenheinrich-multisite-migrate'); ?></strong>
                    <span class="mm-tool-link-card__desc"><?php esc_html_e('Export logs and environment details for support.', 'rosenheinrich-multisite-migrate'); ?></span>
                </span>
            </a>
            <a class="mm-tool-link-card" href="<?php echo esc_url($rmmigrate_activity_url); ?>">
                <span class="mm-tool-link-card__icon" aria-hidden="true"><span class="dashicons dashicons-list-view"></span></span>
                <span class="mm-tool-link-card__body">
                    <strong class="mm-tool-link-card__title"><?php esc_html_e('Activity & job logs', 'rosenheinrich-multisite-migrate'); ?></strong>
                    <span class="mm-tool-link-card__desc"><?php esc_html_e('Open the activity feed and raw job logs.', 'rosenheinrich-multisite-migrate'); ?></span>
                </span>
                <span class="mm-tool-link-card__chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
            </a>
        </div>
    </div>
</div>

<div class="mm-form-section">
    <h2><?php esc_html_e('Safe admin mode', 'rosenheinrich-multisite-migrate'); ?></h2>
    <form method="post" action="<?php echo esc_url($rmmigrate_is_network ? network_admin_url('edit.php?action=rmmigrate_settings') : admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('rmmigrate_settings'); ?>
        <input type="hidden" name="settings_tab" value="system">
        <?php if (!$rmmigrate_is_network) : ?>
            <input type="hidden" name="action" value="rmmigrate_settings">
        <?php endif; ?>
        <label><input type="checkbox" name="admin_safe_mode" value="1" <?php checked(!empty($rmmigrate_settings['admin_safe_mode'])); ?>> <?php esc_html_e('Disable foreign plugin CSS/JS on Multisite Migrate admin pages', 'rosenheinrich-multisite-migrate'); ?></label>
        <p>
            <label for="log_retention_months"><?php esc_html_e('Log retention (months)', 'rosenheinrich-multisite-migrate'); ?></label>
            <input type="number" name="log_retention_months" id="log_retention_months" min="1" max="120" value="<?php echo esc_attr((string) ($rmmigrate_settings['log_retention_months'] ?? 6)); ?>" class="small-text">
            <span class="description"><?php esc_html_e('Activity log entries and log files older than this are pruned hourly. Whole rotated files may be deleted when older than the cutoff.', 'rosenheinrich-multisite-migrate'); ?></span>
        </p>
        <div class="mm-form-actions mm-form-actions--inline">
            <?php submit_button(__('Save', 'rosenheinrich-multisite-migrate'), 'primary mm-btn-teal', 'submit', false); ?>
        </div>
    </form>
</div>

<div class="mm-form-section mm-log-storage-panel">
    <h2><?php esc_html_e('Log storage', 'rosenheinrich-multisite-migrate'); ?></h2>
    <p class="description"><?php esc_html_e('Size limits rotate large log files automatically. Activity entries are trimmed on the current activity.jsonl file before rotation.', 'rosenheinrich-multisite-migrate'); ?></p>
    <form method="post" action="<?php echo esc_url($rmmigrate_is_network ? network_admin_url('edit.php?action=rmmigrate_settings') : admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('rmmigrate_settings'); ?>
        <input type="hidden" name="settings_tab" value="system">
        <?php if (!$rmmigrate_is_network) : ?>
            <input type="hidden" name="action" value="rmmigrate_settings">
        <?php endif; ?>
        <div class="mm-settings-fields">
            <div class="mm-settings-field">
                <label for="log_max_bytes"><?php esc_html_e('Max log file size', 'rosenheinrich-multisite-migrate'); ?></label>
                <select name="log_max_bytes" id="log_max_bytes">
                    <?php foreach (array(1 => 1048576, 2 => 2097152, 5 => 5242880, 10 => 10485760) as $rmmigrate_mb => $rmmigrate_bytes) : ?>
                        <option value="<?php echo esc_attr((string) $rmmigrate_bytes); ?>" <?php selected($rmmigrate_log_max_mb, $rmmigrate_mb); ?>><?php echo esc_html(sprintf(/* translators: %d: megabytes */ __('%d MB', 'rosenheinrich-multisite-migrate'), $rmmigrate_mb)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mm-settings-field">
                <label for="log_max_rotated_files"><?php esc_html_e('Rotated copies to keep', 'rosenheinrich-multisite-migrate'); ?></label>
                <input type="number" name="log_max_rotated_files" id="log_max_rotated_files" min="2" max="10" value="<?php echo esc_attr((string) ($rmmigrate_settings['log_max_rotated_files'] ?? 5)); ?>">
            </div>
            <div class="mm-settings-field">
                <label for="activity_max_entries"><?php esc_html_e('Activity entries to keep', 'rosenheinrich-multisite-migrate'); ?></label>
                <input type="number" name="activity_max_entries" id="activity_max_entries" min="500" max="10000" value="<?php echo esc_attr((string) ($rmmigrate_settings['activity_max_entries'] ?? 2000)); ?>">
            </div>
            <div class="mm-settings-field">
                <label for="activity_page_size"><?php esc_html_e('Activity rows per page', 'rosenheinrich-multisite-migrate'); ?></label>
                <select name="activity_page_size" id="activity_page_size">
                    <?php foreach (array(10, 25, 50) as $rmmigrate_size) : ?>
                        <option value="<?php echo esc_attr((string) $rmmigrate_size); ?>" <?php selected((int) ($rmmigrate_settings['activity_page_size'] ?? 25), $rmmigrate_size); ?>><?php echo esc_html((string) $rmmigrate_size); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mm-settings-field mm-settings-field--full">
                <label for="log_view_lines"><?php esc_html_e('Raw log viewer lines per chunk', 'rosenheinrich-multisite-migrate'); ?></label>
                <input type="number" name="log_view_lines" id="log_view_lines" min="50" max="500" value="<?php echo esc_attr((string) ($rmmigrate_settings['log_view_lines'] ?? 200)); ?>">
            </div>
        </div>
        <div class="mm-form-actions mm-form-actions--inline">
            <?php submit_button(__('Save log limits', 'rosenheinrich-multisite-migrate'), 'primary mm-btn-teal', 'submit', false); ?>
        </div>
    </form>
</div>
