<?php

# 希望您能保留版权 1.3.2
	/******************************************
	** This function is used to determine the
	** script's execution time. It is copied
	** from the PHP help file and DOES NOT 
	** belong to me.
	******************************************/
	function getmicrotime()	{

		list($usec, $sec) = explode(" ", microtime());
		return ((float)$usec + (float)$sec);

	}


	/***********************************************
	** This function writes a log entry every time
	** a file has been downloaded. The arguments
	** are the data to be written, representing
	** the DNS of the client accessing the file,
	** date of the access and the accessed file.
	***********************************************/
	function store_file_acc_info($dns, $data, $acc_file) {

		// specify that the following variables
		// are declared outside the function
		global $logdir, $log_file, $dns_len, $data_len, $acc_file_len;
		
		//open the log file for reading / writing
		$fp = fopen($logdir.$log_file, "r+b");
		
		// read the first line ...
		$nr_inreg_arr = fscanf($fp, "%d\n");
		// ... as an integer representing the number of entries in the log
		$nr_inreg = $nr_inreg_arr[0];
		
		// increment the number of entries and ...
		$rez = sprintf("%08u\n", ($nr_inreg + 1));
		// ... write it back at the beginning of the log file
		fseek($fp, 0, SEEK_SET);
		fwrite($fp, $rez);
		
		// go to the end of the log file
		fseek($fp, 0, SEEK_END);
		
		// bring args to the desired length
		$dns = str_pad($dns, $dns_len);
		$data = str_pad($data, $data_len);
		$acc_file = str_pad($acc_file, $acc_file_len);
		
		// write each arg to the log file
		fwrite($fp, $dns, $dns_len);
		fwrite($fp, $data, $data_len);
		fwrite($fp, $acc_file, $acc_file_len);
		fwrite($fp, "\n");
		
		// close the log file
		fclose($fp);
	}


	/****************************************
	** This function returns the script name
	****************************************/
	function getScriptName() {
		return "精灵下载";
	}


	/*******************************************
	** This function returns the script version
	*******************************************/
	function getScriptVersion() {
		return "v1.3.2";
	}

?>
