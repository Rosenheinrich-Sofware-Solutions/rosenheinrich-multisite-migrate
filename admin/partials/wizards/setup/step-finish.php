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

    <div class="mm-setup-card mm-setup-optin<?php echo !empty($rmmigrate_optin_done) ? ' is-done' : ''; ?>">
        <h2 class="mm-setup-card__title"><?php esc_html_e('Help us improve Multisite Migrate', 'rosenheinrich-multisite-migrate'); ?></h2>
        <p class="mm-setup-card__body">
            <?php esc_html_e('Optionally receive product emails and share anonymous usage signals (wizard steps, backup outcomes, environment basics). No visitor tracking, no backup file contents.', 'rosenheinrich-multisite-migrate'); ?>
        </p>
        <div class="mm-setup-card__actions mm-setup-optin__choices"<?php echo !empty($rmmigrate_optin_done) ? ' hidden' : ''; ?>>
            <label class="mm-setup-optin-choice">
                <input type="checkbox" class="mm-setup-optin-email" value="1" checked="checked" />
                <span><?php esc_html_e('Receive product emails about updates, tips, and occasional offers', 'rosenheinrich-multisite-migrate'); ?></span>
            </label>
            <label class="mm-setup-optin-choice">
                <input type="checkbox" class="mm-setup-optin-telemetry" value="1" checked="checked" />
                <span><?php esc_html_e('Share anonymous usage signals to help improve Multisite Migrate', 'rosenheinrich-multisite-migrate'); ?></span>
            </label>
        </div>
        <p class="mm-setup-card__thanks"<?php echo empty($rmmigrate_optin_done) ? ' hidden' : ''; ?>>
            <?php esc_html_e('Thanks — your choices are saved. You can change them later in Settings.', 'rosenheinrich-multisite-migrate'); ?>
        </p>
        <details class="mm-setup-disclosure">
            <summary><?php esc_html_e('What we may collect when you opt in', 'rosenheinrich-multisite-migrate'); ?></summary>
            <div class="mm-setup-disclosure__section">
                <h3 class="mm-setup-disclosure__heading"><?php esc_html_e('If you allow email notifications', 'rosenheinrich-multisite-migrate'); ?></h3>
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
                <h3 class="mm-setup-disclosure__heading"><?php esc_html_e('If you allow usage signals', 'rosenheinrich-multisite-migrate'); ?></h3>
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
                    __('Product emails and usage signals are sent to multisitemigrate.rosenheinrich.com when you opt in. See our <a href="%s" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.', 'rosenheinrich-multisite-migrate'),
                    esc_url($rmmigrate_telemetry_privacy_url)
                ),
                array('a' => array('href' => true, 'target' => true, 'rel' => true))
            );
            ?>
        </p>
    </div>

    <div class="mm-setup-finish__bar">
        <div class="mm-setup-finish__lead">
            <h2><?php esc_html_e('Ready when you are', 'rosenheinrich-multisite-migrate'); ?></h2>
            <p><?php esc_html_e('Open Backups to create your first package. You can change scope and settings anytime.', 'rosenheinrich-multisite-migrate'); ?></p>
        </div>
        <div class="mm-setup-finish__actions">
            <button type="button" class="button button-primary mm-btn-teal mm-setup-create-backup" data-archives-url="<?php echo esc_attr($rmmigrate_archives_url); ?>">
                <?php esc_html_e('Create Your First Backup', 'rosenheinrich-multisite-migrate'); ?>
            </button>
        </div>
    </div>
</section>
