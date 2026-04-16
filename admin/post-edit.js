jQuery(document).ready( function($) {
	$('#publish').show();
	
	$('#misc-publishing-actions div.num-revisions').contents().filter(function() {
		return ( this.nodeType == 3 && ( typeof(this.nodeValue) != 'undefined' ) && this.nodeValue.indexOf("Revisions") != -1 ); 
	}).wrap('<span class="rev-caption"></span>');
	
	if ( ! $('#timestampdiv a.now-timestamp').length ) {
	 	$('#timestampdiv a.cancel-timestamp').after('<a href="#timestamp_now" class="now-timestamp hide-if-no-js button-now">' + rvyPostEdit.nowCaption + '</a>');
	}
	$('#timestampdiv a.now-timestamp').on('click', function(){
		var nowDate = new Date();

		var nowTimestamp = nowDate.getTime() + ((nowDate.getTimezoneOffset() * 60 - rvyPostEdit.timezoneOffset) * 1000);
		
		nowDate = new Date(nowTimestamp);

		var month = nowDate.getMonth() + 1;
		if ( month.toString().length < 2 ) {
			month = '0' + month;
		}
		$('#mm').val(month);
		
		$('#jj').val(nowDate.getDate());
		$('#aa').val(nowDate.getFullYear());
		$('#hh').val(nowDate.getHours());

		var minutes = nowDate.getMinutes();
		if ( minutes.toString().length < 2 ) {
			minutes = '0' + minutes;
		}
		$('#mn').val(minutes);

		return false;
	});
});