
function GenerationPassword() {
	var elm_submit = document.getElementById('submit');
	var elm_out = document.getElementById('outpd');
	var elm_median = document.getElementById('median');
	var elm_num = document.getElementById('num');
	var elm_capital = document.getElementById('capital');
	var elm_lowercase = document.getElementById('lowercase');
	var elm_symbol = document.getElementById('symbol');
	var num = '0123456789';
	var capital = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	var lowercase = 'abcdefghijklmnopqrstuvwxyz';
	var symbol = '+=-@#~,.[]()!%^*$';
	if(localStorage['median']) elm_median.value = localStorage['median'];
	if(localStorage['num'] == 'true') elm_num.checked = true;
	if(localStorage['num'] == 'false') elm_num.checked = false;
	if(localStorage['capital'] == 'true') elm_capital.checked = true;
	if(localStorage['capital'] == 'false') elm_capital.checked = false;
	if(localStorage['lowercase'] == 'true') elm_lowercase.checked = true;
	if(localStorage['lowercase'] == 'false') elm_lowercase.checked = false;
	if(localStorage['symbol'] == 'true') elm_symbol.checked = true;
	if(localStorage['symbol'] == 'false') elm_symbol.checked = false;
	elm_submit.onclick = function(){
		elm_out.value = '';
		var cardinal_num = '';
		if(elm_num.checked == true) {
			cardinal_num += num;
			localStorage['num'] = 'true';
		} else {
			localStorage['num'] = 'false';
		}
		if(elm_capital.checked == true) {
			cardinal_num += capital;
			localStorage['capital'] = 'true';
		} else {
			localStorage['capital'] = 'false';
		}
		if(elm_lowercase.checked == true) {
			cardinal_num += lowercase;
			localStorage['lowercase'] = 'true';
		} else {
			localStorage['lowercase'] = 'false';
		}
		if(elm_symbol.checked == true) {
			cardinal_num += symbol;
			localStorage['symbol'] = 'true';
		} else {
			localStorage['symbol'] = 'false';
		}
		var median = elm_median.value;
		for( var i=0; i<median; i++) {
			var random_num = Math.round(Math.random()*(cardinal_num.length-1));	
			elm_out.value += cardinal_num[random_num];
			elm_out.select();
		}
		localStorage['median'] = median;
		return false;
	}
}