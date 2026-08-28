(function () {
	'use strict';

	const config = window.VisualPostCompare;
	if (!config || !window.wp) {
		return;
	}

	const { editor, element, i18n, plugins } = wp;
	const { PluginDocumentSettingPanel } = editor;
	const { createElement: el, Fragment, useEffect } = element;
	const { __, sprintf } = i18n;

	const PANEL_NAMESPACE = 'visual-post-compare';
	const HEADER_SETTINGS_SELECTORS = ['div.edit-post-header__settings', 'div.editor-header__settings'];
	const PAST_REVISIONS_SIDEBAR_KEY = 'compare-past-revision';
	const NEW_REVISIONS_SIDEBAR_PRIORITY = ['compare-pending-revision', 'compare-future-revision'];

	function ComparisonSidebar({ sidebar }) {
		const posts = Array.isArray(sidebar.posts) ? sidebar.posts : [];

		return el(
			PluginDocumentSettingPanel,
			{
				name: sidebar.key,
				title: sidebar.label,
				className: 'visual-post-compare-panel visual-post-compare-panel--' + sidebar.key,
			},
			posts.length
				? el(
					'div',
					{ className: 'visual-post-compare-panel__links' },
					posts.map((post) =>
						el(
							'div',
							{
								key: post.id,
								className: 'visual-post-compare-panel__link-row',
							},
							post.authorAvatar
								? el('img', {
									src: post.authorAvatar,
									alt: '',
									title: post.author || '',
									className: 'visual-post-compare-panel__avatar',
								})
								: null,
							el(
								'a',
								{
									href: post.url,
									target: '_blank',
									rel: 'noopener noreferrer',
									title: (config.debugMode) ? 'ID: ' + post.id : '',
									className: 'visual-post-compare-panel__link',
								},
								post.postDate
							)
						)
					)
				)
				: el('p', null, __('No comparison posts.', 'revisionary'))
		);
	}

	function hasComparisonPosts(sidebar) {
		return !!(sidebar && Array.isArray(sidebar.posts) && sidebar.posts.length);
	}

	function sortNewRevisionSidebars(a, b) {
		const aIndex = NEW_REVISIONS_SIDEBAR_PRIORITY.indexOf(a.key);
		const bIndex = NEW_REVISIONS_SIDEBAR_PRIORITY.indexOf(b.key);
		const aPriority = aIndex === -1 ? Number.MAX_SAFE_INTEGER : aIndex;
		const bPriority = bIndex === -1 ? Number.MAX_SAFE_INTEGER : bIndex;

		return aPriority - bPriority;
	}

	function getComparisonSidebars() {
		return Array.isArray(config.comparisonSidebars) ? config.comparisonSidebars : [];
	}

	function getToolbarButtonConfigs() {
		const sidebars = getComparisonSidebars().filter(hasComparisonPosts);
		const pastSidebar = sidebars.find((sidebar) => sidebar.key === PAST_REVISIONS_SIDEBAR_KEY);
		const newRevisionSidebars = sidebars
			.filter((sidebar) => sidebar.key !== PAST_REVISIONS_SIDEBAR_KEY)
			.sort(sortNewRevisionSidebars);
		const buttons = [];

		if (pastSidebar) {
			const lastUpdated = config.lastUpdated || {};
			const lastUpdatedLabel = lastUpdated.label || __('Last updated', 'revisionary');
			const lastUpdatedTitle = lastUpdated.title || '';
			const revisionsUrl = lastUpdated.revisionsUrl || '';

			buttons.push({
				key: 'past',
				sidebarKey: pastSidebar.key,
				label: pastSidebar.label,
				title: lastUpdatedTitle
					? sprintf(__('Open %1$s. %2$s', 'revisionary'), pastSidebar.label, lastUpdatedTitle)
					: sprintf(__('Open %s', 'revisionary'), pastSidebar.label),
				iconClass: 'dashicons-backup',
				count: pastSidebar.posts.length,
				url: revisionsUrl,
				metaLabel: lastUpdatedLabel,
			});
		}

		if (newRevisionSidebars.length) {
			const primarySidebar = newRevisionSidebars[0];
			const totalCount = newRevisionSidebars.reduce((count, sidebar) => count + sidebar.posts.length, 0);
			const label = (newRevisionSidebars.length > 1) ? __('New Revisions', 'revisionary') : primarySidebar.label;
			const iconClass = ('compare-future-revision' === primarySidebar.key) ? 'dashicons-clock' : 'dashicons-edit';

			buttons.push({
				key: 'new',
				sidebarKey: primarySidebar.key,
				label,
				title: sprintf(__('Open %s', 'revisionary'), label),
				iconClass,
				count: totalCount,
			});
		}

		return buttons;
	}

	function getPanelName(sidebarKey) {
		return PANEL_NAMESPACE + '/' + sidebarKey;
	}

	function getHeaderSettings() {
		for (const selector of HEADER_SETTINGS_SELECTORS) {
			const headerSettings = document.querySelector(selector);

			if (headerSettings) {
				return headerSettings;
			}
		}

		return null;
	}

	function openComparisonSidebar(sidebarKey) {
		if (!sidebarKey || !wp.data || !wp.data.dispatch) {
			return;
		}

		const editPostDispatch = wp.data.dispatch('core/edit-post');
		const editorDispatch = wp.data.dispatch('core/editor');
		const editorSelect = (wp.data.select) ? wp.data.select('core/editor') : null;
		const panelName = getPanelName(sidebarKey);

		if (editPostDispatch && typeof editPostDispatch.openGeneralSidebar === 'function') {
			editPostDispatch.openGeneralSidebar('edit-post/document');
		}

		if (editorDispatch && typeof editorDispatch.toggleEditorPanelOpened === 'function') {
			const isPanelOpen = !!(editorSelect && typeof editorSelect.isEditorPanelOpened === 'function' && editorSelect.isEditorPanelOpened(panelName));

			if (!isPanelOpen) {
				editorDispatch.toggleEditorPanelOpened(panelName);
			}
		}

		window.setTimeout(function () {
			const sidebarPanel = document.querySelector('.visual-post-compare-panel--' + sidebarKey);

			if (sidebarPanel && typeof sidebarPanel.scrollIntoView === 'function') {
				try {
					sidebarPanel.scrollIntoView({
						behavior: 'smooth',
						block: 'nearest',
					});
				} catch (error) {
					sidebarPanel.scrollIntoView();
				}
			}
		}, 150);
	}

	function createToolbarButton(buttonConfig) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'components-button visual-post-compare-toolbar__button visual-post-compare-toolbar__button--' + buttonConfig.key;
		button.setAttribute('aria-label', buttonConfig.title);
		button.title = buttonConfig.title;
		button.addEventListener('click', function (event) {
			event.preventDefault();

			if (buttonConfig.url) {
				window.location.href = buttonConfig.url;
				return;
			}

			openComparisonSidebar(buttonConfig.sidebarKey);
		});

		const icon = document.createElement('span');
		icon.className = 'dashicons ' + buttonConfig.iconClass + ' visual-post-compare-toolbar__icon';
		icon.setAttribute('aria-hidden', 'true');
		button.appendChild(icon);

		if (buttonConfig.count) {
			const count = document.createElement('span');
			count.className = 'visual-post-compare-toolbar__count';
			count.textContent = String(buttonConfig.count);
			count.setAttribute('aria-hidden', 'true');
			button.appendChild(count);
		}

		if (buttonConfig.metaLabel) {
			const metaLabel = document.createElement('span');
			metaLabel.className = 'visual-post-compare-toolbar__meta';
			metaLabel.textContent = buttonConfig.metaLabel;
			button.appendChild(metaLabel);
		}

		const screenReaderLabel = document.createElement('span');
		screenReaderLabel.className = 'screen-reader-text';
		screenReaderLabel.textContent = buttonConfig.label;
		button.appendChild(screenReaderLabel);

		return button;
	}

	function ensureToolbarButtons() {
		const headerSettings = getHeaderSettings();
		const toolbarButtons = getToolbarButtonConfigs();

		if (!headerSettings || !toolbarButtons.length) {
			return;
		}

		let toolbar = headerSettings.querySelector('.visual-post-compare-toolbar');

		if (!toolbar) {
			toolbar = document.createElement('div');
			toolbar.className = 'visual-post-compare-toolbar';
			headerSettings.insertBefore(toolbar, headerSettings.firstChild);
		}

		const buttonKeys = toolbarButtons
			.map((button) => button.key + ':' + button.count + ':' + (button.url || '') + ':' + (button.metaLabel || ''))
			.join(',');

		if (toolbar.getAttribute('data-button-keys') === buttonKeys) {
			return;
		}

		toolbar.setAttribute('data-button-keys', buttonKeys);

		while (toolbar.firstChild) {
			toolbar.removeChild(toolbar.firstChild);
		}

		toolbarButtons.forEach((buttonConfig) => {
			toolbar.appendChild(createToolbarButton(buttonConfig));
		});
	}

	function ToolbarButtons() {
		useEffect(() => {
			ensureToolbarButtons();

			const toolbarInterval = window.setInterval(ensureToolbarButtons, 500);

			return () => window.clearInterval(toolbarInterval);
		}, []);

		return null;
	}

	function ComparisonPanels() {
		const sidebars = getComparisonSidebars();
		return el(
			Fragment,
			null,
			sidebars.map((sidebar) => el(ComparisonSidebar, { key: sidebar.key, sidebar })),
			el(ToolbarButtons)
		);
	}

	plugins.registerPlugin('visual-post-compare', { render: ComparisonPanels });
})();
