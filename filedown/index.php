<?php

# 精灵下载 1.3.2

	// ChatApp global maintenance gate
	require_once __DIR__ . '/../maintenance.php';

	@include("./indexfiles/config.php");
	@include($langdir.$lang_file);
	@include($incdir."functions.php");
	@include($incdir."variables.php");

	
	$time_start = getmicrotime();

?>

<html>
<head>
<title><?php echo $msg_index_title; ?></title>
<meta http-equiv="content-type" content="text/html;charset=UTF-8">
<meta http-equiv="refresh" content="1800">
<meta content="document" name="resource-type">
<link href="indexfiles/style.css" type="text/css" rel="styleSheet">


<!-- START FADED ICONS SCRIPT -->
<!-- This script DOES NOT belong to me -->
<!-- The script is taken from http://www.inflames.com -->
<script language="JavaScript1.2">

	function fixUglyIE() {
		for (a in document.links) document.links[a].onfocus = document.links[a].blur;
	}

	if (document.all) {
		document.onmousedown = fixUglyIE;
	}

	function high(which2) {
		theobject = which2;
		highlighting = setInterval("highlightit(theobject)", 50);
	}

	function low(which2) {
		clearInterval(highlighting);
		which2.opacity = 40;
	}

	function highlightit(cur2) {
		if (cur2.opacity < 100) {
			cur2.opacity += 10;
		} else {
			if (window.highlighting) {
				clearInterval(highlighting);
			}
		}
	}

	function MM_displayStatusMsg(msgStr) { //v1.0
	  status = msgStr;
	  document.MM_returnValue = true;
	}

</script>
<!-- STOP FADED ICONS SCRIPT -->

</head>
<body class="THEBODY" onload="MM_displayStatusMsg('<?php echo $msg_status; ?>')">

<!-- START CODE FILE VIEWER -->
<?php

	
	$varget = $_GET;

	
	if (isset($varget["dirpath"]) && !empty($varget["dirpath"])) {
		
		$dirpath = $varget["dirpath"];
		$dirpath = str_replace('*', '&', $dirpath);
	} else {
		
		$dirpath = $defdirpath;
	}

	
	if (!isset($varget["order"])) {
		$varget["order"] = 0;
	}

	
	if (strpos($dirpath, "..") === false) {
	} else {
		
		$dirpath = $defdirpath;
	}

	
	if (strpos($dirpath, "./") === false || strpos($dirpath, "./") != 0) {
		
		$dirpath = $defdirpath;
	}

	
	$jsdir = $dirpath;
	
	$dirpath = stripslashes($dirpath);

	
	if (!@chdir($dirpath)) {
		
		$dirpath = $defdirpath;
		@chdir($dirpath);
	}

	
	if ($dir = @opendir(".")) {
		
		while (($entry = readdir($dir)) !== false) {
			
			$split_name_ext = explode(".", $entry);
			
			$extensie = (count($split_name_ext)-1 != 0) ? $split_name_ext[count($split_name_ext)-1] : "";
			
			$lextensie = strtolower($extensie);
			
			
			if (!in_array($lextensie, $ext_not_to_be_shown)) {
				if (!in_array($entry, $not_to_be_shown)) {
					if (!is_dir($entry)) {
						$fisiere[] = $entry;
					} else {
						$directoare[] = $entry;
					}
				}
			}
		}
		
		closedir($dir);
	}

?>

<!-- START OF THE MAIN TABLE-->
<table cellspacing="0" align="center" cellpadding="0">

	<?php
		
		if ($SHOW_CURRENT_DIRECTORY) {
	?>
	<tr><td>
		<table cellspacing="0" align="center" cellpadding="0" width="100%">
			<!-- START PRINTING CURRENT DIRECTORY NAME -->
			<tr><td align="left"><span class="small_text"><?php echo $msg_cwd; ?></span><span class="small_text"><?php echo "<b>/".substr($dirpath, 2, (strlen($dirpath) - 2))."</b>"; ?></span></td></tr>
			<!-- STOP PRINTING CURRENT DIRECTORY NAME -->
		</table></td></tr>
	<?php
		}
	?>

	<tr><td height="10"></td></tr>

	<tr><td valign="top">


<!-- START ORDERING BY NAME -->
<?php

	
	natcasesort($directoare);
	natcasesort($fisiere);

	
	switch ($varget["order"]) {

		
		case 0:
			$imgsrc = $imgdir."arr_up.gif";			
			break;

		
		case 1:
			
			$fisiere = array_reverse($fisiere);
			$imgsrc = $imgdir."arr_down.gif";
			break;

	}
	
	$sageata = "<img src=\"$imgsrc\">";
?>
<!-- STOP ORDERING BY NAME -->


