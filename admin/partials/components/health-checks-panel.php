<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string,mixed> $rmmigrate_health */
/** @var bool $rmmigrate_is_network */
$rmmigrate_health_auto_open = !empty($rmmigrate_health['warn_count']) || !empty($rmmigrate_health['blocker_count']);
$rmmigrate_hosting_detected_at = (int) ($rmmigrate_health['hosting']['detected_at'] ?? 0);
?>
<div id="mm-health-details" class="mm-form-section mm-health-details<?php echo $rmmigrate_health_auto_open ? '' : ' mm-hidden'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded CSS class. ?>"<?php echo $rmmigrate_health_auto_open ? '' : ' hidden'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded attribute. ?>>
    <h2 class="mm-section-heading"><?php esc_html_e('Health checks', 'rosenheinrich-multisite-migrate'); ?></h2>
    <p><strong><?php echo esc_html(Rmmigrate_Health::summary_text($rmmigrate_health)); ?></strong></p>
    <?php
    $rmmigrate_health_pill = false;
    include RMMIGRATE_PATH . 'admin/partials/components/health-check-list.php';
    ?>
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
