<?php

# 希望您能保留版权 1.3.2
	/*****************************************************
	** 使用true或者false来确定是否启用 
	*****************************************************/

	// 是否启用下载记录? (*** 详细情况参见 '$logdir/LOG_README'  ***)
	// 一旦关闭,下载记录按钮将从工具栏中移除
	$LOG_TRAFFIC = true;

	// 是否允许打印按钮? (打印按钮位于工具栏)
	$PRINT_PAGE = true;

	// 在主体表格上方是否显示路径?
	$SHOW_CURRENT_DIRECTORY = true;

	// 是否显示表格头部状态栏?
	$SHOW_TAB_HEADER_ROW = true;

	// 是否显示图标栏?
	$SHOW_ICON = true;

	// 是否显示文件大小?
	$SHOW_SIZE = true;

	// 是否显示修改日期?
	$SHOW_MODIFIED = true;

	// 是否显示描述栏?
	$SHOW_DESCRIPTION = true;

	// 是否在主表格底部显示脚本执行时间?
	$SHOW_EXEC_TIME = true;

	// 是否在主表格底部显示脚本名称?
	$SHOW_SCRIPT_NAME = true;


	/**********************************************************
	** More customizable features... Changing these variables
	** is NOT recommended except maybe for the '$lang_file'.
	**********************************************************/

	// 语言包目录
	$langdir = "./indexfiles/language/";

	// 默认语言文件 ( *** 可在"language"文件夹中查看已有的语言文件 ***)
	$lang_file = "cn.php";
	
	// 图片目录
	$imgdir = "./indexfiles/images/";

	// include目录
	$incdir = "./indexfiles/include/";

	// 日志目录
	$logdir = "./indexfiles/log/";

	// 日志文件 (*** 详细情况参见 '$logdir/LOG_README'  ***)
	$log_file = "log.txt";

	// 写入日志的DNS栏中的长度(字节)
	$dns_len = 75;

	// 写入日志的修改日期栏中的长度(字节)
	$data_len = 25;

	// 写入日志的下载文件栏中的长度(字节)
	$acc_file_len = 100;

	// 每页显示的文件数
	$pas = 50;

	// 几天内增加的文件认为是新的? (旁边将会有你设置的新添加文件标记'$msg_new')
	$new_period = 2;

	// 保护目录/文件,您可以自己添加
	$not_to_be_shown = array(".", "indexfiles", "index.php", "log.php", "download.php");
	// add here any extensions of files that you do not want to be listed
	$ext_not_to_be_shown = array();

	// 禁止下载的文件类型
	// !!! 注意,切勿移除php,不然本程序就能被下载 !!!
	$not_to_be_dloaded = array("htm", "html", "shtml", "dhtml", "php");

?>