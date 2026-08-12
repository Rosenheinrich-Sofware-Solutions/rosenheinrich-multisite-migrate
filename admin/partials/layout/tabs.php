<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array<string,string> $rmmigrate_tabs */
/** @var string $rmmigrate_active_tab */
/** @var string $rmmigrate_base_url */
if (empty($rmmigrate_tabs)) {
    return;
}
?>
<div class="mm-nav-tabs-scroll">
<nav class="mm-nav-tabs" aria-label="<?php esc_attr_e('Section navigation', 'rosenheinrich-multisite-migrate'); ?>">
    <?php foreach ($rmmigrate_tabs as $rmmigrate_key => $rmmigrate_label) : ?>
        <a href="<?php echo esc_url(add_query_arg('tab', $rmmigrate_key, $rmmigrate_base_url)); ?>"
           class="mm-nav-tab <?php echo esc_attr($rmmigrate_active_tab === $rmmigrate_key ? 'is-active' : ''); ?>">
            <?php echo esc_html($rmmigrate_label); ?>
        </a>
    <?php endforeach; ?>
</nav>
</div>
