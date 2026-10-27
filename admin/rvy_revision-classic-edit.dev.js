/**
* Classic Editor Modifications for Revisionary
*
* By Kevin Behrens
*
* Copyright 2026, PublishPress
*/
jQuery(document).ready( function($) {

	// Announce workflow progress and results to screen readers (WCAG 4.1.3)
	var rvySpeak = function(msg) {
		if (msg && window.wp && wp.a11y && wp.a11y.speak) {
			wp.a11y.speak($('<div>').html(msg).text());
		}
	};

	// Links styled as buttons also respond to the Space key
	$(document).on('keydown', 'div.rvy-creation-ui a[role="button"]', function(e) {
		if (' ' === e.key) {
			e.preventDefault();
			$(this).trigger('click');
		}
	});

    
    var RvyInitializeBlockEditorModifications = function () {
        if ($('#wpbody-content .wp-heading-inline').length) {
			clearInterval(RvyInitInterval);
			
            $('#wpbody-content .wp-heading-inline').append(' '  + rvyObjEdit.revisionCaption + ' ');
        }
	}
	var RvyInitInterval = setInterval(RvyInitializeBlockEditorModifications, 50);


	var RvySubmissionUI = function() {
		var refSelector = '.rvy-misc-actions';

        if (!$(refSelector).length) {
            var refSelector = '#submitdiv div.misc-pub-section:last';
        }

        if (!$(refSelector).length) {
            var refSelector = '#submitdiv div.curtime';
        }

		if (typeof rvyObjEdit.updateCaption != 'undefined') {
	        if (rvyObjEdit[rvyObjEdit.currentStatus + 'StatusCaption'] && (rvyObjEdit[rvyObjEdit.currentStatus + 'StatusCaption'] != $('#post-status-display').html())) {
	            $('#post-status-display').html(rvyObjEdit[rvyObjEdit.currentStatus + 'StatusCaption']);
	        }
	 	}

		if (((rvyObjEdit.ajaxurl && !$('div.rvy-creation-ui').length && $(refSelector).length))) {
			if (rvyObjEdit[rvyObjEdit.currentStatus + 'ActionURL']) {
				var url = rvyObjEdit[rvyObjEdit.currentStatus + 'ActionURL'];
			} else {
				var url = 'javascript:void(0)';
            }

			if (rvyObjEdit[rvyObjEdit.currentStatus + 'ActionCaption']) {
                var approveButtonHTML = '';

                var selectedDateHTML = $('#timestamp').html();

                if (/\d/.test(selectedDateHTML) ) {
                    var dateStr = $('#mm').val() + '/' + $('#jj').val() + '/' + $('#aa').val() + ' ' +  $('#hh').val() + ':' + $('#mn').val() + ':00';
                    var selectedDate = new Date( dateStr );
                    
                    var currentDate = new Date();

                    var timeSelection = selectedDate.getTime() - ((currentDate.getTimezoneOffset() * 60 - rvyObjEdit.timezoneOffset) * 1000);
                    var tdiff = timeSelection - currentDate.getTime();

                    if ((tdiff > 120000)) {
                        var approveCaption = rvyObjEdit['scheduleCaption'];
                    } else {
                        var approveCaption = rvyObjEdit['approveCaption'];
                    }
                } else {
                    var approveCaption = rvyObjEdit['approveCaption'];
                }

                if (rvyObjEdit.canPublish && (rvyObjEdit.PendingStatus != rvyObjEdit.currentStatus) && ('future' != rvyObjEdit.currentStatus)) {
					approveButtonHTML = '<a href="' + rvyObjEdit['pendingActionURL'] + '" class="button rvy-direct-approve" role="button">'
					+ approveCaption + '</a>'
				}

                var rvyPreviewLink = '';

				if (rvyObjEdit[rvyObjEdit.currentStatus + 'CompletedLinkCaption']) {
                    rvyPreviewLink = '<a href="' + rvyObjEdit[rvyObjEdit.currentStatus + 'CompletedURL'] + '" class="revision-preview" target="_blank">' 
                    + rvyObjEdit[rvyObjEdit.currentStatus + 'CompletedLinkCaption'] + '</a>';
                }

                if (-1 !== url.indexOf('action=approve&')) {
                    approveButtonHTML = '';
                }

                if (approveButtonHTML && ('draft' != rvyObjEdit.currentStatus)) {
                    actionCaption = approveCaption;
                } else {
                    actionCaption = rvyObjEdit[rvyObjEdit.currentStatus + 'ActionCaption'];
                }

                $(refSelector).after(
                    '<div class="rvy-creation-ui" style="float:left; padding-left:10px; margin-bottom: 10px">'

                    + '<a href="' + url + '" class="button revision-approve" role="button">'
                    + actionCaption + '</a>'
                
                    + approveButtonHTML



                    + '<div class="revision-created-wrapper" style="display: none; margin: 8px 0 0 2px">'
                    + '<span class="revision-approve revision-created" style="color:green">'
                    + rvyObjEdit[rvyObjEdit.currentStatus + 'CompletedCaption'] + '</span> '
                    + rvyPreviewLink
                    + '</div>'

                    + '</div>'
                );
            }
            
			$('.edit-post-post-schedule__toggle').after('<button class="components-button is-tertiary post-schedule-footnote" disabled>' + rvyObjEdit.onApprovalCaption + '</button>');

			if (rvyObjEdit[rvyObjEdit.currentStatus + 'DeletionURL']) {
				$('a.submitdelete').attr('href', rvyObjEdit[rvyObjEdit.currentStatus + 'DeletionURL']);
            }
            
            // Builders can customize/remove core's last misc section. Keep the
        // revision actions anchored in the remaining native Publish metabox.
        if (!$(refSelector).length) refSelector = '#misc-publishing-actions';
        if (!$(refSelector).length) refSelector = '#minor-publishing-actions';
        if (!$(refSelector).length) refSelector = '#submitpost';

        if (typeof rvyObjEdit.updateCaption != 'undefined') {
            	$('#publish').hide();
            	$('#save-post').val(rvyObjEdit.updateCaption);
            }

            if (rvyObjEdit.deleteCaption) {
                $('#submitdiv #submitpost #delete-action a.submitdelete').html(rvyObjEdit.deleteCaption).show();
            }
        }
	}
	var RvyUIInterval = setInterval(RvySubmissionUI, 100);


    $(document).on('click', 'a.save-timestamp, a.cancel-timestamp', function () {
        setTimeout(function () { $('div.rvy-creation-ui').remove(); }, 0);
    });
    $(document).on('click', 'a.revision-approve, a.rvy-direct-approve', function (event) {
        event.preventDefault();
        var url = $(this).attr('href') || '';
        var operation = $(this).hasClass('rvy-direct-approve') ? 'approve' : 'submit';
        if (url && url !== 'javascript:void(0)') {
            operation = new URL(url, window.location.href).searchParams.get('action') || operation;
        }
        Promise.resolve().then(function () {
            return window.RevisionaryEditorSave.classic(operation);
        }).catch(function (error) {
            var notice = $('div.rvy-editor-save-error');
            if (!notice.length) notice = $('<div class="notice notice-error rvy-editor-save-error" role="alert"><p></p></div>').insertBefore('div.rvy-creation-ui');
            notice.find('p').text(error.message || rvyObjEdit.saveError);
            rvySpeak(error.message || rvyObjEdit.saveError);
        });
        return false;
    });
});
