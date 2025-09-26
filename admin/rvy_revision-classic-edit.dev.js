/**
* Classic Editor Modifications for Revisionary
*
* By Kevin Behrens
*
* Copyright 2025, PublishPress
*/
jQuery(document).ready( function($) {
    var rvySaveClicked = false;
    
    var RvyDisableSubmitButtons = function() {
        if (rvyObjEdit.disableSubmitUntilSave) {
            $('a.revision-approve, a.rvy-direct-approve').attr('disabled', 'disabled');

            if (!rvySaveClicked) {
                $('div.rvy-save-revision-tip').show();
            }
        }
    }

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

                    RvyTimeSelection = selectedDate.getTime() - ((currentDate.getTimezoneOffset() * 60 - rvyObjEdit.timezoneOffset) * 1000);
                    var tdiff = RvyTimeSelection - currentDate.getTime();

                    RvyTimeSelection = RvyTimeSelection / 1000; // pass seconds to server

                    if ((tdiff > 1000)) {
                        var approveCaption = rvyObjEdit['scheduleCaption'];
                    } else {
                        var approveCaption = rvyObjEdit['approveCaption'];
                    }
                } else {
                    var approveCaption = rvyObjEdit['approveCaption'];
                }

                if (rvyObjEdit.canPublish && (rvyObjEdit.PendingStatus != rvyObjEdit.currentStatus) && ('future' != rvyObjEdit.currentStatus)) {
					approveButtonHTML = '&nbsp;<a href="' + rvyObjEdit['pendingActionURL'] + '" class="button rvy-direct-approve">'
					+ approveCaption + '</a>'
				}

                var rvyPreviewLink = '';

				if (rvyObjEdit[rvyObjEdit.currentStatus + 'CompletedLinkCaption']) {
                    rvyPreviewLink = '&nbsp; <a href="' + rvyObjEdit[rvyObjEdit.currentStatus + 'CompletedURL'] + '" class="revision-preview" target="_blank">' 
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

                    + '<a href="' + url + '" class="button revision-approve">'
                    + actionCaption + '</a>'
                
                    + approveButtonHTML

                    + rvyObjEdit.saveRevisionTooltip

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

    $('a.save-timestamp').click(function() {
        if (typeof rvyObjEdit.updateCaption != 'undefined') {
        	$('#save-post').val(rvyObjEdit.updateCaption);
        }

        RvyDisableSubmitButtons();

        setTimeout(function() {
           $('div.rvy-creation-ui').remove();
        }, 50);

        setTimeout(function() {
            RvyDisableSubmitButtons();
         }, 500);
    });

	$(document).on('click', 'a.save-timestamp, a.cancel-timestamp', function() {
        wp.autosave.server.triggerSave();
	});

	function RvyGetRandomInt(max) {
		return Math.floor(Math.random() * max);
    }
    
    $(document).on('click', 'div.postbox-container', function() {
		RvyDisableSubmitButtons();
	});

    var rvyThumbnail = $('#set-post-thumbnail img').attr('src');

    setInterval(function() {
        if ($('#set-post-thumbnail img').attr('src') != rvyThumbnail) {
            RvyDisableSubmitButtons();
        }
     }, 500);

	$(document).on('click', 'a.revision-approve', function() {
        if ($('a.revision-approve').attr('disabled')) {
			return false;
		}

        $('a.revision-approve').attr('disabled', 'disabled');

        if (wp.autosave.server.postChanged()) {
            wp.autosave.server.triggerSave();
            var approvalDelay = 250;
        } else {
            var approvalDelay = 1;
        }
        
        if (!rvyObjEdit[rvyObjEdit.currentStatus + 'ActionURL']) {
			var revisionaryCreateDone = function () {
                $('a.revision-approve').hide();
                $('.revision-created-wrapper, .revision-created').show();

				// @todo: abstract this for other workflows
				rvyObjEdit.currentStatus = rvyObjEdit.PendingStatus;

				$('#post-status-display').html(rvyObjEdit[rvyObjEdit.currentStatus + 'StatusCaption']);
                $('a.revision-preview').attr('href', rvyObjEdit[rvyObjEdit.currentStatus + 'CompletedURL']).show();
			}

			var revisionaryCreateError = function (data, txtStatus) {
				$('div.rvy-creation-ui').html(rvyObjEdit[rvyObjEdit.currentStatus + 'ErrorCaption']);
			}

            var tmoSubmit = setInterval(function() {
                if (!wp.autosave.server.postChanged()) {
                    var data = {'rvy_ajax_field': rvyObjEdit[rvyObjEdit.currentStatus + 'AjaxField'], 'rvy_ajax_value': rvyObjEdit.postID, 'nc': RvyGetRandomInt(99999999)};

                    $.ajax({
                        url: rvyObjEdit.ajaxurl,
                        data: data,
                        dataType: "html",
                        success: revisionaryCreateDone,
                        error: revisionaryCreateError
                    });

                    clearInterval(tmoSubmit);
                }
            }, approvalDelay);
        } else {
            var tmoApproval = setInterval(function() {
                if (!wp.autosave.server.postChanged()) {
                    window.location.href = rvyObjEdit[rvyObjEdit.currentStatus + 'ActionURL'];

                    clearInterval(tmoApproval);
                }
            }, approvalDelay);

            return false;
        }
    });
    
    $(document).on('click', 'a.rvy-direct-approve', function() {
        if ($('a.rvy-direct-approve').attr('disabled')) {
			return false;
		}

        clearInterval(RvyUIInterval);

        $('a.rvy-direct-approve').attr('disabled', 'disabled');

        if (wp.autosave.server.postChanged()) {
            wp.autosave.server.triggerSave();
            var approvalDelay = 250;
        } else {
            var approvalDelay = 1;
        }
        
        var tmoDirectApproval = setInterval(function() {
            if (!wp.autosave.server.postChanged()) {
                window.location.href = rvyObjEdit['pendingActionURL'];

                clearInterval(tmoDirectApproval);
            }
        }, approvalDelay);

        return false;
    });

    $(document).on('click', '#post-body-content *, #content_ifr *, #wp-content-editor-container *, #tinymce *, #submitpost, span.revision-created', function() {
        $('.revision-created-wrapper, .revision-created').hide();

        if (!$('a.rvy-direct-approve').length) {
            $('a.revision-approve').show().removeAttr('disabled');
            $('div.rvy-save-revision-tip').hide();
        }
    });

    rvyObjEdit.saveLabel = '';

    setTimeout (
        function() {
            rvyObjEdit.saveLabel = $('#save-post').val();
        }, 500
    );

    $(document).on('click', '#save-post', function() {
        rvySaveClicked = true;

        $('div.rvy-save-revision-tip').hide();

        setTimeout (
            function() {
                if (rvyObjEdit.saveLabel) {
                    $('#save-post').val(rvyObjEdit.saveLabel);
                }
            }, 50
        );
    });
});
