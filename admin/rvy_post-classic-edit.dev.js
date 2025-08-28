/**
* Classic Editor Modifications for Revisionary
*
* By Kevin Behrens
*
* Copyright 2024, PublishPress
*/
jQuery(document).ready( function($) {
	var rvyIsPublished = false;

	var RvySubmissionUI = function() {
		if (rvyObjEdit.ajaxurl && !$('div.rvy-creation-ui').length) {
			var hideStyle = (rvyObjEdit.actionCaption == '') ? ' style="display:none"' : '';

            var html = '<div class="rvy-creation-ui"' + hideStyle + '><a href="javascript:void(0)" class="button revision-approve revision-create" style="margin-top: 15px; margin-bottom: 15px" title="' 
			+ rvyObjEdit.actionTitle + '">' 
			+ rvyObjEdit.actionCaption + '</a>'
			+ '<div class="revision-creating"><span class="spinner"></span></div>'
			+ '<div class="revision-created-wrapper" style="display:none; margin: 10px 0 10px 5px; font-weight: bold">'
			+ '<span class="revision-approve revision-created">' + rvyObjEdit.completedCaption + '</span> &nbsp;';
			
			html = html + '<a href="javascript:void(0)" class="revision-approve revision-edit" target="_blank">' 
			+ rvyObjEdit.completedEditLinkCaption + '</a>'
			
			+ '</div>';
			
			if (rvyObjEdit.scheduleCaption) {
				var publishedStatuses = Object.keys(rvyObjEdit.publishedStatuses).map(function (key) { return rvyObjEdit.publishedStatuses[key]; });
				rvyIsPublished = publishedStatuses.indexOf(rvyObjEdit.currentStatus) >= 0;

				if (rvyIsPublished) {
					html += '<a href="javascript:void(0)" style="display: none; margin-top: 15px; margin-bottom: 15px" class="button revision-approve revision-schedule" title="' 
					+ rvyObjEdit.scheduleTitle + '">' 
					+ rvyObjEdit.scheduleCaption + '</a>'
					
					+ '<div class="revision-scheduled-wrapper" style="display:none; margin-top: 15px; margin-bottom: 15px; font-weight: bold"><span class="revision-approve revision-scheduled">'
					+ rvyObjEdit.scheduledCaption + '</span> ';

					if (rvyObjEdit.scheduledLinkCaption) {
						html += '&nbsp;<a href="javascript:void(0)" class="revision-approve revision-preview" target="_blank">' 
						+ rvyObjEdit.scheduledLinkCaption + '</a>';
					}
					
					html += '&nbsp;<a href="javascript:void(0)" class="revision-approve revision-edit" target="_blank">' 
					+ rvyObjEdit.scheduledEditLinkCaption + '</a>'

					+ '</div>';
				}
			}

			html += '</div>';
			
			$('#delete-action').before(html);
		}
	}
	var RvyUIInterval = setInterval(RvySubmissionUI, 100);

    /*
	$(document).on('click', 'a.save-timestamp, a.cancel-timestamp', function() {
        wp.autosave.server.triggerSave();
	});
    */

	function RvyGetRandomInt(max) {
		return Math.floor(Math.random() * max);
	}

    $(document).on('click', 'a.revision-create', function() {
		if ($('a.revision-create').attr('disabled')) {
			return;
		}

        $('a.revision-create').attr('disabled', 'disabled');

        if (wp.autosave && wp.autosave.server.postChanged()) {
			var tmoRevisionSubmit = setTimeout(rvyCopyPost, 5000);  // @todo: review

			var intRevisionSubmit = setInterval(function() {
				if (!wp.autosave.server.postChanged()) {
					clearTimeout(tmoRevisionSubmit);
					clearInterval(intRevisionSubmit);
					rvyCopyPost();
				}
			}, 250);

            wp.autosave.server.triggerSave();
        } else {
			rvyCopyPost();
        }
	});

	function rvyCopyPost() {
        var revisionaryCreateDone = function () {
			$('.revision-create').hide();
			$('.revision-creating').hide();
			$('.revision-created-wrapper').show();

			if (rvyObjEdit.completedURL) {
				$('div.revision-created-wrapper a.revision-preview').attr('href', rvyObjEdit.completedURL);
			} else {
				$('div.revision-created-wrapper a.revision-preview').hide();
			}

			$('div.revision-created-wrapper a.revision-edit').attr('href', rvyObjEdit.completedEditURL);
            $('a.revision-create').removeAttr('disabled');
		}

		var revisionaryCreateError = function (data, txtStatus) {
			$('div.rvy-creation-ui').html(rvyObjEdit.errorCaption);
			$('.revision-creating').hide();
		}

		var data = {'rvy_ajax_field': 'create_revision', 'rvy_ajax_value': rvyObjEdit.postID, 'rvy_date_selection': RvyTimeSelection, 'nc': RvyGetRandomInt(99999999)};

		$.ajax({
			url: rvyObjEdit.ajaxurl,
			data: data,
			dataType: "html",
			success: revisionaryCreateDone,
			error: revisionaryCreateError
		});

		$('.revision-creating').show().find('.spinner').css('visibility', 'visible');
	}

	function rvySchedulePost() {
		var revisionaryScheduleDone = function () {
			$('.revision-schedule').hide();
			$('.revision-scheduled-wrapper').show();

			$('div.revision-scheduled-wrapper a.revision-preview').attr('href', rvyObjEdit.scheduledURL);
			$('div.revision-scheduled-wrapper a.revision-edit').attr('href', rvyObjEdit.scheduledEditURL);

            $('a.revision-schedule').removeAttr('disabled');
		}

		var revisionaryScheduleError = function (data, txtStatus) {
			$('div.rvy-creation-ui').html(rvyObjEdit.errorCaption);
		}

		var data = {'rvy_ajax_field': 'create_scheduled_revision', 'rvy_ajax_value': rvyObjEdit.postID, 'rvy_date_selection': RvyTimeSelection, 'nc': RvyGetRandomInt(99999999)};

		$.ajax({
			url: rvyObjEdit.ajaxurl,
			data: data,
			dataType: "html",
			success: revisionaryScheduleDone,
			error: revisionaryScheduleError
		});
	}

	$(document).on('click', '#normal-sortables input, #normal-sortables select', function() {
		$('a.revision-create').attr('disabled', 'disabled');
		$('a.revision-schedule').attr('disabled', 'disabled');
	});

	$(document).on('click', 'a.revision-schedule', function() {
		if ($('a.revision-schedule').attr('disabled')) {
			return;
		}

        $('a.revision-schedule').attr('disabled', 'disabled');

		if (wp.autosave && wp.autosave.server.postChanged()) {
			var tmoRevisionSchedule = setTimeout(rvySchedulePost, 5000);  // @todo: review

			var intRevisionSchedule = setInterval(function() {
				if (!wp.autosave.server.postChanged()) {
					clearTimeout(tmoRevisionSchedule);
					clearInterval(intRevisionSchedule);
					rvySchedulePost();
				}
			}, 250);

            wp.autosave.server.triggerSave();
        } else {
			rvySchedulePost();
        }
	});
    
    $(document).on('click', '#post-body-content *, #content_ifr *, #wp-content-editor-container *, #tinymce *, #submitpost, span.revision-created', function() {
        RvyRefreshScheduleButton();
    });

	var $timestampdiv = $('#timestampdiv');
	$timestampdiv.find('.save-timestamp').on( 'click', function( event ) { 
		RvyRefreshScheduleButton();
	});

    /**
	 *  If date is set to future, change Publish button caption to "Schedule Revision",
	 *  Then set a self-interval to refresh that status once the selected date is no longer future.
	 * 
	 *  If the selected date is already past, change Publish button back to "Update"
	 */
	var RvySelectedFutureDate = false;
	var RvyTimeSelection = '';

	var RvyRefreshScheduleButton = function() {
		var selectedDateHTML = $('#timestamp').html();

		if (! /\d/.test(selectedDateHTML) || !rvyIsPublished) {
			RvyTimeSelection = '';
			$('.rvy-creation-ui .revision-schedule').hide();
			$('.rvy-creation-ui .revision-scheduled-wrapper').hide();
			$('.rvy-creation-ui .revision-created-wrapper').hide();
			$('.rvy-creation-ui .revision-create').show();
			return;
        }
        
        var dateStr = $('#mm').val() + '/' + $('#jj').val() + '/' + $('#aa').val() + ' ' +  $('#hh').val() + ':' + $('#mn').val() + ':00';
		var selectedDate = new Date( dateStr );
        
		var currentDate = new Date();

		RvyTimeSelection = selectedDate.getTime() - ((currentDate.getTimezoneOffset() * 60 - rvyObjEdit.timezoneOffset) * 1000);
		var tdiff = RvyTimeSelection - currentDate.getTime();

		RvyTimeSelection = RvyTimeSelection / 1000; // pass seconds to server

		if ((tdiff > 1000)) {
			RvySelectedFutureDate = true;

			$('.rvy-creation-ui').show();
			$('.rvy-creation-ui .revision-create').hide();
			$('.rvy-creation-ui .revision-created-wrapper').hide();
			$('.rvy-creation-ui .revision-scheduled-wrapper').hide();
            $('.rvy-creation-ui .revision-schedule').show();

            $('#publish').hide();
		} else {

			if ('' == rvyObjEdit.actionCaption) {
				$('.rvy-creation-ui').hide();
			}

			$('.rvy-creation-ui .revision-schedule').hide();
			$('.rvy-creation-ui .revision-scheduled-wrapper').hide();
			$('.rvy-creation-ui .revision-created-wrapper').hide();
			$('.rvy-creation-ui .revision-create').show();

			if ( tdiff <= 0 ) {
				if ( RvySelectedFutureDate ) { // If button isn't already recaptioned, don't mess with it or even query for it
					RvyTimeSelection = '';
				}
            }

			$('#publish').val(rvyObjEdit.update);
            $('#publish').show();
		}
	}

    $(document).on('click', 'a.save-timestamp, a.cancel-timestamp', function() {
        RvyRefreshScheduleButton();
    });
});
