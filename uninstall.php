<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once dirname(__FILE__) . '/includes/class-uninstaller.php';

Rmmigrate_Uninstaller::run();
