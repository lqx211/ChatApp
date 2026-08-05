<?php

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
<title><?php echo $msg_log_title; ?></title>

<meta http-equiv="content-type" content="text/html;charset=UTF-8">
<meta http-equiv="refresh" content="1800">
<meta content="manxstar" name="copyright">
<meta content="ALL,INDEX,FOLLOW" name="robots">
<meta content="document" name="resource-type">
<link href="indexfiles/style.css" type="text/css" rel="styleSheet">
</head>


<body class="THEBODY">

<?php

	
	$varget = $_GET;

	
	if (!isset($varget["from"])) {
		
		$varget["from"] = 0;
	}
	
	
	if (!isset($varget["to"])) {
		
		$varget["to"] = $pas;
	}
	
	
	$from_val = $varget["from"];
	
	$to_val = $varget["to"];


	//open the log file for reading / writing
	$fp = fopen($logdir.$log_file, "r+b");

	
	$nr_inreg_arr = fscanf($fp, "%d\n");
	
	$nr_inreg = $nr_inreg_arr[0];

?>

<!-- START MAIN TABLE -->
<table cellspacing="0" align="center" cellpadding="0">

	<tr><td align="left" colspan="2"><?php echo "<span class=\"small_text\">$msg_log_header</span>"; ?></td></tr>

	<tr><td align="center" colspan="2" height="10"></td></tr>

	<tr><td align="left">
	<?php
		
		$pag_curenta = ceil($to_val/$pas);
		echo "<span class=\"small_text\">$msg_log_page ";

		
		$nr_total_pag = ((($nr_inreg/$pas) - (int)($nr_inreg/$pas)) != 0) ? (int)($nr_inreg/$pas) + 1 : (int)($nr_inreg/$pas);

		
		for ($np = 1; $np <= $nr_total_pag; $np++) {
			if ($np == $pag_curenta) {
				echo $np." ";
			} else {
				echo "<a href=\"log.php?from=".(($np-1)*$pas + 1)."&to=".min($np*$pas, $nr_inreg)."\"><span class=\"page_nr_text\"><b>".$np."</b></span></a> ";
			}
		}
		echo "</span>";
		
	?>
	</td><td align="right"><?php echo "<span class=\"small_text\">$msg_log_total <b>$nr_inreg</b> $msg_log_entry</span>"; ?></td></tr>

	<tr><td valign="top" colspan="2">
<!-- START LOG ENTRIES TABLE -->
<table class="main_table" cellspacing="2" align="center" cellpadding="0">
	<tr>
		<td width="235" align="center" class="tab_header_cell"><?php echo $msg_dns; ?></td>
		<td width="170" align="center" class="tab_header_cell"><?php echo $msg_date; ?></td>
		<td width="305" align="center" class="tab_header_cell"><?php echo $msg_acc_file; ?></td>
	</tr>
<?php
	
	
	$to_val = min($nr_inreg, $to_val);

	
	$j = 0;
	
	
	for ($i = $from_val; $i <= $to_val; $i++) {
		
		$j++;
		
		$poz = $i * (1 + $dns_len + $data_len + $acc_file_len);
		fseek($fp, -$poz, SEEK_END);
		
		$dns = fread($fp, $dns_len);
		$data = fread($fp, $data_len);
		$acc_file = fread($fp, $acc_file_len);
		
		$dns = trim($dns);
		$data = trim($data);
		$acc_file = trim($acc_file);
		echo "<tr bgcolor='".$color[$j%2]."'>";
		echo "<td>$dns</td><td align='center'>$data</td><td align='right'>$acc_file</td>";
		?>
		</tr>
		<?php
	}
	
	fclose($fp);
?>
</table>
<!-- START LOG ENTRIES TABLE -->
	</td></tr>

	<tr><td align="center" colspan="2">
			<table cellspacing="0" align="center" cellpadding="0" width="100%">

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
			</b></span><span class="small_text">秒.</span></td>
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
<!-- STOP MAIN TABLE -->

</body>
</html>
