<?php
/**
 * ChatApp — Maintenance status (written by Maintenance Portal).
 * 手动改这里也行；门户会优先读 data/maintenance_status.php。
 */
return array (
  'is_maintenance' => false,
  'mt_return_code' => 500,
  'maintenance_page' => '/errors/unavailable_erepair.html',
  'allow_mt_login' => false,
  'mt_login_use_mysql_creds' => false,
  'override_mysql_maint_settings' => false,
);
