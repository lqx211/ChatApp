<?php
//获取源代码
$a = $_GET['url'];   
$lines = file(''.$a.'');
foreach ($lines as $line_num => $line) {
echo $line;
}
?>