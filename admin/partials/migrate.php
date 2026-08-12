<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_migrate_tab = $rmmigrate_migrate_tab ?? Rmmigrate_Request_Input::get_key('tab', 'import');
$rmmigrate_base_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-migrate', array(), $rmmigrate_is_network);
$rmmigrate_tabs = array(
    'import'        => __('Import', 'rosenheinrich-multisite-migrate'),
    'recovery'      => __('Recovery', 'rosenheinrich-multisite-migrate'),
    'searchreplace' => __('Search & Replace', 'rosenheinrich-multisite-migrate'),
);
$rmmigrate_active_tab = $rmmigrate_migrate_tab;
include RMMIGRATE_PATH . 'admin/partials/layout/tabs.php';

if ($rmmigrate_migrate_tab === 'import') {
    include RMMIGRATE_PATH . 'admin/partials/import.php';
} elseif ($rmmigrate_migrate_tab === 'recovery') {
    include RMMIGRATE_PATH . 'admin/partials/wizards/recovery-guide.php';
} elseif ($rmmigrate_migrate_tab === 'searchreplace') {
    ?>
    <div class="mm-form-section">
        <h2><?php esc_html_e('Search & Replace', 'rosenheinrich-multisite-migrate'); ?></h2>
        <p class="mm-restore-step-lead"><?php esc_html_e('Update URLs across your existing database after importing a backup or changing your site domain. Multisite Migrate safely updates all URL references across core tables, options, and subsite settings.', 'rosenheinrich-multisite-migrate'); ?></p>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="mm-sr-old"><?php esc_html_e('Old URL', 'rosenheinrich-multisite-migrate'); ?></label></th>
                <td><input type="url" class="regular-text" id="mm-sr-old" value="<?php echo esc_attr(site_url()); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="mm-sr-new"><?php esc_html_e('New URL', 'rosenheinrich-multisite-migrate'); ?></label></th>
                <td><input type="url" class="regular-text" id="mm-sr-new" placeholder="https://"></td>
            </tr>
        </table>
        <p><button type="button" class="button button-primary mm-btn-teal" id="mm-sr-run"><?php esc_html_e('Run Search & Replace', 'rosenheinrich-multisite-migrate'); ?></button></p>
        <div id="mm-sr-result"></div>
    </div>
    <?php
}
