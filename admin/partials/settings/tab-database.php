<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mm-form-section">
<h2><?php esc_html_e('Database export', 'rosenheinrich-multisite-migrate'); ?></h2>
<table class="form-table">
    <tr>
        <th><label for="db_mode"><?php esc_html_e('Export mode', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td>
            <select name="db_mode" id="db_mode">
                <option value="auto" <?php selected($rmmigrate_settings['db_mode'], 'auto'); ?>><?php esc_html_e('Auto', 'rosenheinrich-multisite-migrate'); ?></option>
                <option value="mysqldump" <?php selected($rmmigrate_settings['db_mode'], 'mysqldump'); ?>><?php esc_html_e('mysqldump', 'rosenheinrich-multisite-migrate'); ?></option>
                <option value="php" <?php selected($rmmigrate_settings['db_mode'], 'php'); ?>><?php esc_html_e('PHP', 'rosenheinrich-multisite-migrate'); ?></option>
            </select>
            <p class="description">
                <?php esc_html_e('Auto uses mysqldump when the binary is available and memory allows; otherwise PHP chunked export (5s slices). On shared hosts with short max_execution_time, choose PHP. mysqldump dumps one table per worker slice when multiple tables are included.', 'rosenheinrich-multisite-migrate'); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th><label for="mysqldump_path"><?php esc_html_e('mysqldump path', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td><input type="text" class="regular-text" name="mysqldump_path" id="mysqldump_path" value="<?php echo esc_attr($rmmigrate_settings['mysqldump_path']); ?>"></td>
    </tr>
    <tr>
        <th><label for="db_insert_query_limit"><?php esc_html_e('Insert query limit (bytes)', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td>
            <input type="number" min="32768" max="1048576" step="1024" name="db_insert_query_limit" id="db_insert_query_limit" value="<?php echo esc_attr((string) ($rmmigrate_settings['db_insert_query_limit'] ?? 131072)); ?>">
            <p class="description"><?php esc_html_e('Maximum size of each multi-row INSERT during PHP export (default 128 KB). Lower if you see max_allowed_packet errors.', 'rosenheinrich-multisite-migrate'); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="sql_import_chunk_bytes"><?php esc_html_e('SQL import chunk size (bytes)', 'rosenheinrich-multisite-migrate'); ?></label></th>
        <td>
            <input type="number" min="262144" max="4194304" step="262144" name="sql_import_chunk_bytes" id="sql_import_chunk_bytes" value="<?php echo esc_attr((string) ($rmmigrate_settings['sql_import_chunk_bytes'] ?? 1048576)); ?>">
            <p class="description"><?php esc_html_e('Bytes read from database.sql per restore slice (default 1 MB). Baked into installer packages.', 'rosenheinrich-multisite-migrate'); ?></p>
        </td>
    </tr>
    <tr>
        <th><?php esc_html_e('SQL import transactions', 'rosenheinrich-multisite-migrate'); ?></th>
        <td><label><input type="checkbox" name="sql_import_transactions" value="1" <?php checked(!empty($rmmigrate_settings['sql_import_transactions'])); ?>> <?php esc_html_e('Commit each restore slice in a transaction (plugin + installer via manifest)', 'rosenheinrich-multisite-migrate'); ?></label></td>
    </tr>
    <tr>
        <th><?php esc_html_e('Post-import URL scan', 'rosenheinrich-multisite-migrate'); ?></th>
        <td><label><input type="checkbox" name="post_import_search_replace" value="1" <?php checked(!empty($rmmigrate_settings['post_import_search_replace'])); ?>> <?php esc_html_e('Scan tables after import when SQL-time replace is insufficient (migrations)', 'rosenheinrich-multisite-migrate'); ?></label></td>
    </tr>
</table>
</div>
