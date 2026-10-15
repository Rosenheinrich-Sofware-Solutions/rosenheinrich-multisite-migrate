<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Web-server guard files for the local backup storage root (Apache + IIS).
 */
final class Rmmigrate_Backup_Storage_Protection
{
    public static function apache_htaccess_rules(): string
    {
        return "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "</IfModule>\n";
    }

    public static function index_php_contents(): string
    {
        return "<?php\nif (!defined('ABSPATH')) {\n    exit;\n}\n// Silence is golden.\n";
    }

    public static function iis_web_config_contents(): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<configuration>\n"
            . "  <system.webServer>\n"
            . "    <authorization>\n"
            . "      <deny users=\"*\" />\n"
            . "    </authorization>\n"
            . "  </system.webServer>\n"
            . "</configuration>\n";
    }

    /**
     * Create missing guard files (.htaccess, index.php, web.config).
     */
    public static function ensure_protection_files(string $dir): bool
    {
        $files = array(
            '.htaccess'  => self::apache_htaccess_rules(),
            'index.php'  => self::index_php_contents(),
            'web.config' => self::iis_web_config_contents(),
        );

        foreach ($files as $name => $contents) {
            $path = trailingslashit($dir) . $name;
            if (Rmmigrate_Filesystem::exists($path)) {
                continue;
            }
            if (Rmmigrate_Filesystem::put_contents($path, $contents) === false) {
                return false;
            }
        }

        return true;
    }
}
