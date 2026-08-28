<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mm-form-section">
<h2><?php esc_html_e('Security layers', 'rosenheinrich-multisite-migrate'); ?></h2>
<p class="description"><?php esc_html_e('Encrypt archives at rest with a passphrase (.venc files). Archive format and WordPress core inclusion are chosen in the backup wizard and remembered from your last run.', 'rosenheinrich-multisite-migrate'); ?></p>
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
</table>
</div>
<div class="mm-form-section">
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
