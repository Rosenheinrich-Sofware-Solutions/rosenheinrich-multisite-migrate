<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_settings = Rmmigrate_Schedules::normalize($rmmigrate_settings);
$rmmigrate_save_url = $rmmigrate_is_network
    ? network_admin_url('edit.php?action=rmmigrate_schedule_settings')
    : admin_url('admin-post.php?action=rmmigrate_schedule_settings');
$rmmigrate_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
$rmmigrate_schedule_sites = (is_multisite() && $rmmigrate_is_network)
    ? Rmmigrate_Schedules::sites_for_picker()
    : array();
$rmmigrate_last_tick = (int) get_site_option(Rmmigrate_Scheduler::LAST_TICK_OPTION, 0);
$rmmigrate_pricing_url = Rmmigrate_Capabilities::pricing_url();
if (is_multisite() && !$rmmigrate_is_network) {
    $rmmigrate_blog_rows = Rmmigrate_Schedules::for_blog($rmmigrate_settings, (int) get_current_blog_id());
    $rmmigrate_schedule = $rmmigrate_blog_rows[0];
    $rmmigrate_next_run = !empty($rmmigrate_schedule['enabled']) ? (int) ($rmmigrate_schedule['next_run'] ?? 0) : 0;
} else {
    $rmmigrate_schedule = Rmmigrate_Schedules::network_schedule($rmmigrate_settings);
    $rmmigrate_next_run = Rmmigrate_Schedules::earliest_next_run($rmmigrate_settings);
}
$rmmigrate_row_id = (string) ($rmmigrate_schedule['id'] ?? Rmmigrate_Schedules::new_id());
?>
<div class="mm-schedules-page">
<form method="post" action="<?php echo esc_url($rmmigrate_save_url); ?>" class="mm-schedules-form" id="mm-schedules-form">
    <?php wp_nonce_field('rmmigrate_schedule_settings'); ?>

    <section class="mm-form-section mm-schedules-hero">
        <header class="mm-schedules-hero__head">
            <div class="mm-schedules-hero__copy">
                <h2 class="mm-schedules-hero__title"><?php esc_html_e('Schedules', 'rosenheinrich-multisite-migrate'); ?></h2>
                <p class="mm-schedules-toolbar__meta">
                    <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                    <span>
                        <strong><?php echo esc_html(wp_timezone_string()); ?></strong>
                        · <?php echo esc_html(wp_date('Y-m-d g:i a')); ?>
                        · <a href="<?php echo esc_url(admin_url('options-general.php')); ?>"><?php esc_html_e('Timezone', 'rosenheinrich-multisite-migrate'); ?></a>
                    </span>
                </p>
                <p class="description">
                    <?php esc_html_e('Free includes one local scheduled backup. Cloud destinations, multiple schedules, and incremental backups are available in Plus/Pro.', 'rosenheinrich-multisite-migrate'); ?>
                    <a href="<?php echo esc_url($rmmigrate_pricing_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Compare plans', 'rosenheinrich-multisite-migrate'); ?></a>
                </p>
            </div>
        </header>
    </section>

    <div id="mm-schedules-body" class="mm-schedules-list">
        <?php include RMMIGRATE_PATH . 'admin/partials/schedule-row.php'; ?>
    </div>

    <div class="mm-schedules-meta">
        <?php if ($rmmigrate_is_network || !is_multisite()) : ?>
        <section class="mm-form-section mm-schedules-section mm-schedules-section--retention">
            <header class="mm-cloud-panel__head">
                <h2 class="mm-cloud-panel__title"><?php esc_html_e('Retention', 'rosenheinrich-multisite-migrate'); ?></h2>
            </header>
            <div class="mm-schedules-fields">
                <div class="mm-schedules-field">
                    <label for="retention_network"><?php esc_html_e('Network backups', 'rosenheinrich-multisite-migrate'); ?></label>
                    <div class="mm-schedules-field__control">
                        <input type="number" min="0" max="50" name="retention_network" id="retention_network" value="<?php echo esc_attr((string) ($rmmigrate_settings['retention_network'] ?? 0)); ?>" class="small-text">
                        <span class="description"><?php esc_html_e('0 = keep all', 'rosenheinrich-multisite-migrate'); ?></span>
                    </div>
                </div>
                <?php if (is_multisite()) : ?>
                <div class="mm-schedules-field">
                    <label for="retention_subsite"><?php esc_html_e('Per-site backups', 'rosenheinrich-multisite-migrate'); ?></label>
                    <div class="mm-schedules-field__control">
                        <input type="number" min="0" max="50" name="retention_subsite" id="retention_subsite" value="<?php echo esc_attr((string) ($rmmigrate_settings['retention_subsite'] ?? 0)); ?>" class="small-text">
                        <span class="description"><?php esc_html_e('0 = keep all', 'rosenheinrich-multisite-migrate'); ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php else : ?>
        <section class="mm-form-section mm-schedules-section mm-schedules-section--retention">
            <header class="mm-cloud-panel__head">
                <h2 class="mm-cloud-panel__title"><?php esc_html_e('Retention', 'rosenheinrich-multisite-migrate'); ?></h2>
            </header>
            <p class="description"><?php esc_html_e('Network retention limits are managed in Network Admin.', 'rosenheinrich-multisite-migrate'); ?></p>
            <div class="mm-schedules-fields">
                <div class="mm-schedules-field">
                    <label for="retention_subsite"><?php esc_html_e('Per-site backups', 'rosenheinrich-multisite-migrate'); ?></label>
                    <div class="mm-schedules-field__control">
                        <input type="number" min="0" max="50" name="retention_subsite" id="retention_subsite" value="<?php echo esc_attr((string) ($rmmigrate_settings['retention_subsite'] ?? 0)); ?>" class="small-text">
                        <span class="description"><?php esc_html_e('0 = keep all', 'rosenheinrich-multisite-migrate'); ?></span>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="mm-form-section mm-schedules-section mm-schedules-section--cron">
            <header class="mm-cloud-panel__head">
                <h2 class="mm-cloud-panel__title"><?php esc_html_e('Cron', 'rosenheinrich-multisite-migrate'); ?></h2>
            </header>
            <table class="form-table mm-schedules-kv-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Next run', 'rosenheinrich-multisite-migrate'); ?></th>
                    <td><?php
                    if ($rmmigrate_next_run > 0) {
                        echo esc_html(wp_date('Y-m-d g:i a', $rmmigrate_next_run));
                    } else {
                        esc_html_e('Not scheduled', 'rosenheinrich-multisite-migrate');
                    }
                    ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Last tick', 'rosenheinrich-multisite-migrate'); ?></th>
                    <td><?php
                    if ($rmmigrate_last_tick > 0) {
                        echo esc_html(wp_date('Y-m-d g:i a', $rmmigrate_last_tick));
                    } else {
                        esc_html_e('Never', 'rosenheinrich-multisite-migrate');
                    }
                    ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('WP-Cron', 'rosenheinrich-multisite-migrate'); ?></th>
                    <td><?php
                    if ($rmmigrate_cron_disabled) {
                        esc_html_e('Disabled (system cron)', 'rosenheinrich-multisite-migrate');
                    } else {
                        esc_html_e('On page visits', 'rosenheinrich-multisite-migrate');
                    }
                    ?></td>
                </tr>
            </table>
            <?php if (!$rmmigrate_cron_disabled) : ?>
            <p class="description">
                <?php esc_html_e('For on-time backups on quiet sites, run a server cron against wp-cron.php every minute.', 'rosenheinrich-multisite-migrate'); ?>
            </p>
            <?php endif; ?>
        </section>
    </div>

    <div class="mm-form-actions mm-schedules-form__actions">
        <?php submit_button(__('Save schedule', 'rosenheinrich-multisite-migrate'), 'primary mm-btn-teal', 'submit', false); ?>
    </div>
</form>
</div>
