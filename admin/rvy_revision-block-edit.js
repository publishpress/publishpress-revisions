/**
* Block Editor Modifications for Revisionary
*
* By Kevin Behrens
*
* Copyright 2026, PublishPress
*/
jQuery(document).ready(function ($) {

	// Announce workflow progress and results to screen readers (WCAG 4.1.3)
	var rvySpeak = function(msg) {
		if (msg && window.wp && wp.a11y && wp.a11y.speak) {
			wp.a11y.speak($('<div>').html(msg).text());
		}
	};
	var RvyIsNativeRevisionComparison = function() {
		return !!document.querySelector('.editor-revisions-header');
	};

	// Temporary workaround pending Gutenberg #77014 / Core #65024.
	// This asset is loaded only for workflow revision editing. Replace only
	// the native successful draft-save notice, preserving its ID and actions.
	if (window.wp && wp.data && wp.i18n) {
		var rvyUpdatingSaveNotice = false;
		wp.data.subscribe(function () {
			if (rvyUpdatingSaveNotice || RvyIsNativeRevisionComparison()) return;
			var store = wp.data.select('core/notices');
			if (!store || !store.getNotices) return;
			var draftSaved = wp.i18n.__('Draft saved.');
			store.getNotices().forEach(function (notice) {
				if (notice.status !== 'success' || notice.content !== draftSaved) return;
				rvyUpdatingSaveNotice = true;
				try {
					wp.data.dispatch('core/notices').createNotice('success', wp.i18n.__('Revision updated', 'revisionary'), {
						id: notice.id, type: notice.type, isDismissible: notice.isDismissible,
						actions: notice.actions, speak: notice.speak
					});
				} finally { rvyUpdatingSaveNotice = false; }
			});
		});
	}

	// Initialization operations to perform once React loads the relevant elements
    var RvyInitializeBlockEditorModifications = function () {
        if (($('button.editor-post-publish-button').length || $('button.editor-post-publish-panel__toggle').length) && ($('button.editor-post-switch-to-draft').length || $('button.editor-post-save-draft').length)) {
			clearInterval(RvyInitInterval);
			
            $('select.editor-post-author__select').parent().hide();
            $('button.editor-post-trash').parent().show();
            $('button.editor-post-switch-to-draft').hide();

        }
	}
	var RvyInitInterval = setInterval(RvyInitializeBlockEditorModifications, 50);

    var RvyInitializeHeaderCaption = function () {
        if ($('#editor .editor-document-bar span.editor-document-bar__post-type-label').length) {
			clearInterval(RvyHeaderInterval);

            $('#editor .editor-document-bar span.editor-document-bar__post-type-label').append(' '  + rvyObjEdit.revisionCaption + ' ');
        }
	}
	var RvyHeaderInterval = setInterval(RvyInitializeHeaderCaption, 50);
	
    var RvyHideElements = function () {
        var ediv = 'div.edit-post-sidebar ';
        if ($(ediv + 'div.edit-post-post-visibility,' + ediv + 'div.editor-post-link,' + ediv + 'select.editor-post-author__select:visible,' + ediv + 'div.components-base-control__field input[type="checkbox"]:visible,' + ediv + 'button.editor-post-switch-to-draft,' + ediv + 'button.editor-post-trash').length) {
            $(ediv + 'select.editor-post-author__select').parent().hide();
            $(ediv + 'button.editor-post-trash').parent().show();
            $(ediv + 'button.editor-post-switch-to-draft').hide();
            $(ediv + 'div.editor-post-link').parent().hide();
			
            if (!rvyObjEdit.scheduledRevisionsEnabled) {
                $(ediv + 'div.edit-post-post-schedule').hide();
			}
			
            $(ediv + '#publishpress-notifications').hide();
            $('#icl_div').closest('div.edit-post-meta-boxes-area').hide();
        }
        if ('future' == rvyObjEdit.currentStatus) {
            $('button.editor-post-publish-button').show();
		
        } else {            
        	if ($('button.editor-post-publish-button').length && ($('button.editor-post-save-draft:visible').length || $('button.editor-post-saved-stated:visible').length)) {                
        		$('button.editor-post-publish-button').hide();
        	}
        }

		ediv = null;
	}
	var RvyHideUpdateScheduled = false;
	var RvyHideObserver;
	var RvyHideObserverOptions = {
		childList: true,
		subtree: true,
		attributes: true,
		attributeOldValue: true,
		attributeFilter: ['class', 'style', 'hidden', 'aria-hidden']
	};
	var RvyVisibilityTargets = 'div.edit-post-sidebar, button.editor-post-publish-button, button.editor-post-save-draft, button.editor-post-saved-stated, #icl_div';
	var RvyContainsVisibilityTarget = function (node) {
		return node.nodeType === 1 && (node.matches(RvyVisibilityTargets) || !!node.querySelector(RvyVisibilityTargets));
	};
	var RvyVisibilityMutations = function (records) {
		var relevant = records.some(function (record) {
			// React and other integrations may set an existing value again.
			if (record.type === 'attributes' && record.oldValue === record.target.getAttribute(record.attributeName)) {
				return false;
			}
			if (record.target.nodeType === 1 && record.target.closest(RvyVisibilityTargets)) {
				return true;
			}
			if (record.type === 'attributes') {
				// Ancestor visibility can change without changing a control.
				return RvyContainsVisibilityTarget(record.target);
			}
			return Array.prototype.some.call(record.addedNodes, RvyContainsVisibilityTarget)
				|| Array.prototype.some.call(record.removedNodes, RvyContainsVisibilityTarget);
		});
		if (relevant) {
			RvyScheduleHideElements();
		}
	};
	var RvyScheduleHideElements = function () {
		if (RvyHideUpdateScheduled) {
			return;
		}
		RvyHideUpdateScheduled = true;
		window.requestAnimationFrame(function () {
			RvyHideUpdateScheduled = false;
			// Do not observe our own show/hide writes: they would schedule
			// another frame indefinitely. Restore observation even on failure.
			RvyHideObserver.disconnect();
			try {
				RvyHideElements();
			} finally {
				RvyHideObserver.observe(document.documentElement, RvyHideObserverOptions);
			}
		});
	};

	RvyHideElements();
	RvyHideObserver = new MutationObserver(RvyVisibilityMutations);
	RvyHideObserver.observe(document.documentElement, RvyHideObserverOptions);
	window.addEventListener('resize', RvyScheduleHideElements);
	document.addEventListener('change', RvyScheduleHideElements, true);
	document.addEventListener('click', RvyScheduleHideElements, true);

    var RvySubmissionUI = function () {
		if (RvyIsNativeRevisionComparison()) {
			$('div.rvy-creation-ui').remove();
			return;
		}

        selectedDateHTML = wp.data.select('core/editor').getEditedPostAttribute('date');
        var selectedDate = new Date( selectedDateHTML );
        var currentDate = new Date();

        RvyTimeSelection = selectedDate.getTime() - ((currentDate.getTimezoneOffset() * 60 - rvyObjEdit.timezoneOffset) * 1000);

        if (RvyTimeSelection - currentDate.getTime() > 120000) {
            var approveCaption = rvyObjEdit['scheduleCaption'];

            if ('future' == rvyObjEdit.currentStatus) {
                approveCaption = rvyObjEdit['futureActionCaption'];
            } else {
                approveCaption = rvyObjEdit['scheduleCaption'];
            }
        } else {
            var approveCaption = rvyObjEdit['approveCaption'];
        }

        if ($('div.edit-post-post-schedule').length && !$('div.editor-post-schedule__panel-dropdown:visible').length) {
            $('.rvy-creation-ui').remove();
        }

        if (approveCaption) {
            $('button.rvy-direct-approve:visible span.rvy-caption').html(approveCaption);
        }

        $('button.edit-post-post-visibility__toggle, div.editor-post-url__panel-dropdown, div.components-checkbox-control').closest("div.editor-post-panel__row").hide();

        refSelector = 'button.editor-post-trash';
        refSelectorUseParent = false;
        refSelectorBefore = true;

        if (!$(refSelector).length) {
            var refSelectorUseParent = false;
            refSelectorBefore = false;

            if ($('div.edit-post-post-schedule').length) {
                var refSelector = 'div.edit-post-post-schedule';
            } else {
                var refSelector = 'div.edit-post-post-visibility';

                if (!$(refSelector).length) {
                    // Gutenberg 18.5
                    if ($('div.editor-post-panel__section').length) {
                        refSelector = 'div.editor-post-panel__section';
                    } else {
                        refSelector = 'div.edit-post-post-status h2';
                    }
                }
            }
        }

        if (refSelectorUseParent) {
            var foundUIloc = $(refSelector).parent().length;
        } else {
            var foundUIloc = $(refSelector).length;
        }

        if (rvyObjEdit.ajaxurl && !$('div.edit-post-revision-status').length && foundUIloc) {
            if ($('div.editor-post-panel__row-label').length) {
                var labelOpen = '<div class="editor-post-panel__row-label">';
                var labelClose = '</div>';
                var statusWrapperClass = 'editor-post-panel__row-control';
            } else {
                var labelOpen = '<span>';
                var labelClose = '</span>';
                var statusWrapperClass = '';
            }

            var currentStatusCaption;

            if (typeof rvyObjEdit[rvyObjEdit.currentStatus + 'StatusCaption'] !== 'undefined') {
                currentStatusCaption = rvyObjEdit[rvyObjEdit.currentStatus + 'StatusCaption'];
            } else {
                currentStatusCaption = '';
            }

            if (refSelector == 'div.editor-post-panel__section') {
                var rvyUI = '';

                if (statusWrapperClass) {
                    statusWrapperClass += ' gutenberg-18-5';

                    rvyUI += '<div class="' + statusWrapperClass + '">';
                }

                rvyUI += '<div class="components-dropdown rvy-current-status">'
                + currentStatusCaption
                + '</div>';

                if (statusWrapperClass) {
                    rvyUI += '</div>';
                }

                $('div.editor-post-panel__row-control div.editor-post-status').html(
                    rvyUI
                );
            } else {
                if ($('div.editor-post-status').length) {
                    $('.rvy-creation-ui.edit-post-revision-status').remove();

                    $('div.editor-post-status').parent().html(
                        '<div class="components-dropdown rvy-current-status">'
                        + currentStatusCaption
                        + '</div>'
                    );
                } else {
                    if (!$('.rvy-current-status').length) {
                        var rvyUI = '<div class="components-panel__row rvy-creation-ui edit-post-revision-status">'
                        + labelOpen + rvyObjEdit.statusLabel + labelClose;

                        if (statusWrapperClass) {
                            rvyUI += '<div class="' + statusWrapperClass + '">';
                        }

                        rvyUI += '<div class="components-dropdown rvy-current-status">'
                        + currentStatusCaption
                        + '</div>';

                        if (statusWrapperClass) {
                            rvyUI += '</div>';
                        }

                        rvyUI += '</div>';

                        $(refSelector).before(rvyUI);
                    }
                }
            }

            if (rvyObjEdit[rvyObjEdit.currentStatus + 'ActionURL']) {
                var url = rvyObjEdit[rvyObjEdit.currentStatus + 'ActionURL'];
            } else {
                var url = 'javascript:void(0)';
			}
			
            if (rvyObjEdit[rvyObjEdit.currentStatus + 'ActionCaption']) {
                var approveButtonHTML = '';
				var mainDashicon = '';
				
                if (rvyObjEdit.canPublish && ('future' != rvyObjEdit.currentStatus)) {
                    approveButtonHTML = '<a tabindex="-1" href="' + rvyObjEdit['pendingActionURL'] + '" class="revision-approve">'
                        + '<button type="button" class="components-button revision-approve is-button is-primary ppr-purple-button rvy-direct-approve">'
                        + '<span class="dashicons dashicons-yes" aria-hidden="true"></span>'
                        + '<span class="rvy-caption">' + rvyObjEdit['approveCaption'] + '</span></button></a>';
                        
                    if ('draft' != rvyObjEdit.currentStatus) {
                        approveButtonHTML += '<a tabindex="-1" href="' + rvyObjEdit['declineURL'] + '" class="revision-decline">'
                        + '<button type="button" class="components-button revision-approve is-button is-primary ppr-purple-button rvy-direct-decline">'
                        + '<span class="dashicons dashicons-no" aria-hidden="true"></span>'
                        + '<span class="rvy-caption">' + rvyObjEdit['declineCaption'] + '</span></button></a>';
                    }

                    mainDashicon = 'dashicons-upload';
                } else {
                    if ('pending' == rvyObjEdit.currentStatus) {
                        mainDashicon = 'dashicons-yes';
                    } else {
                        mainDashicon = 'dashicons-upload';
                    }
				}
				
				var rvyPreviewLink = '';
                
                if (rvyObjEdit['viewCaption']) {
                    rvyPreviewLink = '<a tabindex="-1" class="revision-preview" href="' + rvyObjEdit['viewURL'] + '" target="_blank"><button type="button" class="revision-preview components-button is-secondary ppr-purple-button" target="pp_revisions_preview">'
                        + rvyObjEdit['viewCaption'] + '</button></a>';
                }
                
                if (refSelector == 'div.editor-post-panel__section') {
                    divClass = ' gutenberg-18-5';
                } else {
                    divClass = '';
                }
				
                if (refSelectorUseParent) {
                    var uiLoc = $(refSelector).parent();
                } else {
                    var uiLoc = $(refSelector);
                }

                if (!$('div.rvy-creation-ui').length) {
                    var buttonUI = '<div class="rvy-creation-ui rvy-submission-div' + divClass + '">';
                    
                    if (('pending' != rvyObjEdit.currentStatus) && ((!approveButtonHTML && rvyObjEdit.canPublish) || !rvyObjEdit.approveButtonReplacesSubmit)) {
                        buttonUI += '<a tabindex="-1" href="' + url + '" class="revision-approve">'
                        + '<button type="button" class="components-button revision-approve is-button is-primary ppr-purple-button">'
                        + '<span class="dashicons ' + mainDashicon + '" aria-hidden="true"></span>'
                        + '<span class="rvy-caption">' + rvyObjEdit[rvyObjEdit.currentStatus + 'ActionCaption'] + '</span></button></a>';
                    }

                    if (!approveButtonHTML && rvyObjEdit.canPublish) {
                        buttonUI += '<a tabindex="-1" href="' + rvyObjEdit['declineURL'] + '" class="revision-decline">'
                        + '<button type="button" class="components-button revision-approve is-button is-primary ppr-purple-button rvy-direct-decline">'
                        + '<span class="dashicons dashicons-no" aria-hidden="true"></span>'
                        + '<span class="rvy-caption">' + rvyObjEdit['declineCaption'] + '</span></button></a>';
                    }

                    buttonUI += approveButtonHTML
    
                        + '<div class="revision-submitting" style="display: none;">'
                        + '<span class="revision-approve revision-submitting">'
                        + rvyObjEdit[rvyObjEdit.currentStatus + 'InProcessCaption'] + '</span><span class="spinner ppr-submission-spinner" style=""></span></div>'
                        + '<div class="revision-approving" style="display: none;">'
                        + '<span class="revision-approve revision-submitting">'
                        + rvyObjEdit.approvingCaption + '</span><span class="spinner ppr-submission-spinner" style=""></span></div>'
                        + rvyPreviewLink
                        + '</div>';

                    if (refSelectorBefore) {
                        $(uiLoc).before(buttonUI);
                    } else {
                        $(uiLoc).after(buttonUI);
                    }
                }

                $('div.rvy-submission-div').trigger('loaded-ui');
            }

			$('.edit-post-post-schedule__toggle').after('<button class="components-button is-tertiary post-schedule-footnote" disabled>' + rvyObjEdit.onApprovalCaption + '</button>');

            $('button.editor-post-trash').insertBefore('div.rvy-author-selection:first');
        }

        refSelector = null;
        url = null;
        approveButtonHTML = null;
        mainDashicon = null;
        rvyPreviewLink = null;

        $('button.post-schedule-footnote').toggle(!/\d/.test($('button.edit-post-post-schedule__toggle').html()));

        $('button.editor-post-trash').parent().css('text-align', 'right');
	}

    var RvyUIInterval = setInterval(RvySubmissionUI, 100);

    setInterval(function () {
        if (rvyObjEdit.deleteCaption && $('button.editor-post-trash').length && ($('button.editor-post-trash').html() != rvyObjEdit.deleteCaption)) {
            $('button.editor-post-trash').html(rvyObjEdit.deleteCaption).closest('div').show();
        }
    }, 100);


    var rvyTransitionBusy = false;
    $(document).on('click', 'a.revision-approve, a.revision-schedule, button.revision-approve, button.revision-schedule', function (event) {
        event.preventDefault();
        if (rvyTransitionBusy) return false;
        var control = $(this);
        var isDecline = control.hasClass('rvy-direct-decline');
        var url = control.closest('a').attr('href') || '';
        var isSubmission = !isDecline && (!url || url === 'javascript:void(0)');
        if (isDecline) { if (url) window.location.href = url; return false; }
        rvyTransitionBusy = true;
        $('button.revision-approve, button.revision-schedule').prop('disabled', true);
        $('div.revision-approving').show();
        rvySpeak(rvyObjEdit.approvingCaption);
        window.RevisionaryEditorSave.block().then(function () {
            if (isSubmission) return rvySubmitCopy();
            if (!url) throw new Error(rvyObjEdit.saveError);
            var destination = new URL(url, window.location.href);
            destination.searchParams.delete('editor'); // Full save completed; do not reapply an autosave.
            window.location.href = destination.href;
        }).catch(function (error) {
            var notice = $('div.rvy-editor-save-error');
            if (!notice.length) notice = $('<div class="notice notice-error rvy-editor-save-error" role="alert"><p></p></div>').insertBefore('div.rvy-creation-ui');
            notice.find('p').text(error.message || rvyObjEdit.saveError);
            rvySpeak(error.message || rvyObjEdit.saveError);
        }).finally(function () {
            rvyTransitionBusy = false;
            $('button.revision-approve, button.revision-schedule').prop('disabled', false);
            $('div.revision-approving, div.revision-submitting').hide();
        });
        return false;
    });

    function rvySubmitCopy() {
        var revisionaryCreateDone = function () {
            $('.revision-approve').hide();
            $('div.revision-submitting').hide();
            $('.revision-created').show();

			// @todo: abstract this for other workflows
            rvyObjEdit.currentStatus = 'pending';
            $('.rvy-current-status').html(rvyObjEdit[rvyObjEdit.currentStatus + 'StatusCaption']);
            $('.revision-preview').attr('href', rvyObjEdit[rvyObjEdit.currentStatus + 'CompletedURL']).show();
            $('.revision-edit').attr('href', rvyObjEdit[rvyObjEdit.currentStatus + 'CompletedEditURL']).show();

            if ((typeof PPCustomStatuses != 'undefined') && (typeof PPCustomStatuses.statusRestProperty != 'undefined')) {
                var ret = new Object();
                ret[PPCustomStatuses.statusRestProperty] = 'pending-revision';
                wp.data.dispatch('core/editor').editPost(ret);
            }
        }
		
        return $.ajax({
            url: rvyObjEdit.ajaxurl,
            data: {'rvy_ajax_field': rvyObjEdit[rvyObjEdit.currentStatus + 'AjaxField'], 'rvy_ajax_value': wp.data.select('core/editor').getCurrentPostId(), '_rvynonce': rvyObjEdit.revisionActionNonce},
            dataType: "html",
            success: revisionaryCreateDone,
            error: function (data, txtStatus) {
                $('div.rvy-creation-ui').html(rvyObjEdit[rvyObjEdit.currentStatus + 'ErrorCaption']);
                rvySpeak(rvyObjEdit[rvyObjEdit.currentStatus + 'ErrorCaption']);
            }
        });
    }

    var RvyRecaptionDefaultButtons = function () {
        if (!rvyObjEdit.isStatusesPro) {
            RvyRecaptionSaveButton(rvyObjEdit.saveRevision);
        }

        if (('future' == rvyObjEdit.currentStatus) || ('future-revision' == rvyObjEdit.currentStatus) || !rvyObjEdit.isStatusesPro) {
            $('button.editor-post-publish-panel__toggle').hide();
        }
        
        if (rvyObjEdit.viewURL && ($('.editor-preview-dropdown__toggle').length || $('div.block-editor-post-preview__dropdown').length)) {
            var viewPreviewLink = '<a href="' 
            + rvyObjEdit.viewURL 
            + '" target="pp_revisions_copy" role="menuitem" class="ppr-purple-button components-button is-primary editor-preview-dropdown__button-external">'
            + rvyObjEdit.viewTitle 
            + '</a>';

            if (!$('div.components-menu-group div a.ppr-purple-button').length) {
                if ($('.editor-preview-dropdown__button-external svg').length) {
                    $('.editor-preview-dropdown__button-external:not(.ppr-purple-button)').closest('div.components-dropdown-menu__menu').after('<div class="components-menu-group"><div role="group">' + viewPreviewLink + '</div></div>');
                }
            }
        }

        if (rvyObjEdit.viewTitle) {
            $('div.edit-post-header__settings a.rvy-post-preview').attr('title', rvyObjEdit.viewTitle);
        }
        
        if (rvyObjEdit.revisionEdits && $('div.edit-post-sidebar a.editor-post-last-revision__title:visible').length && !$('div.edit-post-sidebar a.editor-post-last-revision__title.rvy-recaption').length) {
            $('div.edit-post-sidebar a.editor-post-last-revision__title').html(rvyObjEdit.revisionEdits);
            $('div.edit-post-sidebar a.editor-post-last-revision__title').addClass('rvy-recaption');
        }

        newPreviewItem = null;
    }
    var RvyRecaptionSaveDraftInterval = setInterval(RvyRecaptionDefaultButtons, 100);

    function RvyRecaptionSaveButton(btnCaption) {
        jQuery(document).ready(function ($) {
            btnSelector = 'button.editor-post-save-draft';

            var node = $(btnSelector);
          
            if (wp.data.select('core/editor').isSavingPost()) {
                return;
            }

            if (!btnCaption) {
                btnCaption = rvyObjEdit.saveRevision;
            }

            if (btnCaption != $('span.revisionary-save-button button').html() || !$('span.revisionary-save-button button:visible').length || $('button.editor-post-save-draft:visible').length
            ) {
                var hideClass = 'presspermit-save-hidden';

                if (!$('.revisionary-save-button').length
                ) {
                    // Clone the stock button
                    node.after('<span class="revisionary-save-button">' + node.clone().css('z-index', 0).removeClass(hideClass).removeClass('editor-post-save-draft').removeAttr('disabled').removeAttr('aria-disabled').removeAttr('style').css('white-space', 'nowrap').css('position', 'relative').show().html(btnCaption).wrap('<span>').parent().html() + '</span>');

                    // Hide the stock button
                    node.addClass(hideClass).hide().css('z-index', -999);

                    $('span.revisionary-save-button button').removeClass('editor-post-save-draft').removeClass(hideClass).removeClass('is-tertiary').addClass('is-primary').addClass('ppr-purple-button').html(btnCaption);

                    node.addClass(hideClass).attr('aria-disabled', true);
                }
            }
        });
    }

    $(document).on('click', 'span.revisionary-save-button button', function() {
        if (!wp.data.select('core/editor').isSavingPost() && !$('span.revisionary-save-button button').attr('aria-disabled')) {
            $(this).parent().prev('button.editor-post-save-draft').trigger('click');
        }
    });
});
