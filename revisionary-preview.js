jQuery(document).ready( function($) {
			var $bar = $('#pp_revisions_top_bar');
			if (!$bar.length || $bar.attr('data-rvy-initialized')) return;
			$bar.attr('data-rvy-initialized', '1');
			$bar.on('click', function(event) { event.stopPropagation(); });
			// SiteOrigin renders its Live Editor around a frontend preview iframe.
			// Run the standard Submit action in the outer editor so its redirect
			// returns the whole editor to the revision instead of replacing only
			// the preview canvas with wp-admin.
			function isSiteOriginEditor() {
				try {
					var urls = [window.location.href, window.top.location.href];
					return urls.some(function(url) {
						var query = new URL(url, window.location.href).searchParams;
						return query.has('so_live_editor') || query.has('siteorigin_panels_live_editor');
					});
				} catch (error) { return false; }
			}
			$bar.on('click', 'a.rvy-submit-revision', function(event) {
				if (!isSiteOriginEditor() || window.top === window) return;
				event.preventDefault();
				window.top.location.href = this.href;
			});
            // The outer SiteOrigin editor owns the persistent standard bar.
            // Its preview can be replaced with about:blank or an error document.
            try {
                if (window.parent !== window && window.parent.document.querySelector('#pp_revisions_top_bar[data-rvy-editor-shell="siteorigin"]')) {
                    $bar.remove();
                    return;
                }
            } catch (error) {}
			var rvyAdminBarMenuZindex = 100001;
			var $previewMessage = $('#pp_revisions_top_bar .rvy_preview_msgspan');

			if ($previewMessage.length) {
				var compactCaption = $previewMessage.attr('data-compact-caption') || '';
				var fullCaption = '';
				$previewMessage.contents().filter(function() {
					return this.nodeType === 3;
				}).each(function() {
					if (!fullCaption && $.trim(this.nodeValue)) {
						fullCaption = $.trim(this.nodeValue);
					}
					$(this).remove();
				});

				$previewMessage.find('span.rvy-preview-link').remove();
				$previewMessage.prepend(
					$('<span class="rvy-preview-caption rvy-preview-caption-wide"></span>').text(fullCaption),
					$('<span class="rvy-preview-caption rvy-preview-caption-compact"></span>').text(compactCaption || fullCaption)
				);

				var $compare = $previewMessage.find('a[target="_revision_diff"]').addClass('rvy-preview-compare components-button is-secondary ppr-purple-button');
				var $primaryActions = $previewMessage.find('a.rvy-submit-revision, a.rvy-approve-revision, a.button-primary');
				var $menuActions = $previewMessage.find('a').not($compare).not($primaryActions);
				var $menuCompare = $compare.clone().removeClass('rvy-preview-compare rvy-preview-link rvy_has_empty_spacing components-button is-secondary ppr-purple-button').addClass('rvy-preview-menu-item');
				var $menu = $('<span class="rvy-preview-actions"></span>');
				var $menuButton = $('<button type="button" class="rvy-preview-actions-toggle" aria-expanded="false" aria-label="More revision actions"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M4 6.5h16v2H4zM4 11h16v2H4zM4 15.5h16v2H4z"></path></svg></button>');
				$menuButton.attr('aria-label', $bar.attr('data-menu-label') || 'More revision actions');
				var $menuPanel = $('<span class="rvy-preview-actions-menu" hidden></span>');
				var primarySlots = [];
				var primaryClasses = [];

				$menuActions.each(function() {
					$(this).removeClass('button button-primary button-secondary rvy-preview-link rvy_has_empty_spacing').addClass('rvy-preview-menu-item').appendTo($menuPanel);
				});
				$primaryActions.each(function(index) {
					var $slot = $('<span class="rvy-preview-primary-slot"></span>');
					primaryClasses[index] = $(this).attr('class') || '';
					$(this).before($slot);
					primarySlots[index] = $slot;
				});

				if ($menuActions.length || $menuCompare.length || $primaryActions.length) {
					$menu.append($menuButton, $menuPanel);
					if ($primaryActions.length) {
						$menu.insertBefore(primarySlots[0]);
					} else if ($compare.length) {
						$menu.insertAfter($compare.last());
					} else {
						$previewMessage.append($menu);
					}
				}

				function closeRevisionActions() {
					$menuButton.attr('aria-expanded', 'false');
					$menuPanel.attr('hidden', 'hidden');
				}

				function updateRevisionActionsLayout() {
					var narrowest = window.matchMedia('(max-width: 560px)').matches;
					$menuCompare.detach();
					if ($compare.length && !$compare.is(':visible')) {
						$menuCompare.appendTo($menuPanel);
					}
					$primaryActions.each(function(index) {
						if (narrowest) {
							$(this).removeClass('button button-primary button-secondary').addClass('rvy-preview-menu-item rvy-preview-menu-primary').appendTo($menuPanel);
						} else {
							$(this).attr('class', primaryClasses[index]).removeAttr('role').insertAfter(primarySlots[index]);
						}
					});
					$menu.toggle(0 < $menuPanel.children('a.rvy-preview-menu-item').length);
					closeRevisionActions();
				}

				$menuButton.on('click', function(event) {
					event.stopPropagation();
					var expanded = 'true' === $menuButton.attr('aria-expanded');
					$menuButton.attr('aria-expanded', expanded ? 'false' : 'true');
					$menuPanel.attr('hidden', expanded ? 'hidden' : null);
				});
				$(document).on('click', closeRevisionActions).on('keydown', function(event) {
					if ('Escape' === event.key) {
						var focusInMenu = $menuPanel.has(document.activeElement).length > 0;
						closeRevisionActions();
						if (focusInMenu) $menuButton.trigger('focus');
					}
				});
				$(window).on('resize', updateRevisionActionsLayout);
				updateRevisionActionsLayout();
			}

			// Relative bars still receive concise controls; only positioning is skipped.
			if ($bar.attr('data-position-bar') === 'relative') return;

			if ($('#wpadminbar').length) {
				var rvyAdminBarHeight = $('#wpadminbar').height();
				var barZ = parseInt($('#wpadminbar').css('z-index'));
				if (barZ > rvyAdminBarMenuZindex) {
					rvyAdminBarMenuZindex = barZ;
				}
			} else {
				if ($bar.attr('data-legacy-divi-offset') === '1') {
					var rvyAdminBarHeight = 32;
				} else {
					var rvyAdminBarHeight = 0;
				}
			}

			$('div.rvy_view_revision').css('position', 'fixed').css('top', '32px');

			var rvyTotalHeight = $('div.rvy_view_revision').height() + rvyAdminBarHeight;
			var rvyTopBarZindex = $('div.rvy_view_revision').css('z-index');
			var rvyAdminBarZindex = $('#wpadminbar').css('z-index');
			var rvyOtherElemZindex = 0;

			$('div.rvy_view_revision').css('top', rvyAdminBarHeight);

			$('body').css('padding-top', $('div.rvy_view_revision').height());

			// SeedProd renders its page inside #sp-page. Some templates position
			// that root independently of the body offset, so ensure it clears the
			// fixed revision bar without adding padding when the normal offset works.
			var $seedprodPage = $('#sp-page');
			if ($seedprodPage.length) {
				// SeedProd's canvas can position inner content relative to the viewport,
				// so body padding alone may leave it underneath the admin bar + revision bar.
				var seedprodAdminBarHeight = $('#wpadminbar').length ? $('#wpadminbar').outerHeight() : 0;
				var seedprodBasePadding = parseFloat($seedprodPage.css('padding-top')) || 0;
				var offsetSeedprodPage = function() {
					seedprodAdminBarHeight = $('#wpadminbar').length ? $('#wpadminbar').outerHeight() : 0;
					$seedprodPage[0].style.setProperty('padding-top', (seedprodBasePadding + seedprodAdminBarHeight) + 'px', 'important');
				};
				offsetSeedprodPage();
				$(window).on('resize', offsetSeedprodPage);
				if (typeof ResizeObserver !== 'undefined' && $('#wpadminbar').length) {
					new ResizeObserver(offsetSeedprodPage).observe($('#wpadminbar')[0]);
				}
			}

			$('header,div').each(function(i,e) {
				if ($(this).css('position') == 'fixed' && ($(this).attr('id') != 'wpadminbar') && (!$(this).hasClass('rvy_view_revision'))) {
					if ($(this).position().top < rvyTotalHeight ) {
						rvyOtherElemZindex = parseInt($(this).css('z-index'));

						if (rvyOtherElemZindex >= rvyAdminBarMenuZindex) {
							rvyOtherElemZindex = rvyAdminBarMenuZindex - 1;
						}

						if (rvyOtherElemZindex >= rvyTopBarZindex) {
							rvyTopBarZindex = rvyOtherElemZindex + 1;

							if (rvyTopBarZindex > rvyAdminBarZindex) {
								rvyTopBarZindex = rvyAdminBarZindex - 1;
							}

							$('div.rvy_view_revision').css('z-index', rvyTopBarZindex);
						}

						$(this).css('padding-top', rvyTotalHeight.toString() + 'px');

						return false;
					}
				}
			});
		});
