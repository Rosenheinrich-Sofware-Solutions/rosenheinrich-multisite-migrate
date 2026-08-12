<?php
if (!defined('ABSPATH')) {
    exit;
}
$rmmigrate_default_local = $rmmigrate_settings['local_storage_path'] ?? '';
$rmmigrate_default_local_hint = Rmmigrate_Engine_Config::default_local_storage_path();
?>
<div class="mm-form-section">
<h2><?php esc_html_e('Local storage', 'rosenheinrich-multisite-migrate'); ?></h2>
<table class="form-table">
    <tr>
        <th><label for="local_storage_path"><?php esc_html_e('Local archives path', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td>
            <input type="text" class="large-text" name="local_storage_path" id="local_storage_path" value="<?php echo esc_attr($rmmigrate_default_local); ?>" placeholder="<?php echo esc_attr($rmmigrate_default_local_hint); ?>">
            <p class="description">
                <?php
                printf(
                    /* translators: %s: default backup archive directory path. */
                    esc_html__('Leave empty to use the default in your uploads directory: %s. You may set a relative (to WordPress root) or absolute path.', 'rosenheinrich-multisite-migrate'),
                    '<code>' . esc_html($rmmigrate_default_local_hint) . '</code>'
                );
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <th><?php esc_html_e('.htaccess protection', 'rosenheinrich-multisite-migrate'); ?></th>
        <td><label><input type="checkbox" name="storage_htaccess" value="1" <?php checked($rmmigrate_settings['storage_htaccess'] ?? true); ?>> <?php esc_html_e('Write .htaccess rules to block direct web access to archives', 'rosenheinrich-multisite-migrate'); ?></label></td>
    </tr>
</table>
</div>
<?php
Rmmigrate_Pro_Hints::render('storage_cloud', array(
    'title'     => __('Off-site storage & schedules (Plus)', 'rosenheinrich-multisite-migrate'),
    'text'      => __('Keep copies off this server, run incremental schedules, and get email when a backup fails — Free local storage alone cannot cover host outages.', 'rosenheinrich-multisite-migrate'),
    'cta_label' => __('See Plus cloud & schedules', 'rosenheinrich-multisite-migrate'),
    'placement' => 'plugin_intent_offsite',
));
?>
