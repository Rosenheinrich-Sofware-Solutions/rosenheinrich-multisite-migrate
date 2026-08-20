<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_settings = Rmmigrate_Settings::get();
$rmmigrate_default_scope = $rmmigrate_settings['default_scope'] ?? 'network';
$rmmigrate_default_profile = $rmmigrate_settings['default_backup_profile'] ?? 'full';
$rmmigrate_default_custom_paths = $rmmigrate_settings['default_custom_paths'] ?? '';
// Pre-check WP-core inclusion from last wizard choice.
$rmmigrate_default_include_core = !empty($rmmigrate_settings['include_wp_core']);
$rmmigrate_default_archive_mode = ($rmmigrate_settings['archive_mode'] ?? 'daf') === 'zip' ? 'zip' : 'daf';
$rmmigrate_active = $rmmigrate_active ?? null;
$rmmigrate_backup_blocked = Rmmigrate_Job::get_active() !== null;
$rmmigrate_uploads_placeholder = Rmmigrate_Engine_Config::uploads_basedir();
?>
<section id="mm-create-wizard" class="mm-create-wizard mm-hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mm-create-wizard-title" data-create-step-count="3">
    <div class="mm-create-wizard__head">
        <div class="mm-create-wizard__head-text">
            <h2 id="mm-create-wizard-title" tabindex="-1"><?php esc_html_e('Protect your site', 'rosenheinrich-multisite-migrate'); ?></h2>
            <p class="mm-create-wizard__lead"><?php esc_html_e('Run a backup now to protect your site.', 'rosenheinrich-multisite-migrate'); ?></p>
        </div>
        <button type="button" class="mm-create-wizard__close" id="mm-create-wizard-close" aria-label="<?php esc_attr_e('Close', 'rosenheinrich-multisite-migrate'); ?>">&times;</button>
    </div>
    <div class="mm-create-wizard__body">
        <ol class="mm-create-wizard-steps" aria-label="<?php esc_attr_e('Backup steps', 'rosenheinrich-multisite-migrate'); ?>">
            <li class="is-active" aria-current="step">
                <button type="button" class="mm-create-step-btn" data-create-goto="1" disabled>
                    <span class="mm-step-num" aria-hidden="true">1</span>
                    <span class="mm-step-label"><?php esc_html_e('Profile', 'rosenheinrich-multisite-migrate'); ?></span>
                </button>
            </li>
            <li>
                <button type="button" class="mm-create-step-btn" data-create-goto="2" disabled>
                    <span class="mm-step-num" aria-hidden="true">2</span>
                    <span class="mm-step-label"><?php esc_html_e('Destination', 'rosenheinrich-multisite-migrate'); ?></span>
                </button>
            </li>
            <li>
                <button type="button" class="mm-create-step-btn" data-create-goto="3" disabled>
                    <span class="mm-step-num" aria-hidden="true">3</span>
                    <span class="mm-step-label"><?php esc_html_e('Review', 'rosenheinrich-multisite-migrate'); ?></span>
                </button>
            </li>
        </ol>

        <div class="mm-create-step is-active" data-create-step="1">
            <?php if (!empty($rmmigrate_subsite_mode)) : ?>
                <input type="hidden" name="mm_scope" value="subsite">
                <p><?php esc_html_e('Backup this site (database + files).', 'rosenheinrich-multisite-migrate'); ?></p>
            <?php elseif ($rmmigrate_is_network) : ?>
                <div class="mm-setup-scope-options">
                    <div class="mm-setup-scope-option">
                        <label><input type="radio" name="mm_scope" value="network" <?php checked($rmmigrate_default_scope, 'network'); ?>> <?php esc_html_e('Full network', 'rosenheinrich-multisite-migrate'); ?></label>
                        <button type="button" class="mm-help-tip" aria-expanded="false" data-tip="<?php echo esc_attr(__('Backs up the entire multisite network including all subsites, uploads, and shared tables.', 'rosenheinrich-multisite-migrate')); ?>" aria-label="<?php esc_attr_e('Help', 'rosenheinrich-multisite-migrate'); ?>">?</button>
                    </div>
                    <div class="mm-setup-scope-option">
                        <label><input type="radio" name="mm_scope" value="network_included" <?php checked($rmmigrate_default_scope, 'network_included'); ?>> <?php esc_html_e('Only selected subsites', 'rosenheinrich-multisite-migrate'); ?></label>
                        <button type="button" class="mm-help-tip" aria-expanded="false" data-tip="<?php echo esc_attr(__('Backs up only the subsites you check below. Shared network tables are still included.', 'rosenheinrich-multisite-migrate')); ?>" aria-label="<?php esc_attr_e('Help', 'rosenheinrich-multisite-migrate'); ?>">?</button>
                    </div>
                    <div class="mm-setup-scope-option">
                        <label><input type="radio" name="mm_scope" value="network_filtered" <?php checked($rmmigrate_default_scope, 'network_filtered'); ?>> <?php esc_html_e('Network (exclude subsites)', 'rosenheinrich-multisite-migrate'); ?></label>
                    </div>
                </div>
                <div id="mm-subsite-include" class="<?php echo esc_attr($rmmigrate_default_scope === 'network_included' ? '' : 'mm-hidden'); ?>">
                    <?php
                    $rmmigrate_selection_mode = 'include';
                    $rmmigrate_selected_blogs = $rmmigrate_settings['default_included_blogs'] ?? array();
                    include RMMIGRATE_PATH . 'admin/partials/network-subsite-exclude.php';
                    ?>
                </div>
                <div id="mm-subsite-exclude" class="<?php echo esc_attr($rmmigrate_default_scope === 'network_filtered' ? '' : 'mm-hidden'); ?>">
                    <?php
                    $rmmigrate_selection_mode = 'exclude';
                    $rmmigrate_selected_blogs = $rmmigrate_settings['default_excluded_blogs'] ?? array();
                    include RMMIGRATE_PATH . 'admin/partials/network-subsite-exclude.php';
                    ?>
                </div>
            <?php else : ?>
                <p><?php esc_html_e('Backup this site (database + files).', 'rosenheinrich-multisite-migrate'); ?></p>
            <?php endif; ?>
            <p>
                <label for="rosenheinrich-multisite-migratefile"><?php esc_html_e('Backup profile', 'rosenheinrich-multisite-migrate'); ?></label><br>
                <select id="rosenheinrich-multisite-migratefile">
                    <option value="full" <?php selected($rmmigrate_default_profile, 'full'); ?>><?php esc_html_e('Full (database + files)', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="db" <?php selected($rmmigrate_default_profile, 'db'); ?>><?php esc_html_e('Database only', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="files" <?php selected($rmmigrate_default_profile, 'files'); ?>><?php esc_html_e('Files only', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="media" <?php selected($rmmigrate_default_profile, 'media'); ?>><?php esc_html_e('Media / uploads only', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="plugins" <?php selected($rmmigrate_default_profile, 'plugins'); ?>><?php esc_html_e('Plugins only', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="themes" <?php selected($rmmigrate_default_profile, 'themes'); ?>><?php esc_html_e('Themes only', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="custom" <?php selected($rmmigrate_default_profile, 'custom'); ?>><?php esc_html_e('Custom paths', 'rosenheinrich-multisite-migrate'); ?></option>
                </select>
            </p>
            <p id="mm-custom-paths-wrap" class="<?php echo esc_attr($rmmigrate_default_profile === 'custom' ? '' : 'mm-hidden'); ?>">
                <label for="mm-custom-paths"><?php esc_html_e('Custom paths (one per line)', 'rosenheinrich-multisite-migrate'); ?></label><br>
                <textarea id="mm-custom-paths" rows="3" class="large-text" placeholder="<?php echo esc_attr($rmmigrate_uploads_placeholder); ?>"><?php echo esc_textarea($rmmigrate_default_custom_paths); ?></textarea>
            </p>
            <p>
                <label for="mm-archive-mode"><?php esc_html_e('Archive format', 'rosenheinrich-multisite-migrate'); ?></label>
                <button type="button" class="mm-help-tip" aria-expanded="false" data-tip="<?php echo esc_attr(__('Choose how this backup is packaged. ZIP needs the ZipArchive extension; DAF is a resumable streaming format that survives short PHP timeouts. Your last choice is remembered for the next backup.', 'rosenheinrich-multisite-migrate')); ?>" aria-label="<?php esc_attr_e('Archive format help', 'rosenheinrich-multisite-migrate'); ?>">?</button><br>
                <select id="mm-archive-mode">
                    <option value="daf" <?php selected($rmmigrate_default_archive_mode, 'daf'); ?>><?php esc_html_e('DAF — Resumable', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="zip" <?php selected($rmmigrate_default_archive_mode, 'zip'); ?>><?php esc_html_e('ZIP — Standard', 'rosenheinrich-multisite-migrate'); ?></option>
                </select><br>
                <span class="description"><?php esc_html_e('DAF resumes across PHP timeouts (best for large sites). ZIP is the universal format but needs the ZipArchive extension.', 'rosenheinrich-multisite-migrate'); ?></span>
            </p>
            <p>
                <label><input type="checkbox" id="mm-include-wp-core" value="1" <?php checked($rmmigrate_default_include_core); ?>> <?php esc_html_e('Include WordPress core files', 'rosenheinrich-multisite-migrate'); ?></label>
                <button type="button" class="mm-help-tip" aria-expanded="false" data-tip="<?php echo esc_attr(__('Includes wp-admin, wp-includes, and root PHP files. Required for empty-server migration; leave off for smaller same-server backups (database + wp-content). Your last choice is remembered for the next backup.', 'rosenheinrich-multisite-migrate')); ?>" aria-label="<?php esc_attr_e('Help', 'rosenheinrich-multisite-migrate'); ?>">?</button>
            </p>
            <details class="mm-advanced-backup-options">
                <summary><?php esc_html_e('Advanced database options', 'rosenheinrich-multisite-migrate'); ?></summary>
                <p>
                    <label><input type="checkbox" id="mm-exclude-log-tables" value="1" <?php checked(!empty($rmmigrate_settings['exclude_log_tables'])); ?>> <?php esc_html_e('Exclude common log tables (Wordfence, Action Scheduler)', 'rosenheinrich-multisite-migrate'); ?></label>
                </p>
                <p>
                    <label><input type="checkbox" id="mm-exclude-revisions" value="1" <?php checked(!empty($rmmigrate_settings['exclude_revisions'])); ?>> <?php esc_html_e('Exclude post revisions (and their postmeta)', 'rosenheinrich-multisite-migrate'); ?></label>
                </p>
                <p>
                    <label for="mm-exclude-tables"><?php esc_html_e('Exclude tables (one name or pattern per line, e.g. q6rg_wfblockediplog or *actionscheduler_*)', 'rosenheinrich-multisite-migrate'); ?></label><br>
                    <textarea id="mm-exclude-tables" rows="3" class="large-text" placeholder="<?php echo esc_attr('*wfblockediplog'); ?>"></textarea>
                </p>
            </details>
        </div>

        <div class="mm-create-step" data-create-step="2">
            <?php
            $rmmigrate_local_archives_path = wp_normalize_path(Rmmigrate_Plugin::backups_dir());
            ?>
            <div class="mm-create-destination">
                <div class="mm-create-dest-card is-selected" role="group" aria-label="<?php esc_attr_e('Local destination', 'rosenheinrich-multisite-migrate'); ?>">
                    <div class="mm-create-dest-card__top">
                        <span class="mm-create-dest-card__icon dashicons dashicons-database" aria-hidden="true"></span>
                        <div class="mm-create-dest-card__heading">
                            <strong class="mm-create-dest-card__title"><?php esc_html_e('Local — on this server', 'rosenheinrich-multisite-migrate'); ?></strong>
                            <span class="mm-create-dest-card__pill"><?php esc_html_e('Selected', 'rosenheinrich-multisite-migrate'); ?></span>
                        </div>
                    </div>
                    <p class="mm-create-dest-card__desc"><?php esc_html_e('Archives stay on this WordPress server. No cloud account or extra setup required.', 'rosenheinrich-multisite-migrate'); ?></p>
                    <ul class="mm-create-dest-card__points">
                        <li><?php esc_html_e('Download from Backups anytime', 'rosenheinrich-multisite-migrate'); ?></li>
                        <li><?php esc_html_e('Works on shared hosting and offline servers', 'rosenheinrich-multisite-migrate'); ?></li>
                        <li><?php esc_html_e('Use for same-server restore and local import', 'rosenheinrich-multisite-migrate'); ?></li>
                    </ul>
                    <p class="mm-create-dest-card__path">
                        <span class="mm-create-dest-card__path-label"><?php esc_html_e('Storage path', 'rosenheinrich-multisite-migrate'); ?></span>
                        <code><?php echo esc_html($rmmigrate_local_archives_path); ?></code>
                    </p>
                    <p class="mm-create-dest-card__status">
                        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                        <?php esc_html_e('Always available', 'rosenheinrich-multisite-migrate'); ?>
                    </p>
                </div>
                <?php
                Rmmigrate_Pro_Hints::render(
                    'create_cloud',
                    array(
                        'title'     => __('Same server = same blast radius', 'rosenheinrich-multisite-migrate'),
                        'text'      => __('Local backups stay on this host. Plus adds BYO cloud (Drive, Dropbox, S3), and scheduled backups to cloud destinations so jobs that stall mid-timeout do not fail silently.', 'rosenheinrich-multisite-migrate'),
                        'cta_label' => __('Set up off-site backup', 'rosenheinrich-multisite-migrate'),
                        'placement' => 'plugin_intent_offsite',
                    )
                );
                ?>
            </div>
            <input type="hidden" id="multisite-migrate-destination" value="local">
        </div>

        <div class="mm-create-step" data-create-step="3">
            <div class="mm-create-review">
                <p class="mm-create-review__lead"><?php esc_html_e('Check these settings before starting the backup.', 'rosenheinrich-multisite-migrate'); ?></p>
                <div class="mm-setup-review-card">
                    <h3 class="mm-setup-review-card__title"><?php esc_html_e('Backup summary', 'rosenheinrich-multisite-migrate'); ?></h3>
                    <dl class="mm-create-review-list" id="mm-create-review"></dl>
                </div>
            </div>
        </div>

        <div class="multisite-migrate-wizard-footer">
            <button type="button" class="button" id="mm-create-cancel"><?php esc_html_e('Cancel', 'rosenheinrich-multisite-migrate'); ?></button>
            <div class="mm-wizard-footer-actions">
                <button type="button" class="button mm-hidden" id="mm-create-back"><?php esc_html_e('Back', 'rosenheinrich-multisite-migrate'); ?></button>
                <button type="button" class="button button-primary mm-btn-teal mm-hidden" id="multisite-migrate-start" <?php disabled(!empty($rmmigrate_backup_blocked)); ?>>
                    <?php esc_html_e('Start backup', 'rosenheinrich-multisite-migrate'); ?>
                </button>
                <button type="button" class="button button-primary mm-btn-teal" id="mm-create-next"><?php esc_html_e('Next', 'rosenheinrich-multisite-migrate'); ?></button>
            </div>
        </div>
    </div>
</section>
