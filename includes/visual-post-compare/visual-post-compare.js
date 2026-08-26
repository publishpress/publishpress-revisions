(function () {
	'use strict';

	const config = window.VisualPostCompare;
	if (!config || !window.wp) {
		return;
	}

	const { editor, element, i18n, plugins } = wp;
	const { PluginDocumentSettingPanel } = editor;
	const { createElement: el, Fragment } = element;
	const { __ } = i18n;

	function ComparisonSidebar({ sidebar }) {
		const posts = Array.isArray(sidebar.posts) ? sidebar.posts : [];

		return el(
			PluginDocumentSettingPanel,
			{
				name: sidebar.key,
				title: sidebar.label,
				className: 'visual-post-compare-panel',
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
				: el('p', null, __('No comparison posts.', 'visual-post-compare'))
		);
	}

	function ComparisonPanels() {
		const sidebars = Array.isArray(config.comparisonSidebars) ? config.comparisonSidebars : [];
		return el(
			Fragment,
			null,
			sidebars.map((sidebar) => el(ComparisonSidebar, { key: sidebar.key, sidebar }))
		);
	}

	plugins.registerPlugin('visual-post-compare', { render: ComparisonPanels });
})();
