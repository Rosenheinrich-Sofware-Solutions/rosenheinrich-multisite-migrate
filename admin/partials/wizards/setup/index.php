<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_setup_steps = Rmmigrate_Setup_Wizard::steps();
$rmmigrate_setup_state = Rmmigrate_Setup_Wizard::get_state();
$rmmigrate_setup_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-setup', array(), $rmmigrate_is_network);
$rmmigrate_archives_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), $rmmigrate_is_network);
$rmmigrate_pricing_url = Rmmigrate_Links::pricing_url('setup_wizard');
$rmmigrate_privacy_url = Rmmigrate_Capabilities::privacy_url();
$rmmigrate_features_url = Rmmigrate_Links::pricing_url('setup_features');
$rmmigrate_mark_url = Rmmigrate_Brand::mark_url();
$rmmigrate_disclosure = Rmmigrate_Setup_Wizard::newsletter_disclosure();
$rmmigrate_feature_cards = Rmmigrate_Setup_Wizard::feature_cards();
$rmmigrate_pro_highlights = Rmmigrate_Setup_Wizard::pro_highlights();
$rmmigrate_newsletter_done = !empty($rmmigrate_setup_state['newsletter_opted_in']) || !empty($rmmigrate_setup_state['newsletter_skipped']);
?>

<div class="mm-setup-onboarding">
    <?php include RMMIGRATE_PATH . 'admin/partials/wizards/setup/step-welcome.php'; ?>
    <?php include RMMIGRATE_PATH . 'admin/partials/wizards/setup/step-features.php'; ?>
    <?php include RMMIGRATE_PATH . 'admin/partials/wizards/setup/step-finish.php'; ?>
</div>
