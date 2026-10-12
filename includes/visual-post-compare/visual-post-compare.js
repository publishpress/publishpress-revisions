(function () {
	'use strict';

	const config = window.VisualPostCompare;
	if (!config || !window.wp) {
		return;
	}

	const { editor, element, i18n, plugins } = wp;
	const { PluginDocumentSettingPanel } = editor;
	const { createElement: el, Fragment, useEffect, useState } = element;
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
		const [sidebars, setSidebars] = useState(Array.isArray(config.comparisonSidebars) ? config.comparisonSidebars : []);
		useEffect(() => {
			let active = true;
			let pending = false;
			let queued = false;
			let postId = config.currentPostId;
			const editorState = () => wp.data.select('core/editor');
			let saving = !!editorState()?.isSavingPost();
			const refresh = () => {
				if (!active || document.hidden || !postId || !config.sidebarsRestPath) return;
				if (pending) { queued = true; return; }
				pending = true;
				const requestedPostId = postId;
				wp.apiFetch({ path: config.sidebarsRestPath + '?post_id=' + encodeURIComponent(requestedPostId) })
					.then((result) => {
						if (active && requestedPostId === postId && Array.isArray(result)) setSidebars(result);
					})
					.catch(() => {})
					.finally(() => {
						pending = false;
						if (active && queued) { queued = false; refresh(); }
					});
			};
			const unsubscribe = wp.data.subscribe(() => {
				const state = editorState();
				const nextId = state?.getCurrentPostId() || config.currentPostId;
				const nextSaving = !!state?.isSavingPost();
				const changedPost = nextId !== postId;
				const saved = saving && !nextSaving;
				postId = nextId;
				saving = nextSaving;
				if (changedPost || saved) refresh();
			});
			window.addEventListener('focus', refresh);
			window.addEventListener('revisionary:revision-created', refresh);
			document.addEventListener('visibilitychange', refresh);
			// Revisions may be submitted or scheduled in another editor window.
			const timer = window.setInterval(() => { if (!saving) refresh(); }, 30000);
			refresh();
			return () => {
				active = false;
				unsubscribe();
				window.clearInterval(timer);
				window.removeEventListener('focus', refresh);
				window.removeEventListener('revisionary:revision-created', refresh);
				document.removeEventListener('visibilitychange', refresh);
			};
		}, []);
		return el(
			Fragment,
			null,
			sidebars.filter((sidebar) => Array.isArray(sidebar.posts) && sidebar.posts.length).map((sidebar) => el(ComparisonSidebar, { key: sidebar.key, sidebar }))
		);
	}

	plugins.registerPlugin('visual-post-compare', { render: ComparisonPanels });
})();
