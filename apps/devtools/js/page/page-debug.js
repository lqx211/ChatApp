$(function(){

	/* 编辑器 */
	var editor  = null;
	
	editor = CodeMirror.fromTextArea(document.getElementById("input_text"), {
		mode: "text/html",
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
/*
var view = function() {
    var A = window.result.document.open();
    A.write($.trim(editor.getValue()));
    A.close();
    A = null;
}
*/
	
	if($('#input_text').val().length < 1)
	{
		var str = '<!DOCTYPE html>\r\n'+
'<html>\r\n'+
'<head>\r\n'+
'    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />\r\n'+
'    <title>测试</title>\r\n'+
'</head>\r\n'+
'<body>\r\n'+
'这是一个HTML测试页面！\r\n'+
'    <div style="color:#C36;">设置颜色</div>\r\n'+
'</body>\r\n'+
'</html>\r\n'
		editor.setValue(str);
		view();
	}
	
	// setting width + height
	var resize_height = function()
	{
		var page_height = $(window).height();
		var page_width = $(window).width();
		$('#result').height(page_height - 170);
		$('.CodeMirror').height(page_height - 170).css('z-index', 3);
		$(".CodeMirror-gutters").height(page_height- 170);
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