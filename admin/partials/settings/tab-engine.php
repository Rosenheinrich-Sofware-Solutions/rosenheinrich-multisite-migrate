<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mm-form-section">
<h2><?php esc_html_e('Engine limits', 'rosenheinrich-multisite-migrate'); ?></h2>
<table class="form-table">
    <tr>
        <th>
            <label for="server_throttle"><?php esc_html_e('Server throttle', 'rosenheinrich-multisite-migrate'); ?></label>
            <button type="button" class="mm-help-tip" aria-expanded="false" data-tip="<?php echo esc_attr(__('Adds short pauses between heavy backup and restore work so shared hosting stays responsive. Off is fastest; High is gentlest on the server. Use Low/Medium/High only if backups overload the host.', 'rosenheinrich-multisite-migrate')); ?>" aria-label="<?php esc_attr_e('Server throttle help', 'rosenheinrich-multisite-migrate'); ?>">?</button>
        </th>
        <td>
            <select name="server_throttle" id="server_throttle">
                <option value="off" <?php selected($rmmigrate_settings['server_throttle'] ?? 'off', 'off'); ?>><?php esc_html_e('Off', 'rosenheinrich-multisite-migrate'); ?></option>
                <option value="low" <?php selected($rmmigrate_settings['server_throttle'] ?? 'off', 'low'); ?>><?php esc_html_e('Low', 'rosenheinrich-multisite-migrate'); ?></option>
                <option value="med" <?php selected($rmmigrate_settings['server_throttle'] ?? 'off', 'med'); ?>><?php esc_html_e('Medium', 'rosenheinrich-multisite-migrate'); ?></option>
                <option value="high" <?php selected($rmmigrate_settings['server_throttle'] ?? 'off', 'high'); ?>><?php esc_html_e('High', 'rosenheinrich-multisite-migrate'); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="max_build_minutes"><?php esc_html_e('Max build time (minutes)', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td><input type="number" min="5" name="max_build_minutes" id="max_build_minutes" value="<?php echo esc_attr((string) ($rmmigrate_settings['max_build_minutes'] ?? 90)); ?>"></td>
    </tr>
    <tr>
        <th><label for="kickoff_mode"><?php esc_html_e('Worker kickoff', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td>
            <select name="kickoff_mode" id="kickoff_mode">
                <?php foreach (array('auto' => __('Auto (loopback, then cron/browser)', 'rosenheinrich-multisite-migrate'), 'loopback' => __('Loopback only', 'rosenheinrich-multisite-migrate'), 'cron' => __('WP-Cron only', 'rosenheinrich-multisite-migrate'), 'browser' => __('Browser polling only', 'rosenheinrich-multisite-migrate')) as $rmmigrate_val => $rmmigrate_label) : ?>
                    <option value="<?php echo esc_attr($rmmigrate_val); ?>" <?php selected($rmmigrate_settings['kickoff_mode'] ?? 'auto', $rmmigrate_val); ?>><?php echo esc_html($rmmigrate_label); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="lock_mode"><?php esc_html_e('Worker lock', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td>
            <select name="lock_mode" id="lock_mode">
                <?php foreach (array('auto' => __('Auto (SQL on NFS)', 'rosenheinrich-multisite-migrate'), 'file' => __('File lock', 'rosenheinrich-multisite-migrate'), 'sql' => __('Database lock', 'rosenheinrich-multisite-migrate')) as $rmmigrate_val => $rmmigrate_label) : ?>
                    <option value="<?php echo esc_attr($rmmigrate_val); ?>" <?php selected($rmmigrate_settings['lock_mode'] ?? 'auto', $rmmigrate_val); ?>><?php echo esc_html($rmmigrate_label); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <th><?php esc_html_e('Archive mode override', 'rosenheinrich-multisite-migrate'); ?></th>
        <td>
            <label><input type="checkbox" name="archive_mode_manual" value="1" <?php checked(!empty($rmmigrate_settings['archive_mode_manual'])); ?>> <?php esc_html_e('Do not auto-switch to DAF on short PHP timeouts (keeps the archive format from your last backup wizard run)', 'rosenheinrich-multisite-migrate'); ?></label>
        </td>
    </tr>
</table>
</div>
