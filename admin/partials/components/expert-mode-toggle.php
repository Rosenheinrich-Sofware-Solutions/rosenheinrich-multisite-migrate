<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Rmmigrate_Expert_Mode', false) || !Rmmigrate_Expert_Mode::user_can_toggle()) {
    return;
}
$rmmigrate_expert_on = Rmmigrate_Expert_Mode::enabled();
?>
<div class="mm-form-section mm-expert-toggle">
    <label class="mm-expert-toggle__label">
        <input type="checkbox" id="mm-expert-mode-toggle" value="1" <?php checked($rmmigrate_expert_on); ?>>
        <strong><?php esc_html_e('Show expert settings', 'rosenheinrich-multisite-migrate'); ?></strong>
    </label>
    <p class="description"><?php esc_html_e('Database engine, chunk sizes, storage paths, exclusions, logs, Search & Replace and AI agents. The defaults work for most sites — you do not need these for normal backups, restores or moves.', 'rosenheinrich-multisite-migrate'); ?></p>
    <p class="mm-expert-toggle__status" role="status" aria-live="polite" hidden></p>
</div>
