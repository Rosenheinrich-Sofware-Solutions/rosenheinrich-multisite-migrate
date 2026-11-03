<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="mm-setup-section mm-setup-features mm-setup-start" id="mm-setup-features" data-step="features">
    <header class="mm-setup-section__head mm-setup-start__head">
        <h2 class="mm-setup-slogan">
            <?php if ($rmmigrate_setup_network) : ?>
                <?php esc_html_e('Back up your whole network in one click', 'rosenheinrich-multisite-migrate'); ?>
            <?php else : ?>
                <?php esc_html_e('Back up this site in one click', 'rosenheinrich-multisite-migrate'); ?>
            <?php endif; ?>
        </h2>
        <p>
            <?php if ($rmmigrate_setup_network) : ?>
                <?php esc_html_e('One package with every subsite, the shared tables, and all files, saved on this server. Download it anytime, or import it on another network to move it.', 'rosenheinrich-multisite-migrate'); ?>
            <?php else : ?>
                <?php esc_html_e('One package with your database and files, saved on this server. Download it anytime, or import it on another site to move it.', 'rosenheinrich-multisite-migrate'); ?>
            <?php endif; ?>
        </p>
    </header>

    <div class="mm-setup-finish__bar mm-setup-start__bar">
        <div class="mm-setup-finish__actions">
            <button type="button" class="button button-primary mm-btn-teal mm-setup-create-backup" data-intent="backup" data-archives-url="<?php echo esc_attr($rmmigrate_backup_now_url); ?>">
                <?php if ($rmmigrate_setup_network) : ?>
                    <?php esc_html_e('Back up the network now', 'rosenheinrich-multisite-migrate'); ?>
                <?php else : ?>
                    <?php esc_html_e('Back up this site now', 'rosenheinrich-multisite-migrate'); ?>
                <?php endif; ?>
            </button>
            <p class="mm-setup-start__hint"><?php esc_html_e('Starts right away with safe defaults: database, files and settings.', 'rosenheinrich-multisite-migrate'); ?></p>
        </div>
        <ul class="mm-setup-intents" aria-label="<?php esc_attr_e('Other ways to start', 'rosenheinrich-multisite-migrate'); ?>">
            <li>
                <button type="button" class="button-link mm-setup-create-backup mm-setup-intent" data-intent="move" data-archives-url="<?php echo esc_attr($rmmigrate_move_url); ?>">
                    <span class="dashicons dashicons-migrate" aria-hidden="true"></span>
                    <?php if ($rmmigrate_setup_network) : ?>
                        <?php esc_html_e('Move this network to another server', 'rosenheinrich-multisite-migrate'); ?>
                    <?php else : ?>
                        <?php esc_html_e('Move this site to another server', 'rosenheinrich-multisite-migrate'); ?>
                    <?php endif; ?>
                </button>
            </li>
            <li>
                <button type="button" class="button-link mm-setup-create-backup mm-setup-intent" data-intent="import" data-archives-url="<?php echo esc_attr($rmmigrate_import_url); ?>">
                    <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                    <?php esc_html_e('Restore or import an existing backup', 'rosenheinrich-multisite-migrate'); ?>
                </button>
            </li>
        </ul>
    </div>

    <?php if (!empty($rmmigrate_precheck['checks'])) : ?>
        <details class="mm-setup-disclosure mm-setup-precheck"<?php echo !empty($rmmigrate_precheck['has_blocker']) ? ' open' : ''; ?>>
            <summary><?php echo esc_html($rmmigrate_precheck['summary']); ?></summary>
            <ul class="mm-setup-disclosure__list mm-setup-precheck__list">
                <?php foreach ($rmmigrate_precheck['checks'] as $rmmigrate_check) : ?>
                    <li class="mm-setup-precheck__item is-<?php echo esc_attr($rmmigrate_check['status']); ?>">
                        <strong><?php echo esc_html($rmmigrate_check['label']); ?></strong>
                        <span><?php echo esc_html($rmmigrate_check['detail']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="description">
                <a href="<?php echo esc_url($rmmigrate_health_url); ?>"><?php esc_html_e('Open the full hosting check', 'rosenheinrich-multisite-migrate'); ?></a>
                &middot;
                <a href="<?php echo esc_url($rmmigrate_guides_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Guides', 'rosenheinrich-multisite-migrate'); ?></a>
            </p>
        </details>
    <?php endif; ?>

    <details class="mm-setup-disclosure mm-setup-included">
        <summary><?php esc_html_e('What the free plugin includes', 'rosenheinrich-multisite-migrate'); ?></summary>
        <div class="mm-setup-feature-grid">
            <?php foreach ($rmmigrate_feature_cards as $rmmigrate_card) : ?>
                <article class="mm-setup-feature-card">
                    <span class="mm-setup-feature-card__icon" aria-hidden="true">
                        <span class="dashicons <?php echo esc_attr($rmmigrate_card['icon']); ?>"></span>
                    </span>
                    <h3 class="mm-setup-feature-card__title"><?php echo esc_html($rmmigrate_card['label']); ?></h3>
                    <p class="mm-setup-feature-card__text"><?php echo esc_html($rmmigrate_card['blurb']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="mm-setup-features__more">
            <a href="<?php echo esc_url($rmmigrate_features_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Compare Free and Pro', 'rosenheinrich-multisite-migrate'); ?></a>
        </p>
    </details>
</section>
