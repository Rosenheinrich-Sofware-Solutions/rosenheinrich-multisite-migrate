<?php
if (!defined('ABSPATH')) {
    exit;
}

$rmmigrate_subsite_mode = true;
$rmmigrate_archives_embedded = false;
$rmmigrate_archives_tab = 'all';
$rmmigrate_filter = 'all';
$rmmigrate_args = array(
    'limit'   => 50,
    'scope'   => Rmmigrate_Multisite_Scope::SCOPE_SUBSITE,
    'blog_id' => get_current_blog_id(),
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
