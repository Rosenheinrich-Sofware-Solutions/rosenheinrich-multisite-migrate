<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="mm-setup-section mm-setup-welcome" id="mm-setup-welcome" data-step="welcome">
    <div class="mm-setup-card<?php echo !empty($rmmigrate_newsletter_done) ? ' is-done' : ''; ?>">
        <div class="mm-setup-card__mark" aria-hidden="true">
            <img src="<?php echo esc_url($rmmigrate_mark_url); ?>" alt="" width="36" height="36" />
        </div>
        <h2 class="mm-setup-card__title"><?php esc_html_e('Never miss an important update', 'rosenheinrich-multisite-migrate'); ?></h2>
        <p class="mm-setup-card__body">
            <?php esc_html_e('Opt in to get email notifications for security & feature updates, weekly product tips, and occasional offers. This helps us keep Multisite Migrate compatible with your site.', 'rosenheinrich-multisite-migrate'); ?>
        </p>
        <div class="mm-setup-card__actions"<?php echo !empty($rmmigrate_newsletter_done) ? ' hidden' : ''; ?>>
            <button type="button" class="button button-primary mm-btn-teal mm-setup-allow-continue">
                <?php esc_html_e('Allow & Continue', 'rosenheinrich-multisite-migrate'); ?>
            </button>
            <button type="button" class="button mm-setup-skip-welcome"><?php esc_html_e('Skip', 'rosenheinrich-multisite-migrate'); ?></button>
        </div>
        <p class="mm-setup-card__thanks"<?php echo empty($rmmigrate_newsletter_done) ? ' hidden' : ''; ?>>
            <?php esc_html_e('Thanks — you can continue below.', 'rosenheinrich-multisite-migrate'); ?>
        </p>
        <details class="mm-setup-disclosure">
            <summary><?php esc_html_e('This will allow Multisite Migrate to', 'rosenheinrich-multisite-migrate'); ?></summary>
            <ul class="mm-setup-disclosure__list">
                <?php foreach ($rmmigrate_disclosure as $rmmigrate_row) : ?>
                    <?php
                    $rmmigrate_row_title = isset($rmmigrate_row['title']) ? (string) $rmmigrate_row['title'] : '';
                    $rmmigrate_row_detail = isset($rmmigrate_row['detail']) ? (string) $rmmigrate_row['detail'] : '';
                    ?>
                    <li>
                        <strong><?php echo esc_html($rmmigrate_row_title); ?></strong>
                        <span><?php echo esc_html($rmmigrate_row_detail); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
        <p class="mm-setup-card__privacy description">
            <?php
            echo wp_kses(
                sprintf(
                    /* translators: %s: privacy policy URL */
                    __('By continuing you agree we may email you about Multisite Migrate. See our <a href="%s" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.', 'rosenheinrich-multisite-migrate'),
                    esc_url($rmmigrate_privacy_url)
                ),
                array('a' => array('href' => true, 'target' => true, 'rel' => true))
            );
            ?>
        </p>
    </div>
</section>
