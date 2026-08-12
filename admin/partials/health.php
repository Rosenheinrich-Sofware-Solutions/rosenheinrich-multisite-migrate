<?php

if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_health = Rmmigrate_Health::get_status();
$rmmigrate_blockers = (int) ($rmmigrate_health['blocker_count'] ?? 0);
$rmmigrate_warns = (int) ($rmmigrate_health['warn_count'] ?? 0);
$rmmigrate_ok = (int) ($rmmigrate_health['ok_count'] ?? 0);
$rmmigrate_total = (int) ($rmmigrate_health['total'] ?? 0);
$rmmigrate_hosting_detected_at = (int) ($rmmigrate_health['hosting']['detected_at'] ?? 0);
$rmmigrate_all_passed = ($rmmigrate_total > 0 && $rmmigrate_ok === $rmmigrate_total && $rmmigrate_blockers === 0);
$rmmigrate_passed_label = sprintf(
    /* translators: 1: passed checks, 2: total checks. */
    __('%1$d of %2$d checks passed', 'rosenheinrich-multisite-migrate'),
    $rmmigrate_ok,
    $rmmigrate_total
);
?>

<div class="mm-stat-strip">
    <div class="mm-stat-card mm-stat-card--passed<?php echo $rmmigrate_all_passed ? ' is-perfect' : ''; ?>">
        <strong><?php esc_html_e('Passed', 'rosenheinrich-multisite-migrate'); ?></strong>
        <span class="mm-health-score" aria-label="<?php echo esc_attr($rmmigrate_passed_label); ?>">
            <span class="mm-health-score__nums" aria-hidden="true">
                <span class="mm-health-score__ok"><?php echo esc_html((string) $rmmigrate_ok); ?></span>
                <span class="mm-health-score__sep">/</span>
                <span class="mm-health-score__total"><?php echo esc_html((string) $rmmigrate_total); ?></span>
            </span>
            <?php if ($rmmigrate_all_passed) : ?>
                <span class="dashicons dashicons-yes-alt mm-health-score__icon" aria-hidden="true"></span>
            <?php endif; ?>
        </span>
    </div>
    <div class="mm-stat-card mm-stat-card--warn<?php echo $rmmigrate_warns > 0 ? ' has-warns' : ' is-zero'; ?>">
        <strong><?php esc_html_e('Warnings', 'rosenheinrich-multisite-migrate'); ?></strong>
        <span class="mm-health-score">
            <span class="mm-health-score__nums">
                <span class="mm-health-score__val"><?php echo esc_html((string) $rmmigrate_warns); ?></span>
            </span>
            <?php if ($rmmigrate_warns > 0) : ?>
                <span class="dashicons dashicons-warning mm-health-score__icon" aria-hidden="true"></span>
            <?php else : ?>
                <span class="dashicons dashicons-yes-alt mm-health-score__icon" aria-hidden="true"></span>
            <?php endif; ?>
        </span>
    </div>
    <div class="mm-stat-card mm-stat-card--blockers<?php echo $rmmigrate_blockers > 0 ? ' has-blockers' : ' is-zero'; ?>">
        <strong><?php esc_html_e('Blockers', 'rosenheinrich-multisite-migrate'); ?></strong>
        <span class="mm-health-score">
            <span class="mm-health-score__nums">
                <span class="mm-health-score__val"><?php echo esc_html((string) $rmmigrate_blockers); ?></span>
            </span>
            <?php if ($rmmigrate_blockers > 0) : ?>
                <span class="dashicons dashicons-dismiss mm-health-score__icon" aria-hidden="true"></span>
            <?php else : ?>
                <span class="dashicons dashicons-yes-alt mm-health-score__icon" aria-hidden="true"></span>
            <?php endif; ?>
        </span>
    </div>
</div>

