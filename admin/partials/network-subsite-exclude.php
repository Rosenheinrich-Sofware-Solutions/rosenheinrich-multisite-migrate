<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_sites = get_sites(array('number' => 500));
$rmmigrate_selection_mode = $rmmigrate_selection_mode ?? 'exclude';
$rmmigrate_selected_blogs = array_map('intval', (array) ($rmmigrate_selected_blogs ?? ($rmmigrate_excluded_blogs ?? array())));
$rmmigrate_field_name = $rmmigrate_selection_mode === 'include' ? 'included_blogs[]' : 'excluded_blogs[]';
$rmmigrate_legend = $rmmigrate_selection_mode === 'include'
    ? __('Include subsites', 'rosenheinrich-multisite-migrate')
    : __('Exclude subsites', 'rosenheinrich-multisite-migrate');
?>
<fieldset class="mm-subsite-list">
    <legend><?php echo esc_html($rmmigrate_legend); ?></legend>
    <?php foreach ($rmmigrate_sites as $rmmigrate_site) : ?>
        <?php
        $rmmigrate_details = get_blog_details($rmmigrate_site->blog_id);
        if (!$rmmigrate_details) {
            continue;
        }
        ?>
        <label class="mm-subsite-option">
            <input type="checkbox" name="<?php echo esc_attr($rmmigrate_field_name); ?>" value="<?php echo esc_attr($rmmigrate_site->blog_id); ?>" <?php checked(in_array((int) $rmmigrate_site->blog_id, $rmmigrate_selected_blogs, true)); ?>>
            <span class="mm-subsite-option__text"><?php echo esc_html($rmmigrate_details->blogname . ' (' . $rmmigrate_details->domain . $rmmigrate_details->path . ')'); ?></span>
        </label>
    <?php endforeach; ?>
</fieldset>