<!-- START PRINTING THE DIRECTORY'S CONTENTS -->
<table class="main_table" cellspacing="2" align="center" cellpadding="0">
	<?php
		if ($SHOW_TAB_HEADER_ROW) {
	?>
	<tr>
		<?php if ($SHOW_ICON) {?>
			<td width="30" align="center" class="tab_header_cell"><?php echo $msg_ico; ?></td>
		<?php } ?>

		<td width="200" align="center" class="tab_header_cell" <?php echo "onclick=\"window.location='index.php?dirpath=$jsdir&order=".(1 - $varget['order'])."'\"";?>>
		<?php echo $msg_name; ?> <?php echo $sageata; ?></td>

		<td width="40" align="center" class="tab_header_cell"><?php echo $msg_ext; ?></td>

		<?php if ($SHOW_SIZE) {?>
			<td width="80" align="center" class="tab_header_cell"><?php echo $msg_size; ?></td>
		<?php } ?>

		<?php if ($SHOW_MODIFIED) {?>
			<td width="110" align="center" class="tab_header_cell"><?php echo $msg_modified; ?></td>
		<?php } ?>

		<?php if ($SHOW_DESCRIPTION) {?>
			<td width="150" align="center" class="tab_header_cell"><?php echo $msg_description; ?></td>
		<?php } ?>
	</tr>
	<?php
		} else {
	?>
	<tr>
		<?php if ($SHOW_ICON) {?>
			<td width="30"></td>
		<?php } ?>

		<td width="200"></td>
		<td width="40"></td>

		<?php if ($SHOW_SIZE) {?>
			<td width="80"></td>
		<?php } ?>

		<?php if ($SHOW_MODIFIED) {?>
			<td width="110"></td>
		<?php } ?>

		<?php if ($SHOW_DESCRIPTION) {?>
			<td width="150"></td></tr>
		<?php } ?>
	<?php
		}
	?>
<?php
	
	
	$j = -1;

	
	foreach($directoare as $key => $director) {

		
		if (!strcmp($director, "..") && !strcmp($dirpath, ".")) {
			continue;
		}

		
		$j++;

		
		$dta = stat($director);

		
		
		if (strcmp($director, "..")) {
			echo "<tr bgcolor='".$color[$j%2]."' height='20'>";
			if ($SHOW_ICON) {
				echo "<td align=\"center\"><img src=\"".$imgdir."dir.gif\"></td>";
			}
			echo "<td align=\"left\"><a href=\"index.php?dirpath=$dirpath/".str_replace('&', '*', $director)."&order=".$varget['order']."\"><span class=\"dir_text\">[".$director."]</span></a>";
			if (((time() - $dta[9]) / 1E+5) < $new_period) {
				echo " <span class=\"small_red_text\">$msg_new</span>";
			}
			echo "</td>";
			echo "<td align=\"right\"></td>";
			if ($SHOW_SIZE) {
				echo "<td align=\"right\"><span class=\"text\">&lt;DIR&gt;</span></td>";
			}
			if ($SHOW_MODIFIED) {
				echo "<td align=\"right\"><span class=\"text\">".date("Y-M-D H:i", $dta[9])."</td>";
			}
			if ($SHOW_DESCRIPTION) {
				echo "<td align=\"right\"></td>";
			}
			echo "</tr>";
		} else {
			echo "<tr bgcolor='".$color[$j%2]."' height='20'>";
			if ($SHOW_ICON) {
				echo "<td align=\"center\"><img src=\"".$imgdir."back.gif\"></td>";
			}
			echo "<td align=\"left\"><a href=\"index.php?dirpath=".dirname($dirpath)."&order=".$varget['order']."\"><span class=\"dir_text\">[".$director."]</span></a></td>";
			echo "<td align=\"right\"></td>";
			if ($SHOW_SIZE) {
				echo "<td align=\"right\"><span class=\"text\">&lt;DIR&gt;</span></td>";
			}
			if ($SHOW_MODIFIED) {
				echo "<td align=\"right\"><span class=\"text\">".date("Y-M-D H:i", $dta[9])."</td>";
			}
			if ($SHOW_DESCRIPTION) {
				echo "<td align=\"right\"></td>";
			}
			echo "</tr>";
		}
		
		clearstatcache();
	}

	
	
	foreach($fisiere as $key => $file) {
		
		$j++;

		
		$dta = stat($file);

		
		$split_name_ext = explode(".", $file);

		
		$extensie = (count($split_name_ext)-1 != 0) ? $split_name_ext[count($split_name_ext)-1] : "";

		
		$lextensie = strtolower($extensie);

		
		
		if (array_key_exists($lextensie, $exts)) {
			$descriere = $exts[$lextensie][0];
			$iconita = $exts[$lextensie][1];
		} else {
			
			$descriere = "";
			$iconita = "unknown.gif";
		}

		echo "<tr bgcolor='".$color[$j%2]."' height='20'>";
		
		if ($SHOW_ICON) {
			echo "<td align=\"center\"><img src=\"".$imgdir."$iconita\"></td>";
		}

		
		if (in_array($lextensie, $not_to_be_dloaded)) {
			
			echo "<td align=\"left\"><a href=\"$dirpath/$file\"><span class=\"text\">".$split_name_ext[0];
		} else {
			
			echo "<td align=\"left\"><a href=\"download.php?fname=".str_replace('&', '*', $dirpath."/".$file)."\" onmouseover=\"MM_displayStatusMsg('')\" onmouseout=\"MM_displayStatusMsg('$msg_status')\"><span class=\"text\">".$split_name_ext[0];
		}
		for($i = 1; $i < count($split_name_ext) - 1; $i++) {
			echo (".$split_name_ext[$i]");
		}
		echo "</span></a>";
		
		if ( ((time() - $dta[9]) / 1E+5) < $new_period) {
			echo " <span class=\"small_red_text\">$msg_new</span> ";
		}
		echo "</td>";
		
		
		echo "<td align=\"right\"><span class=\"text\">";
		echo $extensie;
		echo "</td>";

		
		if ($SHOW_SIZE) {
			echo "<td align=\"right\"><span class=\"text\">";
			printf("<span class=\"text\">%.2f KB</span>", $dta[7]/1024);
			echo "</span></td>";
		}
		
		
		if ($SHOW_MODIFIED) {
			echo "<td align=\"right\"><span class=\"text\">".date("Y-M-D H:i", $dta[9])."</td>";
		}

		
		if ($SHOW_DESCRIPTION) {
			echo "<td align=\"right\"><span class=\"text\">".$descriere."</span></td>";
		}

		echo "</tr>";

		
		clearstatcache();
	}
