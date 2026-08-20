<?php
if (!defined('ABSPATH')) {
    exit;
}
$rmmigrate_modes = array(
    'never'   => __('Never', 'rosenheinrich-multisite-migrate'),
    'failure' => __('On failure', 'rosenheinrich-multisite-migrate'),
    'success' => __('On success', 'rosenheinrich-multisite-migrate'),
    'always'  => __('Always', 'rosenheinrich-multisite-migrate'),
);
$rmmigrate_notify = Rmmigrate_Settings::notification_settings_for_context();
$rmmigrate_settings = array_merge($rmmigrate_settings, array_intersect_key(
    $rmmigrate_notify,
    array_flip(array(
        'email_enabled',
        'email_address',
        'email_manual_mode',
        'email_schedule_mode',
        'email_restore_mode',
        'email_import_mode',
    ))
));
$rmmigrate_default_email = Rmmigrate_Settings::default_admin_email(
    isset($rmmigrate_notify['_notification_blog_id']) ? (int) $rmmigrate_notify['_notification_blog_id'] : null
);
?>
<div class="mm-form-section mm-notifications-panel">
    <h2><?php esc_html_e('Email notifications', 'rosenheinrich-multisite-migrate'); ?></h2>

    <table class="form-table">
        <tr>
            <th scope="row"><?php esc_html_e('Enable email notifications', 'rosenheinrich-multisite-migrate'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="email_enabled" value="1" <?php checked(!empty($rmmigrate_settings['email_enabled'])); ?>>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="email_address"><?php esc_html_e('Email address', 'rosenheinrich-multisite-migrate'); ?></label></th>
            <td>
                <input type="email" class="regular-text" name="email_address" id="email_address" value="<?php echo esc_attr($rmmigrate_settings['email_address'] ?? ''); ?>" placeholder="<?php echo esc_attr($rmmigrate_default_email); ?>">
                <p class="description">
                    <?php
                    if ($rmmigrate_default_email !== '') {
                        if (is_multisite()) {
                            printf(
                                /* translators: %s: network administrator email address */
                                esc_html__('Leave empty to use the network administrator email (%s).', 'rosenheinrich-multisite-migrate'),
                                esc_html($rmmigrate_default_email)
                            );
                        } else {
                            printf(
                                /* translators: %s: site administrator email address */
                                esc_html__('Leave empty to use the site administrator email (%s).', 'rosenheinrich-multisite-migrate'),
                                esc_html($rmmigrate_default_email)
                            );
                        }
                    } else {
                        esc_html_e('Administrator email is used if empty.', 'rosenheinrich-multisite-migrate');
                    }
                    ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="email_manual_mode"><?php esc_html_e('Manual backups', 'rosenheinrich-multisite-migrate'); ?></label></th>
            <td>
                <select name="email_manual_mode" id="email_manual_mode">
                    <?php foreach ($rmmigrate_modes as $rmmigrate_val => $rmmigrate_label) : ?>
                        <option value="<?php echo esc_attr($rmmigrate_val); ?>" <?php selected($rmmigrate_settings['email_manual_mode'] ?? 'failure', $rmmigrate_val); ?>><?php echo esc_html($rmmigrate_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="email_schedule_mode"><?php esc_html_e('Scheduled backups', 'rosenheinrich-multisite-migrate'); ?></label></th>
            <td>
                <select name="email_schedule_mode" id="email_schedule_mode">
                    <?php foreach (array('never', 'failure', 'always') as $rmmigrate_val) : ?>
                        <option value="<?php echo esc_attr($rmmigrate_val); ?>" <?php selected($rmmigrate_settings['email_schedule_mode'] ?? 'failure', $rmmigrate_val); ?>><?php echo esc_html($rmmigrate_modes[$rmmigrate_val]); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="email_restore_mode"><?php esc_html_e('Restore jobs', 'rosenheinrich-multisite-migrate'); ?></label></th>
            <td>
                <select name="email_restore_mode" id="email_restore_mode">
                    <?php foreach ($rmmigrate_modes as $rmmigrate_val => $rmmigrate_label) : ?>
                        <option value="<?php echo esc_attr($rmmigrate_val); ?>" <?php selected($rmmigrate_settings['email_restore_mode'] ?? 'failure', $rmmigrate_val); ?>><?php echo esc_html($rmmigrate_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="email_import_mode"><?php esc_html_e('Import events', 'rosenheinrich-multisite-migrate'); ?></label></th>
            <td>
                <select name="email_import_mode" id="email_import_mode">
                    <?php foreach ($rmmigrate_modes as $rmmigrate_val => $rmmigrate_label) : ?>
                        <option value="<?php echo esc_attr($rmmigrate_val); ?>" <?php selected($rmmigrate_settings['email_import_mode'] ?? 'failure', $rmmigrate_val); ?>><?php echo esc_html($rmmigrate_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
    </table>

    <div class="mm-notifications-panel__test">
        <button type="submit" class="button" form="rmmigrate-test-email">
            <?php esc_html_e('Send test email', 'rosenheinrich-multisite-migrate'); ?>
        </button>
        <p class="description"><?php esc_html_e('Uses WordPress wp_mail. Local hosts without SMTP often fail — install an SMTP plugin for delivery.', 'rosenheinrich-multisite-migrate'); ?></p>
    </div>
</div>
