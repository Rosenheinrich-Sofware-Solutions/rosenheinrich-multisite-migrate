<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="mm-setup-section mm-setup-finish" id="mm-setup-finish" data-step="finish">
    <div class="mm-setup-card mm-setup-optin<?php echo !empty($rmmigrate_optin_done) ? ' is-done' : ''; ?>">
        <h2 class="mm-setup-card__title"><?php esc_html_e('Optional: help us improve the plugin', 'rosenheinrich-multisite-migrate'); ?></h2>
        <p class="mm-setup-card__body">
            <?php esc_html_e('Both are off unless you tick them. Your choice is saved when you start, and you can change it anytime in Settings.', 'rosenheinrich-multisite-migrate'); ?>
        </p>
        <div class="mm-setup-card__actions mm-setup-optin__choices"<?php echo !empty($rmmigrate_optin_done) ? ' hidden' : ''; ?>>
            <label class="mm-setup-optin-choice">
                <input type="checkbox" class="mm-setup-optin-email" value="1" <?php checked(!empty($rmmigrate_optin_done) && !empty($rmmigrate_setup_state['newsletter_opted_in'])); ?> />
                <span><?php esc_html_e('Email me about product updates, tips, and occasional offers. You can unsubscribe anytime.', 'rosenheinrich-multisite-migrate'); ?></span>
            </label>
            <label class="mm-setup-optin-choice">
                <input type="checkbox" class="mm-setup-optin-telemetry" value="1" <?php checked(!empty($rmmigrate_optin_done) && Rmmigrate_Telemetry::has_consent()); ?> />
                <span><?php esc_html_e('Share anonymous usage data (setup steps, backup results, server basics) to help fix problems faster.', 'rosenheinrich-multisite-migrate'); ?></span>
            </label>
        </div>
        <p class="mm-setup-card__thanks"<?php echo empty($rmmigrate_optin_done) ? ' hidden' : ''; ?>>
            <?php esc_html_e('Thanks — your choices are saved. You can change them later in Settings.', 'rosenheinrich-multisite-migrate'); ?>
        </p>
        <details class="mm-setup-disclosure">
            <summary><?php esc_html_e('What is sent if you tick a box', 'rosenheinrich-multisite-migrate'); ?></summary>
            <div class="mm-setup-disclosure__section">
                <h3 class="mm-setup-disclosure__heading"><?php esc_html_e('If you allow product emails', 'rosenheinrich-multisite-migrate'); ?></h3>
                <ul class="mm-setup-disclosure__list">
                    <?php foreach ($rmmigrate_disclosure as $rmmigrate_row) : ?>
                        <li>
                            <strong><?php echo esc_html($rmmigrate_row['title']); ?></strong>
                            <span><?php echo esc_html($rmmigrate_row['detail']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="mm-setup-disclosure__section">
                <h3 class="mm-setup-disclosure__heading"><?php esc_html_e('If you allow usage data', 'rosenheinrich-multisite-migrate'); ?></h3>
                <ul class="mm-setup-disclosure__list">
                    <?php foreach ($rmmigrate_telemetry_disclosure as $rmmigrate_row) : ?>
                        <li>
                            <strong><?php echo esc_html($rmmigrate_row['title']); ?></strong>
                            <span><?php echo esc_html($rmmigrate_row['detail']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="description"><?php esc_html_e('We do not collect visitor analytics, database content, backup files, or your site URL in telemetry events (only a pseudonymous site hash).', 'rosenheinrich-multisite-migrate'); ?></p>
            </div>
        </details>
        <p class="mm-setup-card__privacy description">
            <?php
            echo wp_kses(
                sprintf(
                    /* translators: %s: privacy policy URL */
                    __('Nothing is sent unless you opt in. Product emails and usage data go to multisitemigrate.rosenheinrich.com. See our <a href="%s" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.', 'rosenheinrich-multisite-migrate'),
                    esc_url($rmmigrate_telemetry_privacy_url)
                ),
                array('a' => array('href' => true, 'target' => true, 'rel' => true))
            );
            ?>
        </p>
    </div>

    <p class="mm-setup-skip-wrap">
        <button type="button" class="button-link mm-setup-skip" data-archives-url="<?php echo esc_attr($rmmigrate_archives_url); ?>"><?php esc_html_e('Skip setup and go to Backups', 'rosenheinrich-multisite-migrate'); ?></button>
    </p>
</section>
