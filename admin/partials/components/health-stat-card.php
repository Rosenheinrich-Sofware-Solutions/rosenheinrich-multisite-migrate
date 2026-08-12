<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string,mixed> $rmmigrate_health */
$rmmigrate_blockers = (int) ($rmmigrate_health['blocker_count'] ?? 0);
$rmmigrate_warns = (int) ($rmmigrate_health['warn_count'] ?? 0);
$rmmigrate_health_auto_open = $rmmigrate_blockers > 0 || $rmmigrate_warns > 0;
?>
<div class="mm-stat-card mm-stat-card--clickable">
    <strong><?php esc_html_e('Health', 'rosenheinrich-multisite-migrate'); ?></strong>
    <span>
        <a href="#mm-health-details" class="mm-health-toggle <?php echo esc_attr($rmmigrate_blockers > 0 ? 'mm-status-error' : ($rmmigrate_warns > 0 ? 'mm-status-warn' : '')); ?>" aria-controls="mm-health-details" aria-expanded="<?php echo esc_attr($rmmigrate_health_auto_open ? 'true' : 'false'); ?>">
            <?php
            if ($rmmigrate_blockers > 0) {
                echo esc_html(sprintf(
                    /* translators: %d: blocker count. */
                    _n('%d blocker', '%d blockers', $rmmigrate_blockers, 'rosenheinrich-multisite-migrate'),
                    $rmmigrate_blockers
                ));
            } elseif ($rmmigrate_warns > 0) {
                printf(
                    /* translators: 1: passed check count, 2: total check count. */
                    esc_html__('%1$d / %2$d OK', 'rosenheinrich-multisite-migrate'),
                    (int) $rmmigrate_health['ok_count'],
                    (int) $rmmigrate_health['total']
                );
            } else {
                esc_html_e('All checks passed', 'rosenheinrich-multisite-migrate');
            }
            ?>
        </a>
    </span>
</div>
