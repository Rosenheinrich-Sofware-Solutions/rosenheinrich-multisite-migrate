<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reusable admin banner.
 *
 * @var array<string,mixed> $rmmigrate_banner
 */
$rmmigrate_banner = is_array($rmmigrate_banner ?? null) ? $rmmigrate_banner : array();

$rmmigrate_banner_variant = in_array(
    (string) ($rmmigrate_banner['variant'] ?? 'info'),
    array('info', 'success', 'warning', 'error'),
    true
) ? (string) $rmmigrate_banner['variant'] : 'info';
$rmmigrate_banner_title = trim((string) ($rmmigrate_banner['title'] ?? ''));
$rmmigrate_banner_text = trim((string) ($rmmigrate_banner['text'] ?? ''));
$rmmigrate_banner_icon = trim((string) ($rmmigrate_banner['icon'] ?? ''));
$rmmigrate_banner_class = trim((string) ($rmmigrate_banner['class'] ?? ''));
$rmmigrate_banner_actions = is_array($rmmigrate_banner['actions'] ?? null)
    ? $rmmigrate_banner['actions']
    : array();
$rmmigrate_banner_simple = !empty($rmmigrate_banner['simple']);
$rmmigrate_banner_allow_html = !empty($rmmigrate_banner['allow_html']);

if ($rmmigrate_banner_icon === '') {
    $rmmigrate_banner_icons = array(
        'info'    => 'info',
        'success' => 'yes-alt',
        'warning' => 'warning',
        'error'   => 'warning',
    );
    $rmmigrate_banner_icon = $rmmigrate_banner_icons[$rmmigrate_banner_variant] ?? 'info';
}

if ($rmmigrate_banner_text === '' && $rmmigrate_banner_title === '') {
    return;
}

