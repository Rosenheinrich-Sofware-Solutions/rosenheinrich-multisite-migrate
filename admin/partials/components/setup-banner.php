<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_setup_progress = Rmmigrate_Setup_Wizard::progress_percent();
$rmmigrate_setup_paused   = !empty($rmmigrate_setup_state['skipped']);
?>
<div class="mm-banner mm-setup-banner">
    <div class="mm-setup-banner__inner">
        <span class="mm-setup-banner__icon dashicons dashicons-admin-tools" aria-hidden="true"></span>
        <div class="mm-setup-banner__content">
            <?php if ($rmmigrate_setup_paused) : ?>
                <p class="mm-setup-banner__title"><?php esc_html_e('Setup paused', 'rosenheinrich-multisite-migrate'); ?></p>
                <p class="mm-setup-banner__text"><?php esc_html_e('Continue when you are ready — finish the short setup wizard anytime.', 'rosenheinrich-multisite-migrate'); ?></p>
            <?php else : ?>
                <p class="mm-setup-banner__title"><?php esc_html_e('Finish plugin setup', 'rosenheinrich-multisite-migrate'); ?></p>
                <p class="mm-setup-banner__text"><?php esc_html_e('Complete the setup wizard to see key features and create your first backup.', 'rosenheinrich-multisite-migrate'); ?></p>
            <?php endif; ?>
            <?php if ($rmmigrate_setup_progress > 0 && $rmmigrate_setup_progress < 100) : ?>
                <div class="mm-setup-banner__progress" role="progressbar" aria-valuenow="<?php echo esc_attr((string) $rmmigrate_setup_progress); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e('Setup progress', 'rosenheinrich-multisite-migrate'); ?>">
                    <div class="mm-setup-banner__progress-track">
                        <div class="mm-setup-banner__progress-fill" style="width: <?php echo esc_attr((string) $rmmigrate_setup_progress); ?>%;"></div>
                    </div>
                    <span class="mm-setup-banner__progress-label"><?php echo esc_html(sprintf(/* translators: setup wizard completion percentage */ __('%d%% complete', 'rosenheinrich-multisite-migrate'), $rmmigrate_setup_progress)); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <div class="mm-setup-banner__actions">
            <a class="button button-primary mm-btn-teal" href="<?php echo esc_url($rmmigrate_setup_url); ?>"><?php esc_html_e('Continue setup', 'rosenheinrich-multisite-migrate'); ?></a>
            <button type="button" class="button mm-banner__dismiss mm-setup-banner__dismiss mm-dismiss-setup-banner" data-dismiss-action="rmmigrate_setup_wizard_dismiss_banner" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('rmmigrate_admin')); ?>"><?php esc_html_e('Dismiss', 'rosenheinrich-multisite-migrate'); ?></button>
        </div>
    </div>
</div>
