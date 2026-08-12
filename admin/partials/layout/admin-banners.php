<?php
if (!defined('ABSPATH')) {
    exit;
}
$rmmigrate_page_slug = $rmmigrate_page_slug ?? '';
$rmmigrate_is_network = $rmmigrate_is_network ?? false;

ob_start();
Rmmigrate_Setup_Wizard::render_banner($rmmigrate_is_network, $rmmigrate_page_slug);
include RMMIGRATE_PATH . 'admin/partials/layout/notices.php';
Rmmigrate_Review_Nudge::render($rmmigrate_page_slug, $rmmigrate_is_network);
$rmmigrate_banner_markup = trim((string) ob_get_clean());

if ($rmmigrate_banner_markup === '') {
    return;
}
?>
<div class="mm-admin-banners">
<?php echo wp_kses_post($rmmigrate_banner_markup); ?>
</div>
