<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mm-form-section">
    <h2><?php esc_html_e('Retention', 'rosenheinrich-multisite-migrate'); ?></h2>
    <p class="description"><?php esc_html_e('How many completed backups to keep. Set to 0 to keep all backups (no automatic deletion).', 'rosenheinrich-multisite-migrate'); ?></p>
    <table class="form-table">
        <tr>
            <th><label for="retention_network"><?php esc_html_e('Network backups to keep', 'rosenheinrich-multisite-migrate'); ?></label></th>
            <td>
                <input type="number" min="0" name="retention_network" id="retention_network" value="<?php echo esc_attr((string) ($rmmigrate_settings['retention_network'] ?? 0)); ?>">
                <p class="description"><?php esc_html_e('0 = keep all network backups.', 'rosenheinrich-multisite-migrate'); ?></p>
            </td>
        </tr>
        <?php if (is_multisite()) : ?>
            <tr>
                <th><label for="retention_subsite"><?php esc_html_e('Per-subsite backups to keep', 'rosenheinrich-multisite-migrate'); ?></label></th>
                <td>
                    <input type="number" min="0" name="retention_subsite" id="retention_subsite" value="<?php echo esc_attr((string) ($rmmigrate_settings['retention_subsite'] ?? 0)); ?>">
                    <p class="description"><?php esc_html_e('0 = keep all per-subsite backups.', 'rosenheinrich-multisite-migrate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Deleted subsites', 'rosenheinrich-multisite-migrate'); ?></th>
                <td>
                    <label><input type="checkbox" name="exclude_deleted_subsite_tables" value="1" <?php checked(!empty($rmmigrate_settings['exclude_deleted_subsite_tables'])); ?>> <?php esc_html_e('Exclude database tables from deleted subsites', 'rosenheinrich-multisite-migrate'); ?></label>
                    <p class="description"><?php esc_html_e('Skips orphaned wp_N_* tables when backing up the full network.', 'rosenheinrich-multisite-migrate'); ?></p>
                </td>
            </tr>
        <?php endif; ?>
    </table>
</div>
