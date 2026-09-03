<?php
/**
 * Plugin Name:       Rosenheinrich Multisite Migrate – Backup, Restore & AI (MCP)
 * Plugin URI:        https://multisitemigrate.rosenheinrich.com/
 * Description:       Back up, restore and migrate single sites or multisite networks. Free, portable archives, search & replace, plus AI/MCP tools.
 * Version:           1.2.5
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Rosenheinrich Software Solutions
 * Author URI:        https://rosenheinrich.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rosenheinrich-multisite-migrate
 * Network:           true
 *
 * @package Multisite_Migrate
 */

if (!defined('ABSPATH')) {
    exit;
}

// Fail inert (never fatal the whole network) if a partial/corrupt upload
// left required files missing. A half-written plugin dir must not white-screen.
$rmmigrate_bootstrap_file = __DIR__ . '/includes/class-bootstrap.php';
if (!is_file($rmmigrate_bootstrap_file)) {
    return;
}
require_once $rmmigrate_bootstrap_file;

if (!class_exists('Rmmigrate_Bootstrap', false)) {
    return;
}

// Always register before can_run: orphaned rmmigrate_tick events reschedule
// via wp_get_schedules(); missing key → invalid_schedule spam (WP 6.1+).
Rmmigrate_Bootstrap::register_cron_schedules_filter();

if (!Rmmigrate_Bootstrap::can_run(__FILE__)) {
    // Inert load (path / active-list mismatch): drop orphan tick so WP stops rescheduling.
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('rmmigrate_tick');
    }
    return;
}

// This is the free wp.org edition: no auto-updater, no license/service layer.
if (!defined('RMMIGRATE_WPORG_BUILD')) {
    define('RMMIGRATE_WPORG_BUILD', true);
}
if (!defined('RMMIGRATE_FREE')) {
    define('RMMIGRATE_FREE', true);
}

if (!defined('RMMIGRATE_VERSION')) {
    define('RMMIGRATE_VERSION', '1.2.5');
}
if (!defined('RMMIGRATE_PATH')) {
    define('RMMIGRATE_PATH', plugin_dir_path(__FILE__));
    define('RMMIGRATE_URL', plugin_dir_url(__FILE__));
    define('RMMIGRATE_BASENAME', plugin_basename(__FILE__));
}
if (!defined('RMMIGRATE_DB_EOF')) {
    define('RMMIGRATE_DB_EOF', 'RMMIGRATE_DB_EOF');
}

if (!defined('RMMIGRATE_DIR_NAME')) {
    define('RMMIGRATE_DIR_NAME', 'rosenheinrich-multisite-migrate');
}

Rmmigrate_Bootstrap::mark_owner(Rmmigrate_Bootstrap::OWNER_FREE);

foreach (
    array(
        'Rmmigrate_Activator' => 'includes/class-activator.php',
        'Rmmigrate_Deactivator' => 'includes/class-deactivator.php',
    ) as $rmmigrate_class => $rmmigrate_rel
) {
    if (class_exists($rmmigrate_class, false)) {
        continue;
    }
    $rmmigrate_file = RMMIGRATE_PATH . $rmmigrate_rel;
    if (!is_file($rmmigrate_file)) {
        return;
    }
    require_once $rmmigrate_file;
    if (!class_exists($rmmigrate_class, false)) {
        return;
    }
}

if (class_exists('Rmmigrate_Activator', false)) {
    register_activation_hook(__FILE__, array('Rmmigrate_Activator', 'activate'));
}
if (class_exists('Rmmigrate_Deactivator', false)) {
    register_deactivation_hook(
        __FILE__,
        static function (): void {
            Rmmigrate_Deactivator::deactivate(plugin_basename(__FILE__));
        }
    );
}

add_action(
    'plugins_loaded',
    static function (): void {
        $rmmigrate_plugin_file = RMMIGRATE_PATH . 'includes/class-plugin.php';
        if (!is_file($rmmigrate_plugin_file)) {
            return;
        }
        require_once $rmmigrate_plugin_file;
        if (!class_exists('Rmmigrate_Plugin', false)) {
            return;
        }
        Rmmigrate_Plugin::instance()->run();
    },
    0
);
