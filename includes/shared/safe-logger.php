<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('rmmigrate_shared_log_best_effort_failure')) {
    /**
     * Log a swallowed best-effort failure.
     */
    function rmmigrate_shared_log_best_effort_failure(Throwable $e): void
    {
        $message = 'Best-effort operation failed: ' . $e->getMessage();
        if (class_exists('Rmmigrate_Logger', false)) {
            Rmmigrate_Logger::log($message);
        }
    }
}
