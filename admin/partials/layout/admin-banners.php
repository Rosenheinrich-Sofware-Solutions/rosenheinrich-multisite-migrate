<?php
if (!defined('ABSPATH')) {
    exit;
}
$rmmigrate_page_slug = $rmmigrate_page_slug ?? '';
$rmmigrate_is_network = $rmmigrate_is_network ?? false;

ob_start();
if (Rmmigrate_Restore_Runner::is_maintenance_active()) {
    $rmmigrate_maint_job = Rmmigrate_Job::get_active();
    $rmmigrate_maint_restore_running = $rmmigrate_maint_job !== null && $rmmigrate_maint_job->is_restore();
    $rmmigrate_maint_can_leave = !$rmmigrate_maint_restore_running && !Rmmigrate_Access::is_subsite_scoped_operation();
    ?>
    <div class="mm-notice mm-notice-warning mm-maintenance-active-banner" role="region" aria-label="<?php esc_attr_e('Maintenance mode active', 'rosenheinrich-multisite-migrate'); ?>">
        <div class="mm-notice-body">
            <p class="mm-notice-title"><?php esc_html_e('Maintenance mode is active', 'rosenheinrich-multisite-migrate'); ?></p>
            <?php if ($rmmigrate_maint_restore_running) : ?>
            <p class="mm-notice-text"><?php esc_html_e('Visitors currently see a maintenance screen while a restore is running. It turns off automatically when the restore finishes.', 'rosenheinrich-multisite-migrate'); ?></p>
            <?php elseif ($rmmigrate_maint_can_leave) : ?>
            <p class="mm-notice-text"><?php esc_html_e('Visitors currently see a maintenance screen, but no restore is running. Turn off maintenance mode to make the site available again.', 'rosenheinrich-multisite-migrate'); ?></p>
            <div class="mm-notice-actions">
                <button type="button" class="button button-secondary mm-leave-maintenance-btn" id="mm-leave-maintenance-mode"><?php esc_html_e('Leave maintenance mode', 'rosenheinrich-multisite-migrate'); ?></button>
            </div>
            <?php else : ?>
            <p class="mm-notice-text"><?php esc_html_e('Visitors currently see a maintenance screen. Ask your network administrator to turn off maintenance mode.', 'rosenheinrich-multisite-migrate'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
Rmmigrate_Setup_Wizard::render_banner($rmmigrate_is_network, $rmmigrate_page_slug);
include RMMIGRATE_PATH . 'admin/partials/layout/notices.php';
Rmmigrate_Review_Nudge::render($rmmigrate_page_slug, $rmmigrate_is_network);
$rmmigrate_banner_markup = trim((string) ob_get_clean());

if ($rmmigrate_banner_markup === '') {
    return;
}
?>
<div class="mm-admin-banners">
<?php echo wp_kses_post($rmmigrate_banner_markup); ?>
</div>
