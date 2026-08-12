<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_empty_title = $rmmigrate_empty_title ?? '';
$rmmigrate_empty_message = $rmmigrate_empty_message ?? '';
$rmmigrate_empty_actions = $rmmigrate_empty_actions ?? '';
$rmmigrate_empty_icon = $rmmigrate_empty_icon ?? '';
$rmmigrate_empty_class = $rmmigrate_empty_class ?? 'mm-empty-state--activity';
$rmmigrate_empty_actions_allowed = array(
    'a' => array(
        'class'  => true,
        'href'   => true,
        'target' => true,
        'rel'    => true,
    ),
    'button' => array(
        'type'  => true,
        'class' => true,
        'id'    => true,
    ),
);
?>
<div class="mm-empty-state<?php echo $rmmigrate_empty_class !== '' ? ' ' . esc_attr($rmmigrate_empty_class) : ''; ?>">
    <?php if ($rmmigrate_empty_icon !== '') : ?>
        <span class="mm-empty-state__icon dashicons <?php echo esc_attr($rmmigrate_empty_icon); ?>" aria-hidden="true"></span>
    <?php endif; ?>
    <?php if ($rmmigrate_empty_title !== '') : ?>
        <h3><?php echo esc_html($rmmigrate_empty_title); ?></h3>
    <?php endif; ?>
    <?php if ($rmmigrate_empty_message !== '') : ?>
        <p><?php echo esc_html($rmmigrate_empty_message); ?></p>
    <?php endif; ?>
    <?php if ($rmmigrate_empty_actions !== '') : ?>
        <div class="mm-empty-state__actions"><?php echo wp_kses($rmmigrate_empty_actions, $rmmigrate_empty_actions_allowed); ?></div>
    <?php endif; ?>
</div>
