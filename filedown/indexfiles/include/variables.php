<?php

# 希望您能保留版权 1.3.2
	// an array to store the 'file type' entries
	$fisiere = array();

	// an array to store the 'directory type' entries
	$directoare = array();

	// the default directory is the base directory of the script
	$defdirpath = ".";

	// the array where the icon and description for each extension are defined
	$exts = array(
				"php" => array("$msg_webdoc", "docweb.gif"),
				"htm" => array("$msg_webdoc", "docweb.gif"),
				"html" => array("$msg_webdoc", "docweb.gif"),
				"shtml" => array("$msg_webdoc", "docweb.gif"),
				"dhtml" => array("$msg_webdoc", "docweb.gif"),
				"ace" => array("$msg_ace", "archive.gif"),
				"gz" => array("$msg_gz", "archive.gif"),
				"zip" => array("$msg_zip", "archive.gif"),
				"rar" => array("$msg_rar", "archive.gif"),
				"doc" => array("$msg_doc", "doc.gif"),
				"xls" => array("$msg_xls", "doc.gif"),
				"ppt" => array("$msg_ppt", "doc.gif"),
				"pps" => array("$msg_pps", "doc.gif"),
				"rtf" => array("$msg_rtf", "doc.gif"),
				"txt" => array("$msg_txt", "text.gif"),
				"pdf" => array("$msg_pdf", "pdf.gif"),
				"c" => array("$msg_c", "text.gif"),
				"cpp" => array("$msg_cpp", "text.gif"),
				"java" => array("$msg_java", "text.gif"),
				"class" => array("$msg_class", "class.gif"),
				"exe" => array("$msg_exe", "exe.gif"),
				"mp3" => array("$msg_audio", "media.gif"),
				"wma" => array("$msg_audio", "media.gif"),
				"wav" => array("$msg_audio", "media.gif"),
				"avi" => array("$msg_audio", "media.gif"),
				"mpg" => array("$msg_audio", "media.gif"),
				"chm" => array("$msg_chm", "unknown.gif"),
				"hlp" => array("$msg_hlp", "unknown.gif"),
				"css" => array("$msg_css", "unknown.gif"),
				"js" => array("$msg_js", "text.gif"),
				"gif" => array("$msg_gif", "img.gif"),
				"jpg" => array("$msg_jpg", "img.gif"),
				"png" => array("$msg_png", "img.gif"),
				"bmp" => array("$msg_bmp", "img.gif"),
				"jpeg" => array("$msg_jpeg", "img.gif"),
				"swf" => array("$msg_swf", "media.gif"),
				"ttf" => array("$msg_ttf", "img.gif")
				);

	// the array of colors used to differentiate between rows of data
	$color = array( 1 => "#F9F9F9",
					0 => "#F5F5F5");

	$msg_home_url = "s";
?>