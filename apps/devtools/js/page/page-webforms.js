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
	
	var view = function(data)
	{
		if(typeof data =='undefined' || data.length < 1)
		{
			$('.ce').remove();
			return false;
		}
		
		$('#result').empty();
		
		var args = new Object();
		var big_data = data.split("\n");
		var big_length = big_data.length;
		
		// clone
		$('.ce').remove();

		// core
		for(var j = 0; j < big_length; j++)
		{
			var pairs = big_data[j].slice(big_data[j].indexOf('?') + 1).split('&');
			
			// format table
			if(big_data[j].indexOf('?') != -1)
			{
				var $_tmp = $('.table').eq(0).clone();
				if($('.ce').length % 2 != 0)
				{
					$_tmp.css('margin-left', '1%');
				}

				$_tmp.css('display', '').addClass('ce');
				
				$('.table').last().after($_tmp);
			}
			
			// reduce table
			for(var i = 0; i< pairs.length; i++)   
			{
				var pos = pairs[i].indexOf('=');
				if(pos == -1) continue;
				var argname = decodeURIComponent(pairs[i].substring(0, pos));
				var value = decodeURIComponent(pairs[i].substring(pos + 1));
				$_tmp.append('<tr><td>'+ (big_length>1?(j+1)+ '-':'') + (i + 1)+'</td><td><code>'+argname+'</code></td><td>'+value+'</td></tr>')
			}
		}
	}
	
	editor.on("change", function(c){
		view(editor.getValue());
	});
	
	if($('#input_text').val().length < 1)
	{
		var str = 'http://www.box3.cn/?a=123&b=456&c=789&d=abc&e=def\nhttp://box3.cn/?aa=321&bb=456&cc=789&dd=abc&ee=deff';
		editor.setValue(str);
	}
	
	// setting width + height
	var resize_height = function()
	{
		var page_height = $(window).height();
		var page_width = $(window).width();
		if(page_height - 500 > 400)
		{
			page_height = 900;
		}
		$('.CodeMirror').height(page_height - 500).css('z-index', 3);
		$(".CodeMirror-gutters").height(page_height - 500);
		$('#content').width(page_width - 100);
		$('#header').width(page_width - 100);
	}

	resize_height();
	$(window).resize(function() {
		resize_height();
	});
})