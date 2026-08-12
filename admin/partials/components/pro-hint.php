<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contextual Pro hint card. Rendered only via Rmmigrate_Pro_Hints::render(),
 * which supplies the escaped-on-output variables below.
 *
 * @var string $rmmigrate_hint_slug
 * @var string $rmmigrate_hint_title
 * @var string $rmmigrate_hint_text
 * @var string $rmmigrate_hint_cta_label
 * @var string $rmmigrate_hint_cta_url
 * @var bool   $rmmigrate_hint_dismissible
 */

$rmmigrate_hint_slug        = isset($rmmigrate_hint_slug) ? (string) $rmmigrate_hint_slug : '';
$rmmigrate_hint_title       = isset($rmmigrate_hint_title) ? (string) $rmmigrate_hint_title : '';
$rmmigrate_hint_text        = isset($rmmigrate_hint_text) ? (string) $rmmigrate_hint_text : '';
$rmmigrate_hint_cta_label   = isset($rmmigrate_hint_cta_label) ? (string) $rmmigrate_hint_cta_label : '';
$rmmigrate_hint_cta_url     = isset($rmmigrate_hint_cta_url) ? (string) $rmmigrate_hint_cta_url : '';
$rmmigrate_hint_dismissible = !isset($rmmigrate_hint_dismissible) || (bool) $rmmigrate_hint_dismissible;

if ($rmmigrate_hint_slug === '' || $rmmigrate_hint_cta_url === '') {
    return;
}
?>
<div class="mm-pro-hint" data-mm-pro-hint="<?php echo esc_attr($rmmigrate_hint_slug); ?>">
    <?php if ($rmmigrate_hint_dismissible) : ?>
        <button type="button" class="mm-pro-hint__dismiss mm-notice-card__dismiss" data-slug="<?php echo esc_attr($rmmigrate_hint_slug); ?>" aria-label="<?php esc_attr_e('Dismiss this suggestion', 'rosenheinrich-multisite-migrate'); ?>">
            <span class="screen-reader-text"><?php esc_html_e('Dismiss', 'rosenheinrich-multisite-migrate'); ?></span>
        </button>
    <?php endif; ?>
    <div class="mm-pro-hint__body">
        <?php if ($rmmigrate_hint_title !== '') : ?>
            <p class="mm-pro-hint__title">
                <span class="mm-pro-hint__badge dashicons dashicons-star-filled" aria-hidden="true"></span>
                <?php echo esc_html($rmmigrate_hint_title); ?>
            </p>
        <?php endif; ?>
        <?php if ($rmmigrate_hint_text !== '') : ?>
            <p class="mm-pro-hint__text"><?php echo esc_html($rmmigrate_hint_text); ?></p>
        <?php endif; ?>
        <p class="mm-pro-hint__meta"><?php esc_html_e('Available in the separate Multisite Migrate Pro plugin — your free plugin keeps working as-is.', 'rosenheinrich-multisite-migrate'); ?></p>
        <p class="mm-pro-hint__actions">
            <a class="button button-secondary mm-pro-hint__cta" href="<?php echo esc_url($rmmigrate_hint_cta_url); ?>" target="_blank" rel="noopener noreferrer">
                <?php echo esc_html($rmmigrate_hint_cta_label); ?>
            </a>
        </p>
    </div>
</div>
