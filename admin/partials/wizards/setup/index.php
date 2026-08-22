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
$rmmigrate_pricing_url = Rmmigrate_Links::pricing_url('setup_wizard');
$rmmigrate_privacy_url = Rmmigrate_Capabilities::privacy_url();
$rmmigrate_features_url = Rmmigrate_Links::pricing_url('setup_features');
$rmmigrate_mark_url = Rmmigrate_Brand::mark_url();
$rmmigrate_disclosure = Rmmigrate_Setup_Wizard::newsletter_disclosure();
$rmmigrate_feature_cards = Rmmigrate_Setup_Wizard::feature_cards();
$rmmigrate_pro_highlights = Rmmigrate_Setup_Wizard::pro_highlights();
$rmmigrate_newsletter_done = !empty($rmmigrate_setup_state['newsletter_opted_in']) || !empty($rmmigrate_setup_state['newsletter_skipped']);
$rmmigrate_telemetry_done = Rmmigrate_Telemetry::consent_status() !== 'unset';
$rmmigrate_optin_done = $rmmigrate_newsletter_done && $rmmigrate_telemetry_done;
$rmmigrate_telemetry_disclosure = Rmmigrate_Telemetry::telemetry_disclosure();
$rmmigrate_telemetry_privacy_url = Rmmigrate_Capabilities::privacy_url();
?>

<div class="mm-setup-onboarding">
    <?php include RMMIGRATE_PATH . 'admin/partials/wizards/setup/step-features.php'; ?>
    <?php include RMMIGRATE_PATH . 'admin/partials/wizards/setup/step-finish.php'; ?>
</div>
