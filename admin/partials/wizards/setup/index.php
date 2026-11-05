<?php
if (!defined('ABSPATH')) {
    exit;
}

Rmmigrate_Setup_Wizard::auto_complete_welcome_step();

$rmmigrate_setup_steps = Rmmigrate_Setup_Wizard::steps();
$rmmigrate_setup_state = Rmmigrate_Setup_Wizard::get_state();
$rmmigrate_setup_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-setup', array(), $rmmigrate_is_network);
$rmmigrate_archives_url = add_query_arg(
    array('page' => 'multisite-migrate-archives'),
    'admin.php'
);
$rmmigrate_setup_network = is_multisite();
$rmmigrate_backup_now_url = add_query_arg(array('quick' => '1'), $rmmigrate_archives_url);
$rmmigrate_move_url = add_query_arg(array('quick' => '1', 'intent' => 'move'), $rmmigrate_archives_url);
$rmmigrate_import_url = add_query_arg(
    array('page' => 'multisite-migrate-migrate', 'tab' => 'import'),
    'admin.php'
);
$rmmigrate_health_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-health', array(), $rmmigrate_is_network);
$rmmigrate_privacy_url = Rmmigrate_Capabilities::privacy_url();
$rmmigrate_features_url = Rmmigrate_Links::pricing_url('setup_features');
$rmmigrate_guides_url = Rmmigrate_Links::docs_url('setup_wizard');
$rmmigrate_mark_url = Rmmigrate_Brand::mark_url();
$rmmigrate_disclosure = Rmmigrate_Setup_Wizard::newsletter_disclosure();
$rmmigrate_feature_cards = Rmmigrate_Setup_Wizard::visible_feature_cards();
$rmmigrate_precheck = Rmmigrate_Setup_Wizard::precheck();
$rmmigrate_newsletter_done = !empty($rmmigrate_setup_state['newsletter_opted_in']) || !empty($rmmigrate_setup_state['newsletter_skipped']);
$rmmigrate_telemetry_done = Rmmigrate_Telemetry::consent_status() !== 'unset';
$rmmigrate_optin_done = $rmmigrate_newsletter_done && $rmmigrate_telemetry_done;
$rmmigrate_telemetry_disclosure = Rmmigrate_Telemetry::telemetry_disclosure();
$rmmigrate_telemetry_privacy_url = Rmmigrate_Capabilities::privacy_url();
$rmmigrate_archive_check = Rmmigrate_Setup_Wizard::verify_first_archive();
$rmmigrate_step_1_done = !empty($rmmigrate_setup_state['steps']['welcome']) || empty($rmmigrate_precheck['has_blocker']);
$rmmigrate_step_2_done = !empty($rmmigrate_archive_check['verified']) || !empty($rmmigrate_setup_state['steps']['features']);
$rmmigrate_step_3_done = !empty($rmmigrate_setup_state['complete']) || !empty($rmmigrate_setup_state['steps']['finish']);
$rmmigrate_stepper = array(
    'welcome'  => array($rmmigrate_step_1_done, __('1. Environment check', 'rosenheinrich-multisite-migrate')),
    'features' => array($rmmigrate_step_2_done, __('2. First backup & archive verification', 'rosenheinrich-multisite-migrate')),
    'finish'   => array($rmmigrate_step_3_done, __('3. Preferences & finish', 'rosenheinrich-multisite-migrate')),
);
$rmmigrate_stepper_current = 'finish';
foreach ($rmmigrate_stepper as $rmmigrate_stepper_key => $rmmigrate_stepper_item) {
    if (!$rmmigrate_stepper_item[0]) {
        $rmmigrate_stepper_current = $rmmigrate_stepper_key;
        break;
    }
}
?>

<div class="mm-setup-onboarding mm-setup-onboarding--start">
    <ol class="mm-setup-stepper" aria-label="<?php esc_attr_e('Setup steps', 'rosenheinrich-multisite-migrate'); ?>">
        <?php foreach ($rmmigrate_stepper as $rmmigrate_stepper_key => $rmmigrate_stepper_item) : ?>
            <?php $rmmigrate_stepper_is_current = ($rmmigrate_stepper_key === $rmmigrate_stepper_current); ?>
            <li class="mm-setup-stepper__item<?php echo $rmmigrate_stepper_is_current ? ' is-active' : ($rmmigrate_stepper_item[0] ? ' is-done' : ''); ?>" data-step="<?php echo esc_attr($rmmigrate_stepper_key); ?>"<?php echo $rmmigrate_stepper_is_current ? ' aria-current="step"' : ''; ?>>
                <?php if ($rmmigrate_stepper_item[0] && !$rmmigrate_stepper_is_current) : ?>
                    <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                <?php endif; ?>
                <span class="mm-setup-stepper__label"><?php echo esc_html($rmmigrate_stepper_item[1]); ?></span>
                <?php if ($rmmigrate_stepper_item[0] && !$rmmigrate_stepper_is_current) : ?>
                    <span class="screen-reader-text"><?php esc_html_e('(done)', 'rosenheinrich-multisite-migrate'); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php include RMMIGRATE_PATH . 'admin/partials/wizards/setup/step-features.php'; ?>
    <?php include RMMIGRATE_PATH . 'admin/partials/wizards/setup/step-finish.php'; ?>
</div>
