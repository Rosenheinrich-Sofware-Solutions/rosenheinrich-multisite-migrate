<?php
if (!defined('ABSPATH')) {
    exit;
}
$rmmigrate_app_mods = '';
if (Rmmigrate_Request_Input::get_key('page') === 'multisite-migrate-setup') {
    $rmmigrate_app_mods = ' mm-app--setup';
}
?>
<div class="wrap multisite-migrate-wrap mm-app<?php echo esc_attr($rmmigrate_app_mods); ?>">
