<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="mm-setup-section mm-setup-features" id="mm-setup-features" data-step="features">
    <header class="mm-setup-section__head">
        <h2 class="mm-setup-slogan">
            <span class="mm-setup-slogan__line"><?php esc_html_e('Single site', 'rosenheinrich-multisite-migrate'); ?></span>
            <span class="mm-setup-slogan__line"><?php esc_html_e('or Multisite.', 'rosenheinrich-multisite-migrate'); ?></span>
            <span class="mm-setup-slogan__line"><?php esc_html_e('Backup in minutes.', 'rosenheinrich-multisite-migrate'); ?></span>
        </h2>
        <p><?php esc_html_e('Everything you need to back up, move, and restore networks — without the clutter.', 'rosenheinrich-multisite-migrate'); ?></p>
    </header>
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
        <a href="<?php echo esc_url($rmmigrate_features_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('See all features', 'rosenheinrich-multisite-migrate'); ?></a>
    </p>
</section>
