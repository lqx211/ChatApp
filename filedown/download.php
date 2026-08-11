<?php

	// ChatApp global maintenance gate + admin-only access for this legacy module
	require_once __DIR__ . '/../api/config.php'; // config.php includes maintenance.php

	// Legacy download manager: require an admin session.
	chatapp_session_start();
	$fdUid = 0;
	$fdStmt = db()->prepare('SELECT user_id FROM users WHERE username = ?');
	$fdStmt->execute([$_SESSION['username'] ?? '']);
	$fdUid = (int)($fdStmt->fetchColumn() ?: 0);
	$fdRole = chatapp_get_role($fdUid);
	if ($fdRole !== 'root' && $fdRole !== 'admin') {
		http_response_code(403);
		exit;
	}

	@include("./indexfiles/config.php");
	@include($langdir.$lang_file);
	@include($incdir."functions.php");
	
	
	if (!empty($LOG_TRAFFIC) && function_exists('store_file_acc_info')) {
		@store_file_acc_info(gethostbyaddr($_SERVER['REMOTE_ADDR']), date("Y年n月d日 H:i:s"), $_GET['fname'] ?? '');
	}

	$fn = (string)($_GET['fname'] ?? '');
	$fn = stripslashes($fn);
	$fn = str_replace('*', '&', $fn);

	// Confine reads to the filedown directory (realpath containment).
	$baseDir = realpath(__DIR__);
	if ($baseDir === false) { http_response_code(404); exit; }
	$rel = ltrim($fn, '/');
	if ($rel === '' || strpos($rel, '..') !== false) { echo "$msg_not_allowed"; exit; }
	$candidate = $baseDir . '/' . $rel;
	$realFn = realpath($candidate);
	if ($realFn === false || strpos($realFn . '/', $baseDir . '/') !== 0 || !is_file($realFn)) {
		http_response_code(404);
		exit;
	}

	// Extension blocklist (case-insensitive).
	$ext = strtolower(pathinfo($realFn, PATHINFO_EXTENSION));
	$blk = is_array($not_to_be_dloaded) ? array_map('strtolower', $not_to_be_dloaded) : [];
	if (in_array($ext, $blk, true)) { echo "$msg_not_allowed"; exit; }

	$fn = $realFn;

	header("Content-type: application/octet-stream");
	// Strip CR/LF / control chars from the attachment filename (header injection).
	$filename = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', basename($fn));
	header("Content-Disposition: attachment; filename=" . $filename);

	readfile($fn);
?>
