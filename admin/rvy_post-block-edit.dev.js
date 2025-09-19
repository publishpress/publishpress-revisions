/**
* Block Editor Modifications
*
* Copyright 2021, PublishPress
*/

jQuery(document).ready( function($) {
	// Add links for Pending and / or Scheduled Revisions diff screens
	if (rvyObjEdit.scheduledRevisionsURL || rvyObjEdit.pendingRevisionsURL) {
		var RvyPendingRevPanel = function() {
			var ediv = 'div.edit-post-sidebar ';

			if (rvyObjEdit.scheduledRevisionsURL && !$(ediv + 'div.edit-post-last-revision__panel a.rvy-scheduled-revisions').length ) {
				var sclone = $('div.edit-post-last-revision__panel a:first').clone().addClass('rvy-scheduled-revisions').attr('href', rvyObjEdit.scheduledRevisionsURL).html(rvyObjEdit.scheduledRevisionsCaption);
				$(ediv + 'div.edit-post-last-revision__panel a:last').after(sclone);
			}

			if (rvyObjEdit.pendingRevisionsURL && !$(ediv + 'div.edit-post-last-revision__panel a.rvy-pending-revisions').length ) {
				var pclone = $('div.edit-post-last-revision__panel a:first').clone().addClass('rvy-pending-revisions').attr('href', rvyObjEdit.pendingRevisionsURL).html(rvyObjEdit.pendingRevisionsCaption);
				$(ediv + 'div.edit-post-last-revision__panel a:last').after(pclone);
			}

			$('div.edit-post-last-revision__panel').css('height', 'inherit');
		}
		setInterval(RvyPendingRevPanel, 200);
	}

	var rvyIsPublished = false;

	var RvySubmissionUI = function() {
		if (rvyObjEdit.ajaxurl && !$('button.revision-approve').length) {
			var style = (rvyObjEdit.actionCaption == '') ? ' style="display:none"' : '';

			if (rvyObjEdit.newRevisionDirectLink) {
				var html = '<div class="rvy-creation-ui"><a href="' + rvyObjEdit.newRevisionDirectLink + '" class="revision-approve" target="_blank" title="'
				+ rvyObjEdit.actionTitle + '"' + style + '><button type="button" class="components-button revision-approve revision-create is-primary ppr-purple-button"' + style + '>'
				+ rvyObjEdit.actionCaption + '</button></a>';
			} else {
				var html = '<div class="rvy-creation-ui"><a href="javascript:void(0)" class="revision-approve" title="'
				+ rvyObjEdit.actionTitle + '"' + style + '><button type="button" class="components-button revision-approve revision-create is-primary ppr-purple-button"' + style + '>'
				+ rvyObjEdit.actionCaption + '</button></a>';
			}

			html = html + '<div class="revision-creating" style="display: none;">'
			+ '<span class="revision-approve revision-creating">' + rvyObjEdit.creatingCaption + '</span><span class="spinner ppr-submission-spinner" style=""></span>'
			+ '</div>'

			+ '<div class="revision-approve revision-created">' + rvyObjEdit.completedCaption + '</div> '

			+ '<div class="revision-approve revision-created revision-created-buttons">';
			
			html = html + '<button type="button" class="revision-approve revision-created revision-edit ppr-clear-button" style="display: none">'
			+ '<a href="javascript:void(0)" class="revision-approve revision-edit components-button is-secondary ppr-purple-button" target="pp_revisions_copy">'
			+ rvyObjEdit.completedEditLinkCaption + '</a>'
			+ '</button>'
			+ '</div>'

			if (rvyObjEdit.scheduleCaption) {
				let postStatus = wp.data.select('core/editor').getCurrentPostAttribute('status');
				var publishedStatuses = Object.keys(rvyObjEdit.publishedStatuses).map(function (key) { return rvyObjEdit.publishedStatuses[key]; });
				rvyIsPublished = publishedStatuses.indexOf(postStatus) >= 0;

				if (rvyIsPublished) {
					html += '<a href="javascript:void(0)" style="display: none" class="revision-approve revision-schedule" title="'
					+ rvyObjEdit.scheduleTitle + '"><button type="button" class="components-button revision-approve revision-schedule is-primary ppr-purple-button">'
					+ rvyObjEdit.scheduleCaption + '</button></a>'

					+ '<button type="button" class="revision-approve revision-scheduled ppr-clear-button" style="display: none">'
					+ '<span class="revision-approve revision-scheduled">'
					+ rvyObjEdit.scheduledCaption + '</span> ';

					if (rvyObjEdit.scheduledLinkCaption) {
						html += '<a href="javascript:void(0)" class="revision-approve revision-preview components-button is-secondary ppr-purple-button" target="pp_revisions_copy">'
						+ rvyObjEdit.scheduledLinkCaption + '</a>';
					}

					html += '<a href="javascript:void(0)" class="revision-approve revision-edit components-button is-secondary ppr-purple-button" target="pp_revisions_copy">'
					+ rvyObjEdit.scheduledEditLinkCaption + '</a>'
					
					+ '</button>';
				}
			}

			html += '</div>';

			var elem = $('div.editor-post-schedule__panel-dropdown').closest('div.editor-post-panel__row');

			if (!$(elem).length) {
				elem = $('div.edit-post-post-schedule');
			}

			$(elem).first().after(html);

			if (rvyCreationDisabled) {
				$('button.revision-approve').prop('disabled', 'disabled');
				$('button.revision-schedule').prop('disabled', 'disabled');
				$('a.revision-approve').attr('title', rvyObjEdit.actionDisabledTitle);
				$('a.revision-schedule').attr('title', rvyObjEdit.scheduleDisabledTitle);
			} else {
				$('button.revision-approve').prop('disabled', false);
				$('button.revision-schedule').prop('disabled', false);
				$('a.revision-approve').attr('title', rvyObjEdit.actionTitle);
				$('a.revision-schedule').attr('title', rvyObjEdit.scheduleTitle);
			}

			RvyRefreshScheduleButton();
		}
	}
	var RvyUIInterval = setInterval(RvySubmissionUI, 200);

	function RvyGetRandomInt(max) {
		return Math.floor(Math.random() * max);
	}

	var rvyCreationDisabled = false;

	$(document).on('click', 'div.postbox-container,div.acf-postbox', function() {
		rvyCreationDisabled = true;
		$('button.revision-approve').prop('disabled', 'disabled');
		$('button.revision-schedule').prop('disabled', 'disabled');
		$('a.revision-approve').attr('title', rvyObjEdit.actionDisabledTitle);
		$('a.revision-schedule').attr('title', rvyObjEdit.scheduleDisabledTitle);
	});

	var intSaveWatch = setInterval(() => {
		if (wp.data.select('core/editor').isSavingPost()) {
			rvyCreationDisabled = false;
			$('button.revision-approve').prop('disabled', false);
			$('button.revision-schedule').prop('disabled', false);
			$('a.revision-approve').attr('title', rvyObjEdit.actionTitle);
			$('a.revision-schedule').attr('title', rvyObjEdit.scheduleTitle);
		}
	}, 5000);

	$(document).on('click', 'button.editor-post-publish-button,button.editor-post-save-draft', function() {
		setInterval(() => {
			rvyCreationDisabled = false;
			$('button.revision-approve').prop('disabled', false);
			$('button.revision-schedule').prop('disabled', false);
			$('a.revision-approve').attr('title', rvyObjEdit.actionTitle);
			$('a.revision-schedule').attr('title', rvyObjEdit.scheduleTitle);
		}, 2000);
	});

	var rvyIsAutosaveStarted = false;
	var rvyIsAutosaveDone = false;

	$(document).on('click', 'button.revision-create', function() {
		if ($('a.revision-create').attr('disabled') || ($('button.revision-create').closest('a').attr('href') != 'javascript:void(0)')) {
			return;
		}

		$('button.revision-create').hide();
		$('div.revision-creating').show();
		$('div.revision-creating span.ppr-submission-spinner').css('visibility', 'visible');

		if (!wp.data.select('core/editor').isEditedPostDirty()) {
			rvyCreateCopy();
			return;
		}

		rvyIsAutosaveStarted = false;
		rvyIsAutosaveDone = false;

		wp.data.dispatch('core/editor').autosave();

		var tmrNoAutosave = setTimeout(() => {
			if (!rvyIsAutosaveStarted) {
				clearInterval(intAutosaveWatch);
				rvyCreateCopy();
			}
		}, 10000);

		var intAutosaveDoneWatch;

		var intAutosaveWatch = setInterval(() => {
			if (wp.data.select('core/editor').isAutosavingPost()) {
				rvyIsAutosaveStarted = true; 
				clearInterval(intAutosaveWatch);
				clearTimeout(tmrNoAutosave);

				var tmrAutosaveTimeout = setTimeout(() => {
					if (!rvyIsAutosaveDone) {
						clearInterval(intAutosaveWatch);
						rvyCreateCopy();
					}
				}, 10000);

				intAutosaveDoneWatch = setInterval(() => {
					if (!wp.data.select('core/editor').isAutosavingPost()) {
						rvyIsAutosaveDone = true;
						clearInterval(intAutosaveDoneWatch);
						clearTimeout(tmrAutosaveTimeout);
						rvyCreateCopy();
					}
				}, 100);
			}
		}, 100);
	});

	function rvyCreateCopy() {
		var revisionaryCreateDone = function () {
			$('.revision-create').hide();
			$('.revision-creating').hide();
			$('.revision-created').show();

			$('a.revision-approve span.spinner').css('visibility', 'hidden');

			if (rvyObjEdit.completedURL) {
				$('button.revision-created a.revision-preview').attr('href', rvyObjEdit.completedURL);
			} else {
				$('button.revision-created a.revision-preview').hide();
			}

			$('button.revision-created a.revision-edit').attr('href', rvyObjEdit.completedEditURL);
		}

		var revisionaryCreateError = function (data, txtStatus) {
			$('div.rvy-creation-ui').html(rvyObjEdit.errorCaption);
		}

		var data = {'rvy_ajax_field': 'create_revision', 'rvy_ajax_value': wp.data.select('core/editor').getCurrentPostId(), 'rvy_date_selection': RvyTimeSelection, 'nc': RvyGetRandomInt(99999999)};

		$.ajax({
			url: rvyObjEdit.ajaxurl,
			data: data,
			dataType: "html",
			success: revisionaryCreateDone,
			error: revisionaryCreateError
		});
	}

	$(document).on('click', 'button.revision-schedule', function() {
		if ($('a.revision-schedule').attr('disabled')) {
			return;
		}

		$('button.revision-schedule').prop('disabled', true);

		var revisionaryScheduleDone = function () {
			$('.revision-schedule').hide();
			$('.revision-scheduled').show();

			$('button.revision-scheduled a.revision-preview').attr('href', rvyObjEdit.scheduledURL);
			$('button.revision-scheduled a.revision-edit').attr('href', rvyObjEdit.scheduledEditURL);

			wp.data.dispatch('core/editor').editPost({date: wp.data.select('core/editor').getCurrentPostAttribute('date')});
		}

		var revisionaryScheduleError = function (data, txtStatus) {
			$('div.rvy-creation-ui').html(rvyObjEdit.errorCaption);
		}

		var data = {'rvy_ajax_field': 'create_scheduled_revision', 'rvy_ajax_value': wp.data.select('core/editor').getCurrentPostId(), 'rvy_date_selection': RvyTimeSelection, 'nc': RvyGetRandomInt(99999999)};

		$.ajax({
			url: rvyObjEdit.ajaxurl,
			data: data,
			dataType: "html",
			success: revisionaryScheduleDone,
			error: revisionaryScheduleError
		});
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
		var selectedDateHTML = $('button.edit-post-post-schedule__toggle').html();

		if (! /\d/.test(selectedDateHTML) || !rvyIsPublished) {

			selectedDateHTML = $('button.editor-post-schedule__dialog-toggle').html();

			if (! /\d/.test(selectedDateHTML) || !rvyIsPublished) {
				RvyTimeSelection = '';
				$('.rvy-creation-ui .revision-schedule').hide();
				$('.rvy-creation-ui .revision-scheduled').hide();
				$('.rvy-creation-ui .revision-creating').hide();
				$('.rvy-creation-ui .revision-created').hide();
				$('.rvy-creation-ui .revision-create').show();
				return;
			}
		}

		selectedDateHTML = wp.data.select('core/editor').getEditedPostAttribute('date');
		var selectedDate = new Date( selectedDateHTML );

		var currentDate = new Date();

		RvyTimeSelection = selectedDate.getTime();

		var tdiff = RvyTimeSelection - currentDate.getTime();

		RvyTimeSelection = RvyTimeSelection / 1000; // pass seconds to server

		if ((tdiff > 1000)) {
			RvySelectedFutureDate = true;

			$('.rvy-creation-ui .revision-create').hide();
			$('.rvy-creation-ui .revision-creating').hide();
			$('.rvy-creation-ui .revision-created').hide();
			$('.rvy-creation-ui .revision-scheduled').hide();
			$('.rvy-creation-ui .revision-schedule').show();

		} else {
			$('.rvy-creation-ui .revision-schedule').hide();
			$('.rvy-creation-ui .revision-scheduled').hide();
			$('.rvy-creation-ui .revision-created').hide();
			$('.rvy-creation-ui .revision-creating').hide();
			$('.rvy-creation-ui .revision-create').show();

			if ( tdiff <= 0 ) {
				if ( RvySelectedFutureDate ) { // If button isn't already recaptioned, don't mess with it or even query for it
					RvyTimeSelection = '';
				}
			}
		}
	}

	/**
	 *  Detect date selection by display, then closure of popup dialog
	 */
	var RvyDetectPublishOptionsDivClosureInterval = false;
	var RvyDetectPublishOptionsDiv = function() {
		if ( $('div.edit-post-post-schedule__dialog').length ) {
			clearInterval( RvyDetectPublishOptionsDivInterval );

			var RvyDetectPublishOptionsClosure = function() {
				if ( ! $('div.edit-post-post-schedule__dialog').length ) {
					clearInterval(RvyDetectPublishOptionsDivClosureInterval);
					RvyDetectPublishOptionsDivInterval = setInterval(RvyDetectPublishOptionsDiv, 500);

					RvyRefreshScheduleButton();
				}
			}
			RvyDetectPublishOptionsDivClosureInterval = setInterval(RvyDetectPublishOptionsClosure, 200);
		}

		if ( $('div.editor-post-schedule__dialog').length ) {
			clearInterval( RvyDetectPublishOptionsDivInterval );

			var RvyDetectPublishOptionsClosure = function() {
				if ( ! $('div.editor-post-schedule__dialog').length ) {
					clearInterval(RvyDetectPublishOptionsDivClosureInterval);
					RvyDetectPublishOptionsDivInterval = setInterval(RvyDetectPublishOptionsDiv, 500);

					RvyRefreshScheduleButton();
				}
			}
			RvyDetectPublishOptionsDivClosureInterval = setInterval(RvyDetectPublishOptionsClosure, 200);
		}
	}

    var RvyDetectPublishOptionsDivInterval = setInterval(RvyDetectPublishOptionsDiv, 500);
});
