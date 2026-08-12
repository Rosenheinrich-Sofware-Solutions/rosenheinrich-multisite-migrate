<?php
if (!defined('ABSPATH')) {
    exit;
}
$rmmigrate_header_action = $rmmigrate_header_action ?? '';
$rmmigrate_mark_url = Rmmigrate_Brand::mark_url();
?>
<div class="mm-app-header">
    <div class="mm-app-header-inner">
        <div class="mm-app-brand">
            <img src="<?php echo esc_url($rmmigrate_mark_url); ?>" alt="<?php echo esc_attr(Rmmigrate_Brand::mark_alt()); ?>" class="mm-app-mark" width="28" height="28">
            <span class="mm-app-name" aria-hidden="true"><?php echo esc_html(Rmmigrate_Capabilities::app_name()); ?></span>
        </div>
        <div class="mm-app-title-row">
            <h1 class="mm-app-title"><?php echo esc_html($rmmigrate_page_title ?? ''); ?></h1>
            <?php if ($rmmigrate_header_action !== '') : ?>
                <div class="mm-app-actions"><?php echo wp_kses($rmmigrate_header_action, Rmmigrate_Admin_Router::header_action_kses()); ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
