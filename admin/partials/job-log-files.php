<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inline log viewer when ?log= is present on the Activity page.
 *
 * @var bool $rmmigrate_is_network
 */
$rmmigrate_log_view_lines = Rmmigrate_Engine_Config::log_view_lines();
$rmmigrate_view_log = sanitize_file_name(Rmmigrate_Request_Input::get_text('log'));
$rmmigrate_log_offset = max(0, Rmmigrate_Request_Input::get_int('log_offset', 0));
$rmmigrate_log_chunk = array(
    'lines'           => '',
    'offset_from_end' => 0,
    'has_older'       => false,
    'has_newer'       => false,
    'older_file'      => '',
);
$rmmigrate_log_lines = 0;

if ($rmmigrate_view_log !== '' && Rmmigrate_Activity_Log::is_allowed_log_basename($rmmigrate_view_log)) {
    $rmmigrate_log_path = Rmmigrate_Activity_Log::logs_dir() . '/' . $rmmigrate_view_log;
    if (is_readable($rmmigrate_log_path)) {
        $rmmigrate_log_chunk = Rmmigrate_Activity_Log::read_file_chunk(
            $rmmigrate_log_path,
            $rmmigrate_log_view_lines,
            $rmmigrate_log_offset
        );
        $rmmigrate_log_lines = $rmmigrate_log_chunk['lines'] === ''
            ? 0
            : substr_count($rmmigrate_log_chunk['lines'], "\n") + 1;
    }
}

$rmmigrate_logs_base_url = Rmmigrate_Admin_Router::admin_url(
    'multisite-migrate-activity',
    array(),
    $rmmigrate_is_network
);

if ($rmmigrate_log_chunk['lines'] === '') {
    return;
}
?>
<div class="mm-form-section mm-log-viewer-panel">
    <div class="mm-log-viewer-wrap" data-log="<?php echo esc_attr($rmmigrate_view_log); ?>" data-offset="<?php echo esc_attr((string) $rmmigrate_log_offset); ?>" data-lines="<?php echo esc_attr((string) $rmmigrate_log_view_lines); ?>">
        <div class="mm-log-viewer-head">
            <h2><code><?php echo esc_html($rmmigrate_view_log); ?></code></h2>
            <a class="button button-link" href="<?php echo esc_url($rmmigrate_logs_base_url); ?>"><?php esc_html_e('Back to activity', 'rosenheinrich-multisite-migrate'); ?></a>
        </div>
        <div class="mm-log-viewer-toolbar">
            <?php if ($rmmigrate_log_chunk['has_newer']) : ?>
                <?php
                $rmmigrate_newer_offset = max(0, $rmmigrate_log_offset - $rmmigrate_log_view_lines);
                $rmmigrate_newer_url = add_query_arg(
                    array(
                        'log'        => $rmmigrate_view_log,
                        'log_offset' => $rmmigrate_newer_offset,
                    ),
                    $rmmigrate_logs_base_url
                );
                ?>
                <a class="button button-secondary mm-log-view-newer" href="<?php echo esc_url($rmmigrate_newer_url); ?>"><?php esc_html_e('Load newer', 'rosenheinrich-multisite-migrate'); ?></a>
            <?php endif; ?>
            <?php if ($rmmigrate_log_chunk['has_older']) : ?>
                <?php if ($rmmigrate_log_chunk['older_file'] !== '') : ?>
                    <?php
                    $rmmigrate_older_url = add_query_arg(
                        array(
                            'log'        => $rmmigrate_log_chunk['older_file'],
                            'log_offset' => 0,
                        ),
                        $rmmigrate_logs_base_url
                    );
                    ?>
                    <a class="button button-secondary mm-log-view-older-file" href="<?php echo esc_url($rmmigrate_older_url); ?>">
                        <?php
                        printf(
                            /* translators: %s: rotated log filename */
                            esc_html__('Continue in %s', 'rosenheinrich-multisite-migrate'),
                            esc_html($rmmigrate_log_chunk['older_file'])
                        );
                        ?>
                    </a>
                <?php else : ?>
                    <?php
                    $rmmigrate_older_url = add_query_arg(
                        array(
                            'log'        => $rmmigrate_view_log,
                            'log_offset' => $rmmigrate_log_offset + $rmmigrate_log_view_lines,
                        ),
                        $rmmigrate_logs_base_url
                    );
                    ?>
                    <a class="button button-secondary mm-log-view-older" href="<?php echo esc_url($rmmigrate_older_url); ?>"><?php esc_html_e('Load older', 'rosenheinrich-multisite-migrate'); ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <pre class="mm-log-viewer"><?php echo esc_html($rmmigrate_log_chunk['lines']); ?></pre>
        <p class="description">
            <?php
            printf(
                /* translators: %1$d: number of log lines shown. */
                esc_html__('Showing %1$d lines', 'rosenheinrich-multisite-migrate'),
                (int) $rmmigrate_log_lines
            );
            ?>
        </p>
    </div>
</div>
