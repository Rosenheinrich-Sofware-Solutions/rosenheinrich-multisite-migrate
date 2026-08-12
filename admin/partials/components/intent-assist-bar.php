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
$rmmigrate_intent_health_url = Rmmigrate_Admin_Router::admin_url(
    'multisite-migrate-health',
    array(),
    $rmmigrate_intent_is_network
);
$rmmigrate_intent_activity_url = !empty($rmmigrate_activity_url)
    ? (string) $rmmigrate_activity_url
    : Rmmigrate_Admin_Router::admin_url('multisite-migrate-activity', array(), $rmmigrate_intent_is_network);
$rmmigrate_intent_backup_label = $rmmigrate_intent_is_network
    ? __('Back up network now', 'rosenheinrich-multisite-migrate')
    : __('Back up this site now', 'rosenheinrich-multisite-migrate');
?>
<nav class="mm-action-bar mm-home-quick-actions mm-intent-assist" aria-label="<?php esc_attr_e('What do you want to do?', 'rosenheinrich-multisite-migrate'); ?>">
    <p class="mm-intent-assist__lead screen-reader-text"><?php esc_html_e('What do you want to do?', 'rosenheinrich-multisite-migrate'); ?></p>
    <?php if ($rmmigrate_can_backup_create) : ?>
        <button type="button" class="button button-primary mm-btn-teal" id="mm-toggle-create" data-mm-intent="backup">
            <?php echo esc_html($rmmigrate_intent_backup_label); ?>
        </button>
    <?php endif; ?>
    <a class="button" href="<?php echo esc_url($rmmigrate_intent_health_url); ?>" data-mm-intent="health">
        <?php esc_html_e('Check hosting', 'rosenheinrich-multisite-migrate'); ?>
    </a>
    <a class="button" href="<?php echo esc_url($rmmigrate_intent_activity_url); ?>" data-mm-intent="activity">
        <?php esc_html_e('Activity log', 'rosenheinrich-multisite-migrate'); ?>
    </a>
</nav>
