<?php

	// ChatApp global maintenance gate
	require_once __DIR__ . '/../maintenance.php';

	@include("./indexfiles/config.php");
	@include($langdir.$lang_file);
	@include($incdir."functions.php");
	
	
	if ($LOG_TRAFFIC) {
		store_file_acc_info(gethostbyaddr($_SERVER['REMOTE_ADDR']), date("Y年n月d日 H:i:s"), $_GET['fname']);
	}

	$fn = $_GET['fname'];
	$fn = stripslashes($fn);
	$fn = str_replace('*', '&', $fn);

	
	header("Content-type: application/octet-stream");
	
	$filename = basename($fn);
	header("Content-Disposition: attachment; filename=".$filename);


	$extensie = strrchr($fn, ".");

	if (!in_array(substr($extensie, 1), $not_to_be_dloaded) && (strpos($fn, "..") === false)) {
		if (strpos($fn, "./") === false || strpos($fn, "./") != 0) {
			
			echo "$msg_not_allowed";
		} else {
			
			readfile($fn);
		}
	} else {
		
		echo "$msg_not_allowed";
	}
?>
