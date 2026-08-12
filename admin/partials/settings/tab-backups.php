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
        <th><?php esc_html_e('Encrypt archives at rest', 'rosenheinrich-multisite-migrate'); ?></th>
        <td>
            <label><input type="checkbox" name="encrypt_archives" value="1" <?php checked(!empty($rmmigrate_settings['encrypt_archives'])); ?>></label>
            <button type="button" class="mm-help-tip" aria-expanded="false" data-tip="<?php echo esc_attr(__('Encrypted backups use the .venc extension. You will need the passphrase to restore.', 'rosenheinrich-multisite-migrate')); ?>" aria-label="<?php esc_attr_e('Encryption help', 'rosenheinrich-multisite-migrate'); ?>">?</button>
        </td>
    </tr>
    <tr>
        <th><label for="archive_passphrase"><?php esc_html_e('Encryption passphrase', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td><input type="password" class="regular-text" name="archive_passphrase" id="archive_passphrase" value="<?php echo !empty($rmmigrate_settings['archive_passphrase']) ? '********' : ''; ?>"></td>
    </tr>
    <tr>
        <th><?php esc_html_e('Safety snapshot before restore', 'rosenheinrich-multisite-migrate'); ?></th>
        <td><label><input type="checkbox" name="safety_snapshot_enabled" value="1" <?php checked(!empty($rmmigrate_settings['safety_snapshot_enabled'])); ?>></label></td>
    </tr>
</table>
</div>
<div class="mm-form-section">
<h2><?php esc_html_e('Exclusions', 'rosenheinrich-multisite-migrate'); ?></h2>
<table class="form-table">
    <tr>
        <th><label for="exclude_paths"><?php esc_html_e('Extra exclude paths', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td>
            <textarea name="exclude_paths" id="exclude_paths" rows="4" class="large-text"><?php echo esc_textarea(implode("\n", $rmmigrate_settings['exclude_paths'])); ?></textarea>
            <p class="description"><?php esc_html_e('One path per line, relative to wp-content.', 'rosenheinrich-multisite-migrate'); ?></p>
        </td>
    </tr>
</table>
</div>
