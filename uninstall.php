<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$rmmigrate_uninstaller_file = __DIR__ . '/includes/class-uninstaller.php';
if (!is_file($rmmigrate_uninstaller_file)) {
    return;
}
require_once $rmmigrate_uninstaller_file;

if (class_exists('Rmmigrate_Uninstaller', false)) {
    Rmmigrate_Uninstaller::run();
}
