<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_telemetry_consent = Rmmigrate_Telemetry::has_consent();
$rmmigrate_telemetry_disclosure = Rmmigrate_Telemetry::telemetry_disclosure();
$rmmigrate_telemetry_privacy_url = Rmmigrate_Capabilities::privacy_url();
?>
<div class="mm-form-section mm-privacy-panel">
    <h2><?php esc_html_e('Product telemetry', 'rosenheinrich-multisite-migrate'); ?></h2>
    <p class="description">
        <?php esc_html_e('Optionally share anonymous usage signals so we can fix pain points faster. No visitor tracking, no backup file contents.', 'rosenheinrich-multisite-migrate'); ?>
    </p>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e('Share usage signals', 'rosenheinrich-multisite-migrate'); ?></th>
            <td>
                <label for="mm-telemetry-consent">
                    <input
                        type="checkbox"
                        id="mm-telemetry-consent"
                        class="mm-settings-telemetry-optin"
                        value="1"
                        <?php checked($rmmigrate_telemetry_consent); ?>
                    />
                    <?php esc_html_e('Send anonymous plugin telemetry to Rosenheinrich', 'rosenheinrich-multisite-migrate'); ?>
                </label>
                <p class="description mm-settings-telemetry-status" id="mm-telemetry-consent-status" hidden></p>
                <p class="description">
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: privacy policy URL */
                            __('When enabled, events are sent to multisitemigrate.rosenheinrich.com. See our <a href="%s" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.', 'rosenheinrich-multisite-migrate'),
                            esc_url($rmmigrate_telemetry_privacy_url)
                        ),
                        array('a' => array('href' => true, 'target' => true, 'rel' => true))
                    );
                    ?>
                </p>
            </td>
        </tr>
    </table>

    <details class="mm-setup-disclosure">
        <summary><?php esc_html_e('What we may collect when you allow this', 'rosenheinrich-multisite-migrate'); ?></summary>
        <ul class="mm-setup-disclosure__list">
            <?php foreach ($rmmigrate_telemetry_disclosure as $rmmigrate_row) : ?>
                <li>
                    <strong><?php echo esc_html($rmmigrate_row['title']); ?></strong>
                    <span><?php echo esc_html($rmmigrate_row['detail']); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="description">
            <?php esc_html_e('We do not collect visitor analytics, database content, backup files, or your site URL in telemetry events (only a pseudonymous site hash).', 'rosenheinrich-multisite-migrate'); ?>
        </p>
    </details>
</div>
