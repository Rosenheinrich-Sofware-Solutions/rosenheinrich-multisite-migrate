<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Ajax_Handler
{
    public static function register(): void
    {
        Rmmigrate_Ajax_Backup::register();
        Rmmigrate_Ajax_Restore::register();
        Rmmigrate_Ajax_Import::register();
        Rmmigrate_Ajax_Activity::register();
        Rmmigrate_Ajax_Cleanup::register();
    }
}
