<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/class-topology-db-connection.php';
require_once __DIR__ . '/class-restore-topology-manifest.php';
require_once __DIR__ . '/class-restore-topology-standalone.php';
require_once __DIR__ . '/class-restore-topology-destination.php';
require_once __DIR__ . '/class-restore-topology-wp-config.php';
require_once __DIR__ . '/class-restore-topology-compatibility.php';
require_once __DIR__ . '/class-restore-topology-db-action.php';
require_once __DIR__ . '/class-restore-topology-prefix-remap.php';
require_once __DIR__ . '/class-restore-topology-network-import.php';
require_once __DIR__ . '/class-restore-topology-network-to-subsite.php';
require_once __DIR__ . '/class-restore-topology-user-merge.php';
require_once __DIR__ . '/class-restore-topology-ui.php';
require_once __DIR__ . '/class-migration-preflight.php';
require_once __DIR__ . '/class-network-restore-quarantine.php';
require_once __DIR__ . '/class-network-restore-orchestration.php';

if (!class_exists('Rmmigrate_Sql_Import_Prefix_Rewrite', false)) {
    $rmmigrate_topology_prefix_rewrite_bases = array();
    if (defined('RMMIGRATE_PATH')) {
        $rmmigrate_topology_prefix_rewrite_bases[] = RMMIGRATE_PATH;
    }
    $rmmigrate_topology_prefix_rewrite_bases[] = dirname(__DIR__, 2);
    $rmmigrate_topology_prefix_rewrite_bases[] = dirname(__DIR__);
    foreach (array_unique($rmmigrate_topology_prefix_rewrite_bases) as $rmmigrate_topology_base) {
        if (!is_string($rmmigrate_topology_base) || $rmmigrate_topology_base === '') {
            continue;
        }
        foreach (array(
            '/includes/class-sql-import-prefix-rewrite.php',
            '/class-sql-import-prefix-rewrite.php',
        ) as $rmmigrate_topology_suffix) {
            $rmmigrate_topology_path = rtrim($rmmigrate_topology_base, '/\\') . $rmmigrate_topology_suffix;
            if (is_file($rmmigrate_topology_path)) {
                require_once $rmmigrate_topology_path;
                break 2;
            }
        }
    }
}
