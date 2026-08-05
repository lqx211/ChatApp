(function ($) {
	$(function () {
		
		var ajax_handle = null;
	
		$('#content a').removeClass().addClass(storage.get(storage.define['DEFINE_BTN_STYLE_NAME']) || 'btn');
		
		$('#url_text').on('input', function(){
			var val = $.trim($(this).val());
			if(val.length > 0) {
				$('#send').removeClass('disabled');
			} else {
				$('#send').addClass('disabled');
			}
		}).on('blur', function(){
			var val = $.trim($('#url_text').val());
			if(val.length > 0 && val.substr(0, 7) != 'http://' && val.substr(0, 8) != 'https://') {
				val = 'http://' + val;
			}
			$('#url_text').val(val);
		});
		
		$('#send').on('click', function(){
			var msg = ['请求中...', 'send', '请求被取消了'];
			if($(this).hasClass('disabled')) return;
			if($(this).text() == msg[0]) {
				ajax_handle.abort();
				$(this).text(msg[1]);
				return false;
			}
			
			var url_text = $('#url_text').val();
			$(this).text(msg[0]);
			var type = $('#select_method').val(),
			ajax_handle = $.ajax({
			  url: "http://127.0.0.1/index.php?url=" + url_text + "&type=" + type,
			  cache: false,
			  data: $.trim($('#textarea_body').val()),
			  timeout: 15000,
			  dataType: "json",
			  success: function(html){
			  console.log(html);
				  $('#send').text(msg[1]);
				  $('#text_header').text(html.header);
				  $('#textarea_result').val('').val(html.body);
			  },
			  error: function (XMLHttpRequest, textStatus, errorThrown) {
				  $('#text_header').text(msg[2]);
			  }
			});

		});
		
		$('#select_method').on('change', function(){
			if($(this).val() == 'GET') {
				$('#post_body>.post_body_2').show();
				$('#post_body>.post_body_1').hide();
			} else {
				$('#post_body>.post_body_1').show();
				$('#post_body>.post_body_2').hide();
			}
		});
		
		func.closepage_timeout(179);
		func.copyright();
		$('#url_text').focus();
	});
	
}(jQuery))