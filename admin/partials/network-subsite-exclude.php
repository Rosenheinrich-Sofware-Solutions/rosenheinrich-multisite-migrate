<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_site_limit = Rmmigrate_Multisite_Scope::subsite_picker_limit();
$rmmigrate_total_sites = (int) get_sites(array('count' => true));
$rmmigrate_sites = get_sites(array('number' => $rmmigrate_site_limit));
$rmmigrate_sites_truncated = $rmmigrate_total_sites > $rmmigrate_site_limit;
$rmmigrate_selection_mode = $rmmigrate_selection_mode ?? 'exclude';
$rmmigrate_selected_blogs = array_map('intval', (array) ($rmmigrate_selected_blogs ?? ($rmmigrate_excluded_blogs ?? array())));
$rmmigrate_field_name = $rmmigrate_selection_mode === 'include' ? 'included_blogs[]' : 'excluded_blogs[]';
$rmmigrate_legend = $rmmigrate_selection_mode === 'include'
    ? __('Include subsites', 'rosenheinrich-multisite-migrate')
    : __('Exclude subsites', 'rosenheinrich-multisite-migrate');
$rmmigrate_list_ids = array();
foreach ($rmmigrate_sites as $rmmigrate_site) {
    if (is_object($rmmigrate_site) && isset($rmmigrate_site->blog_id)) {
        $rmmigrate_list_ids[] = (int) $rmmigrate_site->blog_id;
    }
}
$rmmigrate_extra_ids = array_values(array_diff($rmmigrate_selected_blogs, $rmmigrate_list_ids));
$rmmigrate_extra_sites = Rmmigrate_Multisite_Scope::network_site_labels($rmmigrate_extra_ids);
?>
<fieldset class="mm-subsite-list" data-mm-selection-mode="<?php echo esc_attr($rmmigrate_selection_mode); ?>">
    <legend><?php echo esc_html($rmmigrate_legend); ?></legend>
    <?php if ($rmmigrate_sites_truncated) : ?>
        <p class="mm-subsite-search">
            <label class="screen-reader-text" for="mm-subsite-search-<?php echo esc_attr($rmmigrate_selection_mode); ?>">
                <?php esc_html_e('Search subsites', 'rosenheinrich-multisite-migrate'); ?>
            </label>
            <input
                type="search"
                id="mm-subsite-search-<?php echo esc_attr($rmmigrate_selection_mode); ?>"
                class="regular-text mm-subsite-search-input"
                placeholder="<?php esc_attr_e('Search subsites by name or URL…', 'rosenheinrich-multisite-migrate'); ?>"
                autocomplete="off"
            >
        </p>
        <div class="mm-subsite-search-results mm-hidden" aria-live="polite"></div>
    <?php endif; ?>
    <?php if ($rmmigrate_extra_sites !== array()) : ?>
        <div class="mm-subsite-extra">
            <?php foreach ($rmmigrate_extra_sites as $rmmigrate_extra) : ?>
                <label class="mm-subsite-option">
                    <input type="checkbox" name="<?php echo esc_attr($rmmigrate_field_name); ?>" value="<?php echo esc_attr((string) $rmmigrate_extra['blog_id']); ?>" <?php checked(true); ?>>
                    <span class="mm-subsite-option__text"><?php echo esc_html((string) $rmmigrate_extra['label']); ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
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
    <?php if ($rmmigrate_sites_truncated) : ?>
        <p class="description"><?php
            printf(
                /* translators: 1: site list limit, 2: total subsite count */
                esc_html__('Showing the first %1$d of %2$d subsites. Search above to find and select others.', 'rosenheinrich-multisite-migrate'),
                (int) $rmmigrate_site_limit,
                (int) $rmmigrate_total_sites
            );
        ?></p>
    <?php endif; ?>
</fieldset>
