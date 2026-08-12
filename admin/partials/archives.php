<?php
if (!defined('ABSPATH')) {

    exit;

}



$rmmigrate_archives_tab = $rmmigrate_archives_tab ?? Rmmigrate_Request_Input::get_key('tab', 'all');



if ($rmmigrate_archives_tab === 'failed') {

    $rmmigrate_filter = 'failed';

}



$rmmigrate_archives_embedded = true;

include RMMIGRATE_PATH . 'admin/partials/backups-list.php';
include RMMIGRATE_PATH . 'admin/partials/activity-detail-modal.php';

