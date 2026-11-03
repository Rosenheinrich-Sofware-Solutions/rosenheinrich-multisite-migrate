<?php
if (!defined('ABSPATH')) {
    exit;
}

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
                <p class="mm-setup-banner__title"><?php esc_html_e('New here? Start with a backup', 'rosenheinrich-multisite-migrate'); ?></p>
                <p class="mm-setup-banner__text"><?php esc_html_e('The start page backs up this site in one click, or helps you move it.', 'rosenheinrich-multisite-migrate'); ?></p>
            <?php endif; ?>
        </div>
        <div class="mm-setup-banner__actions">
            <a class="button button-primary mm-btn-teal" href="<?php echo esc_url($rmmigrate_setup_url); ?>"><?php esc_html_e('Open start page', 'rosenheinrich-multisite-migrate'); ?></a>
            <button type="button" class="button mm-banner__dismiss mm-setup-banner__dismiss mm-dismiss-setup-banner" data-dismiss-action="rmmigrate_setup_wizard_dismiss_banner" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('rmmigrate_admin')); ?>"><?php esc_html_e('Dismiss', 'rosenheinrich-multisite-migrate'); ?></button>
        </div>
    </div>
</div>
