<?php
/**
 * ChatApp - Status
 * Change the values below to enable maintenance mode, or to customize the maintenance page and login behavior.
 */


return [
    'is_maintenance'         => false,
    'mt_return_code'          => 503,
    'maintenance_page'        => '/errors/unavailable_offline.html', # breakdb erepair offline upgrade limit spam(!2)
    'allow_mt_login' => false,
    'mt_login_use_mysql_creds' => false,
];

