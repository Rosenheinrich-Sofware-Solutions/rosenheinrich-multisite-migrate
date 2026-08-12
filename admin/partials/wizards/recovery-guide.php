<?php
if (!defined('ABSPATH')) {
    exit;
}
$rmmigrate_docs_restore = Rmmigrate_Capabilities::marketing_url(
    Rmmigrate_Capabilities::docs_restore_url(),
    'recovery_guide'
);
$rmmigrate_docs_hub = Rmmigrate_Capabilities::marketing_url(
    Rmmigrate_Capabilities::docs_url(),
    'recovery_docs_hub'
);
?>
<div class="mm-form-section mm-recovery-docs">
    <h2><?php esc_html_e('Recovery', 'rosenheinrich-multisite-migrate'); ?></h2>
    <p class="description"><?php esc_html_e('Restore guides — same-server rollback, advanced import, empty-server migration, multisite domain mapping, and disaster recovery — are on the documentation site.', 'rosenheinrich-multisite-migrate'); ?></p>
    <p>
        <a class="button button-primary mm-btn-teal" href="<?php echo esc_url($rmmigrate_docs_restore); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Read the restore guide', 'rosenheinrich-multisite-migrate'); ?></a>
        <a class="button" href="<?php echo esc_url($rmmigrate_docs_hub); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('All documentation', 'rosenheinrich-multisite-migrate'); ?></a>
    </p>
</div>
