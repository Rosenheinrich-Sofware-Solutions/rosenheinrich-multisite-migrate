<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array<int,array{slug:string,label:string}> $rmmigrate_wizard_steps */
/** @var string $rmmigrate_wizard_current_step */
$rmmigrate_wizard_aria_label = $rmmigrate_wizard_aria_label ?? __('Setup steps', 'rosenheinrich-multisite-migrate');
?>
<ol class="multisite-migrate-wizard-steps" aria-label="<?php echo esc_attr($rmmigrate_wizard_aria_label); ?>">
    <?php foreach ($rmmigrate_wizard_steps as $rmmigrate_step) : ?>
        <li class="<?php
        $rmmigrate_wizard_step_classes = array();
        if ($rmmigrate_wizard_current_step === $rmmigrate_step['slug']) {
            $rmmigrate_wizard_step_classes[] = 'is-active';
        }
        if (Rmmigrate_Wizard_Controller::step_index($rmmigrate_wizard_steps, $rmmigrate_step['slug']) < Rmmigrate_Wizard_Controller::step_index($rmmigrate_wizard_steps, $rmmigrate_wizard_current_step)) {
            $rmmigrate_wizard_step_classes[] = 'is-done';
        }
        echo esc_attr(implode(' ', $rmmigrate_wizard_step_classes));
        ?>"<?php if ($rmmigrate_wizard_current_step === $rmmigrate_step['slug']) : ?> aria-current="step"<?php endif; ?>>
            <?php echo esc_html($rmmigrate_step['label']); ?>
        </li>
    <?php endforeach; ?>
</ol>
