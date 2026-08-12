<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Upgrader
{
    /**
     * Run schema/settings upgrades once per plugin version (activate or ZIP update).
     * Hot path: site-option version compare only — never dbDelta on every request.
     */
    public static function maybe_upgrade(): void
    {
        $stored = (string) get_site_option('rmmigrate_db_version', '');
        if ($stored === RMMIGRATE_VERSION) {
            return;
        }

        Rmmigrate_Activator::ensure_schema();

        // Seed opaque system token for new installs / new system logs; never delete legacy logs.
        Rmmigrate_Activity_Log::site_log_token();

        update_site_option('rmmigrate_db_version', RMMIGRATE_VERSION);
    }
}