$rmmigrate_banner_classes = trim(
    'mm-banner mm-banner--' . $rmmigrate_banner_variant
    . ($rmmigrate_banner_simple ? ' mm-notice-card--simple' : ' mm-notice-card')
    . ($rmmigrate_banner_class !== '' ? ' ' . $rmmigrate_banner_class : '')
);
?>
<div class="<?php echo esc_attr($rmmigrate_banner_classes); ?>">
    <div class="mm-notice-card__inner">
        <span class="mm-notice-card__icon dashicons dashicons-<?php echo esc_attr($rmmigrate_banner_icon); ?>" aria-hidden="true"></span>
        <div class="mm-notice-card__content">
            <?php if ($rmmigrate_banner_title !== '') : ?>
                <p class="mm-notice-card__title"><?php echo esc_html($rmmigrate_banner_title); ?></p>
            <?php endif; ?>
            <?php if ($rmmigrate_banner_text !== '') : ?>
                <p class="mm-notice-card__text"><?php
                if ($rmmigrate_banner_allow_html) {
                    echo wp_kses_post($rmmigrate_banner_text);
                } else {
                    echo esc_html($rmmigrate_banner_text);
                }
                ?></p>
            <?php endif; ?>
        </div>
        <?php if ($rmmigrate_banner_actions !== array()) : ?>
            <div class="mm-notice-card__actions">
                <?php foreach ($rmmigrate_banner_actions as $rmmigrate_banner_action) : ?>
                    <?php
                    if (!is_array($rmmigrate_banner_action)) {
                        continue;
                    }
                    $rmmigrate_action_type = (string) ($rmmigrate_banner_action['type'] ?? 'link');
                    if ($rmmigrate_action_type === 'dismiss') {
                        $rmmigrate_dismiss_class = trim(
                            'mm-banner__dismiss ' . (string) ($rmmigrate_banner_action['class'] ?? '')
                        );
                        if (!empty($rmmigrate_banner_action['button'])) {
                            ?>
                            <button type="button" class="button <?php echo esc_attr($rmmigrate_dismiss_class); ?>"><?php esc_html_e('Dismiss', 'rosenheinrich-multisite-migrate'); ?></button>
                            <?php
                        } else {
                            $rmmigrate_dismiss_class .= ' mm-notice-card__dismiss';
                            ?>
                            <button type="button" class="<?php echo esc_attr($rmmigrate_dismiss_class); ?>" aria-label="<?php esc_attr_e('Dismiss this notice', 'rosenheinrich-multisite-migrate'); ?>">
                                <span class="screen-reader-text"><?php esc_html_e('Dismiss', 'rosenheinrich-multisite-migrate'); ?></span>
                            </button>
                            <?php
                        }
                        continue;
                    }
                    if ($rmmigrate_action_type === 'button') {
                        $rmmigrate_action_label = (string) ($rmmigrate_banner_action['label'] ?? '');
                        if ($rmmigrate_action_label === '') {
                            continue;
                        }
                        $rmmigrate_action_class = 'button';
                        if (!empty($rmmigrate_banner_action['primary'])) {
                            $rmmigrate_action_class .= ' button-primary mm-btn-teal';
                        }
                        if (!empty($rmmigrate_banner_action['class'])) {
                            $rmmigrate_action_class .= ' ' . (string) $rmmigrate_banner_action['class'];
                        }
                        $rmmigrate_action_attrs = is_array($rmmigrate_banner_action['attrs'] ?? null)
                            ? $rmmigrate_banner_action['attrs']
                            : array();
                        ?>
                        <button type="button" class="<?php echo esc_attr(trim($rmmigrate_action_class)); ?>"<?php
                        foreach ($rmmigrate_action_attrs as $rmmigrate_attr_key => $rmmigrate_attr_val) {
                            if (!is_string($rmmigrate_attr_key) || $rmmigrate_attr_key === '') {
                                continue;
                            }
                            echo ' ' . esc_attr($rmmigrate_attr_key) . '="' . esc_attr((string) $rmmigrate_attr_val) . '"';
                        }
                        ?>><?php echo esc_html($rmmigrate_action_label); ?></button>
                        <?php
                        continue;
                    }
                    $rmmigrate_action_url = (string) ($rmmigrate_banner_action['url'] ?? '');
                    $rmmigrate_action_label = (string) ($rmmigrate_banner_action['label'] ?? '');
                    if ($rmmigrate_action_url === '' || $rmmigrate_action_label === '') {
                        continue;
                    }
                    $rmmigrate_action_class = 'button';
                    if (!empty($rmmigrate_banner_action['primary'])) {
                        $rmmigrate_action_class .= ' button-primary mm-btn-teal';
                    }
                    if (!empty($rmmigrate_banner_action['class'])) {
                        $rmmigrate_action_class .= ' ' . (string) $rmmigrate_banner_action['class'];
                    }
                    $rmmigrate_action_rel = !empty($rmmigrate_banner_action['external']) ? 'noopener' : '';
                    $rmmigrate_action_attrs = is_array($rmmigrate_banner_action['attrs'] ?? null)
                        ? $rmmigrate_banner_action['attrs']
                        : array();
                    ?>
                    <a class="<?php echo esc_attr(trim($rmmigrate_action_class)); ?>" href="<?php echo esc_url($rmmigrate_action_url); ?>"<?php if (!empty($rmmigrate_banner_action['external'])) : ?> target="_blank" rel="<?php echo esc_attr($rmmigrate_action_rel); ?>"<?php endif; ?><?php
                    foreach ($rmmigrate_action_attrs as $rmmigrate_attr_key => $rmmigrate_attr_val) {
                        if (!is_string($rmmigrate_attr_key) || $rmmigrate_attr_key === '') {
                            continue;
                        }
                        echo ' ' . esc_attr($rmmigrate_attr_key) . '="' . esc_attr((string) $rmmigrate_attr_val) . '"';
                    }
                    ?>><?php echo esc_html($rmmigrate_action_label); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
