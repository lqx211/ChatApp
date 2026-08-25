<?php
/**
 * ChatApp - Maintenance admin credentials
 * Hard-coded for local maintenance access only.
 * Change these values before deploying publicly!
 */

// Values may be overridden via environment variables (recommended for deployed
// servers). The fallbacks below are for local development only — change them
// before deploying publicly!
$MAINT_USER   = getenv('MAINT_USER') ?: 'admin';
$MAINT_PASS   = getenv('MAINT_PASS') ?: 'admin';
$MAINT_SECRET = getenv('MAINT_SECRET') ?: '8f3a9114514d4f6a0e5000c96aff9e4c';
