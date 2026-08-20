<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="mm-setup-section mm-setup-finish" id="mm-setup-finish" data-step="finish">
    <div class="mm-setup-pro-panel">
        <div class="mm-setup-pro-panel__copy">
            <h2><?php esc_html_e('Go further with Pro', 'rosenheinrich-multisite-migrate'); ?></h2>
            <p><?php esc_html_e('One local schedule is included. Cloud destinations, multiple schedules, and recovery points are in Plus — when you are ready.', 'rosenheinrich-multisite-migrate'); ?></p>
            <ul class="mm-setup-pro-panel__list">
                <?php foreach ($rmmigrate_pro_highlights as $rmmigrate_item) : ?>
                    <li>
                        <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                        <span><?php echo esc_html($rmmigrate_item); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="mm-setup-pro-panel__cta">
            <a class="button button-primary mm-btn-teal" href="<?php echo esc_url($rmmigrate_pricing_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View Pro plans', 'rosenheinrich-multisite-migrate'); ?></a>
        </div>
    </div>

    <div class="mm-setup-finish__bar">
        <div class="mm-setup-finish__lead">
            <h2><?php esc_html_e('Ready when you are', 'rosenheinrich-multisite-migrate'); ?></h2>
            <p><?php esc_html_e('Open Backups to create your first package. You can change scope and settings anytime.', 'rosenheinrich-multisite-migrate'); ?></p>
        </div>
        <div class="mm-setup-finish__actions">
            <button type="button" class="button button-primary mm-btn-teal mm-setup-create-backup" data-archives-url="<?php echo esc_url($rmmigrate_archives_url); ?>">
                <?php esc_html_e('Create Your First Backup', 'rosenheinrich-multisite-migrate'); ?>
            </button>
        </div>
    </div>
</section>
