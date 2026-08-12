<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_health = Rmmigrate_Health::get_status();
$rmmigrate_setup_state = Rmmigrate_Setup_Wizard::get_state();
$rmmigrate_setup_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-setup', array(), $rmmigrate_is_network);
$rmmigrate_archives_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array(), $rmmigrate_is_network);
$rmmigrate_migrate_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-migrate', array('tab' => 'import'), $rmmigrate_is_network);
$rmmigrate_upgrade_url = Rmmigrate_Links::pricing_url('dashboard');
$rmmigrate_failed_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-archives', array('filter' => 'failed'), $rmmigrate_is_network);

$rmmigrate_recent_args = array('limit' => 5, 'status_group' => 'completed');
if (!$rmmigrate_is_network && is_multisite()) {
    $rmmigrate_recent_args['scope'] = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
    $rmmigrate_recent_args['blog_id'] = get_current_blog_id();
}
$rmmigrate_recent_jobs = Rmmigrate_Job::list_jobs($rmmigrate_recent_args);

$rmmigrate_failed_args = array('limit' => 5, 'status_group' => 'failed');
if (!$rmmigrate_is_network && is_multisite()) {
    $rmmigrate_failed_args['scope'] = Rmmigrate_Multisite_Scope::SCOPE_SUBSITE;
    $rmmigrate_failed_args['blog_id'] = get_current_blog_id();
}
$rmmigrate_failed_jobs = Rmmigrate_Job::list_jobs($rmmigrate_failed_args);

$rmmigrate_scope_label = __('Full network', 'rosenheinrich-multisite-migrate');
if (is_multisite()) {
    $rmmigrate_scope = $rmmigrate_settings['default_scope'] ?? 'network';
    $rmmigrate_site_count = count(Rmmigrate_Multisite_Scope::get_all_site_ids());
    if ($rmmigrate_scope === 'network_included') {
        $rmmigrate_included = array_map('intval', (array) ($rmmigrate_settings['default_included_blogs'] ?? array()));
        $rmmigrate_scope_label = sprintf(
            /* translators: %d: number of subsites */
            __('Selected subsites (%d)', 'rosenheinrich-multisite-migrate'),
            count($rmmigrate_included)
        );
    } elseif ($rmmigrate_scope === 'network_filtered') {
        $rmmigrate_scope_label = __('Network (with exclusions)', 'rosenheinrich-multisite-migrate');
    } elseif ($rmmigrate_scope === 'subsite') {
        $rmmigrate_scope_label = __('Single subsite default', 'rosenheinrich-multisite-migrate');
    }
    $rmmigrate_network_summary = sprintf(
        /* translators: 1: site count, 2: scope label */
        __('%1$d subsites · default: %2$s', 'rosenheinrich-multisite-migrate'),
        max(0, $rmmigrate_site_count - 1),
        $rmmigrate_scope_label
    );
} else {
    $rmmigrate_network_summary = __('Single site', 'rosenheinrich-multisite-migrate');
}
?>

<?php include RMMIGRATE_PATH . 'admin/partials/components/verify-restore-banner.php'; ?>

<?php include RMMIGRATE_PATH . 'admin/partials/backup-create-panel.php'; ?>

<div id="mm-create-host">

<div class="mm-stat-strip">
    <?php include RMMIGRATE_PATH . 'admin/partials/components/health-stat-card.php'; ?>
    <div class="mm-stat-card">
        <strong><?php esc_html_e('Last backup', 'rosenheinrich-multisite-migrate'); ?></strong>
        <span><?php if ($rmmigrate_last && !empty($rmmigrate_last->data['completed_at'])) {
            echo esc_html(Rmmigrate_Time::local_label((string) $rmmigrate_last->data['completed_at']));
        } elseif ($rmmigrate_last) {
            echo esc_html__('—', 'rosenheinrich-multisite-migrate');
        } else {
            esc_html_e('None yet', 'rosenheinrich-multisite-migrate');
        } ?></span>
    </div>
    <?php if (Rmmigrate_Setup_Wizard::needs_setup_banner()) : ?>
        <div class="mm-stat-card">
            <strong><?php esc_html_e('Setup', 'rosenheinrich-multisite-migrate'); ?></strong>
            <span><a href="<?php echo esc_url($rmmigrate_setup_url); ?>"><?php
                printf(
                    /* translators: %1$d: setup wizard completion percentage. */
                    esc_html__('%1$d%% complete', 'rosenheinrich-multisite-migrate'),
                    (int) Rmmigrate_Setup_Wizard::progress_percent()
                );
                ?></a></span>
        </div>
    <?php endif; ?>
</div>

<?php include RMMIGRATE_PATH . 'admin/partials/components/health-checks-panel.php'; ?>

<?php include RMMIGRATE_PATH . 'admin/partials/components/active-job-banner.php'; ?>

<?php include RMMIGRATE_PATH . 'admin/partials/components/intent-assist-bar.php'; ?>

