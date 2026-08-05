// 原创：http://www.bootcss.com/p/websafecolors/
// 收录时间: 2014-10-16 上一次更新：2014-10-16

(function ($) {
	
	

	$(function () {
		func.closepage_timeout();
		func.copyright();
	});
	
}(jQuery));
jQuery(document).ready(function () {

	// HIDE BROWSER BAR ON LOAD
	/*
	window.addEventListener("load",function() {
		setTimeout(function(){
		  window.scrollTo(0, 1);
	    }, 0);
	});
	*/

/*
	// ACTIVATE CHOSEN PLUGIN
	jQuery(".chzn-select").chosen();
	
	// SHOW CONTENT BASED ON SELECT OPTION VALUE
    jQuery(function () {
        jQuery('#color_filter').change(function () {
            var selected = $(this).find(':selected').val();
            jQuery(".swatches").hide();
            jQuery('#' + selected).show();
        }).change();
    });
	*/
	
	$('.tabbable a').on('click', function(){
		$('.swatches').hide();
		$('#' + $(this).attr('data')).show();
	});
	
	//$('.swatches').hide();
	
	func.closepage_timeout();
	func.copyright();

});