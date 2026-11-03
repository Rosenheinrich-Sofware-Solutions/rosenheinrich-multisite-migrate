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
?>

<div class="mm-setup-onboarding mm-setup-onboarding--start">
    <?php include RMMIGRATE_PATH . 'admin/partials/wizards/setup/step-features.php'; ?>
    <?php include RMMIGRATE_PATH . 'admin/partials/wizards/setup/step-finish.php'; ?>
</div>
