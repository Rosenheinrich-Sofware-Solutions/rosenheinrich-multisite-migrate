<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_subsite_mode = true;
$rmmigrate_archives_embedded = false;
$rmmigrate_archives_tab = 'all';
$rmmigrate_filter = 'all';
$rmmigrate_args = array(
    'scope'   => Rmmigrate_Multisite_Scope::SCOPE_SUBSITE,
    'blog_id' => get_current_blog_id(),
);
$rmmigrate_backups_per_page = 25;
$rmmigrate_backups_total = Rmmigrate_Job::count_jobs($rmmigrate_args);
$rmmigrate_backups_total_pages = max(1, (int) ceil($rmmigrate_backups_total / $rmmigrate_backups_per_page));
$rmmigrate_backups_page = max(1, min(Rmmigrate_Request_Input::get_int('paged', 1), $rmmigrate_backups_total_pages));
$rmmigrate_args['limit'] = $rmmigrate_backups_per_page;
$rmmigrate_args['offset'] = ($rmmigrate_backups_page - 1) * $rmmigrate_backups_per_page;
$rmmigrate_pagination_base = add_query_arg(
    array(
        'page' => 'multisite-migrate-subsite-backups',
    ),
    admin_url('admin.php')
);
$rmmigrate_jobs = Rmmigrate_Job::list_jobs($rmmigrate_args);
$rmmigrate_highlight_job_id = $rmmigrate_highlight_job_id ?? Rmmigrate_Request_Input::get_int('job_id');
$rmmigrate_active = Rmmigrate_Job::resolve_admin_active_job(
    false,
    (int) $rmmigrate_highlight_job_id
);
if ($rmmigrate_highlight_job_id > 0) {
    $rmmigrate_jobs = Rmmigrate_Job::ensure_job_in_list($rmmigrate_jobs, $rmmigrate_highlight_job_id);
}

include RMMIGRATE_PATH . 'admin/partials/backups-list.php';