<?php if (Rmmigrate_Setup_Wizard::can_run() && !Rmmigrate_Setup_Wizard::is_finished()) : ?>
<div class="mm-home-setup-later mm-form-section">
    <h2 class="mm-section-heading"><?php esc_html_e('Set up later', 'rosenheinrich-multisite-migrate'); ?></h2>
    <p class="description"><?php esc_html_e('Optional - finish the setup wizard when you are ready, or explore Multisite Migrate Pro.', 'rosenheinrich-multisite-migrate'); ?></p>
    <p>
        <a class="button" href="<?php echo esc_url($rmmigrate_upgrade_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Explore Pro features', 'rosenheinrich-multisite-migrate'); ?></a>
        <?php if (Rmmigrate_Setup_Wizard::menu_visible()) : ?>
        <a class="button" href="<?php echo esc_url($rmmigrate_setup_url); ?>"><?php esc_html_e('Setup wizard', 'rosenheinrich-multisite-migrate'); ?></a>
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<div class="mm-home-grid">
    <div class="mm-premium-panel mm-home-panel">
        <div class="mm-premium-panel-header">
            <h2 class="mm-section-heading"><?php esc_html_e('Recent backups', 'rosenheinrich-multisite-migrate'); ?></h2>
            <a href="<?php echo esc_url($rmmigrate_archives_url); ?>" class="mm-panel-header-link"><?php esc_html_e('View all', 'rosenheinrich-multisite-migrate'); ?> &rarr;</a>
        </div>
        <?php if (empty($rmmigrate_recent_jobs)) : ?>
            <div class="mm-empty-section">
            <?php
            $rmmigrate_empty_title = __('No completed backups yet', 'rosenheinrich-multisite-migrate');
            $rmmigrate_empty_message = __('After your first backup finishes, your most recent restore points appear here.', 'rosenheinrich-multisite-migrate');
            $rmmigrate_empty_icon = 'dashicons-yes-alt';
            $rmmigrate_empty_actions = '';
            include RMMIGRATE_PATH . 'admin/partials/components/empty-state.php';
            ?>
            </div>
        <?php else : ?>
            <div class="mm-home-job-list">
                <?php foreach ($rmmigrate_recent_jobs as $rmmigrate_job) : ?>
                    <a href="<?php echo esc_url(add_query_arg('job_id', $rmmigrate_job->get_id(), $rmmigrate_archives_url)); ?>" class="mm-job-item">
                        <div class="mm-job-icon">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="mm-job-details">
                            <span class="mm-job-title">
                                <strong>#<?php echo (int) $rmmigrate_job->get_id(); ?></strong> &middot; 
                                <?php echo esc_html($rmmigrate_job->get_display_scope()); ?>
                            </span>
                            <span class="mm-job-meta">
                                <?php echo esc_html(Rmmigrate_Time::local_label((string) ($rmmigrate_job->data['completed_at'] ?? $rmmigrate_job->data['created_at'] ?? ''))); ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="mm-premium-panel mm-home-panel">
        <div class="mm-premium-panel-header">
            <h2 class="mm-section-heading"><?php esc_html_e('Network', 'rosenheinrich-multisite-migrate'); ?></h2>
        </div>
        
        <div class="mm-network-stats-grid">
            <?php if (is_multisite()) : ?>
                <div class="mm-network-stat-item mm-stat-full-width">
                    <span class="mm-badge mm-badge--success"><?php esc_html_e('Multisite-ready', 'rosenheinrich-multisite-migrate'); ?></span>
                </div>
            <?php endif; ?>
            
            <div class="mm-network-stat-item">
                <span class="mm-network-stat-value"><?php echo is_multisite() ? esc_html($rmmigrate_site_count) : '1'; ?></span>
                <span class="mm-network-stat-label"><?php echo is_multisite() ? esc_html__('Total sites in network', 'rosenheinrich-multisite-migrate') : esc_html__('Site count', 'rosenheinrich-multisite-migrate'); ?></span>
            </div>
            
            <div class="mm-network-stat-item">
                <span class="mm-network-stat-value mm-stat-value-text"><?php echo esc_html($rmmigrate_scope_label); ?></span>
                <span class="mm-network-stat-label"><?php esc_html_e('Last backup scope', 'rosenheinrich-multisite-migrate'); ?></span>
            </div>
            
            <?php if (!empty($rmmigrate_failed_jobs)) : ?>
                <div class="mm-network-stat-item mm-status-warn mm-stat-full-width">
                    <span class="mm-network-stat-value">
                        <?php echo esc_html(count($rmmigrate_failed_jobs)); ?>
                    </span>
                    <span class="mm-network-stat-label"><?php esc_html_e('Failed jobs', 'rosenheinrich-multisite-migrate'); ?></span>
                    <a href="<?php echo esc_url($rmmigrate_failed_url); ?>" class="mm-stat-link"><?php esc_html_e('View failed jobs', 'rosenheinrich-multisite-migrate'); ?></a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- #mm-create-host -->