<div class="mm-premium-panel mm-health-page-panel">
    <div class="mm-premium-panel-header">
        <h2 class="mm-section-heading"><?php esc_html_e('System health', 'rosenheinrich-multisite-migrate'); ?></h2>
    </div>
    <p class="mm-health-page-summary"><?php echo esc_html(Rmmigrate_Health::summary_text($rmmigrate_health)); ?></p>

    <div class="mm-table-scroll-wrap mm-table-cards-mobile">
        <table class="mm-premium-table mm-data-table mm-health-table">
            <thead>
                <tr>
                    <th class="column-status"><?php esc_html_e('Status', 'rosenheinrich-multisite-migrate'); ?></th>
                    <th class="column-check"><?php esc_html_e('Check', 'rosenheinrich-multisite-migrate'); ?></th>
                    <th class="column-detail"><?php esc_html_e('Detail', 'rosenheinrich-multisite-migrate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $rmmigrate_health_icons = array(
                    'disk_space'      => 'dashicons-chart-area',
                    'backup_storage'  => 'dashicons-database',
                    'zip_extension'   => 'dashicons-media-archive',
                    'database_export' => 'dashicons-database-view',
                    'php_timeout'     => 'dashicons-clock',
                    'memory_limit'    => 'dashicons-performance',
                    'loopback'        => 'dashicons-rest-api',
                    'kickoff_mode'    => 'dashicons-controls-play',
                    'lock_mode'       => 'dashicons-lock',
                    'scheduler'       => 'dashicons-calendar-alt',
                );
                foreach ($rmmigrate_health['checks'] as $rmmigrate_check_key => $rmmigrate_check) :
                    $rmmigrate_check_icon = $rmmigrate_health_icons[$rmmigrate_check_key] ?? 'dashicons-yes-alt';
                    ?>
                    <tr>
                        <td class="column-status" data-label="<?php echo esc_attr(__('Status', 'rosenheinrich-multisite-migrate')); ?>">
                            <span class="<?php echo esc_attr(Rmmigrate_Health::check_css_class($rmmigrate_check, true)); ?>">
                                <?php echo esc_html(Rmmigrate_Health::check_status_label($rmmigrate_check)); ?>
                            </span>
                        </td>
                        <td class="column-check" data-label="<?php echo esc_attr(__('Check', 'rosenheinrich-multisite-migrate')); ?>">
                            <span class="mm-health-check-label">
                                <span class="dashicons <?php echo esc_attr($rmmigrate_check_icon); ?>" aria-hidden="true"></span>
                                <span class="mm-health-check-label__text"><?php echo esc_html($rmmigrate_check['label']); ?></span>
                            </span>
                        </td>
                        <td class="column-detail" data-label="<?php echo esc_attr(__('Detail', 'rosenheinrich-multisite-migrate')); ?>">
                            <?php echo esc_html($rmmigrate_check['detail']); ?>
                            <?php if (!empty($rmmigrate_check['next_action'])) : ?>
                                <p class="mm-health-next-action description">
                                    <strong><?php esc_html_e('Next:', 'rosenheinrich-multisite-migrate'); ?></strong>
                                    <?php echo esc_html((string) $rmmigrate_check['next_action']); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="mm-health-actions">
        <button type="button" class="button button-secondary" id="mm-test-hosting-detection"><?php esc_html_e('Re-run server check', 'rosenheinrich-multisite-migrate'); ?></button>
        <span id="mm-hosting-detection-status" class="description"></span>
        <?php if ($rmmigrate_hosting_detected_at > 0) : ?>
            <span class="description"><?php
            printf(
                /* translators: %1$s: hosting detection timestamp in the site timezone. */
                esc_html__('Last checked: %1$s', 'rosenheinrich-multisite-migrate'),
                esc_html(Rmmigrate_Time::local_ts_label($rmmigrate_hosting_detected_at, 'Y-m-d g:i:s a'))
            );
            ?></span>
        <?php endif; ?>
    </p>
</div>
