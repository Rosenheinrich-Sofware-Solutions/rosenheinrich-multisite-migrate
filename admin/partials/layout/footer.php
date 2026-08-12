<?php

if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_footer_links = Rmmigrate_Brand::admin_footer_links();
?>
<footer class="mm-app-footer" aria-label="<?php esc_attr_e('Multisite Migrate resources', 'rosenheinrich-multisite-migrate'); ?>">
    <div class="mm-app-footer__inner">
        <div class="mm-app-footer__meta">
            <img
                src="<?php echo esc_url(Rmmigrate_Brand::mark_url()); ?>"
                alt=""
                class="mm-app-footer__mark"
                width="18"
                height="18"
                aria-hidden="true"
            >
            <span class="mm-app-footer__version">
                <?php
                printf(
                    /* translators: %s: plugin version number. */
                    esc_html__('Version %s', 'rosenheinrich-multisite-migrate'),
                    esc_html(RMMIGRATE_VERSION)
                );
                ?>
            </span>
        </div>
        <?php if ($rmmigrate_footer_links !== array()) : ?>
            <nav class="mm-app-footer__links" aria-label="<?php esc_attr_e('Help and resources', 'rosenheinrich-multisite-migrate'); ?>">
                <?php foreach ($rmmigrate_footer_links as $rmmigrate_link) : ?>
                    <?php
                    if (!is_array($rmmigrate_link)) {
                        continue;
                    }
                    $rmmigrate_label = isset($rmmigrate_link['label']) ? (string) $rmmigrate_link['label'] : '';
                    $rmmigrate_url = isset($rmmigrate_link['url']) ? (string) $rmmigrate_link['url'] : '';
                    if ($rmmigrate_label === '' || $rmmigrate_url === '') {
                        continue;
                    }
                    ?>
                    <?php if ($rmmigrate_url === '#' || strpos($rmmigrate_url, '#') === 0) : ?>
                        <a href="#" class="mm-feedback-open-help"><?php echo esc_html($rmmigrate_label); ?></a>
                    <?php else : ?>
                        <a href="<?php echo esc_url($rmmigrate_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($rmmigrate_label); ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>
</footer>
