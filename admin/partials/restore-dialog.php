<?php
if (!defined('ABSPATH')) {
    exit;
}

?>
<div id="mm-restore-dialog" class="mm-hidden" role="dialog" aria-modal="true" aria-labelledby="mm-restore-dialog-title"<?php echo !empty($rmmigrate_subsite_mode) ? ' data-mm-subsite-mode="1"' : ''; ?>>
    <div class="mm-restore-overlay">
        <div class="mm-restore-modal">
            <div class="multisite-migrate-wizard-modal-head mm-modal-head">
                <h2 id="mm-restore-dialog-title"><?php esc_html_e('Restore backup', 'rosenheinrich-multisite-migrate'); ?></h2>
            </div>
            <div class="multisite-migrate-wizard-modal-body mm-modal-body">
                <input type="hidden" id="mm-restore-source-id" value="">

                <ol class="mm-create-wizard-steps mm-restore-wizard-steps" aria-label="<?php esc_attr_e('Restore steps', 'rosenheinrich-multisite-migrate'); ?>">
                    <li class="is-active" aria-current="step">
                        <span class="mm-create-step-btn">
                            <span class="mm-step-num" aria-hidden="true">1</span>
                            <span class="mm-step-label"><?php esc_html_e('Intent', 'rosenheinrich-multisite-migrate'); ?></span>
                        </span>
                    </li>
                    <li>
                        <span class="mm-create-step-btn">
                            <span class="mm-step-num" aria-hidden="true">2</span>
                            <span class="mm-step-label"><?php esc_html_e('Migration', 'rosenheinrich-multisite-migrate'); ?></span>
                        </span>
                    </li>
                    <li>
                        <span class="mm-create-step-btn">
                            <span class="mm-step-num" aria-hidden="true">3</span>
                            <span class="mm-step-label"><?php esc_html_e('Options', 'rosenheinrich-multisite-migrate'); ?></span>
                        </span>
                    </li>
                    <li>
                        <span class="mm-create-step-btn">
                            <span class="mm-step-num" aria-hidden="true">4</span>
                            <span class="mm-step-label"><?php esc_html_e('Confirm', 'rosenheinrich-multisite-migrate'); ?></span>
                        </span>
                    </li>
                </ol>

                <div class="mm-restore-step is-active" data-restore-step="1">
                    <?php if (!empty($rmmigrate_subsite_mode)) : ?>
                        <p class="mm-restore-step-lead"><?php esc_html_e('This will overwrite this site with the selected backup.', 'rosenheinrich-multisite-migrate'); ?></p>
                        <input type="hidden" name="mm_restore_type" value="same_server">
                    <?php else : ?>
                    <p class="mm-restore-step-lead"><?php esc_html_e('Select how you want to restore this backup. Choose "Same server" for standard rollbacks or "Migration" to update site URLs.', 'rosenheinrich-multisite-migrate'); ?></p>
                    <div class="multisite-migrate-wizard-cards" role="radiogroup" aria-label="<?php esc_attr_e('Restore intent', 'rosenheinrich-multisite-migrate'); ?>">
                        <button type="button" class="multisite-migrate-wizard-card is-selected" data-restore-type="same_server" role="radio" aria-checked="true">
                            <h4><?php esc_html_e('Same server', 'rosenheinrich-multisite-migrate'); ?></h4>
                            <p><?php esc_html_e('Restore in place with no URL changes.', 'rosenheinrich-multisite-migrate'); ?></p>
                        </button>
                        <button type="button" class="multisite-migrate-wizard-card" data-restore-type="migration" role="radio" aria-checked="false">
                            <h4><?php esc_html_e('Migration', 'rosenheinrich-multisite-migrate'); ?></h4>
                            <p><?php esc_html_e('Move to a new domain or URL.', 'rosenheinrich-multisite-migrate'); ?></p>
                        </button>
                    </div>
                    <input type="radio" name="mm_restore_type" value="same_server" checked class="mm-hidden">
                    <input type="radio" name="mm_restore_type" value="migration" class="mm-hidden">
                    <?php endif; ?>
                </div>

                <div class="mm-restore-step" data-restore-step="2">
                    <div id="mm-migration-fields">
                        <p class="mm-restore-step-lead mm-migration-url-note"><?php esc_html_e('Configure your target site and home URLs. Multisite Migrate automatically updates all database tables, links, and subsite domains during migration.', 'rosenheinrich-multisite-migrate'); ?></p>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="mm-old-site-url"><?php echo (is_multisite() && !empty($rmmigrate_is_network)) ? esc_html__('Old network URL', 'rosenheinrich-multisite-migrate') : esc_html__('Old site URL', 'rosenheinrich-multisite-migrate'); ?></label></th>
                                <td><input type="url" class="regular-text" id="mm-old-site-url"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mm-new-site-url"><?php echo (is_multisite() && !empty($rmmigrate_is_network)) ? esc_html__('New network URL', 'rosenheinrich-multisite-migrate') : esc_html__('New site URL', 'rosenheinrich-multisite-migrate'); ?></label></th>
                                <td><input type="url" class="regular-text" id="mm-new-site-url" placeholder="https://"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mm-old-home-url"><?php esc_html_e('Old home URL', 'rosenheinrich-multisite-migrate'); ?></label></th>
                                <td><input type="url" class="regular-text" id="mm-old-home-url"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mm-new-home-url"><?php esc_html_e('New home URL', 'rosenheinrich-multisite-migrate'); ?></label></th>
                                <td>
                                    <input type="url" class="regular-text" id="mm-new-home-url" placeholder="https://">
                                    <p><label for="mm-home-same-as-site"><input type="checkbox" id="mm-home-same-as-site" value="1" checked> <?php esc_html_e('Same as site URL', 'rosenheinrich-multisite-migrate'); ?></label></p>
                                </td>
                            </tr>
                        </table>
                        <p id="mm-restore-passphrase-wrap" class="mm-hidden">
                            <label for="mm-restore-passphrase"><?php esc_html_e('Archive passphrase', 'rosenheinrich-multisite-migrate'); ?></label>
                            <input type="password" class="regular-text" id="mm-restore-passphrase" autocomplete="off">
                            <span class="description"><?php esc_html_e('Required for encrypted .venc backups.', 'rosenheinrich-multisite-migrate'); ?></span>
                        </p>
                        <?php if (is_multisite() && $rmmigrate_is_network) : ?>
                            <div id="mm-blog-migration-table"></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mm-restore-step" data-restore-step="3">
                    <p class="mm-restore-step-lead"><?php esc_html_e('Select what data to restore and whether to create an automatic safety backup before overwriting existing data.', 'rosenheinrich-multisite-migrate'); ?></p>
                    <p><strong><?php esc_html_e('Restore mode', 'rosenheinrich-multisite-migrate'); ?></strong></p>
                    <p>
                        <label><input type="radio" name="mm_restore_mode" value="db"> <?php esc_html_e('Database only', 'rosenheinrich-multisite-migrate'); ?></label><br>
                        <label><input type="radio" name="mm_restore_mode" value="files"> <?php esc_html_e('Files only', 'rosenheinrich-multisite-migrate'); ?></label><br>
                        <label><input type="radio" name="mm_restore_mode" value="both" checked> <?php esc_html_e('Database and files', 'rosenheinrich-multisite-migrate'); ?></label>
                    </p>
                    <div id="mm-topology-options" class="mm-hidden">
                        <p><strong><?php esc_html_e('Multisite import topology', 'rosenheinrich-multisite-migrate'); ?></strong></p>
                        <div id="mm-topology-subsite-on-network" class="mm-hidden">
                            <p>
                                <label>
                                    <input type="radio" name="mm_topology_import" value="auto" checked>
                                    <?php esc_html_e('Default import', 'rosenheinrich-multisite-migrate'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="mm_topology_import" value="subsite_on_network">
                                    <?php esc_html_e('Import as subsite on this network', 'rosenheinrich-multisite-migrate'); ?>
                                </label>
                            </p>
                            <p>
                                <label for="mm-topology-target-url"><?php esc_html_e('Target subsite URL', 'rosenheinrich-multisite-migrate'); ?></label><br>
                                <input type="url" class="regular-text" id="mm-topology-target-url" placeholder="https://">
                            </p>
                            <p>
                                <label for="mm-topology-target-blog-id"><?php esc_html_e('Existing blog ID (0 = create new)', 'rosenheinrich-multisite-migrate'); ?></label><br>
                                <input type="number" class="small-text" id="mm-topology-target-blog-id" min="0" value="0">
                            </p>
                        </div>
                        <div id="mm-topology-network-to-subsite" class="mm-hidden">
                            <p>
                                <label>
                                    <input type="radio" name="mm_topology_import" value="auto" checked>
                                    <?php esc_html_e('Default import', 'rosenheinrich-multisite-migrate'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="mm_topology_import" value="network_to_subsite">
                                    <?php esc_html_e('Import into this subsite slot', 'rosenheinrich-multisite-migrate'); ?>
                                </label>
                            </p>
                            <p>
                                <label for="mm-topology-network-blog-id"><?php esc_html_e('Target blog ID', 'rosenheinrich-multisite-migrate'); ?></label><br>
                                <input type="number" class="small-text" id="mm-topology-network-blog-id" min="1" value="<?php echo is_multisite() ? (int) get_current_blog_id() : 1; ?>">
                            </p>
                        </div>
                    </div>
                    <p class="mm-restore-safety-row">
                        <label>
                            <input type="checkbox" id="mm-safety-snapshot" value="1" checked>
                            <?php esc_html_e('Create safety backup before restore (recommended)', 'rosenheinrich-multisite-migrate'); ?>
                        </label>
                        <?php
                        Rmmigrate_Admin_Help_Tip::render(
                            array(
                                __('Multisite Migrate runs a quick backup of the current site before overwriting data, so you can roll back if restore fails.', 'rosenheinrich-multisite-migrate'),
                            ),
                            __('Safety backup help', 'rosenheinrich-multisite-migrate'),
                            'mm-restore-safety-help'
                        );
                        ?>
                    </p>
                </div>

                <div class="mm-restore-step" data-restore-step="4">
                    <p class="mm-restore-step-lead"><?php esc_html_e('Review your configuration summary below. Confirm your restore options to begin the process.', 'rosenheinrich-multisite-migrate'); ?></p>
                    <dl class="multisite-migrate-wizard-review" id="mm-restore-review-summary"></dl>
                    <p class="mm-restore-confirm-row">
                        <label class="mm-restore-confirm-label" for="mm-restore-confirm">
                            <input type="checkbox" id="mm-restore-confirm" value="1">
                            <?php esc_html_e('I understand this will overwrite existing data.', 'rosenheinrich-multisite-migrate'); ?>
                        </label>
                    </p>
                </div>

                <div class="multisite-migrate-wizard-footer">
                    <button type="button" class="button" id="mm-restore-cancel-dialog"><?php esc_html_e('Cancel', 'rosenheinrich-multisite-migrate'); ?></button>
                    <div class="mm-wizard-footer-actions">
                        <button type="button" class="button mm-hidden" id="mm-restore-back"><?php esc_html_e('Back', 'rosenheinrich-multisite-migrate'); ?></button>
                        <button type="button" class="button button-primary mm-btn-teal" id="mm-restore-next"><?php esc_html_e('Next', 'rosenheinrich-multisite-migrate'); ?></button>
                        <button type="button" class="button button-primary mm-btn-teal mm-hidden" id="mm-restore-start"><?php esc_html_e('Start restore', 'rosenheinrich-multisite-migrate'); ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