?>

</table>
<!-- STOP PRINTING THE DIRECTORY'S CONTENTS -->


		</td>
		<td width="3"></td>
		
		<!-- START TOOLBAR -->
		<td valign="top" align="center">
			<table cellspacing="3" cellpadding="0" border="0" class="main_table">
				<?php
					if ($LOG_TRAFFIC) {
				?>
				<tr><td align="center"><img src="<?php echo $imgdir; ?>log.gif" width="16" height="16" border="0" onmouseover="high(this);MM_displayStatusMsg('<?php echo $msg_log; ?>')" style="opacity=40" onmouseout="low(this);MM_displayStatusMsg('<?php echo $msg_status; ?>')" onclick='window.parent.$.artDialog.open("lightapp/filedown/log.php",{title:"日志",ico: window.parent.core.icon("log"),width:"745px",height:"350px"});'></td></tr>
				<?php
					}
				?>
				
				<tr><td align="center"><img src="<?php echo $imgdir; ?>info.gif" width="16" height="16" border="0" onmouseover="high(this);MM_displayStatusMsg('<?php echo $msg_info; ?>')" style="opacity=40" onmouseout="low(this);MM_displayStatusMsg('<?php echo $msg_status; ?>')" onclick="window.open('<?php echo $msg_home_url; ?>')"></td></tr>
				<?php
					if ($PRINT_PAGE) {
				?>
				<tr><td align="center"><img src="<?php echo $imgdir; ?>print.gif" width="16" height="16" border="0" onmouseover="high(this);MM_displayStatusMsg('<?php echo $msg_print; ?>')" style="opacity=40" onmouseout="low(this);MM_displayStatusMsg('<?php echo $msg_status; ?>')" onclick="window.print()"></td></tr>
				<?php
					}
				?>
			</table>
		</td>
		<!-- STOP TOOLBAR -->

	</tr>
	
	<tr><td height="10"></td></tr>

	<tr><td align="center">
		<table cellspacing="0" align="center" cellpadding="0" width="100%"><tr>
			<?php
				
				if ($SHOW_EXEC_TIME) {
			?>
			<!-- START PRINTING EXEC TIME -->
			<td align="left"><span class="small_text"><?php echo $msg_exec_time; ?>: </span><span class="small_text"><b>
			<?php
				$time_end = getmicrotime();
				$time = $time_end - $time_start;
				printf("%.4f", $time);
			?>
			</b></span><span class="small_text">秒</span></td>
			<!-- STOP PRINTING EXEC TIME -->
			<?php
				}
				
				if ($SHOW_SCRIPT_NAME) {
			?>
			<td align="right"><span class="small_text"><?php echo getScriptName(). " ".getScriptVersion(); ?></span></td></tr>
			<?php
				}
			?>
		</table></td>
	</tr>

</table>
<!-- STOP OF THE MAIN TABLE-->
<!-- STOP CODE FILE VIEWER -->

</body>
</html>
