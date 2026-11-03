<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deterministic intent CTAs (Ads pain → less thinking). No LLM.
 * Restore stays on the archive row — no ambiguous global Restore CTA.
 *
 * @var bool $rmmigrate_is_network
 * @var bool $rmmigrate_can_backup_create
 * @var bool $rmmigrate_subsite_mode
 * @var string $rmmigrate_activity_url
 */

$rmmigrate_can_backup_create = $rmmigrate_can_backup_create ?? Rmmigrate_Access::can_create_backup();
$rmmigrate_intent_is_network = !empty($rmmigrate_is_network) && empty($rmmigrate_subsite_mode);
$rmmigrate_intent_move_url = Rmmigrate_Admin_Router::admin_url(
    'multisite-migrate-migrate',
    array(),
    $rmmigrate_intent_is_network
);
$rmmigrate_intent_backup_label = $rmmigrate_intent_is_network
    ? __('Back up the network now', 'rosenheinrich-multisite-migrate')
    : __('Back up this site now', 'rosenheinrich-multisite-migrate');
$rmmigrate_intent_show_primary = $rmmigrate_can_backup_create && empty($rmmigrate_intent_hide_primary);
?>
<nav class="mm-action-bar mm-home-quick-actions mm-intent-assist" aria-label="<?php esc_attr_e('What do you want to do?', 'rosenheinrich-multisite-migrate'); ?>">
    <p class="mm-intent-assist__lead screen-reader-text"><?php esc_html_e('What do you want to do?', 'rosenheinrich-multisite-migrate'); ?></p>
    <?php if ($rmmigrate_intent_show_primary) : ?>
        <button type="button" class="button button-primary mm-btn-teal" id="mm-toggle-create" data-mm-intent="backup">
            <?php echo esc_html($rmmigrate_intent_backup_label); ?>
        </button>
    <?php endif; ?>
    <a class="button" href="<?php echo esc_url($rmmigrate_intent_move_url); ?>" data-mm-intent="move">
        <?php esc_html_e('Restore or move a backup file', 'rosenheinrich-multisite-migrate'); ?>
    </a>
    <?php if ($rmmigrate_intent_show_primary) : ?>
        <button type="button" class="button-link mm-customize-backup" data-mm-intent="customize">
            <?php esc_html_e('Backup options…', 'rosenheinrich-multisite-migrate'); ?>
        </button>
    <?php endif; ?>
</nav>
