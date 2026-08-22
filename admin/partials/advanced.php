<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_advanced_tab = $rmmigrate_advanced_tab ?? Rmmigrate_Request_Input::get_key('tab', 'engine');
$rmmigrate_base_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-advanced', array(), $rmmigrate_is_network);
$rmmigrate_tabs = array(
    'engine'        => __('Backup Settings', 'rosenheinrich-multisite-migrate'),
    'notifications' => __('Notifications', 'rosenheinrich-multisite-migrate'),
    'privacy'       => __('Privacy', 'rosenheinrich-multisite-migrate'),
    'system'        => __('Tools & logs', 'rosenheinrich-multisite-migrate'),
);
$rmmigrate_active_tab = $rmmigrate_advanced_tab;
include RMMIGRATE_PATH . 'admin/partials/layout/tabs.php';

$rmmigrate_save_url = $rmmigrate_is_network
    ? network_admin_url('edit.php?action=rmmigrate_settings')
    : admin_url('admin-post.php');

if ($rmmigrate_advanced_tab === 'engine') {
    ?>
    <form method="post" action="<?php echo esc_url($rmmigrate_save_url); ?>">
        <?php wp_nonce_field('rmmigrate_settings'); ?>
        <input type="hidden" name="settings_tab" value="engine">
        <?php if (!$rmmigrate_is_network) : ?>
            <input type="hidden" name="action" value="rmmigrate_settings">
        <?php endif; ?>
        <?php include RMMIGRATE_PATH . 'admin/partials/settings/tab-backups.php'; ?>
        <?php include RMMIGRATE_PATH . 'admin/partials/settings/tab-retention.php'; ?>
        <?php include RMMIGRATE_PATH . 'admin/partials/settings/tab-database.php'; ?>
        <?php include RMMIGRATE_PATH . 'admin/partials/settings/tab-engine.php'; ?>
        <?php include RMMIGRATE_PATH . 'admin/partials/settings/tab-storage-advanced.php'; ?>
        <div class="mm-form-actions">
            <?php submit_button(null, 'primary mm-btn-teal', 'submit'); ?>
        </div>
    </form>
    <?php
} elseif ($rmmigrate_advanced_tab === 'notifications') {
    $rmmigrate_test_action = $rmmigrate_is_network
        ? network_admin_url('edit.php?action=rmmigrate_test_email')
        : admin_url('admin-post.php?action=rmmigrate_test_email');
    ?>
    <form id="rmmigrate-test-email" class="hidden" method="post" action="<?php echo esc_url($rmmigrate_test_action); ?>">
        <?php wp_nonce_field('rmmigrate_test_email'); ?>
        <input type="hidden" name="action" value="rmmigrate_test_email">
        <input type="hidden" name="email_address" value="<?php echo esc_attr((string) ($rmmigrate_settings['email_address'] ?? '')); ?>">
    </form>
    <form method="post" action="<?php echo esc_url($rmmigrate_save_url); ?>">
        <?php wp_nonce_field('rmmigrate_settings'); ?>
        <input type="hidden" name="settings_tab" value="notifications">
        <?php if (!$rmmigrate_is_network) : ?>
            <input type="hidden" name="action" value="rmmigrate_settings">
        <?php endif; ?>
        <?php include RMMIGRATE_PATH . 'admin/partials/settings/tab-notifications.php'; ?>
        <div class="mm-form-actions">
            <?php submit_button(null, 'primary mm-btn-teal', 'submit'); ?>
        </div>
    </form>
    <?php
} elseif ($rmmigrate_advanced_tab === 'privacy') {
    include RMMIGRATE_PATH . 'admin/partials/settings/tab-privacy.php';
} else {
    include RMMIGRATE_PATH . 'admin/partials/tools-system.php';
}
