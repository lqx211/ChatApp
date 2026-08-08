<?php
/**
 * ChatApp - Status
 * Change the values below to enable maintenance mode, or to customize the maintenance page and login behavior.
 */


return [
    'is_maintenance'         => false,
    'mt_return_code'          => 503, # 429:TooManyRequests, 503:ServiceUnavailable, 500:InternalServerError, 401:Unauthorized, 403:Forbidden, 200:OK
    'maintenance_page'        => '/errors/unavailable_upgrade.html', # breakdb erepair offline upgrade limit spam(!2)
    'allow_mt_login' => false,
    'mt_login_use_mysql_creds' => false,
];

