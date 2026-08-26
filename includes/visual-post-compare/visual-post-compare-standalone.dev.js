(function () {
	'use strict';

	const config = window.VisualPostCompareStandalone;
	if (!config || !window.wp) {
		return;
	}

	const {
		apiFetch,
		blockEditor,
		blockLibrary,
		blockSerializationDefaultParser,
		blocks,
		components,
		element,
		hooks,
		i18n,
		privateApis,
		richText,
	} = wp;
	const { __experimentalUseBlockPreview: useBlockPreview } = blockEditor;
	const { Button, Notice, Spinner } = components;
	const { createElement: el, useEffect, useMemo, useRef, useState } = element;
	const { __, sprintf } = i18n;
	const { RichTextData, applyFormat, concat, create, getFormatType, registerFormatType, slice } = richText;

	const CONSENT = 'I acknowledge private features are not for use in themes or plugins and doing so will break in the next version of WordPress.';
	let parseRawBlock = null;

	try {
		const { unlock } = privateApis.__dangerousOptInToUnstableAPIsOnlyForCoreModules(
			CONSENT,
			'@wordpress/editor'
		);
		const unlockedBlocks = unlock(blocks.privateApis);
		parseRawBlock = unlockedBlocks && unlockedBlocks.parseRawBlock;
	} catch (error) {
		console.error('Visual Post Compare: Unable to unlock parseRawBlock().', error);
	}

	const grammarParse = blockSerializationDefaultParser && blockSerializationDefaultParser.parse;
	let coreBlocksRegistered = false;

	/**
	 * The block-library script exposes block definitions, but loading that script on
	 * a custom wp-admin screen does not register the definitions with @wordpress/blocks.
	 * parseRawBlock() returns undefined for an unknown block type, which previously
	 * caused diffRevisionContent() to filter every block out of the result.
	 */
	function ensureCoreBlocksRegistered() {
		if (coreBlocksRegistered || blocks.getBlockType('core/paragraph')) {
			coreBlocksRegistered = true;
			return;
		}

		if (!blockLibrary || typeof blockLibrary.registerCoreBlocks !== 'function') {
			throw new Error(
				__('WordPress core block definitions are unavailable on this screen.', 'revisionary')
			);
		}

		blockLibrary.registerCoreBlocks();

		if (!blocks.getBlockType('core/paragraph')) {
			throw new Error(
				__('WordPress core blocks could not be registered for comparison.', 'revisionary')
			);
		}

		coreBlocksRegistered = true;
	}


	const REVISION_REMOVED_FILTER_SVG = `
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 0" width="0" height="0"
			focusable="false" role="none" aria-hidden="true"
			style="visibility:hidden;position:absolute;left:-9999px;overflow:hidden">
			<defs>
				<filter id="revision-removed-filter" x="0" y="0" width="100%" height="100%">
					<feColorMatrix type="matrix"
						values="0.5 0.3 0.2 0 0.15
						        0.2 0.2 0.1 0 0
						        0.2 0.2 0.1 0 0
						        0   0   0   0.8 0"/>
				</filter>
			</defs>
		</svg>`;

	// Keep these selectors identical to WordPress 7.0's RevisionsCanvas.
	const REVISION_DIFF_STYLES = `
		.is-revision-added {
			box-shadow: inset 0 0 0 9999px color-mix(in srgb, currentColor 5%, #00a32a 15%), 0 0 0 4px color-mix(in srgb, currentColor 5%, #00a32a 15%);
			outline: 3px solid #00a32a;
			outline-offset: 2px;
		}
		.is-revision-removed,
		.revision-diff-removed {
			text-decoration: line-through;
			filter: url(#revision-removed-filter);
		}
		.is-revision-removed {
			outline: 3px dashed #d63638;
			outline-offset: 2px;
		}
		.is-revision-modified {
			outline: 3px dotted #9a7000 !important;
			outline-offset: 2px;
		}
		.revision-diff-added {
			background-color: color-mix(in srgb, currentColor 5%, #00a32a 15%);
			text-decoration: none;
		}
		.revision-diff-format-added {
			text-decoration: underline wavy color-mix(in srgb, currentColor 30%, #00a32a 70%);
			text-decoration-thickness: 2px;
		}
		.revision-diff-format-removed {
			text-decoration: underline wavy color-mix(in srgb, currentColor 20%, #d63638 80%);
			text-decoration-thickness: 2px;
		}
		.revision-diff-format-changed {
			text-decoration: underline wavy color-mix(in srgb, currentColor 30%, #dba617 70%);
			text-decoration-thickness: 2px;
		}
	`;

	function withRevisionDiffClasses(BlockListBlock) {
		return function RevisionDiffBlockListBlock(props) {
			const diffStatus = props.block && props.block.__revisionDiffStatus;
			const status = diffStatus && diffStatus.status;
			let revisionClass = '';

			if (status === 'added') {
				revisionClass = 'is-revision-added';
			} else if (status === 'removed') {
				revisionClass = 'is-revision-removed';
			} else if (status === 'modified') {
				revisionClass = 'is-revision-modified';
			}

			return el(BlockListBlock, {
				...props,
				className: [props.className, revisionClass].filter(Boolean).join(' '),
			});
		};
	}

	hooks.addFilter(
		'editor.BlockListBlock',
		'visual-post-compare/revision-diff-classes',
		withRevisionDiffClasses
	);

	function ensureRevisionFormats() {
		[
			['revision/diff-removed', __('Removed', 'revisionary'), 'del', 'revision-diff-removed'],
			['revision/diff-added', __('Added', 'revisionary'), 'ins', 'revision-diff-added'],
			['revision/diff-format-added', __('Format added', 'revisionary'), 'span', 'revision-diff-format-added'],
			['revision/diff-format-removed', __('Format removed', 'revisionary'), 'span', 'revision-diff-format-removed'],
			['revision/diff-format-changed', __('Format changed', 'revisionary'), 'span', 'revision-diff-format-changed'],
		].forEach(([name, title, tagName, className]) => {
			if (getFormatType && getFormatType(name)) {
				return;
			}
			try {
				registerFormatType(name, {
					title,
					tagName,
					className,
					attributes: { title: 'title' },
					edit: () => null,
				});
			} catch (error) {
				// Core or another module may already have registered the format.
			}
		});
	}

	ensureRevisionFormats();

	function tokenize(value) {
		return String(value || '').match(/\s+|[\p{L}\p{N}_]+|[^\s\p{L}\p{N}_]+/gu) || [];
	}

	function diffTokens(previousTokens, currentTokens) {
		const rows = previousTokens.length + 1;
		const cols = currentTokens.length + 1;
		const table = Array.from({ length: rows }, () => new Uint32Array(cols));

		for (let i = previousTokens.length - 1; i >= 0; i--) {
			for (let j = currentTokens.length - 1; j >= 0; j--) {
				table[i][j] = previousTokens[i] === currentTokens[j]
					? table[i + 1][j + 1] + 1
					: Math.max(table[i + 1][j], table[i][j + 1]);
			}
		}

		const parts = [];
		let i = 0;
		let j = 0;
		const push = (type, value) => {
			if (!value) return;
			const last = parts[parts.length - 1];
			if (last && last.type === type) {
				last.value += value;
			} else {
				parts.push({ type, value });
			}
		};

		while (i < previousTokens.length || j < currentTokens.length) {
			if (i < previousTokens.length && j < currentTokens.length && previousTokens[i] === currentTokens[j]) {
				push('unchanged', currentTokens[j]);
				i++;
				j++;
			} else if (j < currentTokens.length && (i >= previousTokens.length || table[i][j + 1] >= table[i + 1][j])) {
				push('added', currentTokens[j++]);
			} else if (i < previousTokens.length) {
				push('removed', previousTokens[i++]);
			}
		}

		return parts;
	}

	function applyRichTextDiff(currentRichText, previousRichText) {
		const currentText = currentRichText.toPlainText();
		const previousText = previousRichText.toPlainText();
		const textDiff = diffTokens(tokenize(previousText), tokenize(currentText));
		let result = create({ text: '' });
		let currentIndex = 0;
		let previousIndex = 0;

		textDiff.forEach((part) => {
			const length = part.value.length;
			if (part.type === 'removed') {
				const removed = slice(previousRichText, previousIndex, previousIndex + length);
				result = concat(result, applyFormat(removed, {
					type: 'revision/diff-removed',
					attributes: {
						title: __('Removed', 'revisionary'),
					},
				}, 0, length));
				previousIndex += length;
			} else if (part.type === 'added') {
				const added = slice(currentRichText, currentIndex, currentIndex + length);
				result = concat(result, applyFormat(added, {
					type: 'revision/diff-added',
					attributes: {
						title: __('Added', 'revisionary'),
					},
				}, 0, length));
				currentIndex += length;
			} else {
				result = concat(result, slice(currentRichText, currentIndex, currentIndex + length));
				currentIndex += length;
				previousIndex += length;
			}
		});

		return new RichTextData(result);
	}

	function rawSignature(rawBlock) {
		return JSON.stringify({
			name: rawBlock.blockName,
			attrs: rawBlock.attrs,
			html: (rawBlock.innerContent || []).filter(
				(value) => value !== null && String(value).trim() !== ''
			),
		});
	}

	function plainTextFromRaw(rawBlock) {
		const html = rawBlock && rawBlock.innerHTML ? rawBlock.innerHTML : '';
		const node = document.createElement('div');
		node.innerHTML = html;
		return node.textContent || '';
	}

	function textSimilarity(a, b) {
		if (!a && !b) return 1;
		if (!a || !b) return 0;
		const aWords = tokenize(a).filter((token) => /[\p{L}\p{N}]/u.test(token));
		const bWords = tokenize(b).filter((token) => /[\p{L}\p{N}]/u.test(token));
		if (!aWords.length && !bWords.length) return 1;
		const set = new Set(aWords);
		let matches = 0;
		bWords.forEach((word) => {
			if (set.has(word)) matches++;
		});
		return matches / Math.max(aWords.length, bWords.length, 1);
	}

	function pairSimilarBlocks(rawBlocks) {
		const removed = [];
		const added = [];
		rawBlocks.forEach((block, index) => {
			const status = block.__revisionDiffStatus && block.__revisionDiffStatus.status;
			if (status === 'removed') removed.push({ block, index });
			if (status === 'added') added.push({ block, index });
		});

		if (!removed.length || !added.length) return rawBlocks;

		const usedRemoved = new Set();
		const usedAdded = new Set();
		const replacements = new Map();

		removed.forEach((rem) => {
			let best = null;
			let bestScore = -1;
			const sameTypeAdded = added.filter((candidate) =>
				!usedAdded.has(candidate.index) && candidate.block.blockName === rem.block.blockName
			);
			const sameTypeRemoved = removed.filter((candidate) => candidate.block.blockName === rem.block.blockName);

			sameTypeAdded.forEach((candidate) => {
				const score = textSimilarity(plainTextFromRaw(rem.block), plainTextFromRaw(candidate.block));
				const unambiguous = sameTypeAdded.length === 1 && sameTypeRemoved.length === 1;
				if ((unambiguous || score >= 0.5) && score > bestScore) {
					best = candidate;
					bestScore = score;
				}
			});

			if (best) {
				usedRemoved.add(rem.index);
				usedAdded.add(best.index);
				replacements.set(best.index, {
					...best.block,
					__revisionDiffStatus: { status: 'modified' },
					__previousRawBlock: rem.block,
				});
			}
		});

		return rawBlocks.map((block, index) => {
			if (replacements.has(index)) return replacements.get(index);
			if (usedRemoved.has(index) || usedAdded.has(index)) return null;
			return block;
		}).filter(Boolean);
	}

	function diffRawBlocks(currentRaw, previousRaw) {
		const currentSignatures = currentRaw.map(rawSignature);
		const previousSignatures = previousRaw.map(rawSignature);
		const rows = previousRaw.length + 1;
		const cols = currentRaw.length + 1;
		const table = Array.from({ length: rows }, () => new Uint32Array(cols));

		for (let i = previousRaw.length - 1; i >= 0; i--) {
			for (let j = currentRaw.length - 1; j >= 0; j--) {
				table[i][j] = previousSignatures[i] === currentSignatures[j]
					? table[i + 1][j + 1] + 1
					: Math.max(table[i + 1][j], table[i][j + 1]);
			}
		}

		const result = [];
		let i = 0;
		let j = 0;
		while (i < previousRaw.length || j < currentRaw.length) {
			if (i < previousRaw.length && j < currentRaw.length && previousSignatures[i] === currentSignatures[j]) {
				const current = currentRaw[j++];
				const previous = previousRaw[i++];
				result.push({
					...current,
					innerBlocks: diffRawBlocks(current.innerBlocks || [], previous.innerBlocks || []),
				});
			} else if (j < currentRaw.length && (i >= previousRaw.length || table[i][j + 1] >= table[i + 1][j])) {
				result.push({ ...currentRaw[j++], __revisionDiffStatus: { status: 'added' } });
			} else if (i < previousRaw.length) {
				result.push({ ...previousRaw[i++], __revisionDiffStatus: { status: 'removed' } });
			}
		}

		return pairSimilarBlocks(result);
	}

	function applyDiffToBlock(currentBlock, previousBlock, diffStatus) {
		const blockType = blocks.getBlockType(currentBlock.name);
		if (!blockType || !blockType.attributes) return;

		Object.entries(blockType.attributes).forEach(([attributeName, definition]) => {
			if (!definition || definition.source !== 'rich-text') return;
			const currentValue = currentBlock.attributes[attributeName];
			const previousValue = previousBlock.attributes[attributeName];
			if (currentValue instanceof RichTextData && previousValue instanceof RichTextData) {
				currentBlock.attributes[attributeName] = applyRichTextDiff(currentValue, previousValue);
			}
		});

		diffStatus.hasInlineTextDiff = true;
	}

	function applyDiffRecursively(parsedBlock, rawBlock) {
		if (rawBlock.__revisionDiffStatus) {
			if (rawBlock.__revisionDiffStatus.status === 'modified' && rawBlock.__previousRawBlock) {
				const previousParsed = parseRawBlock(rawBlock.__previousRawBlock);
				if (previousParsed) {
					applyDiffToBlock(parsedBlock, previousParsed, rawBlock.__revisionDiffStatus);
				}
			}
			parsedBlock.__revisionDiffStatus = rawBlock.__revisionDiffStatus;
			parsedBlock.attributes = parsedBlock.attributes || {};
			parsedBlock.attributes.__revisionDiffStatus = rawBlock.__revisionDiffStatus;
		}

		if (parsedBlock.innerBlocks && rawBlock.innerBlocks) {
			for (let index = 0; index < parsedBlock.innerBlocks.length; index++) {
				if (parsedBlock.innerBlocks[index] && rawBlock.innerBlocks[index]) {
					applyDiffRecursively(parsedBlock.innerBlocks[index], rawBlock.innerBlocks[index]);
				}
			}
		}
	}

	function diffRevisionContent(currentContent, previousContent) {
		if (typeof grammarParse !== 'function' || typeof parseRawBlock !== 'function') {
			throw new Error(__('WordPress 7.0 block revision parser APIs are unavailable.', 'revisionary'));
		}

		// A custom wp-admin page does not run the normal post-editor bootstrap.
		// Register the core block types before parseRawBlock() is asked to resolve
		// core/paragraph, core/heading, core/list, etc.
		ensureCoreBlocksRegistered();

		const currentRaw = grammarParse(currentContent || '');
		const previousRaw = grammarParse(previousContent || '');
		const mergedRaw = diffRawBlocks(currentRaw, previousRaw);

		const parsedBlocks = mergedRaw.map((rawBlock) => {
			// The grammar parser can emit whitespace-only freeform entries between
			// blocks. Core normally drops these; do the same here rather than turning
			// them into a missing/freeform block on the standalone screen.
			if (!rawBlock.blockName && !(rawBlock.innerHTML || '').trim()) {
				return null;
			}

			const parsed = parseRawBlock(rawBlock);
			if (parsed) {
				applyDiffRecursively(parsed, rawBlock);
			}
			return parsed;
		}).filter(Boolean);

		if (mergedRaw.some((rawBlock) => rawBlock.blockName) && parsedBlocks.length === 0) {
			throw new Error(
				__('The comparison was generated, but WordPress could not parse any comparison blocks.', 'revisionary')
			);
		}

		return parsedBlocks;
	}


	function scopeEditorCss(cssText, scopeSelector) {
		if (!cssText || !scopeSelector || typeof CSSStyleSheet === 'undefined') {
			return '';
		}

		let sheet;
		try {
			sheet = new CSSStyleSheet();
			sheet.replaceSync(cssText);
		} catch (error) {
			console.warn('Visual Post Compare: Unable to scope editor CSS.', error);
			return '';
		}

		const scopeSelectorList = (selectorText) => selectorText
			.split(',')
			.map((selector) => {
				selector = selector.trim();
				if (!selector) {
					return '';
				}

				if (selector === ':root' || selector === 'html' || selector === 'body' || selector === '.editor-styles-wrapper') {
					return scopeSelector;
				}

				selector = selector
					.replace(/^:root(?=\s|>|\+|~|\.|#|:|\[|$)/, scopeSelector)
					.replace(/^html(?=\s|>|\+|~|\.|#|:|\[|$)/, scopeSelector)
					.replace(/^body(?=\s|>|\+|~|\.|#|:|\[|$)/, scopeSelector)
					.replace(/^\.editor-styles-wrapper(?=\s|>|\+|~|\.|#|:|\[|$)/, scopeSelector);

				if (selector.indexOf(scopeSelector) === 0) {
					return selector;
				}

				return scopeSelector + ' ' + selector;
			})
			.filter(Boolean)
			.join(', ');

		const serializeRules = (rules) => Array.from(rules).map((rule) => {
			if (rule.type === CSSRule.STYLE_RULE) {
				const selectors = scopeSelectorList(rule.selectorText);
				return selectors ? selectors + '{' + rule.style.cssText + '}' : '';
			}

			if (rule.type === CSSRule.MEDIA_RULE || rule.type === CSSRule.SUPPORTS_RULE || rule.type === CSSRule.LAYER_BLOCK_RULE) {
				const inner = serializeRules(rule.cssRules);
				if (!inner) {
					return '';
				}
				const prefix = rule.cssText.slice(0, rule.cssText.indexOf('{')).trim();
				return prefix + '{' + inner + '}';
			}

			// @font-face and @keyframes are namespaced by identifiers rather than
			// selectors, so retaining them cannot restyle the surrounding admin UI.
			if (rule.type === CSSRule.FONT_FACE_RULE || rule.type === CSSRule.KEYFRAMES_RULE) {
				return rule.cssText;
			}

			return '';
		}).join('\n');

		return serializeRules(sheet.cssRules);
	}


	function ComparisonSlider({ comparison, onSelect }) {
		const presentation = comparison.presentation || {};
		const posts = Array.isArray(comparison.posts) ? comparison.posts : [];
		if (posts.length <= 2) {
			return null;
		}

		const selectedId = Number(comparison.selectedId || (comparison.to && comparison.to.id));
		const foundIndex = posts.findIndex((post) => Number(post.id) === selectedId);
		const selectedIndex = foundIndex >= 0 ? foundIndex : 0;
		const count = posts.length;
		const usePostDate = presentation.showPostDate === true;
		const [hasFocus, setHasFocus] = useState(false);

		const sliderDateLabel = (post) => usePostDate
			? (post.sliderPostDateLabel || post.postDateLabel || post.postDate)
			: (post.sliderModifiedLabel || post.modifiedLabel || post.modified);

		const tooltipPosition = posts.length > 1
			? (selectedIndex / (posts.length - 1)) * 100
			: 50;
		const tooltipTransform = selectedIndex === 0
			? 'translateX(0)'
			: (selectedIndex === posts.length - 1 ? 'translateX(-100%)' : 'translateX(-50%)');
		const tooltipArrowLeft = selectedIndex === 0
			? '8px'
			: (selectedIndex === posts.length - 1 ? 'calc(100% - 8px)' : '50%');
		const selectedPost = posts[selectedIndex] || null;

		return el(
			'div',
			{ className: 'visual-post-compare-revision__selector', style: { '--vpc-slider-count': count } },
			el(
				'div',
				{
					className: 'visual-post-compare-revision__range-wrap',
					style: { '--vpc-slider-intervals': Math.max(posts.length - 1, 1) },
				},
				el(
					'div',
					{ className: 'visual-post-compare-revision__segmented-track', 'aria-hidden': true },
					...Array.from({ length: Math.max(posts.length - 1, 1) }, (_, index) =>
						el('span', {
							key: 'track-segment-' + index,
							className: [
								'visual-post-compare-revision__track-segment',
								index < selectedIndex ? 'is-filled' : '',
							].filter(Boolean).join(' '),
						})
					)
				),
				el('input', {
					type: 'range',
					className: 'visual-post-compare-revision__range',
					min: 0,
					max: posts.length - 1,
					step: 1,
					value: selectedIndex,
					'aria-label': __('Select comparison post', 'revisionary'),
					onFocus: () => setHasFocus(true),
					onBlur: () => setHasFocus(false),
					onChange: (event) => {
						const post = posts[Number(event.target.value)];
						if (post) {
							onSelect(post);
						}
					},
				}),
				hasFocus && selectedPost
					? el(
						'div',
						{
							className: 'visual-post-compare-revision__slider-tooltip',
							role: 'tooltip',
							style: {
								left: tooltipPosition + '%',
								transform: tooltipTransform,
								'--vpc-tooltip-arrow-left': tooltipArrowLeft,
							},
						},
						sliderDateLabel(selectedPost)
					)
					: null
			)
		);
	}

	function RevisionPreview({ comparison, onApprove, isApproving }) {
		const canvasRef = useRef(null);
		const [diffMarkers, setDiffMarkers] = useState([]);
		const [hasCanvasOverflow, setHasCanvasOverflow] = useState(false);
		const diffResult = useMemo(() => {
			try {
				return {
					blocks: diffRevisionContent(comparison.to.content || '', comparison.from.content || ''),
					error: null,
				};
			} catch (error) {
				console.error('Visual Post Compare: Unable to build revision diff.', error);
				return { blocks: [], error };
			}
		}, [comparison]);

		// WordPress 7.0 exports this hook as __experimentalUseBlockPreview. It renders the block list in this document
		// instead of an auto-sized iframe. That matches the editor revisions canvas
		// more closely and prevents unchanged blocks from disappearing in width=0
		// preview iframes.
		const previewProps = useBlockPreview({
			blocks: diffResult.blocks,
			props: {
				className: 'editor-styles-wrapper is-root-container is-layout-constrained visual-post-compare-revision__live-preview',
			},
		});

		useEffect(() => {
			const canvas = canvasRef.current;
			if (!canvas) {
				setDiffMarkers([]);
				return undefined;
			}

			let frame = null;
			let resizeObserver = null;
			let mutationObserver = null;

			const scheduleUpdate = () => {
				if (frame !== null) {
					cancelAnimationFrame(frame);
				}

				frame = requestAnimationFrame(() => {
					frame = null;
					const preview = canvas.querySelector('.visual-post-compare-revision__live-preview');
					const hasOverflow = canvas.scrollHeight > canvas.clientHeight + 1;
					setHasCanvasOverflow(hasOverflow);

					if (!preview || !hasOverflow) {
						setDiffMarkers([]);
						return;
					}

					const selector = '.is-revision-added, .is-revision-removed, .is-revision-modified';
					const nodes = Array.from(preview.querySelectorAll(selector)).filter((node) => {
						const parentChanged = node.parentElement && node.parentElement.closest(selector);
						return !parentChanged || !preview.contains(parentChanged);
					});
					const canvasRect = canvas.getBoundingClientRect();
					const canvasTop = window.scrollY + canvasRect.top;
					const canvasHeight = Math.max(canvas.scrollHeight, canvas.clientHeight, 1);

					setDiffMarkers(nodes.map((node, index) => {
						const rect = node.getBoundingClientRect();
						const nodeTop = canvas.scrollTop + rect.top - canvasRect.top;
						const top = Math.max(0, Math.min(100, (nodeTop / canvasHeight) * 100));
						const height = Math.max(0.8, Math.min(100 - top, (rect.height / canvasHeight) * 100));
						let status = 'modified';
						if (node.classList.contains('is-revision-added')) {
							status = 'added';
						} else if (node.classList.contains('is-revision-removed')) {
							status = 'removed';
						}

						return { key: status + '-' + index, node, status, top, height };
					}));
				});
			};

			scheduleUpdate();
			window.addEventListener('resize', scheduleUpdate);

			if (typeof ResizeObserver !== 'undefined') {
				resizeObserver = new ResizeObserver(scheduleUpdate);
				resizeObserver.observe(canvas);
			}

			if (typeof MutationObserver !== 'undefined') {
				mutationObserver = new MutationObserver(scheduleUpdate);
				mutationObserver.observe(canvas, { childList: true, subtree: true });
			}

			return () => {
				window.removeEventListener('resize', scheduleUpdate);
				if (resizeObserver) {
					resizeObserver.disconnect();
				}
				if (mutationObserver) {
					mutationObserver.disconnect();
				}
				if (frame !== null) {
					cancelAnimationFrame(frame);
				}
			};
		}, [comparison, diffResult.blocks]);

		const markerLabel = (status) => {
			if (status === 'added') {
				return __('Added block', 'visual-post-compare');
			}
			if (status === 'removed') {
				return __('Removed block', 'visual-post-compare');
			}
			return __('Modified block', 'visual-post-compare');
		};

		const editorStyleElements = (Array.isArray(config.styles) ? config.styles : [])
			.map((style, index) => {
				if (!style || !style.css) {
					return null;
				}

				const scopedCss = scopeEditorCss(
					style.css,
					'.visual-post-compare-revision__live-preview'
				);

				return scopedCss
					? el('style', { key: 'vpc-editor-style-' + index }, scopedCss)
					: null;
			})
			.filter(Boolean);

		const presentation = comparison.presentation || {};
		const linkedValue = (post, value) => el(
			'a',
			{ href: post.url || '#', className: 'visual-post-compare-revision__date-link' },
			value
		);
		const statusCaption = (post) => presentation.mimeTypeStatus ? post.mimeTypeStatusLabel : post.statusLabel;
		const dateLine = (post, prefix, value, className) => el(
			'div',
			{ className: 'visual-post-compare-revision__date-row ' + className },
			el('span', { className: 'visual-post-compare-revision__date-prefix' }, prefix),
			linkedValue(post, value)
		);
		const authorLine = (post) => post.authorName
			? el('div', { className: 'visual-post-compare-revision__author' }, sprintf(__('by %s', 'revisionary'), post.authorName))
			: null;
		const details = (post) => [
			presentation.showModified !== false ? dateLine(post, presentation.modifiedPrefix || __('Modified: ', 'revisionary'), post.modifiedLabel || post.modified, 'is-modified-date') : null,
			presentation.showPostDate ? dateLine(post, presentation.postDatePrefix || __('Post Date: ', 'revisionary'), post.postDateLabel || post.postDate, 'is-post-date') : null,
			presentation.showAuthor !== false ? authorLine(post) : null,
		].filter(Boolean);
		const isCurrentSelected = Number(comparison.to.id) === Number(comparison.from.id);
		const currentMeta = el('div', { key: 'current' },
			el('span', { className: 'visual-post-compare-revision__status' }, __('Current', 'revisionary')),
			el('strong', null, comparison.from.title || sprintf(__('Post %d', 'revisionary'), comparison.from.id)),
			...details(comparison.from)
		);
		const revisionMeta = isCurrentSelected ? el('div', {}, '') : el('div', { key: 'revision' },
			presentation.showRightStatus !== false ? el('span', { className: 'visual-post-compare-revision__status' }, statusCaption(comparison.to) || __('Revision', 'revisionary')) : null,
			el('strong', null, comparison.to.title || sprintf(__('Post %d', 'revisionary'), comparison.to.id)),
			...details(comparison.to),
			comparison.canApprove
				? el(Button, {
					variant: 'primary',
					className: 'visual-post-compare-revision__approve',
					onClick: onApprove,
					isBusy: isApproving,
					disabled: isApproving,
				}, isApproving ? __('Approving…', 'revisionary') : config.approveCaption || __('Approve', 'revisionary'))
				: null
		);

		const metaColumns = config.currentPostFirst === false
			? [revisionMeta, currentMeta]
			: [currentMeta, revisionMeta];

		const diffMarkerNav = hasCanvasOverflow && diffMarkers.length
			? el(
				'nav',
				{
					className: 'visual-post-compare-revision__diff-markers',
					'aria-label': __('Document changes', 'visual-post-compare'),
				},
				...diffMarkers.map((marker) => el('button', {
					key: marker.key,
					type: 'button',
					className: 'visual-post-compare-revision__diff-marker is-' + marker.status,
					title: markerLabel(marker.status),
					'aria-label': markerLabel(marker.status),
					style: { top: marker.top + '%', '--vpc-marker-height': marker.height + '%' },
					onClick: () => {
						const canvas = canvasRef.current;
						if (!canvas || !marker.node) {
							return;
						}
						const canvasRect = canvas.getBoundingClientRect();
						const nodeRect = marker.node.getBoundingClientRect();
						const nodeTop = canvas.scrollTop + nodeRect.top - canvasRect.top;
						const targetTop = nodeTop - Math.max(0, (canvas.clientHeight - nodeRect.height) / 2);
						canvas.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' });
					},
				}))
			)
			: null;

		return el(
			'section',
			{ className: 'visual-post-compare-revision' },
			el(
				'div',
				{ className: 'visual-post-compare-revision__legend', 'aria-label': __('Comparison legend', 'visual-post-compare') },
				el('span', { className: 'is-added' }, __('Added', 'visual-post-compare')),
				el('span', { className: 'is-removed' }, __('Removed', 'visual-post-compare')),
				el('span', { className: 'is-modified' }, __('Modified', 'visual-post-compare'))
			),
			el(
				'div',
				{ className: 'visual-post-compare-revision__body' },
				diffResult.error
					? el(Notice, { status: 'error', isDismissible: false }, diffResult.error.message)
					: el(
						'div',
						{ className: 'visual-post-compare-revision__canvas-shell' },
						el(
							'div',
							{ className: 'visual-post-compare-revision__canvas', ref: canvasRef },
							...editorStyleElements,
							el('style', null, REVISION_DIFF_STYLES),
							el('div', {
								className: 'visual-post-compare-revision__svg-filters',
								'aria-hidden': true,
								dangerouslySetInnerHTML: { __html: REVISION_REMOVED_FILTER_SVG },
							}),
							el('div', previewProps)
						),
						diffMarkerNav
					),
				el(
					'aside',
					{ className: 'visual-post-compare-revision__sidebar' },
					metaColumns[0],
					metaColumns[1]
				)
			)
		);
	}

	function normalizeCurrentPostPlacement(comparison) {
		if (!comparison || !Array.isArray(comparison.posts) || !comparison.from) {
			return comparison;
		}

		const fromId = Number(comparison.from.id);
		const currentPost = comparison.posts.find((post) => Number(post.id) === fromId);
		if (!currentPost) {
			return comparison;
		}

		const otherPosts = comparison.posts.filter((post) => Number(post.id) !== fromId);
		const posts = config.currentPostFirst === false
			? [...otherPosts, currentPost]
			: [currentPost, ...otherPosts];

		return { ...comparison, posts };
	}

	function App() {
		const [comparison, setComparison] = useState(null);
		const [error, setError] = useState(null);
		const [isApproving, setIsApproving] = useState(false);
		const [approvalNotice, setApprovalNotice] = useState(null);

		useEffect(() => {
			apiFetch({
				path: config.restPath + '?from=' + encodeURIComponent(config.from) + '&to=' + encodeURIComponent(config.to) + (config.comparisonKey ? '&comparison=' + encodeURIComponent(config.comparisonKey) : ''),
			})
				.then((loadedComparison) => setComparison(normalizeCurrentPostPlacement(loadedComparison)))
				.catch(setError);
		}, []);

		if (error) {
			return el(Notice, { status: 'error', isDismissible: false },
				(error && error.message) || __('Unable to load this comparison.', 'revisionary')
			);
		}

		if (!comparison) {
			return el('div', { className: 'visual-post-compare-loading' }, el(Spinner), __('Loading comparison…', 'revisionary'));
		}

		const approve = async () => {
			if (isApproving || !comparison.canApprove || Number(comparison.to.id) === Number(comparison.from.id)) {
				return;
			}

			setIsApproving(true);
			setApprovalNotice(null);
			try {
				const updatedComparison = await apiFetch({
					path: config.approveRestPath + '?from=' + encodeURIComponent(config.from) + '&to=' + encodeURIComponent(comparison.to.id) + (config.comparisonKey ? '&comparison=' + encodeURIComponent(config.comparisonKey) : ''),
					method: 'POST',
				});
				setComparison(normalizeCurrentPostPlacement(updatedComparison));
				setApprovalNotice({ status: 'success', message: __('The target post content has been replaced with the approved content.', 'revisionary') });
			} catch (approveError) {
				setApprovalNotice({
					status: 'error',
					message: (approveError && approveError.message) || __('Unable to approve this comparison.', 'revisionary'),
				});
			} finally {
				setIsApproving(false);
			}
		};

		return el(
			'div',
			{ className: 'visual-post-compare-screen' },
			el('header', { className: 'visual-post-compare-screen__header' },
				el('h1', null, config.headline || __('Compare Revisions', 'revisionary'))
			),
			approvalNotice ? el(Notice, { status: approvalNotice.status, isDismissible: true, onRemove: () => setApprovalNotice(null) }, approvalNotice.message) : null,
			el(ComparisonSlider, {
				comparison,
				onSelect: (post) => {
					setApprovalNotice(null);
					setComparison({ ...comparison, to: post, selectedId: Number(post.id) });
				},
			}),
			el(RevisionPreview, { comparison, onApprove: approve, isApproving })
		);
	}

	const root = document.getElementById('visual-post-compare-root');
	if (root) {
		element.createRoot(root).render(el(App));
	}
})();
