<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string,mixed> $rmmigrate_health */
/** @var bool $rmmigrate_health_pill */
$rmmigrate_health_pill = !empty($rmmigrate_health_pill);
?>
<ul<?php echo $rmmigrate_health_pill ? '' : ' class="mm-health-checklist"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded class attribute. ?>>
    <?php foreach ($rmmigrate_health['checks'] as $rmmigrate_check) : ?>
        <li>
            <?php if ($rmmigrate_health_pill) : ?>
                <span class="<?php echo esc_attr(Rmmigrate_Health::check_css_class($rmmigrate_check, true)); ?>">
                    <?php echo esc_html(Rmmigrate_Health::check_status_label($rmmigrate_check)); ?>
                </span>
                <?php echo esc_html($rmmigrate_check['label']); ?> — <?php echo esc_html($rmmigrate_check['detail']); ?>
                <?php if (!empty($rmmigrate_check['next_action'])) : ?>
                    <span class="mm-health-next-action">
                        <strong><?php esc_html_e('Next:', 'rosenheinrich-multisite-migrate'); ?></strong>
                        <?php echo esc_html((string) $rmmigrate_check['next_action']); ?>
                    </span>
                <?php endif; ?>
            <?php else : ?>
                <span class="<?php echo esc_attr(Rmmigrate_Health::check_css_class($rmmigrate_check)); ?>"><?php echo esc_html($rmmigrate_check['label']); ?>:</span>
                <?php echo esc_html($rmmigrate_check['detail']); ?>
                <?php if (!empty($rmmigrate_check['next_action'])) : ?>
                    <span class="mm-health-next-action">
                        <strong><?php esc_html_e('Next:', 'rosenheinrich-multisite-migrate'); ?></strong>
                        <?php echo esc_html((string) $rmmigrate_check['next_action']); ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
