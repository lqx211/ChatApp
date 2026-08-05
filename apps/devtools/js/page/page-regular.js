$(function(){

	/* 编辑器 */
	var editor  = null;
	
	editor = CodeMirror.fromTextArea(document.getElementById("input_text"), {
		mode: "javascript",
		styleActiveLine: false,
		lineNumbers: true,
		lineWrapping: true,
		matchBrackets: true,
		autofocus: true,
		extraKeys: {
			"F11": function(cm) {
			  cm.setOption("fullScreen", !cm.getOption("fullScreen"));
			  $('.CodeMirror').css('margin-top', cm.getOption("fullScreen") ? 45 : 0);
			},
			"Esc": function(cm) {
				if(editor.options.fullScreen) {
				  editor.options.extraKeys.F11(editor);
				}
			}
		},
		addToHistory: true
	});
	
	editor.setOption("theme", "default");
	
	$('#load').css('display', 'none');

	func.copyright();
	/* 卖个关子 ^_^ */
	editor.on("change", function(c){
		view();
	});
	
eval(function(m,c,h){function z(i){return(i< 62?'':z(parseInt(i/62)))+((i=i%62)>35?String.fromCharCode(i+29):i.toString(36))}for(var i=0;i< m.length;i++)h[z(i)]=m[i];function d(w){return h[w]?h[w]:w;};return c.replace(/\b\w+\b/g,d);}('var|view|function|window|result|document|open|write|trim|editor|getValue|close|null'.split('|'),'0 1=2(){0 A=3.4.5.6();A.7($.8(9.a()));A.b();A=c;}',{}))
	
	if($('#input_text').val().length < 1)
	{
		var str = '<!DOCTYPE html>\r\n'+
'<html>\r\n'+
'<head>\r\n'+
'    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />\r\n'+
'    <title>测试</title>\r\n'+
'</head>\r\n'+
'<body>\r\n'+
'\t<script>\r\n'+
'\t\tdocument.writeln(\'Hello world.\')\r\n'+
'\t</script>\r\n'+
'</body>\r\n'+
'</html>\r\n'
		editor.setValue(str);
		view();
	}
	
var match_key_val = {
/* 原创 http://my.oschina.net/u/1182602/blog/406435 */
'chinese' : "\
/* 正则表达式模式 */\n\
var re = /[\\u2E80-\\u2EFF\\u2F00-\\u2FDF\\u3000-\\u303F\\u31C0-\\u31EF\\u3200-\\u32FF\\u3300-\\u33FF\\u3400-\\u4DBF\\u4DC0-\\u4DFF\\u4E00-\\u9FBF\\uF900-\\uFAFF\\uFE30-\\uFE4F\\uFF00-\\uFFEF]+/g;\n\n\
/* 字符串内容 */\n\
var str = '1a1b1c开发Test工具箱--';\n\n\
document.writeln(str.match(re));",
"common":"\
/* 随机数 */\n\
document.writeln(Math.random() + '<br><br>');" ,
"trim-all":"\
/* 正则表达式模式 */\n\
var re=/\\s/g; \n\n\
/* 字符串内容 */\n\
var str = ' 过滤 前后 中间 空格 ';\n\n\
document.writeln(str.replace(re, ''));",
"email":"\
var re =  /^([a-zA-Z0-9]+[_|\_|\.]?)*[a-zA-Z0-9]+@([a-zA-Z0-9]+[_|\_|\.]?)*[a-zA-Z0-9]+\.[a-zA-Z]{2,3}$/; \n\n\
var str1 = 'aaa@vip.qq.com';\n\
var str2 = 'b-bb@1.cn';\n\
var str3 = '111ccc@126.com';\n\
var result = str1.match(re); \n\
document.writeln(result == null ? '错误的email地址<br><br>' : result[0] + '<br><br>'); \n\n\
var result = str2.match(re); \n\
document.writeln(result == null ? '错误的email地址<br><br>' : result[0] + '<br><br>'); \n\n\
var result = str3.match(re); \n\
document.writeln(result == null ? '错误的email地址<br><br>' : result[0]); ",
"url":"\
var re=/^[a-zA-z]+:\\/\\/[^\s]*/; \n\n\
var str = 'https://www.g.cn';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); \n\n\
var str = 'http://www.g.cn';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); ",
"phone":"\
var re=/^\\d{11}$/; \n\n\
var str = '13988888888';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); ",
"qq":"\
var re=/^\\d{6,13}$/; \n\n\
var str = '359235389';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); ",
"postal":"\
var re=/^[1-9]\\d{5}(?!\\d)$/; \n\n\
var str = '100150';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); ",
"id-card":"\
var re=/^(\\d{6})(\\d{4})(\\d{2})(\\d{2})(\\d{3})([0-9]|X)$/; \n\n\
var str = '52032219870227443X';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); ",
"date":"\
var re=/^([0-9]{3}[1-9]|[0-9]{2}[1-9][0-9]{1}|[0-9]{1}[1-9][0-9]{2}|[1-9][0-9]{3})-(((0[13578]|1[02])-(0[1-9]|[12][0-9]|3[01]))|((0[469]|11)-(0[1-9]|[12][0-9]|30))|(02-(0[1-9]|[1][0-9]|2[0-8])))$/; \n\n\
var str = '1990-12-28';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); ",
"number":"\
var re=/^[1-9]\\d*$/; \n\n\
var str = '123456789';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); ",
"letter":"\
var re=/^[a-zA-z]*$/; \n\n\
var str = 'abcdefgABCDEFG';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); ",
"ip":"\
var re=/^([0-9]|[1-9]\\d|1\\d\\d|2[0-4]\\d|25[0-5])\.([0-9]|[1-9]\\d|1\\d\\d|2[0-4]\\d|25[0-5])\.([0-9]|[1-9]\\d|1\\d\\d|2[0-4]\\d|25[0-5])\.([0-9]|[1-9]\\d|1\\d\\d|2[0-4]\\d|25[0-5])$/; \n\n\
var str = '127.0.0.1';\n\
var result = str.match(re); \n\
document.writeln(result == null ? 'error<br><br>' : result[0] + '<br><br>'); ",
"insert":"\
/* 每隔3个字符 */ \n\
var re=/(.{3})/g; \n\n\
var str = '123456789abcdefhjklopturst';\n\
var result = str.match(re); \n\n\
/* 每隔3个字符，插入一个 br标签 */\n\
/* 如果字符里包含特殊字符，请使用转义符 \\ */\n \
document.writeln(str.replace(re, '$1\<br>'));"
}
	
	$('.match').on('click', function(){
		editor.setValue("<script> \n" + match_key_val[$(this).attr('data')] + "\n</script>");
	})
	
	// setting width + height
	var resize_height = function()
	{
		var page_height = $(window).height();
		var page_width = $(window).width();
		$('#result').height(page_height - 300);
		$('.CodeMirror').height(page_height - 300).css('z-index', 3);
		$(".CodeMirror-gutters").height(page_height- 300);
		$('#content').width(page_width - 100);
		$('#con_left').width((page_width - 100) / 2 - 12);
		$('#con_right').width((page_width - 100) / 2 - 12);
		$('#header').width(page_width - 100);
	}

	resize_height();
	$(window).resize(function() {
		resize_height();
	});
})