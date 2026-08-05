// 原创：http://larsjung.de/qrcode/
// 收录时间: 2014-05-23  

(function ($) {
	'use stric';
	var isOpera = Object.prototype.toString.call(window.opera) === '[object Opera]',
	
	guiValuePairs = [
		["size", "px"],
		["minversion", ""],
		["quiet", " modules"],
		["radius", "%"],
		["msize", "%"],
		["mposx", "%"],
		["mposy", "%"]
	],
	
	updateGui = function () {
		$.each(guiValuePairs, function (idx, pair) {
		var $label = $('label[for="' + pair[0] + '"]');
			$label.text($label.text().replace(/:.*/, ': ' + $('#' + pair[0]).val() + pair[1]));
		});
	},
	
	updateQrCode = function () {

		var options = {
				render: $("#render").val(),
				ecLevel: $("#eclevel").val(),
				minVersion: parseInt($("#minversion").val(), 10),

				fill: $("#fill").val(),
				background: $("#background").val(),
				// fill: $("#qrcode_img_buffer")[0],

				text: $("#text").val(),
				size: parseInt($("#size").val(), 10),
				radius: parseInt($("#radius").val(), 10) * 0.01,
				quiet: parseInt($("#quiet").val(), 10),

				mode: parseInt($("#mode").val(), 10),

				mSize: parseInt($("#msize").val(), 10) * 0.01,
				mPosX: parseInt($("#mposx").val(), 10) * 0.01,
				mPosY: parseInt($("#mposy").val(), 10) * 0.01,

				label: $("#label").val(),
				fontname: $("#font").val(),
				fontcolor: $("#fontcolor").val(),

				image: $("#qrcode_img_buffer")[0]
			};

		$("#qrcode_container").empty().qrcode(options);
	},
	
	update = function () {
		updateGui();
		updateQrCode();
	},
	
	onImageInput = function () {
		var input = $("#image")[0];
		if (input.files && input.files[0]) {

			var reader = new FileReader();
			reader.onload = function (event) {
				$("#qrcode_img_buffer").attr("src", event.target.result);
				$("#mode").val("4");
				setTimeout(update, 250);
			};
			reader.readAsDataURL(input.files[0]);
		}
	},
	
	download = function (event) {
		try {
			var data = $("#qrcode_container canvas")[0].toDataURL('image/png');
			$("#qrcode_download").attr("href", data);
		} catch(e) {
			$("#qrcode_download").text("请用截图工具完成二维码图片保存。");
		}
	};
	
	$(function () {
		if (isOpera) {
			$('html').addClass('opera');
			$('#radius').prop('disabled', true);
		}

		$("#qrcode_download").on("click", download);
		$("#image").on('change', onImageInput);
		$("input, textarea, select").on("input change", update);
		$(window).load(update);
		update();
		
		func.closepage_timeout();
		func.copyright();
	});
	
}(jQuery));