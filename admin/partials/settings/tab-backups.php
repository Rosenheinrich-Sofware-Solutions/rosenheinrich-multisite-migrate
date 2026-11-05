<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mm-form-section">
<h2><?php esc_html_e('Security layers', 'rosenheinrich-multisite-migrate'); ?></h2>
<p class="description"><?php esc_html_e('Optional: protect backup files with a passphrase. You will need it to restore.', 'rosenheinrich-multisite-migrate'); ?></p>
<table class="form-table">
    <tr>
        <th scope="row" id="encrypt_archives-label"><?php esc_html_e('Encrypt archives at rest', 'rosenheinrich-multisite-migrate'); ?></th>
        <td>
            <input type="checkbox" name="encrypt_archives" id="encrypt_archives" value="1" <?php checked(!empty($rmmigrate_settings['encrypt_archives'])); ?> aria-labelledby="encrypt_archives-label">
            <button type="button" class="mm-help-tip" id="encrypt_archives_help" aria-labelledby="encrypt_archives encrypt_archives_help" aria-expanded="false" data-tip="<?php echo esc_attr(__('Encrypted backups use the .venc extension. You will need the passphrase to restore.', 'rosenheinrich-multisite-migrate')); ?>" aria-label="<?php esc_attr_e('Encryption help', 'rosenheinrich-multisite-migrate'); ?>">?</button>
        </td>
    </tr>
    <tr>
        <th><label for="archive_passphrase"><?php esc_html_e('Encryption passphrase', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td><input type="password" class="regular-text" name="archive_passphrase" id="archive_passphrase" value="<?php echo !empty($rmmigrate_settings['archive_passphrase']) ? '********' : ''; ?>"></td>
    </tr>
    <?php if (!empty($rmmigrate_settings['encrypt_archives']) && Rmmigrate_Settings::get_archive_passphrase() === '') : ?>
    <tr>
        <th scope="row"><?php esc_html_e('Passphrase warning', 'rosenheinrich-multisite-migrate'); ?></th>
        <td>
            <p class="mm-status-warn description"><?php esc_html_e('Encryption is enabled without a passphrase. New archives will be obfuscated, not confidential — set a passphrase for real protection.', 'rosenheinrich-multisite-migrate'); ?></p>
        </td>
    </tr>
    <?php endif; ?>
    <tr>
        <th scope="row"><label for="safety_snapshot_enabled"><?php esc_html_e('Safety snapshot before restore', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td><input type="checkbox" name="safety_snapshot_enabled" id="safety_snapshot_enabled" value="1" <?php checked(!empty($rmmigrate_settings['safety_snapshot_enabled'])); ?>></td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Backup before updates (optional)', 'rosenheinrich-multisite-migrate'); ?></th>
        <td>
            <label for="mm-auto-backup-before-update">
                <input type="checkbox" name="auto_backup_before_update" id="mm-auto-backup-before-update" value="1" aria-describedby="mm-auto-backup-before-update-desc" <?php checked(!empty($rmmigrate_settings['auto_backup_before_update'])); ?>>
                <?php esc_html_e('Start a backup automatically when a WordPress, plugin, or theme update begins', 'rosenheinrich-multisite-migrate'); ?>
            </label>
            <p class="description" id="mm-auto-backup-before-update-desc"><?php esc_html_e('The plugin starts the backup as soon as the update begins and runs the first steps immediately; the rest may finish shortly after. For a guaranteed restore point, create a backup manually before you update.', 'rosenheinrich-multisite-migrate'); ?></p>
            <p>
                <label for="mm-pre-update-profile"><?php esc_html_e('What to back up', 'rosenheinrich-multisite-migrate'); ?></label>
                <select name="pre_update_profile" id="mm-pre-update-profile">
                    <option value="db" <?php selected(($rmmigrate_settings['pre_update_profile'] ?? 'db'), 'db'); ?>><?php esc_html_e('Database only (fast)', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="full" <?php selected(($rmmigrate_settings['pre_update_profile'] ?? 'db'), 'full'); ?>><?php esc_html_e('Database and files (lets you undo a broken plugin or theme update)', 'rosenheinrich-multisite-migrate'); ?></option>
                </select>
            </p>
            <p>
                <label for="mm-retention-pre-update"><?php esc_html_e('Maximum pre-update backups to keep', 'rosenheinrich-multisite-migrate'); ?></label>
                <input type="number" class="small-text" name="retention_pre_update" id="mm-retention-pre-update" min="1" max="20" value="<?php echo esc_attr((string) max(1, min(20, (int) ($rmmigrate_settings['retention_pre_update'] ?? 3)))); ?>">
            </p>
        </td>
    </tr>
</table>
</div>
<div class="mm-form-section mm-expert-only"<?php echo Rmmigrate_Expert_Mode::enabled() ? '' : ' hidden'; ?>>
<h2><?php esc_html_e('Exclusions', 'rosenheinrich-multisite-migrate'); ?></h2>
<table class="form-table">
    <tr>
        <th><label for="exclude_paths"><?php esc_html_e('Extra exclude paths', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td>
            <textarea name="exclude_paths" id="exclude_paths" rows="4" class="large-text"><?php echo esc_textarea(implode("\n", (array) ($rmmigrate_settings['exclude_paths'] ?? array()))); ?></textarea>
            <p class="description"><?php esc_html_e('One path per line, relative to wp-content.', 'rosenheinrich-multisite-migrate'); ?></p>
        </td>
    </tr>
</table>
</div>
