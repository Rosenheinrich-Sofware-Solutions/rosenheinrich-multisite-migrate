<?php
if (!defined('ABSPATH')) {
    exit;
}

?>
<div id="mm-activity-detail-dialog" class="mm-hidden" role="dialog" aria-modal="true" aria-labelledby="mm-activity-detail-title">
    <div class="mm-restore-overlay">
        <div class="mm-restore-modal mm-activity-detail-modal">
            <div class="multisite-migrate-wizard-modal-head mm-modal-head">
                <h2 id="mm-activity-detail-title"><?php esc_html_e('Activity details', 'rosenheinrich-multisite-migrate'); ?></h2>
                <button type="button" class="mm-activity-detail-close" aria-label="<?php esc_attr_e('Close', 'rosenheinrich-multisite-migrate'); ?>">&times;</button>
            </div>
            <div class="multisite-migrate-wizard-modal-body mm-modal-body" id="mm-activity-detail-body">
                <p class="description"><?php esc_html_e('Loading…', 'rosenheinrich-multisite-migrate'); ?></p>
            </div>
        </div>
    </div>
</div>
