<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @var array<string,mixed> $rmmigrate_schedule
 * @var string              $rmmigrate_row_id
 * @var array<string,mixed> $rmmigrate_settings
 * @var bool                $rmmigrate_is_network
 * @var array<int,array{id:int,label:string}> $rmmigrate_schedule_sites
 */
$rmmigrate_scope_labels = array(
    'network' => __('Full network', 'rosenheinrich-multisite-migrate'),
    'subsite' => __('Single subsite', 'rosenheinrich-multisite-migrate'),
);
if (!is_multisite()) {
    $rmmigrate_scope_labels = array('subsite' => __('Full site', 'rosenheinrich-multisite-migrate'));
}
$rmmigrate_row_scope = (string) ($rmmigrate_schedule['scope'] ?? 'network');
$rmmigrate_row_blog_id = (int) ($rmmigrate_schedule['blog_id'] ?? 0);
if ($rmmigrate_row_scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE && $rmmigrate_row_blog_id <= 0 && $rmmigrate_schedule_sites !== array()) {
    $rmmigrate_row_blog_id = (int) $rmmigrate_schedule_sites[0]['id'];
}
$rmmigrate_next = (int) ($rmmigrate_schedule['next_run'] ?? 0);
$rmmigrate_prefix = 'schedules[' . $rmmigrate_row_id . ']';
$rmmigrate_hour_24 = (int) ($rmmigrate_schedule['hour'] ?? 3);
$rmmigrate_minute = max(0, min(59, (int) ($rmmigrate_schedule['minute'] ?? 0)));
$rmmigrate_hour_12 = $rmmigrate_hour_24 % 12;
$rmmigrate_hour_12 = $rmmigrate_hour_12 === 0 ? 12 : $rmmigrate_hour_12;
$rmmigrate_ampm = $rmmigrate_hour_24 >= 12 ? 'pm' : 'am';
$rmmigrate_show_network_scope = is_multisite() && !empty($rmmigrate_is_network);
?>
<article class="mm-form-section mm-schedule-card mm-schedule-row" data-schedule-row="<?php echo esc_attr($rmmigrate_row_id); ?>" data-interval="<?php echo esc_attr($rmmigrate_schedule['interval'] ?? 'weekly'); ?>" data-scope="<?php echo esc_attr($rmmigrate_row_scope); ?>">
    <header class="mm-schedule-card__header">
        <div class="mm-schedule-card__identity">
            <label class="mm-schedule-card__label" for="mm-schedule-name-<?php echo esc_attr($rmmigrate_row_id); ?>"><?php esc_html_e('Name', 'rosenheinrich-multisite-migrate'); ?></label>
            <input type="text" class="regular-text mm-schedule-name" id="mm-schedule-name-<?php echo esc_attr($rmmigrate_row_id); ?>" name="<?php echo esc_attr($rmmigrate_prefix); ?>[name]" value="<?php echo esc_attr($rmmigrate_schedule['name'] ?? ''); ?>" required>
        </div>
        <div class="mm-schedule-card__header-actions">
            <label class="mm-schedule-enabled">
                <input type="checkbox" id="mm-schedule-enabled-<?php echo esc_attr($rmmigrate_row_id); ?>" name="<?php echo esc_attr($rmmigrate_prefix); ?>[enabled]" value="1" <?php checked(!empty($rmmigrate_schedule['enabled'])); ?>>
                <span><?php esc_html_e('Enabled', 'rosenheinrich-multisite-migrate'); ?></span>
            </label>
        </div>
    </header>

    <div class="mm-schedule-card__settings">
        <div class="mm-schedule-field">
            <label class="mm-schedule-card__label" for="mm-schedule-interval-<?php echo esc_attr($rmmigrate_row_id); ?>"><?php esc_html_e('Interval', 'rosenheinrich-multisite-migrate'); ?></label>
            <select name="<?php echo esc_attr($rmmigrate_prefix); ?>[interval]" id="mm-schedule-interval-<?php echo esc_attr($rmmigrate_row_id); ?>" class="mm-schedule-interval">
                <option value="daily" <?php selected($rmmigrate_schedule['interval'] ?? '', 'daily'); ?>><?php esc_html_e('Daily', 'rosenheinrich-multisite-migrate'); ?></option>
                <option value="weekly" <?php selected($rmmigrate_schedule['interval'] ?? 'weekly', 'weekly'); ?>><?php esc_html_e('Weekly', 'rosenheinrich-multisite-migrate'); ?></option>
                <option value="monthly" <?php selected($rmmigrate_schedule['interval'] ?? '', 'monthly'); ?>><?php esc_html_e('Monthly', 'rosenheinrich-multisite-migrate'); ?></option>
            </select>
        </div>

        <div class="mm-schedule-field mm-schedule-time-cell">
            <span class="mm-schedule-card__label"><?php esc_html_e('Time', 'rosenheinrich-multisite-migrate'); ?></span>
            <div class="mm-schedule-inline-fields">
                <select name="<?php echo esc_attr($rmmigrate_prefix); ?>[hour_12]" class="mm-schedule-hour" aria-label="<?php esc_attr_e('Hour', 'rosenheinrich-multisite-migrate'); ?>">
                    <?php for ($rmmigrate_h = 1; $rmmigrate_h <= 12; $rmmigrate_h++) : ?>
                    <option value="<?php echo esc_attr((string) $rmmigrate_h); ?>" <?php selected($rmmigrate_hour_12, $rmmigrate_h); ?>><?php echo esc_html(sprintf('%02d', $rmmigrate_h)); ?></option>
                    <?php endfor; ?>
                </select>
                <span class="mm-schedule-time-sep" aria-hidden="true">:</span>
                <select name="<?php echo esc_attr($rmmigrate_prefix); ?>[minute]" class="mm-schedule-minute" aria-label="<?php esc_attr_e('Minute', 'rosenheinrich-multisite-migrate'); ?>">
                    <?php for ($rmmigrate_m = 0; $rmmigrate_m <= 59; $rmmigrate_m++) : ?>
                    <option value="<?php echo esc_attr((string) $rmmigrate_m); ?>" <?php selected($rmmigrate_minute, $rmmigrate_m); ?>><?php echo esc_html(sprintf('%02d', $rmmigrate_m)); ?></option>
                    <?php endfor; ?>
                </select>
                <select name="<?php echo esc_attr($rmmigrate_prefix); ?>[ampm]" class="mm-schedule-ampm" aria-label="<?php esc_attr_e('AM/PM', 'rosenheinrich-multisite-migrate'); ?>">
                    <option value="am" <?php selected($rmmigrate_ampm, 'am'); ?>><?php esc_html_e('AM', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="pm" <?php selected($rmmigrate_ampm, 'pm'); ?>><?php esc_html_e('PM', 'rosenheinrich-multisite-migrate'); ?></option>
                </select>
                <select name="<?php echo esc_attr($rmmigrate_prefix); ?>[weekday]" class="mm-schedule-weekday" aria-label="<?php esc_attr_e('Weekday', 'rosenheinrich-multisite-migrate'); ?>">
                <?php
                $rmmigrate_days = array(
                    __('Sunday', 'rosenheinrich-multisite-migrate'),
                    __('Monday', 'rosenheinrich-multisite-migrate'),
                    __('Tuesday', 'rosenheinrich-multisite-migrate'),
                    __('Wednesday', 'rosenheinrich-multisite-migrate'),
                    __('Thursday', 'rosenheinrich-multisite-migrate'),
                    __('Friday', 'rosenheinrich-multisite-migrate'),
                    __('Saturday', 'rosenheinrich-multisite-migrate'),
                );
                foreach ($rmmigrate_days as $rmmigrate_i => $rmmigrate_day) :
                    ?>
                    <option value="<?php echo esc_attr((string) $rmmigrate_i); ?>" <?php selected((int) ($rmmigrate_schedule['weekday'] ?? 1), $rmmigrate_i); ?>><?php echo esc_html($rmmigrate_day); ?></option>
                <?php endforeach; ?>
                </select>
                <input type="number" min="1" max="28" class="small-text mm-schedule-dom" name="<?php echo esc_attr($rmmigrate_prefix); ?>[day_of_month]" value="<?php echo esc_attr((string) ($rmmigrate_schedule['day_of_month'] ?? 1)); ?>" aria-label="<?php esc_attr_e('Day of month', 'rosenheinrich-multisite-migrate'); ?>">
            </div>
        </div>

        <div class="mm-schedule-field mm-schedule-profile-cell">
            <span class="mm-schedule-card__label"><?php esc_html_e('Profile', 'rosenheinrich-multisite-migrate'); ?></span>
            <div class="mm-schedule-inline-fields">
                <select name="<?php echo esc_attr($rmmigrate_prefix); ?>[profile]" aria-label="<?php esc_attr_e('Profile', 'rosenheinrich-multisite-migrate'); ?>">
                    <option value="full" <?php selected($rmmigrate_schedule['profile'] ?? 'full', 'full'); ?>><?php esc_html_e('Full', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="db" <?php selected($rmmigrate_schedule['profile'] ?? '', 'db'); ?>><?php esc_html_e('DB only', 'rosenheinrich-multisite-migrate'); ?></option>
                    <option value="files" <?php selected($rmmigrate_schedule['profile'] ?? '', 'files'); ?>><?php esc_html_e('Files only', 'rosenheinrich-multisite-migrate'); ?></option>
                </select>
            </div>
            <p class="description"><?php esc_html_e('Destination is always local storage in Free.', 'rosenheinrich-multisite-migrate'); ?></p>
        </div>

        <?php if ($rmmigrate_show_network_scope) : ?>
        <div class="mm-schedule-field">
            <label class="mm-schedule-card__label" for="mm-schedule-scope-<?php echo esc_attr($rmmigrate_row_id); ?>"><?php esc_html_e('Scope', 'rosenheinrich-multisite-migrate'); ?></label>
            <select name="<?php echo esc_attr($rmmigrate_prefix); ?>[scope]" id="mm-schedule-scope-<?php echo esc_attr($rmmigrate_row_id); ?>" class="mm-schedule-scope">
                <?php foreach ($rmmigrate_scope_labels as $rmmigrate_scope_key => $rmmigrate_scope_label) : ?>
                    <option value="<?php echo esc_attr($rmmigrate_scope_key); ?>" <?php selected($rmmigrate_row_scope, $rmmigrate_scope_key); ?>><?php echo esc_html($rmmigrate_scope_label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else : ?>
        <input type="hidden" name="<?php echo esc_attr($rmmigrate_prefix); ?>[scope]" value="subsite">
        <input type="hidden" name="<?php echo esc_attr($rmmigrate_prefix); ?>[blog_id]" value="<?php echo esc_attr((string) get_current_blog_id()); ?>">
        <?php endif; ?>
    </div>

    <?php if ($rmmigrate_show_network_scope) : ?>
    <div class="mm-schedule-card__scope-panel">
        <div class="mm-schedule-scope-pane mm-schedule-scope-pane--network<?php echo $rmmigrate_row_scope === Rmmigrate_Multisite_Scope::SCOPE_NETWORK ? '' : ' mm-hidden'; ?>">
            <p class="description"><?php esc_html_e('Backs up the entire network.', 'rosenheinrich-multisite-migrate'); ?></p>
        </div>
        <div class="mm-schedule-scope-pane mm-schedule-scope-pane--subsite<?php echo $rmmigrate_row_scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE ? '' : ' mm-hidden'; ?>">
            <label class="mm-schedule-card__label" for="mm-schedule-blog-<?php echo esc_attr($rmmigrate_row_id); ?>"><?php esc_html_e('Target subsite', 'rosenheinrich-multisite-migrate'); ?></label>
            <select name="<?php echo esc_attr($rmmigrate_prefix); ?>[blog_id]" id="mm-schedule-blog-<?php echo esc_attr($rmmigrate_row_id); ?>" class="mm-schedule-blog-id">
                <?php foreach ($rmmigrate_schedule_sites as $rmmigrate_site_row) : ?>
                    <option value="<?php echo esc_attr((string) $rmmigrate_site_row['id']); ?>" <?php selected($rmmigrate_row_blog_id, (int) $rmmigrate_site_row['id']); ?>><?php echo esc_html($rmmigrate_site_row['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php endif; ?>

    <footer class="mm-schedule-card__meta">
        <span class="mm-schedule-card__label"><?php esc_html_e('Next run', 'rosenheinrich-multisite-migrate'); ?></span>
        <span class="mm-schedule-next-run<?php echo !empty($rmmigrate_schedule['enabled']) ? ' is-active' : ' is-off'; ?>">
            <?php
            if (!empty($rmmigrate_schedule['enabled']) && $rmmigrate_next > 0) {
                echo esc_html(wp_date('Y-m-d g:i a', $rmmigrate_next));
            } else {
                esc_html_e('Not scheduled', 'rosenheinrich-multisite-migrate');
            }
            ?>
        </span>
    </footer>
</article>
