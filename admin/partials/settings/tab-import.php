<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mm-form-section">
<h2><?php esc_html_e('Import settings', 'rosenheinrich-multisite-migrate'); ?></h2>
<table class="form-table">
    <tr>
        <th><label for="import_chunk_size"><?php esc_html_e('Upload chunk size', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td>
            <select name="import_chunk_size" id="import_chunk_size">
                <?php foreach (array('1M' => 1048576, '2M' => 2097152, '5M' => 5242880, '8M' => 8388608) as $rmmigrate_label => $rmmigrate_bytes) : ?>
                    <option value="<?php echo esc_attr((string) $rmmigrate_bytes); ?>" <?php selected((int) ($rmmigrate_settings['import_chunk_size'] ?? 2097152), $rmmigrate_bytes); ?>><?php echo esc_html($rmmigrate_label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Chunk size for local archive uploads on the Import page.', 'rosenheinrich-multisite-migrate'); ?></p>
        </td>
    </tr>
</table>
</div>
