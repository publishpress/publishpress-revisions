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
	const { __experimentalUseBlockPreview: useBlockPreview, BlockIcon } = blockEditor;
	const { Button, Notice, Spinner } = components;
	const { createElement: el, useEffect, useMemo, useRef, useState } = element;
	const { __, sprintf } = i18n;
	const { RichTextData, applyFormat, concat, create, getFormatType, registerFormatType, slice } = richText;
	const classicDiff = window.VisualPostCompareClassicDiff || null;

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
			box-shadow: inset 0 0 0 9999px color-mix(in srgb, currentColor 5%, #00a32a 15%), 0 0 0 3px color-mix(in srgb, currentColor 5%, #00a32a 15%);
			outline: 3px solid #00a32a;
			outline-offset: 6px;
		}
		.is-revision-removed,
		.revision-diff-removed {
			text-decoration: line-through;
			filter: url(#revision-removed-filter);
		}
		.is-revision-removed {
			outline: 3px dashed #d63638;
			outline-offset: 6px;
		}
		.is-revision-modified {
			outline: 3px dotted #dba617 !important;
			outline-offset: 6px;
		}
		.is-revision-changed {
			outline-offset: 6px;
		}
		.is-shortcode-diff-container[data-visual-post-compare-container-type="gallery"],
		.is-shortcode-diff-container.gallery,
		.is-shortcode-diff-container.tiled-gallery {
			display: flow-root;
		}
		.revision-diff-added {
			background-color: color-mix(in srgb, currentColor 5%, #00a32a 15%);
			text-decoration: none;
		}
		.revision-diff-format-added,
		.revision-diff-format-removed,
		.revision-diff-format-changed {
			border-bottom: 3px dotted #3c434a;
			text-decoration: none;
			box-decoration-break: clone;
			-webkit-box-decoration-break: clone;
		}
		.has-diff-tooltips .is-revision-modified[data-visual-post-compare-container-diff="true"],
		.has-diff-tooltips .is-revision-changed[data-visual-post-compare-container-diff="true"] {
			outline-color: transparent !important;
		}
		.has-diff-tooltips .is-revision-modified[data-visual-post-compare-container-diff="true"].is-diff-tooltip-active,
		.has-diff-tooltips .is-revision-changed[data-visual-post-compare-container-diff="true"].is-diff-tooltip-active,
		.has-diff-tooltips .is-revision-modified[data-visual-post-compare-container-diff="true"].is-diff-border-active,
		.has-diff-tooltips .is-revision-changed[data-visual-post-compare-container-diff="true"].is-diff-border-active {
			outline-color: #dba617 !important;
		}
		.is-revision-added.is-diff-border-active,
		.is-revision-removed.is-diff-border-active,
		.is-revision-modified.is-diff-border-active,
		.is-revision-changed.is-diff-border-active {
			outline-width: 4px !important;
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
			} else if (
				status === 'modified'
				&& (
					diffStatus.forceContainerModified
					|| !(
						diffStatus.hasGranularImageDiff
						|| (
							diffStatus.hasGranularDiff
							&& !diffStatus.hasUnrepresentedAttributeDiff
						)
					)
				)
			) {
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
			['revision/diff-removed', __('Remove', 'revisionary'), 'del', 'revision-diff-removed'],
			['revision/diff-added', __('Add', 'revisionary'), 'ins', 'revision-diff-added'],
			['revision/diff-format-added', __('Add Format', 'revisionary'), 'span', 'revision-diff-format-added'],
			['revision/diff-format-removed', __('Remove Format', 'revisionary'), 'span', 'revision-diff-format-removed'],
			['revision/diff-format-changed', __('Modify Format', 'revisionary'), 'span', 'revision-diff-format-changed'],
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

	function stableDiffValue(value) {
		if (Array.isArray(value)) {
			return value.map(stableDiffValue);
		}
		if (value && typeof value === 'object') {
			return Object.keys(value).sort().reduce((result, key) => {
				result[key] = stableDiffValue(value[key]);
				return result;
			}, {});
		}
		return value;
	}

	function formatSignatureAt(richTextValue, index) {
		const formats = richTextValue
			&& Array.isArray(richTextValue.formats)
			&& Array.isArray(richTextValue.formats[index])
			? richTextValue.formats[index]
			: [];
		const signatures = formats
			.filter((format) => format && typeof format.type === 'string' && !format.type.startsWith('revision/diff-'))
			.map((format) => JSON.stringify(stableDiffValue(format)))
			.sort();
		return JSON.stringify(signatures);
	}

	function applyFormattingDiff(
		currentRichText,
		previousRichText,
		currentStart,
		previousStart,
		length,
		diffStatus
	) {
		let value = slice(currentRichText, currentStart, currentStart + length);
		let rangeStart = null;

		for (let offset = 0; offset <= length; offset++) {
			const differs = offset < length && formatSignatureAt(currentRichText, currentStart + offset)
				!== formatSignatureAt(previousRichText, previousStart + offset);

			if (differs && rangeStart === null) {
				rangeStart = offset;
			} else if (!differs && rangeStart !== null) {
				value = applyFormat(value, {
					type: 'revision/diff-format-changed',
					attributes: {
						title: __('Modify Format', 'revisionary'),
					},
				}, rangeStart, offset);
				rangeStart = null;
				diffStatus.hasGranularDiff = true;
			}
		}

		return value;
	}

	function applyRichTextDiff(currentRichText, previousRichText, preserveObjects = false, diffStatus = null) {
		// RichText's internal text includes an object-replacement character for
		// inline images. toPlainText() removes it, which shifts every subsequent
		// slice offset and makes an image-only removal impossible to highlight.
		// Keep the previous behavior for block-editor content, but retain those
		// placeholders while diffing normalized Classic Editor HTML.
		const currentText = preserveObjects && typeof currentRichText.text === 'string'
			? currentRichText.text
			: currentRichText.toPlainText();
		const previousText = preserveObjects && typeof previousRichText.text === 'string'
			? previousRichText.text
			: previousRichText.toPlainText();
		const textDiff = diffTokens(tokenize(previousText), tokenize(currentText));
		let result = create({ text: '' });
		let currentIndex = 0;
		let previousIndex = 0;

		textDiff.forEach((part) => {
			const length = part.value.length;
			if (preserveObjects && /^\s+$/u.test(part.value)) {
				if (part.type === 'added') {
					result = concat(result, slice(currentRichText, currentIndex, currentIndex + length));
					currentIndex += length;
				} else if (part.type === 'removed') {
					previousIndex += length;
				} else {
					result = concat(result, slice(currentRichText, currentIndex, currentIndex + length));
					currentIndex += length;
					previousIndex += length;
				}
				return;
			}
			if (part.type === 'removed') {
				const removed = slice(previousRichText, previousIndex, previousIndex + length);
				result = concat(result, applyFormat(removed, {
					type: 'revision/diff-removed',
					attributes: {
						title: __('Remove', 'revisionary'),
					},
				}, 0, length));
				previousIndex += length;
				if (preserveObjects && diffStatus) diffStatus.hasGranularDiff = true;
			} else if (part.type === 'added') {
				const added = slice(currentRichText, currentIndex, currentIndex + length);
				result = concat(result, applyFormat(added, {
					type: 'revision/diff-added',
					attributes: {
						title: __('Add', 'revisionary'),
					},
				}, 0, length));
				currentIndex += length;
				if (preserveObjects && diffStatus) diffStatus.hasGranularDiff = true;
			} else {
				result = concat(
					result,
					preserveObjects && diffStatus && config.htmlAttributeChanges
						? applyFormattingDiff(
							currentRichText,
							previousRichText,
							currentIndex,
							previousIndex,
							length,
							diffStatus
						)
						: slice(currentRichText, currentIndex, currentIndex + length)
				);
				currentIndex += length;
				previousIndex += length;
			}
		});

		return new RichTextData(result);
	}

	function normalizeClassicSignatureValue(value) {
		if (Array.isArray(value)) return value.map(normalizeClassicSignatureValue);
		if (value && typeof value === 'object') {
			return Object.keys(value).sort().reduce((result, key) => {
				result[key] = normalizeClassicSignatureValue(value[key]);
				return result;
			}, {});
		}
		if (typeof value !== 'string') return value;
		const template = document.createElement('template');
		template.innerHTML = value;
		const walker = document.createTreeWalker(template.content, NodeFilter.SHOW_TEXT);
		while (walker.nextNode()) {
			walker.currentNode.nodeValue = walker.currentNode.nodeValue.replace(/[\t\r\n \u00a0]+/g, '');
		}
		return template.innerHTML;
	}

	function rawSignature(rawBlock, normalizeClassicWhitespace = false) {
		const attributes = normalizeClassicWhitespace
			? normalizeClassicSignatureValue(rawBlock.attrs)
			: rawBlock.attrs;
		return JSON.stringify({
			name: rawBlock.blockName,
			attrs: attributes,
			html: (rawBlock.innerContent || []).filter(
				(value) => value !== null && String(value).trim() !== ''
			).map((value) => normalizeClassicWhitespace ? normalizeClassicSignatureValue(value) : value),
		});
	}

	function isIgnorableClassicRawBlock(rawBlock) {
		if (!rawBlock || (rawBlock.blockName && rawBlock.blockName !== 'core/paragraph')) return false;
		const template = document.createElement('template');
		template.innerHTML = rawBlock.innerHTML || '';
		if (template.content.querySelector('div, section, article, aside, table, ul, ol, li, figure, blockquote')) return false;
		if (template.content.querySelector('br, img, hr, audio, video, iframe, object, embed, svg, canvas')) return false;
		return !(template.content.textContent || '').replace(/\u00a0/g, ' ').trim();
	}

	function plainTextFromRaw(rawBlock) {
		const node = document.createElement('div');
		node.innerHTML = rawBlock && rawBlock.innerHTML ? rawBlock.innerHTML : '';
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

	function isFormattingRootReplacement(currentBlock, previousBlock) {
		const currentTemplate = document.createElement('template');
		const previousTemplate = document.createElement('template');
		currentTemplate.innerHTML = currentBlock.innerHTML || '';
		previousTemplate.innerHTML = previousBlock.innerHTML || '';
		const currentRoot = currentTemplate.content.firstElementChild;
		const previousRoot = previousTemplate.content.firstElementChild;
		if (!currentRoot || !previousRoot || currentTemplate.content.children.length !== 1
			|| previousTemplate.content.children.length !== 1) return false;
		const currentText = normalizedElementText(currentRoot);
		const previousText = normalizedElementText(previousRoot);
		if (!currentText || currentText !== previousText || currentRoot.tagName === previousRoot.tagName) return false;
		const formatRoots = new Set([
			'p', 'span', 'b', 'strong', 'i', 'em', 'ins', 'del', 'small', 'big', 'large',
			'sup', 'sub', 'pre', 'q', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		]);
		return formatRoots.has(currentRoot.tagName.toLowerCase())
			|| formatRoots.has(previousRoot.tagName.toLowerCase());
	}

	function pairSimilarBlocks(rawBlocks, enableClassicGranularDiff = false) {
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
				!usedAdded.has(candidate.index)
				&& (
					candidate.block.blockName === rem.block.blockName
					|| isFormattingRootReplacement(candidate.block, rem.block)
				)
			);
			const sameTypeRemoved = removed.filter((candidate) =>
				candidate.block.blockName === rem.block.blockName
				|| isFormattingRootReplacement(candidate.block, rem.block)
			);

			sameTypeAdded.forEach((candidate) => {
				const formattingRootReplacement = isFormattingRootReplacement(candidate.block, rem.block);
				let canBlend = false;
				if (
					enableClassicGranularDiff
					&& classicDiff
				) {
					canBlend = classicDiff.canBlendStructure(candidate.block, rem.block);
					if (!canBlend && !formattingRootReplacement) return;
				}
				const score = textSimilarity(plainTextFromRaw(rem.block), plainTextFromRaw(candidate.block));
				const unambiguous = sameTypeAdded.length === 1 && sameTypeRemoved.length === 1;
				if ((unambiguous || score >= 0.5 || canBlend || formattingRootReplacement) && score > bestScore) {
					best = candidate;
					bestScore = score;
				}
			});

			if (best) {
				usedRemoved.add(rem.index);
				usedAdded.add(best.index);
				const formattingDiff = enableClassicGranularDiff && classicDiff
					? classicDiff.markHtmlFormattingChanges(
						best.block,
						rem.block,
						(previousText, currentText) => diffTokens(tokenize(previousText), tokenize(currentText))
					)
					: null;
				const imageOnlyDiff = enableClassicGranularDiff && classicDiff
					&& classicDiff.hasImageOnlyDifference(best.block, rem.block);
				const replacementBlock = formattingDiff
					? {
						...best.block,
						innerHTML: formattingDiff.html,
						innerContent: [formattingDiff.html],
					}
					: best.block;
				const diffStatus = { status: 'modified' };
				diffStatus.currentHtml = best.block.innerHTML || '';
				diffStatus.previousHtml = rem.block.innerHTML || '';
				if (formattingDiff) {
					diffStatus.hasGranularDiff = true;
					diffStatus.hasUnrepresentedAttributeDiff = !formattingDiff.complete;
					diffStatus.htmlDiffComplete = Boolean(formattingDiff.complete);
					diffStatus.forceContainerModified = Boolean(formattingDiff.forceContainerModified);
				}
				if (imageOnlyDiff) {
					diffStatus.hasGranularImageDiff = true;
				}
				replacements.set(best.index, {
					...replacementBlock,
					__revisionDiffStatus: diffStatus,
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

	function diffRawBlocks(currentRaw, previousRaw, enableClassicGranularDiff = false) {
		const currentSignatures = currentRaw.map(
			(rawBlock) => rawSignature(rawBlock, enableClassicGranularDiff)
		);
		const previousSignatures = previousRaw.map(
			(rawBlock) => rawSignature(rawBlock, enableClassicGranularDiff)
		);
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
					innerBlocks: diffRawBlocks(
						current.innerBlocks || [],
						previous.innerBlocks || [],
						enableClassicGranularDiff
					),
				});
			} else if (j < currentRaw.length && (i >= previousRaw.length || table[i][j + 1] >= table[i + 1][j])) {
				result.push({ ...currentRaw[j++], __revisionDiffStatus: { status: 'added' } });
			} else if (i < previousRaw.length) {
				result.push({ ...previousRaw[i++], __revisionDiffStatus: { status: 'removed' } });
			}
		}

		return pairSimilarBlocks(result, enableClassicGranularDiff);
	}

	function applyDiffToBlock(currentBlock, previousBlock, diffStatus, diffNestedAttributes = false) {
		const blockType = blocks.getBlockType(currentBlock.name);
		if (!blockType || !blockType.attributes) return;
		if (diffNestedAttributes && diffStatus.htmlDiffComplete) return;
		let hasUnrepresentedAttributeDiff = false;
		const changedAttributes = {};

		Object.entries(blockType.attributes).forEach(([attributeName, definition]) => {
			const currentValue = currentBlock.attributes[attributeName];
			const previousValue = previousBlock.attributes[attributeName];

			if (definition && definition.source === 'rich-text') {
				if (currentValue instanceof RichTextData && previousValue instanceof RichTextData) {
					currentBlock.attributes[attributeName] = applyRichTextDiff(
						currentValue,
						previousValue,
						diffNestedAttributes,
						diffStatus
					);
				}
			} else if (diffNestedAttributes && classicDiff && definition && definition.source === 'query') {
				currentBlock.attributes[attributeName] = classicDiff.applyNestedAttributeDiff(
					currentValue,
					previousValue,
					definition,
					diffStatus,
					applyRichTextDiff
				);
			}
			if (!diffNestedAttributes && (!definition || definition.source !== 'rich-text')) {
				const currentString = stringifyAttributeValue(currentValue);
				const previousString = stringifyAttributeValue(previousValue);
				if (currentString !== previousString) {
					changedAttributes[attributeName] = diffTokens(
						tokenize(previousString),
						tokenize(currentString)
					);
				}
			}

			if (
				diffNestedAttributes
				&& classicDiff
				&& classicDiff.hasUnrepresentedAttributeDifference(currentValue, previousValue, definition)
			) {
				hasUnrepresentedAttributeDiff = true;
			}
		});

		if (!diffNestedAttributes && Object.keys(changedAttributes).length) {
			diffStatus.changedAttributes = changedAttributes;
		}
		if (
			!diffNestedAttributes
			&& currentBlock.name === 'core/image'
			&& currentBlock.attributes.url
			&& previousBlock.attributes.url
			&& currentBlock.attributes.url !== previousBlock.attributes.url
		) {
			diffStatus.previousImageUrl = previousBlock.attributes.url;
		}

		if (diffNestedAttributes) {
			diffStatus.hasUnrepresentedAttributeDiff = Boolean(
				diffStatus.hasUnrepresentedAttributeDiff || hasUnrepresentedAttributeDiff
			);
		}
	}

	function stringifyAttributeValue(value) {
		if (value === null || value === undefined) return '';
		if (typeof value === 'string') return value;
		try {
			return JSON.stringify(value, null, 2);
		} catch (error) {
			return String(value);
		}
	}

	function applyDiffRecursively(parsedBlock, rawBlock, diffNestedAttributes = false) {
		if (rawBlock.__revisionDiffStatus) {
			if (rawBlock.__revisionDiffStatus.status === 'modified' && rawBlock.__previousRawBlock) {
				const previousParsed = parseRawBlock(rawBlock.__previousRawBlock);
				if (previousParsed) {
					applyDiffToBlock(
						parsedBlock,
						previousParsed,
						rawBlock.__revisionDiffStatus,
						diffNestedAttributes
					);
				}
			}
			parsedBlock.__revisionDiffStatus = rawBlock.__revisionDiffStatus;
			parsedBlock.attributes = parsedBlock.attributes || {};
			parsedBlock.attributes.__revisionDiffStatus = rawBlock.__revisionDiffStatus;
		}

		if (parsedBlock.innerBlocks && rawBlock.innerBlocks) {
			for (let index = 0; index < parsedBlock.innerBlocks.length; index++) {
				if (parsedBlock.innerBlocks[index] && rawBlock.innerBlocks[index]) {
					applyDiffRecursively(
						parsedBlock.innerBlocks[index],
						rawBlock.innerBlocks[index],
						diffNestedAttributes
					);
				}
			}
		}
	}

	function containsSerializedBlocks(content) {
		return /<!--\s+\/?wp:[a-z0-9_-]+(?:\/[a-z0-9_-]+)?(?:\s|-->)/i.test(content || '');
	}

	/**
	 * Canonicalize differences introduced by TinyMCE, KSES, or HTML
	 * serialization without hiding meaningful markup changes.
	 *
	 * Parsing and serializing through the browser normalizes entity spelling,
	 * tag/attribute whitespace, tag case, and void-element syntax. CSSOM does
	 * the same for insignificant whitespace and trailing semicolons in inline
	 * styles while retaining property names, values, priorities, and order.
	 * Attribute order and repeated whitespace in token-list attributes have no
	 * rendering significance, so those are canonicalized explicitly.
	 */
	/**
	 * Convert Classic Editor HTML into the same block-shaped input used by the
	 * visual revisions diff. The block grammar parser intentionally returns
	 * un-delimited HTML as one freeform block, which makes an entire Classic
	 * Editor post appear changed when only a word or image was edited.
	 *
	 * rawHandler() applies WordPress core's HTML-to-block transforms, separating
	 * paragraphs, headings, lists, images, and other supported elements. Once
	 * serialized, the existing block diff can pair those elements and apply its
	 * existing rich-text substring highlighting. Serialized block-editor content
	 * is returned byte-for-byte so that its comparison behavior is unchanged.
	 */
	function prepareContentForDiff(content) {
		const value = String(content || '');
		if (!value.trim() || containsSerializedBlocks(value)) {
			return value;
		}

		if (!classicDiff || typeof classicDiff.prepareContentForDiff !== 'function') {
			throw new Error(
				__('Classic Editor comparison support is unavailable.', 'revisionary')
			);
		}

		return classicDiff.prepareContentForDiff(value);
	}

	function normalizeBlockEditorRawBlocks(rawBlocks) {
		return (rawBlocks || []).flatMap((rawBlock) => {
			const normalizedInnerBlocks = normalizeBlockEditorRawBlocks(rawBlock.innerBlocks || []);
			if ('core/paragraph' !== rawBlock.blockName || normalizedInnerBlocks.length) {
				return [{ ...rawBlock, innerBlocks: normalizedInnerBlocks }];
			}

			const template = document.createElement('template');
			template.innerHTML = rawBlock.innerHTML || '';
			const elements = Array.from(template.content.children);
			const onlyParagraphElements = elements.length > 1
				&& elements.every((element) => 'p' === element.tagName.toLowerCase())
				&& Array.from(template.content.childNodes).every((node) =>
					node.nodeType === Node.ELEMENT_NODE
					|| (node.nodeType === Node.TEXT_NODE && !(node.nodeValue || '').trim())
				);
			if (!onlyParagraphElements) {
				return [{ ...rawBlock, innerBlocks: normalizedInnerBlocks }];
			}

			// Invalid serialized paragraph blocks can contain several sibling <p>
			// elements. Rendering those through core/paragraph nests them inside a
			// generated <p>, causing the browser to move content outside the block
			// wrapper. Split them before diffing so every paragraph remains visible
			// and receives its own block-level status.
			return elements.map((element) => {
				const html = element.outerHTML;
				return {
					...rawBlock,
					attrs: { ...(rawBlock.attrs || {}) },
					innerBlocks: [],
					innerHTML: html,
					innerContent: [html],
				};
			});
		});
	}

	function diffRevisionContent(currentContent, previousContent) {
		if (typeof grammarParse !== 'function' || typeof parseRawBlock !== 'function') {
			throw new Error(__('WordPress 7.0 block revision parser APIs are unavailable.', 'revisionary'));
		}

		// A custom wp-admin page does not run the normal post-editor bootstrap.
		// Register the core block types before parseRawBlock() is asked to resolve
		// core/paragraph, core/heading, core/list, etc.
		ensureCoreBlocksRegistered();
		const isClassicComparison = Boolean(classicDiff) && (
			!containsSerializedBlocks(currentContent)
			|| !containsSerializedBlocks(previousContent)
		);

		let currentRaw = grammarParse(prepareContentForDiff(currentContent));
		let previousRaw = grammarParse(prepareContentForDiff(previousContent));
		if (isClassicComparison) {
			currentRaw = currentRaw.filter((rawBlock) => !isIgnorableClassicRawBlock(rawBlock));
			previousRaw = previousRaw.filter((rawBlock) => !isIgnorableClassicRawBlock(rawBlock));
		} else {
			currentRaw = normalizeBlockEditorRawBlocks(currentRaw);
			previousRaw = normalizeBlockEditorRawBlocks(previousRaw);
		}
		const mergedRaw = diffRawBlocks(currentRaw, previousRaw, isClassicComparison);

		const parsedBlocks = mergedRaw.map((rawBlock) => {
			// The grammar parser can emit whitespace-only freeform entries between
			// blocks. Core normally drops these; do the same here rather than turning
			// them into a missing/freeform block on the standalone screen.
			if (!rawBlock.blockName && !(rawBlock.innerHTML || '').trim()) {
				return null;
			}

			const parsed = parseRawBlock(rawBlock);
			if (parsed) {
				applyDiffRecursively(parsed, rawBlock, isClassicComparison);
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
		if (posts.length < 2) {
			return null;
		}

		const selectedId = Number(comparison.selectedId || (comparison.revision && comparison.revision.id));
		const foundIndex = posts.findIndex((post) => Number(post.id) === selectedId);
		const selectedIndex = foundIndex >= 0 ? foundIndex : 0;
		const count = posts.length;
		const usePostDate = presentation.sliderPostDate === true;
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

	function findSecondaryDiffNodes(nodes) {
		const secondaryNodes = new WeakSet();
		const containerCounts = new WeakMap();
		nodes.forEach((node) => {
			const diffContainer = node.parentElement
				? node.parentElement.closest(
					'.is-revision-added, .is-revision-removed, .is-revision-modified, .is-revision-changed'
				)
				: null;
			if (!diffContainer) return;
			const count = containerCounts.get(diffContainer) || 0;
			if (count > 0) secondaryNodes.add(node);
			containerCounts.set(diffContainer, count + 1);
		});
		return secondaryNodes;
	}

	function deduplicateCoincidentContainerNodes(nodes, containerSelector) {
		const result = [];
		nodes.forEach((node) => {
			if (!node.matches(containerSelector)) {
				result.push(node);
				return;
			}
			const rect = node.getBoundingClientRect();
			const center = rect.top + rect.height / 2;
			const duplicateIndex = result.findIndex((candidate) => {
				if (!candidate.matches(containerSelector)) return false;
				if (!candidate.contains(node) && !node.contains(candidate)) return false;
				const candidateRect = candidate.getBoundingClientRect();
				const candidateCenter = candidateRect.top + candidateRect.height / 2;
				return Math.abs(candidateCenter - center) < 1;
			});
			if (duplicateIndex < 0) {
				result.push(node);
			} else if (result[duplicateIndex].contains(node)) {
				// Prefer the immediate highlighted container over a coincident wrapper.
				result[duplicateIndex] = node;
			}
		});
		return result;
	}

	function hasRenderableMarkerTop(marker) {
		if (!marker || !marker.isContainerDiff) return true;
		return Number.isFinite(marker.top) && marker.top > 0;
	}

	function restoreShortcodeContextComments(preview) {
		const replaceMarker = (marker, commentText) => {
			if (marker.dataset.visualPostCompareCommentRestored === 'true') return;
			const comment = document.createComment(commentText);
			const parent = marker.parentElement;
			const sentinelOnlyContainer = parent
				&& !(parent.textContent || '').trim()
				&& Array.from(parent.children).every((child) =>
					child === marker || child.hidden || child.tagName.toLowerCase() === 'br'
				);
			if (sentinelOnlyContainer && parent.parentNode) {
				const diffContainer = parent.closest(
					'.is-revision-added, .is-revision-removed, .is-revision-modified, .is-revision-changed'
				);
				parent.insertBefore(comment, marker);
				marker.dataset.visualPostCompareCommentRestored = 'true';
				parent.dataset.visualPostCompareShortcodeSentinelOnly = 'true';
				if (diffContainer && !(diffContainer.textContent || '').trim() && !diffContainer.querySelector('img')) {
					diffContainer.classList.remove(
						'is-revision-added', 'is-revision-removed', 'is-revision-modified', 'is-revision-changed'
					);
				}
				return;
			}
			marker.parentNode.insertBefore(comment, marker);
			marker.dataset.visualPostCompareCommentRestored = 'true';
		};
		Array.from(preview.querySelectorAll('[data-visual-post-compare-shortcode-start]')).forEach((marker) => {
			const key = marker.getAttribute('data-visual-post-compare-shortcode-start') || '';
			replaceMarker(marker, ' visual-post-compare-shortcode:' + key + ' ');
		});
		Array.from(preview.querySelectorAll('[data-visual-post-compare-shortcode-end]')).forEach((marker) => {
			const key = marker.getAttribute('data-visual-post-compare-shortcode-end') || '';
			replaceMarker(marker, ' /visual-post-compare-shortcode:' + key + ' ');
		});
	}

	function suppressBlockEditorWarnings(preview) {
		Array.from(preview.querySelectorAll('.block-editor-warning')).forEach((warning) => {
			const block = warning.closest('[data-block]');
			const warningShell = warning.parentElement && warning.parentElement.style.all === 'initial'
				? warning.parentElement
				: warning;
			warningShell.hidden = true;
			warningShell.setAttribute('aria-hidden', 'true');
			if (block) block.classList.remove('has-warning');
		});
	}

	function enablePreviewTextSelection(preview) {
		if (!preview) return;
		Array.from(preview.querySelectorAll('[inert]')).forEach((element) => {
			if (!(element.textContent || '').trim() && !element.querySelector('img, a, [data-visual-post-compare-tooltip]')) return;
			element.removeAttribute('inert');
			element.setAttribute('data-visual-post-compare-text-selectable', 'true');
			if (element.hasAttribute('tabindex')) element.setAttribute('tabindex', '-1');
		});
	}

	function enablePreviewBlockSelection(preview) {
		if (!preview) return;
		Array.from(preview.querySelectorAll('[data-block]')).forEach((block) => {
			block.removeAttribute('inert');
			block.setAttribute('data-visual-post-compare-block-selectable', 'true');
			if (block.hasAttribute('tabindex')) block.setAttribute('tabindex', '-1');
		});
	}

	function disablePreviewLinks(preview) {
		if (!preview) return;
		Array.from(preview.querySelectorAll('a')).forEach((link) => {
			link.dataset.visualPostCompareLinkInert = 'true';
			link.setAttribute('aria-disabled', 'true');
			link.setAttribute('tabindex', '-1');
		});
	}

	const DIFF_TYPE_DEFINITIONS = [
		['image-added', '.visual-post-compare-image-diff--added', __('add', 'revisionary'), __('image', 'revisionary')],
		['image-removed', '.visual-post-compare-image-diff--removed', __('remove', 'revisionary'), __('image', 'revisionary')],
		['image-modified', '.visual-post-compare-image-diff--modified', __('modify', 'revisionary'), __('image', 'revisionary')],
		['text-added', '.revision-diff-added', __('add', 'revisionary'), __('text', 'revisionary')],
		['text-removed', '.revision-diff-removed', __('remove', 'revisionary'), __('text', 'revisionary')],
		['text-modified', '.revision-diff-format-added, .revision-diff-format-removed, .revision-diff-format-changed', __('modify', 'revisionary'), __('format', 'revisionary')],
		['link-modified', '.revision-diff-link-changed', __('change', 'revisionary'), __('link', 'revisionary')],
	];
	let tooltipElement = null;
	let tooltipTarget = null;

	function ensureTooltipElement() {
		if (!config.tooltipsEnabled || !config.tooltipTemplate) return null;
		if (tooltipElement && tooltipElement.isConnected) return tooltipElement;
		const template = document.createElement('template');
		template.innerHTML = config.tooltipTemplate;
		tooltipElement = template.content.firstElementChild;
		if (!tooltipElement) return null;
		tooltipElement.classList.add('visual-post-compare-diff-tooltip', 'click');
		tooltipElement.setAttribute('aria-hidden', 'true');
		document.body.appendChild(tooltipElement);
		return tooltipElement;
	}

	function hideDiffTooltip() {
		if (tooltipTarget) tooltipTarget.classList.remove('is-diff-tooltip-active');
		const tooltip = ensureTooltipElement();
		if (tooltip) {
			tooltip.classList.remove('is-active');
			tooltip.setAttribute('aria-hidden', 'true');
		}
		tooltipTarget = null;
	}

	function tooltipHorizontalCorrection(rect, viewportWidth, margin = 8) {
		if (rect.left < margin) return margin - rect.left;
		if (rect.right > viewportWidth - margin) return viewportWidth - margin - rect.right;
		return 0;
	}

	function positionDiffTooltip() {
		const tooltip = ensureTooltipElement();
		if (!tooltip || !tooltipTarget || !tooltipTarget.isConnected) {
			hideDiffTooltip(true);
			return;
		}
		const rect = tooltipTarget.getBoundingClientRect();
		tooltip.style.position = 'fixed';
		tooltip.style.left = (rect.left + rect.width / 2) + 'px';
		tooltip.style.top = Math.max(8, rect.top) + 'px';
		tooltip.style.zIndex = '1000000';
		const tooltipText = tooltip.querySelector('.tooltip-text');
		if (!tooltipText) return;
		const viewportWidth = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
		const correction = tooltipHorizontalCorrection(
			tooltipText.getBoundingClientRect(),
			viewportWidth
		);
		if (correction) tooltip.style.left = (parseFloat(tooltip.style.left) + correction) + 'px';
	}

	function showDiffTooltip(target, caption) {
		const tooltip = ensureTooltipElement();
		if (!tooltip || !target || !caption) return;
		if (tooltipTarget && tooltipTarget !== target) {
			tooltipTarget.classList.remove('is-diff-tooltip-active');
		}
		const text = tooltip.querySelector('.tooltip-text > span');
		if (text) {
			text.innerHTML = caption;
			text.style.whiteSpace = 'pre-line';
		}
		tooltipTarget = target;
		target.classList.add('is-diff-tooltip-active');
		tooltip.classList.add('is-active');
		tooltip.setAttribute('aria-hidden', 'false');
		positionDiffTooltip();
	}

	function classifyDiffNode(node) {
		if (node.classList.contains('visual-post-compare-image-diff--added')) return DIFF_TYPE_DEFINITIONS[0];
		if (node.classList.contains('visual-post-compare-image-diff--removed')) return DIFF_TYPE_DEFINITIONS[1];
		if (node.classList.contains('visual-post-compare-image-diff--modified')) {
			return ['image-modified', '.visual-post-compare-image-diff--modified', __('modify', 'revisionary'),
				node.dataset.visualPostCompareImageAttributesOnly === 'true'
					? __("image's attributes", 'revisionary')
					: __('image', 'revisionary')];
		}
		if (node.classList.contains('revision-diff-added')) return DIFF_TYPE_DEFINITIONS[3];
		if (node.classList.contains('revision-diff-removed')) return DIFF_TYPE_DEFINITIONS[4];
		if (node.classList.contains('revision-diff-link-changed')) return DIFF_TYPE_DEFINITIONS[6];
		if (node.matches('.revision-diff-format-added, .revision-diff-format-removed, .revision-diff-format-changed')) return DIFF_TYPE_DEFINITIONS[5];
		return null;
	}

	function blockContainerType(container, blockIndex) {
		if (!blockIndex) return '';
		const wrapper = container.closest('[data-block]');
		const block = wrapper && blockIndex.get(wrapper.getAttribute('data-block'));
		if (!block || !block.name) return '';
		const blockType = blocks.getBlockType(block.name);
		if (blockType && blockType.title) return blockType.title;
		const slug = block.name.split('/').pop() || '';
		return slug.replace(/[-_]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
	}

	function containerType(container, blockIndex) {
		const blockType = blockContainerType(container, blockIndex);
		if (blockType) return blockType;
		if (container.matches('table, .wp-block-table') || container.querySelector('table')) return __('Table', 'revisionary');
		const galleryClass = (element) => element && /(?:^|\s)(?:gallery|tiled-gallery)(?:\s|$)/i.test(element.className || '');
		if (
			container.dataset.visualPostCompareContainerType === 'gallery'
			|| galleryClass(container)
			|| container.querySelector('[data-visual-post-compare-container-type="gallery"]')
		) {
			return __('Gallery', 'revisionary');
		}
		if (container.matches('ul, ol, .wp-block-list')) return __('List', 'revisionary');
		if (container.matches('p, .wp-block-paragraph')) return __('Paragraph', 'revisionary');
		return __('Section', 'revisionary');
	}

	function countLeafDiffs(container, selector) {
		return Array.from(container.querySelectorAll(selector)).filter(
			(node) => !node.querySelector(selector)
		).length;
	}

	function countLabel(noun, count) {
		return count + ' ' + noun + (count === 1 ? '' : 's');
	}

	function escapeTooltipText(value) {
		const element = document.createElement('span');
		element.textContent = String(value || '');
		return element.innerHTML;
	}

	function tooltipContext(isPastRevision) {
		return isPastRevision
			? __('Revision Restore', 'revisionary')
			: __('Revision Publication', 'revisionary');
	}

	function tooltipCaption(action, subject, isPastRevision, details = []) {
		const hasDetails = details.length > 0;
		const lead = escapeTooltipText(tooltipContext(isPastRevision))
			+ ' ' + escapeTooltipText(__('will', 'revisionary'))
			+ ' <b>' + escapeTooltipText(action) + '</b> '
			+ escapeTooltipText(__('this', 'revisionary'))
			+ ' ' + escapeTooltipText(subject)
			+ (hasDetails ? ':' : '.');
		return lead + (hasDetails
			? '<span class="visual-post-compare-tooltip-details">'
				+ details.map((detail) => '• ' + escapeTooltipText(detail)).join('<br>')
				+ '</span>'
			: '');
	}

	function semanticFormatParts(summary) {
		const definitions = [
			[__('Add', 'revisionary'), __('bolding', 'revisionary')],
			[__('Remove', 'revisionary'), __('bolding', 'revisionary')],
			[__('Add', 'revisionary'), __('italics', 'revisionary')],
			[__('Remove', 'revisionary'), __('italics', 'revisionary')],
			[__('Add', 'revisionary'), __('underscore', 'revisionary')],
			[__('Remove', 'revisionary'), __('underscore', 'revisionary')],
			[__('Add', 'revisionary'), __('strikethrough', 'revisionary')],
			[__('Remove', 'revisionary'), __('strikethrough', 'revisionary')],
		];
		const lines = String(summary || '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
		const parts = lines.map((line) => {
			if (line === __('Change font size', 'revisionary')) {
				return { action: __('change', 'revisionary'), subject: __('font size', 'revisionary') };
			}
			const match = definitions.find(([action, subject]) => line === action + ' ' + subject);
			return match ? { action: match[0].toLowerCase(), subject: match[1] } : null;
		});
		return parts.length && parts.every(Boolean) ? parts : [];
	}

	function semanticFormatTooltip(summary, isPastRevision) {
		const parts = semanticFormatParts(summary);
		if (!parts.length) return '';
		const context = isPastRevision
			? __('Revision restore', 'revisionary')
			: __('Revision publication', 'revisionary');
		const changes = parts.map((part) => '<b>' + escapeTooltipText(part.action) + '</b> '
			+ escapeTooltipText(part.subject));
		return escapeTooltipText(context) + ' ' + escapeTooltipText(__('will', 'revisionary')) + ' '
			+ (changes.length === 1
				? changes[0]
				: changes.slice(0, -1).join(', ') + ' ' + escapeTooltipText(__('and', 'revisionary')) + ' ' + changes[changes.length - 1])
			+ '.';
	}

	function individualDiffTooltip(node, definition, isPastRevision) {
		let subject = definition[3];
		const details = [];
		if (node.classList.contains('visual-post-compare-line-break-diff')) {
			subject = __('line break', 'revisionary');
		}
		if (definition[0] === 'text-modified' && node.dataset.visualPostCompareFormatSummary) {
			const semanticTooltip = semanticFormatTooltip(
				node.dataset.visualPostCompareFormatSummary,
				isPastRevision
			);
			if (semanticTooltip) return semanticTooltip;
			const summaryLines = node.dataset.visualPostCompareFormatSummary.split(/\r?\n/)
				.map((line) => line.trim())
				.filter(Boolean);
			if (summaryLines.length && /:$/.test(summaryLines[0])) summaryLines.shift();
			details.push(...summaryLines);
		}
		return tooltipCaption(definition[2], subject, isPastRevision, details);
	}

	function modifiedContainerSummary(container, blockIndex, isPastRevision) {
		const actionLabels = {
			'image-added': [__('Add', 'revisionary'), __('Image', 'revisionary')],
			'image-removed': [__('Remove', 'revisionary'), __('Image', 'revisionary')],
			'image-modified': [__('Modify', 'revisionary'), __('Image', 'revisionary')],
			'text-added': [__('Add', 'revisionary'), __('Text', 'revisionary')],
			'text-removed': [__('Remove', 'revisionary'), __('Text', 'revisionary')],
			'text-modified': [__('Modify', 'revisionary'), __('Text', 'revisionary')],
			'link-modified': [__('Change', 'revisionary'), __('Link', 'revisionary')],
		};
		const lines = [];
		let visibleFormattingChanges = 0;
		let addedLineBreaks = 0;
		let removedLineBreaks = 0;
		DIFF_TYPE_DEFINITIONS.forEach(([type, selector]) => {
			const leafNodes = Array.from(container.querySelectorAll(selector)).filter(
				(node) => !node.querySelector(selector)
			);
			if (type === 'text-added') {
				addedLineBreaks = leafNodes.filter((node) => node.classList.contains('visual-post-compare-line-break-diff')).length;
			}
			if (type === 'text-removed') {
				removedLineBreaks = leafNodes.filter((node) => node.classList.contains('visual-post-compare-line-break-diff')).length;
			}
			const count = leafNodes.filter(
				(node) => !node.classList.contains('visual-post-compare-line-break-diff')
			).length;
			container.classList.toggle(type, leafNodes.length > 0);
			if (count && type === 'text-added') {
				lines.push(count + ' ' + (count === 1
					? __('Text Addition', 'revisionary')
					: __('Text Additions', 'revisionary')));
			} else if (count && type === 'text-removed') {
				lines.push(count + ' ' + (count === 1
					? __('Text Deletion', 'revisionary')
					: __('Text Deletions', 'revisionary')));
			} else if (count && type === 'text-modified') {
				visibleFormattingChanges = count;
			} else if (count && type === 'link-modified') {
				lines.push(count + ' ' + (count === 1 ? __('Link Change', 'revisionary') : __('Link Changes', 'revisionary')));
			} else if (count) {
				lines.push(actionLabels[type][0] + ' ' + countLabel(actionLabels[type][1], count));
			}
		});
		const lineBreakLabel = (count) => count === 1
			? __('Line Break', 'revisionary')
			: countLabel(__('Line Break', 'revisionary'), count);
		if (addedLineBreaks) lines.push(__('Add', 'revisionary') + ' ' + lineBreakLabel(addedLineBreaks));
		if (removedLineBreaks) lines.push(__('Remove', 'revisionary') + ' ' + lineBreakLabel(removedLineBreaks));
		const blockWrapper = container.closest('[data-block]') || container;
		const unrepresentedFormattingChanges = Number(
			blockWrapper.dataset.visualPostCompareUnrepresentedFormattingCount || 0
		);
		const formattingChangeCount = visibleFormattingChanges + unrepresentedFormattingChanges;
		if (formattingChangeCount) {
			lines.push(formattingChangeCount + ' ' + (formattingChangeCount === 1
				? __('formatting change', 'revisionary')
				: __('formatting changes', 'revisionary')));
		}
		if (blockWrapper.dataset.visualPostCompareUnrepresentedChanges) {
			try {
				lines.push(...JSON.parse(blockWrapper.dataset.visualPostCompareUnrepresentedChanges).filter(
					(summary) => !isModifiedHtmlTagSummary(summary)
				));
			} catch (error) {
				// Ignore malformed transient metadata rather than suppressing the tooltip.
			}
		}
		if (!lines.length && blockIndex) {
			const wrapper = container.closest('[data-block]');
			const block = wrapper && blockIndex.get(wrapper.getAttribute('data-block'));
			const status = block && block.attributes && block.attributes.__revisionDiffStatus;
			const attributes = status && Object.keys(status.changedAttributes || {});
			if (attributes && attributes.length) {
				lines.push(__('Modified attributes:', 'revisionary') + ' ' + attributes.join(', '));
			} else if (status && status.currentHtml !== status.previousHtml) {
				lines.push(__('Modified html markup', 'revisionary'));
			}
		}
		return tooltipCaption(
			__('modify', 'revisionary'),
			containerType(container, blockIndex),
			isPastRevision,
			lines
		);
	}

	function addedOrRemovedContainerSummary(container, action, blockIndex, isPastRevision) {
		return tooltipCaption(action, containerType(container, blockIndex), isPastRevision);
	}

	function highlightShortcodeFormatContainers(preview) {
		const contentRoot = preview.querySelector('.visual-post-compare-revision__block-preview') || preview;
		const topLevelUnit = (node) => {
			let unit = node;
			while (unit && unit.parentElement && unit.parentElement !== contentRoot) unit = unit.parentElement;
			return unit;
		};
		Array.from(preview.querySelectorAll('[data-visual-post-compare-shortcode-start]')).forEach((start) => {
			const key = start.getAttribute('data-visual-post-compare-shortcode-start') || '';
			const end = Array.from(preview.querySelectorAll('[data-visual-post-compare-shortcode-end]')).find(
				(marker) => (marker.getAttribute('data-visual-post-compare-shortcode-end') || '') === key
			);
			if (!end) return;
			const startUnit = topLevelUnit(start);
			const endUnit = topLevelUnit(end);
			if (!startUnit || !endUnit || startUnit.parentElement !== endUnit.parentElement) return;
			const expandedUnits = [];
			for (let unit = startUnit.nextElementSibling; unit && unit !== endUnit; unit = unit.nextElementSibling) {
				if (!unit.matches('[data-visual-post-compare-shortcode-sentinel-only="true"]')) expandedUnits.push(unit);
			}
			expandedUnits.forEach((unit) => {
				const changeSelector = [
					'.revision-diff-format-added',
					'.revision-diff-format-removed',
					'.revision-diff-format-changed',
					'.revision-diff-link-changed',
					'.revision-diff-added',
					'.revision-diff-removed',
					'.visual-post-compare-image-diff--added',
					'.visual-post-compare-image-diff--removed',
					'.visual-post-compare-image-diff--modified',
					'.is-revision-added',
					'.is-revision-removed',
					'.is-revision-modified',
				].join(', ');
				const changeNodes = Array.from(unit.querySelectorAll(changeSelector));
				if (unit.matches(changeSelector)) changeNodes.unshift(unit);
				if (!changeNodes.length) return;
				const galleryClass = (element) => element
					&& /(?:^|\s)(?:gallery|tiled-gallery)(?:\s|$)/i.test(element.className || '');
				const gallery = Array.from(unit.querySelectorAll('[class]')).find((candidate) =>
					galleryClass(candidate) && changeNodes.some((node) => candidate.contains(node))
				);
				const source = changeNodes[0];
				const container = gallery || source.closest(
					'table, figure, [class*="gallery"], section, article, div'
				) || unit;
				const target = unit.contains(container) ? container : unit;
				const addedOrRemovedContainer = target.closest('.is-revision-added, .is-revision-removed');
				if (addedOrRemovedContainer && unit.contains(addedOrRemovedContainer)) {
					addedOrRemovedContainer.classList.add('is-shortcode-diff-container');
					if (gallery) addedOrRemovedContainer.dataset.visualPostCompareContainerType = 'gallery';
					return;
				}
				if (target !== unit && unit.classList.contains('is-revision-modified')) {
					unit.classList.remove('is-revision-modified', 'is-revision-changed');
					delete unit.dataset.visualPostCompareContainerDiff;
					delete unit.dataset.visualPostCompareTooltip;
				}
				if (gallery) target.dataset.visualPostCompareContainerType = 'gallery';
				target.classList.add('is-revision-modified', 'is-shortcode-diff-container');
			});
		});
	}

	function normalizeContainerDiffStatuses(preview) {
		Array.from(preview.querySelectorAll('.is-revision-added, .is-revision-removed')).forEach((container) => {
			const overlappingModified = [container, ...container.querySelectorAll(
				'.is-revision-modified, .is-revision-changed'
			)];
			overlappingModified.forEach((modified) => {
				modified.classList.remove(
					'is-revision-modified',
					'is-revision-changed',
					'is-diff-border-active',
					'is-diff-tooltip-active'
				);
				delete modified.dataset.visualPostCompareContainerDiff;
				delete modified.dataset.visualPostCompareTooltip;
			});
		});
	}

	function applyAddedRemovedLinkMetadata(preview) {
		if (!preview) return;
		Array.from(preview.querySelectorAll('a')).forEach((link) => {
			if (link.dataset.visualPostCompareLinkAttributes) return;
			const removed = link.closest('.revision-diff-removed, .is-revision-removed');
			const added = link.closest('.revision-diff-added, .is-revision-added');
			if (!removed && !added) return;
			const url = link.getAttribute('href') || '';
			link.dataset.visualPostCompareLinkAttributes = JSON.stringify([{
				label: 'url:',
				current: added ? url : '',
				previous: removed ? url : '',
			}]);
		});
	}

	function applyDiffMetadata(preview, blockIndex, isPastRevision) {
		if (!preview) return;
		normalizeContainerDiffStatuses(preview);
		applyAddedRemovedLinkMetadata(preview);
		const individualSelector = DIFF_TYPE_DEFINITIONS.map((definition) => definition[1]).join(', ');
		if (!config.htmlAttributeChanges) {
			Array.from(preview.querySelectorAll('.is-revision-modified')).forEach((container) => {
				const blockWrapper = container.closest('[data-block]') || container;
				const hasIndividualChange = container.matches(individualSelector)
					|| Boolean(container.querySelector(individualSelector));
				const hasUnrepresentedChange = Boolean(
					Number(blockWrapper.dataset.visualPostCompareUnrepresentedFormattingCount || 0)
					|| blockWrapper.dataset.visualPostCompareUnrepresentedChanges
				);
				if (!hasIndividualChange && !hasUnrepresentedChange) {
					container.classList.remove('is-revision-modified', 'is-revision-changed');
					delete container.dataset.visualPostCompareContainerDiff;
					delete container.dataset.visualPostCompareTooltip;
				}
			});
		}
		Array.from(preview.querySelectorAll(individualSelector)).forEach((node) => {
			if (node.closest('.visual-post-compare-revision__post-title')) return;
			const definition = classifyDiffNode(node);
			if (definition) {
				node.dataset.visualPostCompareTooltip = individualDiffTooltip(
					node,
					definition,
					isPastRevision
				);
				if (config.tooltipsEnabled) node.removeAttribute('title');
			}
		});
		Array.from(preview.querySelectorAll('.is-revision-modified')).forEach((container) => {
			if (container.matches('.visual-post-compare-revision__post-title')) return;
			container.dataset.visualPostCompareContainerDiff = 'true';
			container.dataset.visualPostCompareTooltip = modifiedContainerSummary(container, blockIndex, isPastRevision);
		});
		Array.from(preview.querySelectorAll('.is-revision-added')).forEach((container) => {
			container.dataset.visualPostCompareContainerDiff = 'true';
			container.dataset.visualPostCompareTooltip = addedOrRemovedContainerSummary(
				container, __('add', 'revisionary'), blockIndex, isPastRevision
			);
		});
		Array.from(preview.querySelectorAll('.is-revision-removed')).forEach((container) => {
			container.dataset.visualPostCompareContainerDiff = 'true';
			container.dataset.visualPostCompareTooltip = addedOrRemovedContainerSummary(
				container, __('remove', 'revisionary'), blockIndex, isPastRevision
			);
		});
	}

	function findShortcodeDiffNodes(preview, nodes) {
		const shortcodeNodes = new WeakSet();
		const diffNodeSet = new Set(nodes);
		const walker = document.createTreeWalker(
			preview,
			NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_COMMENT
		);
		let shortcodeDepth = 0;
		while (walker.nextNode()) {
			const candidate = walker.currentNode;
			if (candidate.nodeType === Node.COMMENT_NODE) {
				const comment = (candidate.nodeValue || '').trim();
				if (/^\/visual-post-compare-shortcode:/.test(comment)) {
					shortcodeDepth = Math.max(0, shortcodeDepth - 1);
				} else if (/^visual-post-compare-shortcode:/.test(comment)) {
					shortcodeDepth++;
					let ancestor = candidate.parentElement;
					while (ancestor && ancestor !== preview) {
						if (diffNodeSet.has(ancestor)) shortcodeNodes.add(ancestor);
						ancestor = ancestor.parentElement;
					}
				}
			} else if (shortcodeDepth > 0 && diffNodeSet.has(candidate)) {
				shortcodeNodes.add(candidate);
			}
		}
		return shortcodeNodes;
	}

	function getDiffMarkerStatus(node) {
		if (
			node.closest('.is-revision-added')
			|| node.classList.contains('revision-diff-added')
			|| node.classList.contains('visual-post-compare-image-diff--added')
		) {
			return 'added';
		}
		if (
			node.closest('.is-revision-removed')
			|| node.classList.contains('revision-diff-removed')
			|| node.classList.contains('visual-post-compare-image-diff--removed')
		) {
			return 'removed';
		}
		return 'modified';
	}

	function indexBlocksByClientId(blockList, index = new Map()) {
		(blockList || []).forEach((block) => {
			if (!block) return;
			if (block.clientId) index.set(block.clientId, block);
			indexBlocksByClientId(block.innerBlocks, index);
		});
		return index;
	}

	function blockForDiffNode(node, blockIndex) {
		if (!node || !blockIndex) return null;
		const wrapper = node.closest('[data-block]');
		return wrapper ? blockIndex.get(wrapper.getAttribute('data-block')) || null : null;
	}

	const VISUAL_FORMAT_TAGS = new Set([
		'b', 'strong', 'i', 'em', 'ins', 'del', 'small', 'big', 'large', 'sup', 'sub',
		'pre', 'q', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
	]);

	function normalizedElementText(element) {
		return (element && element.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function parseHtmlFragment(html) {
		const template = document.createElement('template');
		template.innerHTML = String(html || '');
		return template;
	}

	function rawImageSignature(image) {
		return JSON.stringify(Array.from(image.attributes)
			.filter((attribute) => attribute.name !== 'id')
			.map((attribute) => [attribute.name, attribute.value])
			.sort(([a], [b]) => (a < b ? -1 : (a > b ? 1 : 0))));
	}

	function findRenderedImage(blockWrapper, sourceImage, usedImages) {
		const source = sourceImage.getAttribute('src') || '';
		const candidates = Array.from(blockWrapper.querySelectorAll(
			'img:not(.visual-post-compare-image-diff__previous)'
		)).filter((image) => !usedImages.has(image));
		const match = candidates.find((image) => (image.getAttribute('src') || '') === source)
			|| candidates[0]
			|| null;
		if (match) usedImages.add(match);
		return match;
	}

	function wrapModifiedImage(currentImage, previousImage) {
		if (!currentImage || !previousImage || currentImage.closest('.visual-post-compare-image-diff--modified')) return false;
		const swap = document.createElement('span');
		swap.className = 'visual-post-compare-image-diff--modified visual-post-compare-block-image-diff';
		swap.tabIndex = 0;
		swap.setAttribute('role', 'button');
		swap.setAttribute('aria-label', __('Show previous image', 'revisionary'));
		const previous = previousImage.cloneNode(true);
		previous.removeAttribute('id');
		currentImage.classList.add('visual-post-compare-image-diff__current');
		previous.className = 'visual-post-compare-image-diff__previous';
		previous.setAttribute('aria-hidden', 'true');
		const backup = document.createElement('span');
		backup.className = 'dashicons dashicons-backup visual-post-compare-image-diff__backup';
		backup.setAttribute('aria-hidden', 'true');
		currentImage.parentNode.insertBefore(swap, currentImage);
		swap.append(currentImage, previous, backup);
		const showPrevious = () => swap.classList.add('is-showing-previous');
		const showCurrent = () => swap.classList.remove('is-showing-previous');
		swap.addEventListener('mouseenter', showPrevious);
		swap.addEventListener('mouseleave', showCurrent);
		swap.addEventListener('click', (event) => {
			event.preventDefault();
			if (event.target === previous || swap.classList.contains('is-showing-previous')) showCurrent();
			else showPrevious();
		});
		swap.addEventListener('keydown', (event) => {
			if (event.key !== 'Enter' && event.key !== ' ') return;
			event.preventDefault();
			swap.classList.toggle('is-showing-previous');
		});
		return true;
	}

	function decorateExplicitHtmlImages(blockWrapper, diffStatus) {
		if (!diffStatus.currentHtml && !diffStatus.previousHtml) return;
		const currentTemplate = parseHtmlFragment(diffStatus.currentHtml);
		const previousTemplate = parseHtmlFragment(diffStatus.previousHtml);
		const currentImages = Array.from(currentTemplate.content.querySelectorAll('img'));
		const previousImages = Array.from(previousTemplate.content.querySelectorAll('img'));
		if (!currentImages.length && !previousImages.length) return;

		const usedCurrent = new Set();
		const usedPrevious = new Set();
		const unchangedPairs = [];
		currentImages.forEach((currentImage, currentIndex) => {
			const signature = rawImageSignature(currentImage);
			const previousIndex = previousImages.findIndex((previousImage, index) =>
				!usedPrevious.has(index) && rawImageSignature(previousImage) === signature
			);
			if (previousIndex < 0) return;
			usedCurrent.add(currentIndex);
			usedPrevious.add(previousIndex);
			unchangedPairs.push([currentIndex, previousIndex]);
		});
		const remainingCurrent = currentImages.map((image, index) => ({ image, index }))
			.filter((item) => !usedCurrent.has(item.index));
		const remainingPrevious = previousImages.map((image, index) => ({ image, index }))
			.filter((item) => !usedPrevious.has(item.index));
		const galleryContainer = blockWrapper.matches('.wp-block-gallery, [class~="gallery"], [class~="tiled-gallery"]')
			|| blockWrapper.querySelector('.wp-block-gallery, [class~="gallery"], [class~="tiled-gallery"]');
		const modifiedPairs = [];
		const pairedCurrent = new Set();
		const pairedPrevious = new Set();
		remainingCurrent.forEach((current, currentIndex) => {
			let previousIndex = -1;
			if (!galleryContainer && remainingCurrent.length === remainingPrevious.length) {
				previousIndex = currentIndex;
			} else {
				const alt = current.image.getAttribute('alt') || '';
				previousIndex = remainingPrevious.findIndex((previous, index) =>
					!pairedPrevious.has(index)
					&& alt
					&& alt === (previous.image.getAttribute('alt') || '')
				);
			}
			if (previousIndex < 0 || pairedPrevious.has(previousIndex)) return;
			pairedCurrent.add(currentIndex);
			pairedPrevious.add(previousIndex);
			modifiedPairs.push([current, remainingPrevious[previousIndex]]);
		});
		const renderedUsed = new Set();
		unchangedPairs.forEach(([currentIndex]) => {
			findRenderedImage(blockWrapper, currentImages[currentIndex], renderedUsed);
		});
		modifiedPairs.forEach(([current, previous]) => {
			const rendered = findRenderedImage(blockWrapper, current.image, renderedUsed);
			if (wrapModifiedImage(rendered, previous.image)) {
				diffStatus.hasGranularImageDiff = true;
			}
		});
		remainingCurrent.filter((item, index) => !pairedCurrent.has(index)).forEach(({ image }) => {
			const rendered = findRenderedImage(blockWrapper, image, renderedUsed);
			if (!rendered) return;
			rendered.classList.add('visual-post-compare-image-diff--added');
			rendered.dataset.visualPostCompareImageDiff = 'added';
			diffStatus.hasGranularImageDiff = true;
		});
		remainingPrevious.filter((item, index) => !pairedPrevious.has(index)).forEach(({ image }) => {
			const removed = image.cloneNode(true);
			removed.removeAttribute('id');
			removed.classList.add('visual-post-compare-image-diff--removed');
			removed.dataset.visualPostCompareImageDiff = 'removed';
			removed.dataset.visualPostCompareSynthesized = 'removed';
			blockWrapper.appendChild(removed);
			diffStatus.hasGranularImageDiff = true;
		});
	}

	function visualFormatElements(root) {
		return Array.from(root.querySelectorAll('*')).filter((element) => {
			const tag = element.tagName.toLowerCase();
			return VISUAL_FORMAT_TAGS.has(tag) || (tag === 'span' && element.hasAttribute('style'));
		});
	}

	function semanticFormatName(element) {
		if (!element) return '';
		const tag = element.tagName.toLowerCase();
		if (tag === 'b' || tag === 'strong') return __('bolding', 'revisionary');
		if (tag === 'i' || tag === 'em') return __('italics', 'revisionary');
		if (tag === 'u' || tag === 'ins') return __('underscore', 'revisionary');
		if (tag === 's' || tag === 'strike' || tag === 'del') return __('strikethrough', 'revisionary');
		return '';
	}

	function isFontSizeFormat(element) {
		if (!element) return false;
		const tag = element.tagName.toLowerCase();
		return ['small', 'big', 'large', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(tag)
			|| Boolean(element.style && element.style.getPropertyValue('font-size'));
	}

	function semanticFormatChangeSummaries(current, previous) {
		if (isFontSizeFormat(current) || isFontSizeFormat(previous)) {
			return [__('Change font size', 'revisionary')];
		}
		const currentFormat = semanticFormatName(current);
		const previousFormat = semanticFormatName(previous);
		if (currentFormat && currentFormat === previousFormat) return [];
		const summaries = [];
		if (previousFormat) summaries.push(__('Remove', 'revisionary') + ' ' + previousFormat);
		if (currentFormat) summaries.push(__('Add', 'revisionary') + ' ' + currentFormat);
		return summaries;
	}

	function formatElementSummary(current, previous) {
		if (!current || !previous) return '';
		const details = [];
		const tag = current.tagName.toLowerCase();
		const label = tag;
		const currentFontSize = current.style && current.style.getPropertyValue('font-size');
		const previousFontSize = previous.style && previous.style.getPropertyValue('font-size');
		if (currentFontSize !== previousFontSize) details.push(__('Change font size', 'revisionary'));
		if (!config.htmlAttributeChanges) return details.join('\n');
		const currentClasses = new Set((current.getAttribute('class') || '').split(/\s+/).filter(Boolean));
		const previousClasses = new Set((previous.getAttribute('class') || '').split(/\s+/).filter(Boolean));
		const addedClasses = Array.from(currentClasses).filter((name) => !previousClasses.has(name));
		const removedClasses = Array.from(previousClasses).filter((name) => !currentClasses.has(name));
		if (addedClasses.length) details.push(label + ' ' + __('class added:', 'revisionary') + ' ' + addedClasses.join(', '));
		if (removedClasses.length) details.push(label + ' ' + __('class removed:', 'revisionary') + ' ' + removedClasses.join(', '));
		if ((current.getAttribute('style') || '') !== (previous.getAttribute('style') || '')) {
			const currentStyle = current.getAttribute('style') || '';
			const previousStyle = previous.getAttribute('style') || '';
			if (currentStyle.replace(/font-size\s*:[^;]+;?/gi, '')
				!== previousStyle.replace(/font-size\s*:[^;]+;?/gi, '')) {
				details.push(label + ' ' + __('style modified', 'revisionary'));
			}
		}
		const excludedAttributes = new Set(['class', 'style', 'href']);
		const currentAttributes = new Map(Array.from(current.attributes).map(
			(attribute) => [attribute.name, attribute.value]
		));
		const previousAttributes = new Map(Array.from(previous.attributes).map(
			(attribute) => [attribute.name, attribute.value]
		));
		new Set([...currentAttributes.keys(), ...previousAttributes.keys()]).forEach((name) => {
			if (excludedAttributes.has(name) || name.startsWith('data-visual-post-compare-')) return;
			if (currentAttributes.get(name) === previousAttributes.get(name)) return;
			details.push(label + ' ' + __('attribute modified:', 'revisionary') + ' ' + name);
		});
		return details.join('\n');
	}

	function findRenderedFormatElement(blockWrapper, sourceElement, usedElements) {
		const tag = sourceElement.tagName.toLowerCase();
		const text = normalizedElementText(sourceElement);
		const candidates = Array.from(blockWrapper.querySelectorAll(tag)).filter(
			(element) => !usedElements.has(element) && normalizedElementText(element) === text
		);
		const match = candidates[0] || null;
		if (match) usedElements.add(match);
		return match;
	}

	function markRenderedTextFormat(blockWrapper, text, summary) {
		if (!text) return false;
		const walker = document.createTreeWalker(blockWrapper, NodeFilter.SHOW_TEXT);
		while (walker.nextNode()) {
			const node = walker.currentNode;
			if (!node.parentElement) continue;
			const offset = node.nodeValue.indexOf(text);
			if (offset < 0) continue;
			const existingMarker = node.parentElement.closest(
				'.revision-diff-format-added, .revision-diff-format-removed, .revision-diff-format-changed'
			);
			if (existingMarker) {
				existingMarker.dataset.visualPostCompareFormatSummary = summary;
				return true;
			}
			const marker = document.createElement('span');
			marker.className = 'revision-diff-format-changed';
			marker.dataset.visualPostCompareFormatSummary = summary;
			const tail = node.splitText(offset);
			tail.splitText(text.length);
			tail.parentNode.insertBefore(marker, tail);
			marker.appendChild(tail);
			return true;
		}
		return false;
	}

	function markFormatElement(element, summary) {
		if (!element) return false;
		const target = element.closest('.revision-diff-format-changed') || element;
		target.classList.add('revision-diff-format-changed');
		if (summary) target.dataset.visualPostCompareFormatSummary = summary;
		return true;
	}

	function linkAttributeChanges(current, previous) {
		if (!current || !previous) return [];
		const currentAttributes = new Map(Array.from(current.attributes).map((attribute) => [attribute.name, attribute.value]));
		const previousAttributes = new Map(Array.from(previous.attributes).map((attribute) => [attribute.name, attribute.value]));
		return Array.from(new Set([...currentAttributes.keys(), ...previousAttributes.keys()]))
			.filter((name) => !name.startsWith('data-visual-post-compare-'))
			.filter((name) => currentAttributes.get(name) !== previousAttributes.get(name))
			.map((name) => ({
				label: 'href' === name ? 'url:' : name,
				current: currentAttributes.get(name) || '',
				previous: previousAttributes.get(name) || '',
			}));
	}

	function markLinkElement(element, changes = []) {
		if (!element) return false;
		const formatMarker = element.closest(
			'.revision-diff-format-added, .revision-diff-format-removed, .revision-diff-format-changed'
		);
		if (formatMarker && !formatMarker.dataset.visualPostCompareFormatSummary) {
			formatMarker.classList.remove(
				'revision-diff-format-added',
				'revision-diff-format-removed',
				'revision-diff-format-changed'
			);
		}
		element.classList.add('revision-diff-link-changed');
		if (changes.length) element.dataset.visualPostCompareLinkAttributes = JSON.stringify(changes);
		return true;
	}

	function clearUnreportedFormatMarker(element) {
		if (!element) return;
		const marker = element.closest(
			'.revision-diff-format-added, .revision-diff-format-removed, .revision-diff-format-changed'
		);
		if (!marker || marker.dataset.visualPostCompareFormatSummary) return;
		if (normalizedElementText(marker) !== normalizedElementText(element)) return;
		marker.classList.remove(
			'revision-diff-format-added',
			'revision-diff-format-removed',
			'revision-diff-format-changed'
		);
	}

	function isModifiedHtmlTagSummary(summary) {
		const prefix = __('Modified html tag:', 'revisionary');
		return String(summary || '').split(/\r?\n/).some(
			(line) => line.trim().startsWith(prefix)
		);
	}

	function decorateHtmlFormattingDiffs(blockWrapper, diffStatus) {
		if (!diffStatus.currentHtml && !diffStatus.previousHtml) return;
		const currentTemplate = parseHtmlFragment(diffStatus.currentHtml);
		const previousTemplate = parseHtmlFragment(diffStatus.previousHtml);
		const currentFormats = visualFormatElements(currentTemplate.content);
		const previousFormats = visualFormatElements(previousTemplate.content);
		const usedPrevious = new Set();
		const usedRendered = new Set();
		const unrepresented = [];
		let unrepresentedFormattingCount = 0;
		const recordUnrepresented = (summary) => {
			if (isModifiedHtmlTagSummary(summary)) return;
			if (semanticFormatParts(summary).length) unrepresentedFormattingCount++;
			else unrepresented.push(summary);
		};

		const currentLinks = Array.from(currentTemplate.content.querySelectorAll('a'));
		const previousLinks = Array.from(previousTemplate.content.querySelectorAll('a'));
		const renderedLinks = Array.from(blockWrapper.querySelectorAll('a'));
		const linkedImageUrl = (link) => {
			const image = link && link.querySelector('img');
			return image ? image.getAttribute('src') || '' : '';
		};
		const usedPreviousLinks = new Set();
		const usedRenderedLinks = new Set();
		currentLinks.forEach((current, currentIndex) => {
			const text = normalizedElementText(current);
			const imageUrl = linkedImageUrl(current);
			let previousIndex = previousLinks.findIndex((previous, index) =>
				!usedPreviousLinks.has(index)
				&& (imageUrl
					? linkedImageUrl(previous) === imageUrl
					: normalizedElementText(previous) === text)
			);
			if (!imageUrl && previousIndex < 0 && previousLinks[currentIndex] && !usedPreviousLinks.has(currentIndex)) {
				previousIndex = currentIndex;
			}
			if (previousIndex < 0) return;
			usedPreviousLinks.add(previousIndex);
			const changedAttributes = linkAttributeChanges(current, previousLinks[previousIndex]);
			if (!changedAttributes.length) return;
			const currentHref = current.getAttribute('href') || '';
			const rendered = renderedLinks.find((link) =>
				!usedRenderedLinks.has(link)
				&& imageUrl
				&& linkedImageUrl(link) === imageUrl
			) || renderedLinks.find((link) =>
				!usedRenderedLinks.has(link)
				&& (link.getAttribute('href') || '') === currentHref
				&& normalizedElementText(link) === text
			) || renderedLinks.find((link) =>
				!usedRenderedLinks.has(link) && (link.getAttribute('href') || '') === currentHref
			) || renderedLinks.find((link) =>
				!usedRenderedLinks.has(link) && normalizedElementText(link) === text
			) || renderedLinks[currentIndex];
			if (rendered) usedRenderedLinks.add(rendered);
			markLinkElement(rendered, changedAttributes);
		});

		currentFormats.forEach((current) => {
			const text = normalizedElementText(current);
			let previousIndex = previousFormats.findIndex((previous, index) =>
				!usedPrevious.has(index)
				&& previous.tagName === current.tagName
				&& normalizedElementText(previous) === text
			);
			if (previousIndex < 0) {
				const currentSemantic = semanticFormatName(current);
				previousIndex = previousFormats.findIndex((previous, index) =>
					!usedPrevious.has(index)
					&& (
						previous.tagName === current.tagName
						|| (currentSemantic && currentSemantic === semanticFormatName(previous))
						|| (isFontSizeFormat(current) && isFontSizeFormat(previous))
					)
				);
			}
			if (previousIndex >= 0) {
				usedPrevious.add(previousIndex);
				const attributeSummary = formatElementSummary(current, previousFormats[previousIndex]);
				const rendered = findRenderedFormatElement(blockWrapper, current, usedRendered);
				if (!attributeSummary) {
					clearUnreportedFormatMarker(rendered);
					return;
				}
				if (!markFormatElement(rendered, attributeSummary)) recordUnrepresented(attributeSummary);
				return;
			}
			previousIndex = previousFormats.findIndex((previous, index) =>
				!usedPrevious.has(index) && normalizedElementText(previous) === text
			);
			const changedTags = [current.tagName.toLowerCase()];
			let previous = null;
			if (previousIndex >= 0) {
				usedPrevious.add(previousIndex);
				previous = previousFormats[previousIndex];
				changedTags.unshift(previous.tagName.toLowerCase());
			}
			const semanticSummaries = semanticFormatChangeSummaries(current, previous);
			if (!semanticSummaries.length && previous && semanticFormatName(current) === semanticFormatName(previous)) return;
			if (!semanticSummaries.length && !config.htmlAttributeChanges) {
				const rendered = findRenderedFormatElement(blockWrapper, current, usedRendered);
				clearUnreportedFormatMarker(rendered);
				return;
			}
			const summary = semanticSummaries.length
				? semanticSummaries.join('\n')
				: __('Modified html tag:', 'revisionary') + ' ' + Array.from(new Set(changedTags)).join(', ');
			const rendered = findRenderedFormatElement(blockWrapper, current, usedRendered);
			if (!markFormatElement(rendered, summary)) recordUnrepresented(summary);
		});

		previousFormats.forEach((previous, index) => {
			if (usedPrevious.has(index)) return;
			const semanticSummaries = semanticFormatChangeSummaries(null, previous);
			if (!semanticSummaries.length && !config.htmlAttributeChanges) return;
			const summary = semanticSummaries.length
				? semanticSummaries.join('\n')
				: __('Modified html tag:', 'revisionary') + ' ' + previous.tagName.toLowerCase();
			if (!markRenderedTextFormat(blockWrapper, normalizedElementText(previous), summary)) {
				recordUnrepresented(summary);
			}
		});

		const currentElements = Array.from(currentTemplate.content.querySelectorAll('*:not(img)'));
		const previousElements = Array.from(previousTemplate.content.querySelectorAll('*:not(img)'));
		const currentFormatSet = new Set(currentFormats);
		const previousFormatSet = new Set(previousFormats);
		currentElements.forEach((current, index) => {
			const previous = previousElements[index];
			if (currentFormatSet.has(current) || (previous && previousFormatSet.has(previous))) return;
			if (!previous) {
				if (!config.htmlAttributeChanges) return;
				const summary = __('Modified html tag:', 'revisionary') + ' ' + current.tagName.toLowerCase();
				const rendered = findRenderedFormatElement(blockWrapper, current, usedRendered);
				if (!markFormatElement(rendered, summary)) recordUnrepresented(summary);
				return;
			}
			if (current.tagName !== previous.tagName) {
				const semanticSummaries = semanticFormatChangeSummaries(current, previous);
				if (!semanticSummaries.length && semanticFormatName(current) === semanticFormatName(previous)) return;
				if (!semanticSummaries.length && !config.htmlAttributeChanges) return;
				const summary = semanticSummaries.length
					? semanticSummaries.join('\n')
					: __('Modified html tag:', 'revisionary') + ' '
						+ previous.tagName.toLowerCase() + ', ' + current.tagName.toLowerCase();
				const rendered = findRenderedFormatElement(blockWrapper, current, usedRendered);
				if (!markFormatElement(rendered, summary)) recordUnrepresented(summary);
				return;
			}
			const summary = formatElementSummary(current, previous);
			const rendered = findRenderedFormatElement(blockWrapper, current, usedRendered)
				|| Array.from(blockWrapper.querySelectorAll(current.tagName.toLowerCase())).find(
					(element) => normalizedElementText(element) === normalizedElementText(current)
				);
			if (!summary) {
				clearUnreportedFormatMarker(rendered);
				return;
			}
			if (!markFormatElement(rendered, summary)) recordUnrepresented(summary);
		});
		previousElements.slice(currentElements.length).forEach((previous) => {
			if (previousFormatSet.has(previous)) return;
			if (!config.htmlAttributeChanges) return;
			const summary = __('Modified html tag:', 'revisionary') + ' ' + previous.tagName.toLowerCase();
			if (!markRenderedTextFormat(blockWrapper, normalizedElementText(previous), summary)) {
				recordUnrepresented(summary);
			}
		});
		if (unrepresentedFormattingCount) {
			blockWrapper.dataset.visualPostCompareUnrepresentedFormattingCount = String(
				unrepresentedFormattingCount
			);
		}
		if (unrepresented.length) {
			blockWrapper.dataset.visualPostCompareUnrepresentedChanges = JSON.stringify(Array.from(new Set(unrepresented)));
		}
	}

	function decorateBlockImageDiffs(preview, blockIndex, includeImageDiffs = true) {
		if (!preview || !blockIndex) return;
		Array.from(preview.querySelectorAll('[data-block]')).forEach((blockWrapper) => {
			const block = blockIndex.get(blockWrapper.getAttribute('data-block'));
			const diffStatus = block && block.attributes && block.attributes.__revisionDiffStatus;
			if (!block || !diffStatus) return;
			if (diffStatus.status === 'added' || diffStatus.status === 'removed') {
				if (includeImageDiffs) Array.from(blockWrapper.querySelectorAll('img')).forEach((image) => {
					const status = diffStatus.status;
					image.classList.add('visual-post-compare-image-diff--' + status);
					image.dataset.visualPostCompareImageDiff = status;
				});
				return;
			}
			if (diffStatus.status !== 'modified') return;
			if (blockWrapper.dataset.visualPostCompareHtmlDecorated !== 'true') {
				blockWrapper.dataset.visualPostCompareHtmlDecorated = 'true';
				if (includeImageDiffs) decorateExplicitHtmlImages(blockWrapper, diffStatus);
				decorateHtmlFormattingDiffs(blockWrapper, diffStatus);
			}
			if (includeImageDiffs && block.name === 'core/image' && diffStatus.previousImageUrl) {
				const currentImage = blockWrapper.querySelector('img:not(.visual-post-compare-image-diff__previous)');
				if (!currentImage || currentImage.closest('.visual-post-compare-image-diff--modified')) return;
				const previousImage = currentImage.cloneNode(true);
				previousImage.src = diffStatus.previousImageUrl;
				previousImage.removeAttribute('srcset');
				previousImage.removeAttribute('sizes');
				wrapModifiedImage(currentImage, previousImage);
			}
		});
	}

	function BlockDiffSidebar({ block, tooltip }) {
		if (!block) {
			return el('p', { className: 'visual-post-compare-revision__block-empty' },
				__('Select a changed block to view its details.', 'revisionary')
			);
		}
		const blockType = blocks.getBlockType(block.name);
		const diffStatus = block.attributes && block.attributes.__revisionDiffStatus;
		const changedAttributes = diffStatus && diffStatus.changedAttributes;
		const changedAttributeEntries = Object.entries(changedAttributes || {});
		const hasChanges = Boolean(diffStatus && (
			diffStatus.status
			|| diffStatus.hasGranularDiff
			|| diffStatus.hasGranularImageDiff
			|| diffStatus.hasUnrepresentedAttributeDiff
			|| diffStatus.forceContainerModified
			|| diffStatus.currentHtml !== diffStatus.previousHtml
		));
		return el(
			'div',
			{ className: 'visual-post-compare-revision__block-details' },
			el('div', { className: 'visual-post-compare-revision__block-identity' },
				BlockIcon && blockType ? el(BlockIcon, { icon: blockType.icon, showColors: true }) : null,
				el('strong', null, (blockType && blockType.title) || block.name)
			),
			changedAttributeEntries.length ? el('div', { className: 'visual-post-compare-revision__changed-attributes' },
				el('h2', null, __('Changed attributes', 'revisionary')),
				...changedAttributeEntries.map(([attributeName, parts]) => el(
					'div',
					{ className: 'visual-post-compare-revision__attribute-row', key: attributeName },
					el('span', { className: 'visual-post-compare-revision__attribute-label' }, attributeName),
					el('span', { className: 'editor-revision-fields-diff__value' },
						...parts.map((part, index) => part.type === 'added'
							? el('ins', { key: index, className: 'editor-revision-fields-diff__added' }, part.value)
							: part.type === 'removed'
								? el('del', { key: index, className: 'editor-revision-fields-diff__removed' }, part.value)
								: el('span', { key: index }, part.value)
						)
					)
				))
			) : (tooltip
				? el('div', {
					className: 'visual-post-compare-revision__classic-info-text',
					dangerouslySetInnerHTML: { __html: tooltip },
				})
				: (hasChanges
					? el('p', { className: 'visual-post-compare-revision__block-empty' }, __('Changes detected.', 'revisionary'))
					: null))
		);
	}

	function blockTooltipForSelection(blockWrapper, target) {
		if (!blockWrapper) return '';
		const closest = target && target.closest && target.closest('[data-visual-post-compare-tooltip]');
		if (closest && blockWrapper.contains(closest)) return closest.dataset.visualPostCompareTooltip || '';
		if (blockWrapper.dataset.visualPostCompareTooltip) return blockWrapper.dataset.visualPostCompareTooltip;
		const container = blockWrapper.querySelector(
			'[data-visual-post-compare-container-diff="true"][data-visual-post-compare-tooltip]'
		);
		if (container) return container.dataset.visualPostCompareTooltip || '';
		const changedItem = blockWrapper.querySelector('[data-visual-post-compare-tooltip]');
		return changedItem ? changedItem.dataset.visualPostCompareTooltip || '' : '';
	}

	function classicStructuralContainer(node, preview) {
		if (!node || !preview) return null;
		const changedContainer = node.closest && node.closest('[data-visual-post-compare-container-diff="true"]');
		if (changedContainer && preview.contains(changedContainer)) return changedContainer;
		const selector = [
			'[data-visual-post-compare-container-type]',
			'table', 'figure', 'blockquote', 'ul', 'ol', 'p', 'section', 'article', 'div'
		].join(', ');
		let container = node.closest && node.closest(selector);
		while (container && container !== preview && (
			container.classList.contains('visual-post-compare-revision__live-preview')
			|| container.classList.contains('visual-post-compare-revision__block-preview')
			|| container.hasAttribute('data-visual-post-compare-shortcode-sentinel-only')
		)) container = container.parentElement && container.parentElement.closest(selector);
		return container && preview.contains(container) ? container : null;
	}

	function classicAttributeDetails(root) {
		if (!root) return [];
		if (root.dataset.visualPostCompareImageAttributes) {
			try {
				return JSON.parse(root.dataset.visualPostCompareImageAttributes).map((attribute) => ({
					label: attribute.label,
					parts: diffTokens(tokenize(attribute.previous), tokenize(attribute.current)),
				}));
			} catch (error) {
				return [];
			}
		}
		const nodes = [root, ...root.querySelectorAll('[data-visual-post-compare-format-summary]')];
		const rows = [];
		const seen = new Set();
		nodes.forEach((node) => String(node.dataset.visualPostCompareFormatSummary || '')
			.split(/\r?\n/).map((line) => line.trim()).filter(Boolean).forEach((line) => {
				let match = line.match(/^Modified html tag:\s*(.+)$/i);
				if (match) {
					const key = 'html tag|' + match[1];
					if (!seen.has(key)) rows.push({ label: __('html tag', 'revisionary'), value: match[1] });
					seen.add(key);
					return;
				}
				match = line.match(/^(.*?)\s+(attribute modified|class added|class removed):\s*(.+)$/i);
				if (!match) return;
				const label = (match[1] + ' ' + match[2]).toLowerCase();
				const key = label + '|' + match[3];
				if (!seen.has(key)) rows.push({ label, value: match[3] });
				seen.add(key);
			}));
		const linkNodes = [root, ...root.querySelectorAll('[data-visual-post-compare-link-attributes]')]
			.filter((node) => node.dataset.visualPostCompareLinkAttributes);
		linkNodes.forEach((node) => {
			try {
				JSON.parse(node.dataset.visualPostCompareLinkAttributes).forEach((attribute) => {
					const key = 'link|' + attribute.label + '|' + attribute.previous + '|' + attribute.current;
					if (seen.has(key)) return;
					rows.push({
						label: attribute.label,
						parts: diffTokens(tokenize(attribute.previous), tokenize(attribute.current)),
					});
					seen.add(key);
				});
			} catch (error) {
				// Ignore malformed transient metadata without suppressing other details.
			}
		});
		return rows;
	}

	function itemAttributeDetails(target, type) {
		if (!target) return [];
		const changed = classicAttributeDetails(target);
		const rows = [...changed];
		const labels = new Set(rows.map((row) => row.label));
		const addValue = (label, value) => {
			if (!value || labels.has(label)) return;
			rows.push({ label, value });
			labels.add(label);
		};
		if ('image' === type) {
			const image = target.matches && target.matches('img')
				? target
				: target.querySelector('.visual-post-compare-image-diff__current, img:not(.visual-post-compare-image-diff__previous), .visual-post-compare-image-diff__previous');
			const link = (image && image.closest('a')) || (target.closest && target.closest('a'));
			if (image) {
				addValue('image url:', image.getAttribute('src') || '');
				['title', 'description', 'aria-description'].forEach((name) => addValue(name, image.getAttribute(name) || ''));
			}
			if (link) {
				addValue('link url:', link.getAttribute('href') || '');
				['title', 'description', 'aria-description'].forEach((name) => addValue(name, link.getAttribute(name) || ''));
			}
		} else if ('link' === type) {
			addValue('url:', target.getAttribute('href') || '');
			['title', 'description', 'aria-description'].forEach((name) => addValue(name, target.getAttribute(name) || ''));
		}
		return rows;
	}

	function classicInfoTooltip(root) {
		if (!root) return '';
		const html = root.dataset.visualPostCompareTooltip || '';
		if (!html) return '';
		const template = document.createElement('template');
		template.innerHTML = html;
		const details = template.content.querySelector('.visual-post-compare-tooltip-details');
		if (details) {
			const retained = details.innerHTML.split(/<br\s*\/?\s*>/i).filter((item) => !(
				/modified html tag/i.test(item)
				|| /attribute modified/i.test(item)
				|| /class (?:added|removed)/i.test(item)
			));
			if (retained.length) details.innerHTML = retained.join('<br>');
			else details.remove();
		}
		let result = template.innerHTML.trim();
		if (!template.content.querySelector('.visual-post-compare-tooltip-details')) {
			result = result.replace(/:\s*$/, '.');
		}
		return result;
	}

	function classicInfoForNode(node, preview) {
		if (!node || !preview) return null;
		const imageDiff = node.closest && node.closest('.visual-post-compare-image-diff--added, .visual-post-compare-image-diff--removed, .visual-post-compare-image-diff--modified');
		const image = imageDiff || (node.closest && node.closest('img'));
		const link = node.closest && node.closest('a');
		const textChange = node.closest && node.closest('.revision-diff-added, .revision-diff-removed, .revision-diff-format-added, .revision-diff-format-removed, .revision-diff-format-changed');
		let target = image || link || node;
		let title = image ? __('Image', 'revisionary') : (link ? __('Link', 'revisionary') : '');
		if (textChange && !image && !link) target = classicStructuralContainer(textChange, preview) || textChange;
		if (!image && !link) {
			target = target.matches && target.matches('[data-visual-post-compare-container-diff="true"]')
				? target
				: (classicStructuralContainer(target, preview) || target);
			title = containerType(target, null);
		}
		const isAddedOrRemovedItem = Boolean(
			(image && image.matches('.visual-post-compare-image-diff--added, .visual-post-compare-image-diff--removed'))
			|| target.closest('.is-revision-added, .is-revision-removed')
		);
		const isAddedOrRemovedContainer = !image && !link
			&& target.matches('.is-revision-added, .is-revision-removed');
		return {
			title: title || __('Info', 'revisionary'),
			tooltip: classicInfoTooltip(target),
			attributes: isAddedOrRemovedContainer
				? []
				: itemAttributeDetails(target, image ? 'image' : (link ? 'link' : 'container')),
			attributeCaption: isAddedOrRemovedItem || (image && !image.matches('.visual-post-compare-image-diff--modified'))
				? __('Attributes', 'revisionary')
				: __('Changed attributes', 'revisionary'),
		};
	}

	function ClassicInfoSidebar({ info }) {
		if (!info) return el('p', { className: 'visual-post-compare-revision__block-empty' }, __('Select content to view its details.', 'revisionary'));
		return el('div', { className: 'visual-post-compare-revision__block-details' },
			el('div', { className: 'visual-post-compare-revision__block-identity' }, el('strong', null, info.title)),
			info.tooltip
				? el('div', { className: 'visual-post-compare-revision__classic-info-text', dangerouslySetInnerHTML: { __html: info.tooltip } })
				: null,
			info.attributes.length ? el('div', { className: 'visual-post-compare-revision__changed-attributes' },
				el('h2', null, info.attributeCaption || __('Changed attributes', 'revisionary')),
				...info.attributes.map((attribute, index) => el('div', { className: 'visual-post-compare-revision__attribute-row', key: index },
					el('span', { className: 'visual-post-compare-revision__attribute-label' }, attribute.label),
					el('span', { className: 'editor-revision-fields-diff__value' },
						...(attribute.parts || [{ type: 'unchanged', value: attribute.value }]).map((part, partIndex) =>
							part.type === 'added'
								? el('ins', { key: partIndex, className: 'editor-revision-fields-diff__added' }, part.value)
								: part.type === 'removed'
									? el('del', { key: partIndex, className: 'editor-revision-fields-diff__removed' }, part.value)
									: el('span', { key: partIndex }, part.value)
						)
					)
				))
			) : null
		);
	}

	function FieldsPromo({ promo }) {
		if (!promo) return null;
		return el('section', { className: 'visual-post-compare-fields' },
			el('div', { className: 'visual-post-compare-fields__promo pp-revisions-pro-promo-right-sidebar' },
				el('div', { className: 'postbox-container' },
					el('div', { className: 'meta-box-sortables' },
						el('div', { className: 'advertisement-box-content postbox' },
							el('div', { className: 'postbox-header' },
								el('h3', { className: 'advertisement-box-header hndle is-non-sortable' }, __('Compare Custom Fields', 'revisionary'))
							),
							el('div', { className: 'inside' },
								el('p', null, __('Revisions Pro displays cleanly formatted field changes for:', 'revisionary')),
								el('ul', null,
									...[
										__('Featured Image', 'revisionary'),
										__('Advanced Custom Fields', 'revisionary'),
										__('Pods', 'revisionary'),
										__('PublishPress Cart', 'revisionary'),
										__('WooCommerce', 'revisionary'),
										__('Yoast SEO', 'revisionary'),
									].map((label) => el('li', { key: label }, label))
								),
								el('div', { className: 'pp-pro-badge-banner' },
									el('a', { className: 'pp-upgrade-btn', href: promo.url, target: '_blank', rel: 'noopener noreferrer' }, __('Upgrade to Pro', 'revisionary'))
								)
							)
						)
					)
				)
			)
		);
	}

	function RevisionPreview({ comparison, onApprove, isApproving }) {
		const canvasRef = useRef(null);
		const [diffMarkers, setDiffMarkers] = useState([]);
		const [hasCanvasOverflow, setHasCanvasOverflow] = useState(false);
		const [markerTrack, setMarkerTrack] = useState(null);
		const [sidebarTab, setSidebarTab] = useState('revision');
		const [canvasTab, setCanvasTab] = useState('content');
		const [showCurrentDetails, setShowCurrentDetails] = useState(false);
		const [selectedBlock, setSelectedBlock] = useState(null);
		const [selectedBlockTooltip, setSelectedBlockTooltip] = useState('');
		const [classicInfo, setClassicInfo] = useState(null);
		const isCurrentSelected = Number(comparison.revision.id) === Number(comparison.current.id);
		const displayedPost = isCurrentSelected ? comparison.current : comparison.revision;
		const displayedTitle = String(displayedPost.title || '');
		const currentTitle = String(comparison.current.title || '');
		const titleChanged = !isCurrentSelected && displayedTitle !== currentTitle;
		const diffResult = useMemo(() => {
			try {
				return {
					blocks: diffRevisionContent(displayedPost.content || '', comparison.current.content || ''),
					error: null,
				};
			} catch (error) {
				console.error('Visual Post Compare: Unable to build revision diff.', error);
				return { blocks: [], error };
			}
		}, [comparison]);
		const isBlockEditorComparison = containsSerializedBlocks(displayedPost.content || '')
			&& containsSerializedBlocks(comparison.current.content || '');
		const blockIndex = useMemo(() => indexBlocksByClientId(diffResult.blocks), [diffResult.blocks]);
		const hasContentChanges = useMemo(() => {
			const changed = (block) => Boolean(
				block && (
					block.__revisionDiffStatus
					|| (block.attributes && block.attributes.__revisionDiffStatus)
					|| (block.innerBlocks || []).some(changed)
				)
			);
			return diffResult.blocks.some(changed);
		}, [diffResult.blocks]);
		const proFields = window.RevisionaryVisualCompareFields;
		const hasCustomFieldChanges = Boolean(
			proFields && typeof proFields.hasChanges === 'function' && proFields.hasChanges(comparison)
		);
		const fieldSummary = hasCustomFieldChanges && typeof proFields.getSummary === 'function'
			? proFields.getSummary(comparison)
			: [];
		const activeCanvasTab = hasCustomFieldChanges ? canvasTab : 'content';
		const noChangesDetected = !isCurrentSelected && !diffResult.error && !titleChanged && !hasContentChanges && !hasCustomFieldChanges;

		useEffect(() => {
			setSidebarTab('revision');
			setSelectedBlock(null);
			setSelectedBlockTooltip('');
			setClassicInfo(null);
		}, [comparison.revision.id]);

		// WordPress 7.0 exports this hook as __experimentalUseBlockPreview. It renders the block list in this document
		// instead of an auto-sized iframe. That matches the editor revisions canvas
		// more closely and prevents unchanged blocks from disappearing in width=0
		// preview iframes.
		const previewProps = useBlockPreview({
			blocks: diffResult.blocks,
			props: {
				className: 'visual-post-compare-revision__block-preview',
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
			let decoratedPreview = null;
			let activeBorderTarget = null;
			const changedContainerSelector = '.is-revision-added, .is-revision-removed, .is-revision-modified, .is-revision-changed';
			const clearActiveBorder = () => {
				if (activeBorderTarget) activeBorderTarget.classList.remove('is-diff-border-active');
				activeBorderTarget = null;
			};
			const activateBorder = (target) => {
				if (activeBorderTarget === target) return;
				clearActiveBorder();
				activeBorderTarget = target;
				if (activeBorderTarget) activeBorderTarget.classList.add('is-diff-border-active');
			};
			const tooltipTargetAtPoint = (event) => Array.from(
				canvas.querySelectorAll('[data-visual-post-compare-tooltip]')
			).filter((target) => {
				const rect = target.getBoundingClientRect();
				return rect.width > 0 && rect.height > 0
					&& event.clientX >= rect.left && event.clientX < rect.right
					&& event.clientY >= rect.top && event.clientY < rect.bottom;
			}).sort((a, b) => {
				const aRect = a.getBoundingClientRect();
				const bRect = b.getBoundingClientRect();
				return (aRect.width * aRect.height) - (bRect.width * bRect.height);
			})[0] || null;
			const tooltipMove = (event) => {
				const target = tooltipTargetAtPoint(event);
				if (target) showDiffTooltip(target, target.dataset.visualPostCompareTooltip);
				else hideDiffTooltip();
			};
			const tooltipClick = (event) => {
				const target = tooltipTargetAtPoint(event);
				if (target) showDiffTooltip(target, target.dataset.visualPostCompareTooltip);
				else hideDiffTooltip(true);
			};
			const borderClick = (event) => {
				const target = event.target.closest && event.target.closest(changedContainerSelector);
				if (target && canvas.contains(target)) activateBorder(target);
				else clearActiveBorder();
			};
			const markerBorderActivate = (event) => {
				const target = event.detail && event.detail.target;
				if (target && canvas.contains(target) && target.matches(changedContainerSelector)) {
					activateBorder(target);
				}
			};
			const outsideBorderClick = (event) => {
				if (event.target.closest && event.target.closest('.visual-post-compare-revision__diff-marker')) return;
				if (activeBorderTarget && !activeBorderTarget.contains(event.target)) clearActiveBorder();
			};
			const tooltipLeave = (event) => {
				const relatedTarget = event.relatedTarget;
				if (
					relatedTarget
					&& relatedTarget.closest
					&& relatedTarget.closest('.visual-post-compare-revision__diff-marker')
				) return;
				hideDiffTooltip();
			};
			const outsideTooltipClick = (event) => {
				const tooltip = ensureTooltipElement();
				if (tooltip && tooltip.contains(event.target)) {
					hideDiffTooltip(true);
					return;
				}
				if (!canvas.contains(event.target)
					&& !(event.target.closest && event.target.closest('.visual-post-compare-revision__diff-marker'))) {
					hideDiffTooltip(true);
				}
			};
			const blockSelectionClick = (event) => {
				if (!isBlockEditorComparison) return;
				const preview = canvas.querySelector('.visual-post-compare-revision__live-preview');
				const blockWrapper = event.target.closest && event.target.closest('[data-block]');
				const selected = preview && blockWrapper && preview.contains(blockWrapper)
					? blockIndex.get(blockWrapper.getAttribute('data-block')) || null
					: null;
				setSelectedBlock(selected);
				setSelectedBlockTooltip(selected ? blockTooltipForSelection(blockWrapper, event.target) : '');
				if (selected) setSidebarTab('block');
			};
			const classicSelectionClick = (event) => {
				if (isBlockEditorComparison || !event.target.closest) return;
				const preview = canvas.querySelector('.visual-post-compare-revision__live-preview');
				if (!preview || !preview.contains(event.target)) return;
				const info = classicInfoForNode(event.target, preview);
				if (info) {
					setClassicInfo(info);
					setSidebarTab('block');
				}
			};
			const preventPreviewLinkActivation = (event) => {
				if (!event.target.closest) return;
				const link = event.target.closest('a[data-visual-post-compare-link-inert="true"]');
				if (link && canvas.contains(link)) event.preventDefault();
			};
			canvas.addEventListener('click', blockSelectionClick);
			canvas.addEventListener('click', classicSelectionClick);
			canvas.addEventListener('click', borderClick);
			canvas.addEventListener('click', preventPreviewLinkActivation, true);
			canvas.addEventListener('visual-post-compare:activate-container-border', markerBorderActivate);
			document.addEventListener('click', outsideBorderClick);
			if (config.tooltipsEnabled) {
				canvas.addEventListener('mousemove', tooltipMove);
				canvas.addEventListener('mouseleave', tooltipLeave);
				canvas.addEventListener('click', tooltipClick);
				canvas.addEventListener('scroll', positionDiffTooltip);
				window.addEventListener('resize', positionDiffTooltip);
				document.addEventListener('click', outsideTooltipClick);
			}

			const scheduleUpdate = () => {
				if (frame !== null) {
					cancelAnimationFrame(frame);
				}

				frame = requestAnimationFrame(() => {
					frame = null;
					// Fit the canvas to the remaining viewport rather than relying on a
					// content-dependent page height. This calculation intentionally does
					// not distinguish a revision from the current-post reference view.
					const canvasTop = Math.max(0, canvas.getBoundingClientRect().top);
					const availableCanvasHeight = Math.max(
						160,
						Math.floor(window.innerHeight - canvasTop - 16)
					);
					const canvasHeightValue = `${availableCanvasHeight}px`;
					if (canvas.style.getPropertyValue('--visual-post-compare-canvas-height') !== canvasHeightValue) {
						canvas.style.setProperty('--visual-post-compare-canvas-height', canvasHeightValue);
					}
					const preview = canvas.querySelector('.visual-post-compare-revision__live-preview');
					const hasOverflow = canvas.scrollHeight > canvas.clientHeight + 1;
					setHasCanvasOverflow(hasOverflow);
					if (preview) suppressBlockEditorWarnings(preview);
					if (preview) restoreShortcodeContextComments(preview);
					if (preview) enablePreviewTextSelection(preview);
					if (preview && isBlockEditorComparison) enablePreviewBlockSelection(preview);
					if (preview) disablePreviewLinks(preview);

					if (preview && classicDiff && !isCurrentSelected) {
						decoratedPreview = preview;
						classicDiff.decorateImageDiffs(
							preview,
							comparison.revision.content || '',
							comparison.current.content || ''
						);
					}
					if (preview && !isCurrentSelected) {
						decorateBlockImageDiffs(preview, blockIndex, isBlockEditorComparison);
						highlightShortcodeFormatContainers(preview);
						applyDiffMetadata(
							preview,
							isBlockEditorComparison ? blockIndex : null,
							Boolean(comparison.revision.isPastRevision)
						);
					}

					if (isCurrentSelected) {
						setDiffMarkers([]);
						setMarkerTrack(null);
						return;
					}

					if (!preview || !hasOverflow) {
						setDiffMarkers([]);
						setMarkerTrack(null);
						return;
					}

					const selector = [
						'.is-revision-added',
						'.is-revision-removed',
						'.is-revision-modified',
						'.revision-diff-added',
						'.revision-diff-removed',
						'.revision-diff-format-added',
						'.revision-diff-format-removed',
						'.revision-diff-format-changed',
						'.revision-diff-link-changed',
						'.visual-post-compare-image-diff--added',
						'.visual-post-compare-image-diff--removed',
						'.visual-post-compare-image-diff--modified',
					].join(', ');
					const containerSelector = '.is-revision-added, .is-revision-removed, .is-revision-modified, .is-revision-changed';
					const candidateNodes = Array.from(preview.querySelectorAll(selector)).filter((node) => {
						if (node.closest('.visual-post-compare-revision__post-title')) return false;
						if (config.extraMarkers) {
							if (node.matches(containerSelector)) return true;
							return !node.querySelector(selector);
						}
						const parentContainer = node.parentElement
							? node.parentElement.closest(containerSelector)
							: null;
						if (parentContainer) return false;
						if (node.matches(containerSelector)) return true;
						return !node.querySelector(selector);
					});
					const nodes = config.extraMarkers
						? deduplicateCoincidentContainerNodes(candidateNodes, containerSelector)
						: candidateNodes;
					const shortcodeDiffNodes = findShortcodeDiffNodes(preview, nodes);
					const secondaryDiffNodes = findSecondaryDiffNodes(nodes);
					const canvasRect = canvas.getBoundingClientRect();
					const shellRect = canvas.parentElement.getBoundingClientRect();
					setMarkerTrack({
						top: canvasRect.top - shellRect.top,
						height: canvas.clientHeight,
					});
					const canvasHeight = Math.max(canvas.scrollHeight, canvas.clientHeight, 1);

					setDiffMarkers(nodes.map((node, index) => {
						const rect = node.getBoundingClientRect();
						const nodeTop = canvas.scrollTop + rect.top - canvasRect.top;
						const nodeCenter = nodeTop + rect.height / 2;
						const top = Math.max(0, Math.min(100, (nodeCenter / canvasHeight) * 100));
						const height = Math.max(0.8, Math.min(100 - top, (rect.height / canvasHeight) * 100));
						const scrollTop = Math.max(
							0,
							nodeTop - Math.max(0, (canvas.clientHeight - rect.height) / 2)
						);
						const status = getDiffMarkerStatus(node);
						return {
							key: status + '-' + index,
							node,
							status,
							top,
							height,
							scrollTop,
							isSecondaryDiff: secondaryDiffNodes.has(node),
							isShortcodeDiff: shortcodeDiffNodes.has(node),
							isContainerDiff: node.matches(containerSelector),
							block: isBlockEditorComparison ? blockForDiffNode(node, blockIndex) : null,
						};
					}).filter(hasRenderableMarkerTop));
				});
			};

			scheduleUpdate();
			window.addEventListener('resize', scheduleUpdate);

			if (typeof ResizeObserver !== 'undefined') {
				resizeObserver = new ResizeObserver(scheduleUpdate);
				resizeObserver.observe(canvas);
				resizeObserver.observe(canvas.parentElement);
				const preview = canvas.querySelector('.visual-post-compare-revision__live-preview');
				if (preview) {
					resizeObserver.observe(preview);
				}
			}

			if (typeof MutationObserver !== 'undefined') {
				mutationObserver = new MutationObserver(scheduleUpdate);
				mutationObserver.observe(canvas, { childList: true, subtree: true });
			}

			return () => {
				canvas.removeEventListener('click', blockSelectionClick);
				canvas.removeEventListener('click', classicSelectionClick);
				canvas.removeEventListener('click', borderClick);
				canvas.removeEventListener('click', preventPreviewLinkActivation, true);
				canvas.removeEventListener('visual-post-compare:activate-container-border', markerBorderActivate);
				document.removeEventListener('click', outsideBorderClick);
				clearActiveBorder();
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
				// The keyed preview is discarded as one React-owned subtree. Avoid
				// structurally mutating its decorated descendants during teardown.
				if (config.tooltipsEnabled) {
					canvas.removeEventListener('mousemove', tooltipMove);
					canvas.removeEventListener('mouseleave', tooltipLeave);
					canvas.removeEventListener('click', tooltipClick);
					canvas.removeEventListener('scroll', positionDiffTooltip);
					window.removeEventListener('resize', positionDiffTooltip);
					document.removeEventListener('click', outsideTooltipClick);
					hideDiffTooltip(true);
				}
			};
		}, [comparison, diffResult.blocks, isBlockEditorComparison, blockIndex]);

		const markerLabel = (status) => {
			if (status === 'added') {
				return __('Add Block', 'visual-post-compare');
			}
			if (status === 'removed') {
				return __('Remove Block', 'visual-post-compare');
			}
			return __('Modify Block', 'visual-post-compare');
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
			{ href: post.url || '#', className: 'visual-post-compare-revision__date-link', target: '_blank' },
			value
		);
		const statusCaption = (post) => presentation.mimeTypeStatus ? post.mimeTypeStatusLabel : post.statusLabel;
		const dateLine = (post, prefix, value, className) => el(
			'div',
			{ className: 'visual-post-compare-revision__date-row ' + className },
			el('span', { className: 'visual-post-compare-revision__date-prefix' }, prefix),
			post.canEdit && !post.isPastRevision
				? linkedValue(post, value)
				: el('span', { className: 'visual-post-compare-revision__date-value' }, value)
		);
		const authorLine = (post) => post.authorName
			? el('div', { className: 'visual-post-compare-revision__author' }, sprintf(presentation.authorName, post.authorName))
			: null;
		const details = (post) => [
			presentation.showPostDate ? dateLine(post, Number(post.id) !== Number(comparison.current.id) ? presentation.postDatePrefix : __('Post Date: ', 'revisionary'), post.postDateLabel || post.postDate, 'is-post-date') : null,
			presentation.showModified !== false ? dateLine(post, presentation.modifiedPrefix, post.modifiedLabel || post.modified, 'is-modified-date') : null,
			presentation.showAuthor !== false ? authorLine(post) : null,
			Number(post.id) !== Number(comparison.current.id) && post.revision_action ? el('div', { className: 'visual-post-compare-revision__author visual-post-compare-revision__action' }, post.revision_action) : null,
			Number(post.id) !== Number(comparison.current.id) && post.revision_published ? dateLine(post, presentation.approvedDatePrefix, post.revision_published, 'is-approved-date') : null,
			Number(post.id) !== Number(comparison.current.id) && post.approver ? el('div', { className: 'visual-post-compare-revision__author' }, sprintf(presentation.approvedByCaption, post.approver)) : null,
			
			// @todo: current post status, revision type, approved by

		].filter(Boolean);
		const currentDetailsVisible = isCurrentSelected || showCurrentDetails;
		const currentMeta = el('div', { key: 'current', className: isCurrentSelected ? 'is-current-selected' : '' },
			el('span', { className: 'visual-post-compare-revision__status' }, el(
				'a',
				{ href: comparison.current.viewURL || '#', className: 'visual-post-compare-view-link', target: '_blank' },
				presentation.currentCaption
			), el('button', {
				type: 'button',
				className: 'visual-post-compare-revision__current-details-toggle',
				disabled: isCurrentSelected,
				'aria-expanded': currentDetailsVisible,
				'aria-label': currentDetailsVisible ? __('Hide current post details', 'revisionary') : __('Show current post details', 'revisionary'),
				title: currentDetailsVisible ? __('Hide current post details', 'revisionary') : __('Show current post details', 'revisionary'),
				onClick: () => setShowCurrentDetails(!showCurrentDetails),
			}, el('svg', { className: 'components-panel__arrow', width: 24, height: 24, viewBox: '0 0 24 24', 'aria-hidden': true, focusable: 'false' },
				el('path', { d: currentDetailsVisible ? 'M6.5 12 12 7l5.5 5-1 1.1L12 9l-4.5 4.1L6.5 12z' : 'M6.5 8 12 13l5.5-5-1-1.1L12 11 7.5 6.9 6.5 8z' })
			))),
			currentDetailsVisible ? el('strong', null, comparison.current.title || sprintf(__('Post %d', 'revisionary'), comparison.current.id)) : null,
			el('div', { className: 'visual-post-compare-revision__author visual-post-compare-revision__current-status' }, sprintf(presentation.currentStatusCaption, comparison.current.statusLabel)),
			...(currentDetailsVisible ? details(comparison.current) : [])
		);
		const revisionMeta = isCurrentSelected ? el('div', {}, '') : el('div', { key: 'revision' },
			presentation.showRightStatus !== false ? el('span', { className: 'visual-post-compare-revision__status' }, 
			(comparison.revision.isPastRevision ? comparison.revision.previewURL : comparison.revision.viewURL) ? 
			el(
				'a',
				{
					href: comparison.revision.isPastRevision
						? comparison.revision.previewURL
						: comparison.revision.viewURL,
					className: 'visual-post-compare-view-link',
					target: '_blank',
				},
				statusCaption(comparison.revision) || __('Revision', 'revisionary')
			) : statusCaption(comparison.revision) || __('Revision', 'revisionary')) : null,
			el('strong', null, comparison.revision.title || sprintf(__('Revision %d', 'revisionary'), comparison.revision.id)),
			...details(comparison.revision),
			comparison.revision.canApprove
				? el(Button, {
					variant: 'primary',
					className: 'visual-post-compare-revision__approve',
					onClick: onApprove,
					isBusy: isApproving,
					disabled: isApproving,
				}, isApproving ? comparison.revision.approvingCaption : comparison.revision.approveCaption)
				: null
		);

		const settingsLink = el('div', { className: 'visual-post-settings' },
		presentation.settingsCaption ? el(
			'a',
			{ href: presentation.settingsURL || '#', className: 'visual-post-settings-link dashicons dashicons-admin-generic', target: '_blank', 'aria-label': presentation.settingsCaption, title: presentation.settingsCaption },
			el('span', { className: 'screen-reader-text' }, presentation.settingsCaption)
		) : null
		);

		const fieldsPromo = (comparison.presentation || {}).fieldsPromo;
		const fieldsSection = hasCustomFieldChanges && typeof proFields.render === 'function'
			? proFields.render(comparison, showDiffTooltip, hideDiffTooltip)
			: null;
		const viewChangesTooltip = __('Click to view changes', 'revisionary');
		const viewChangesTooltipProps = {
			'data-visual-post-compare-tooltip': viewChangesTooltip,
			onMouseEnter: (event) => showDiffTooltip(event.currentTarget, viewChangesTooltip),
			onMouseLeave: () => hideDiffTooltip(),
			onFocus: (event) => showDiffTooltip(event.currentTarget, viewChangesTooltip),
			onBlur: () => hideDiffTooltip(),
		};
		const selectFields = (providerId) => {
			setCanvasTab('fields');
			window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
				const canvas = canvasRef.current;
				const provider = providerId && canvas
					? canvas.querySelector('#visual-post-compare-fields-provider-' + providerId)
					: null;
				if (provider && canvas) {
					canvas.scrollTo({ top: provider.offsetTop, behavior: 'smooth' });
				} else if (canvas) {
					canvas.scrollTo({ top: 0, behavior: 'smooth' });
				}
			}));
		};
		const fieldSummarySection = hasCustomFieldChanges ? el(
			'div',
			{ className: 'visual-post-compare-fields__summary' },
			el('span', { className: 'visual-post-compare-revision__status visual-post-compare-fields__summary-headline' },
				el('a', { href: '#visual-post-compare-fields', className: 'visual-post-compare-view-link', ...viewChangesTooltipProps, onClick: (event) => { event.preventDefault(); selectFields(); } }, __('Custom Field Changes', 'revisionary'))
			),
			el('ul', { className: 'visual-post-compare-fields__summary-list' },
				...fieldSummary.map((provider) => el('li', { key: provider.id }, el('a', {
					href: '#visual-post-compare-fields-provider-' + provider.id,
					className: 'visual-post-compare-fields__summary-link',
					...viewChangesTooltipProps,
					onClick: (event) => { event.preventDefault(); selectFields(provider.id); },
				}, sprintf(__('%s: %d', 'revisionary'), provider.label, provider.count))))
			)
		) : null;
		const metaColumns = config.currentPostFirst === false
			? [revisionMeta, currentMeta]
			: [currentMeta, revisionMeta];
		const revisionSidebarContent = el(
			'div',
			{ className: 'visual-post-compare-revision__revision-tab-content' },
			settingsLink, metaColumns[0], metaColumns[1], fieldsPromo ? el(FieldsPromo, { promo: fieldsPromo }) : fieldSummarySection
		);

		const diffMarkerNav = hasCanvasOverflow && markerTrack && diffMarkers.length
			? el(
				'nav',
				{
					className: 'visual-post-compare-revision__diff-markers',
					'aria-label': __('Document changes', 'visual-post-compare'),
					style: {
						top: markerTrack.top + 'px',
						height: markerTrack.height + 'px',
						bottom: 'auto',
					},
				},
				...diffMarkers.filter(hasRenderableMarkerTop).map((marker) => el('button', {
					key: marker.key,
					type: 'button',
					className: [
						'visual-post-compare-revision__diff-marker',
						'is-' + marker.status,
						marker.isSecondaryDiff ? 'is-secondary-diff' : '',
						marker.isShortcodeDiff ? 'is-shortcode-diff' : '',
						marker.isContainerDiff ? 'is-container-diff' : '',
					].filter(Boolean).join(' '),
					title: config.tooltipsEnabled ? undefined : markerLabel(marker.status),
					'aria-label': markerLabel(marker.status),
					style: { top: marker.top + '%', '--vpc-marker-height': marker.height + '%' },
					onMouseEnter: () => showDiffTooltip(
						marker.node,
						marker.node.dataset.visualPostCompareTooltip || markerLabel(marker.status)
					),
					onMouseLeave: () => hideDiffTooltip(),
					onClick: () => {
						const canvas = canvasRef.current;
						if (!canvas || !marker.node) {
							return;
						}
						canvas.scrollTo({ top: marker.scrollTop, behavior: 'smooth' });
						setSelectedBlock(null);
						setSelectedBlockTooltip('');
						if (marker.block) {
							setSelectedBlock(marker.block);
							const blockWrapper = marker.node.closest && marker.node.closest('[data-block]');
							setSelectedBlockTooltip(blockTooltipForSelection(blockWrapper, marker.node));
							setSidebarTab('block');
						} else if (!isBlockEditorComparison) {
							const preview = canvas.querySelector('.visual-post-compare-revision__live-preview');
							const info = preview ? classicInfoForNode(marker.node, preview) : null;
							if (info) {
								setClassicInfo(info);
								setSidebarTab('block');
							}
						}
						if (marker.isContainerDiff) {
							canvas.dispatchEvent(new CustomEvent('visual-post-compare:activate-container-border', {
								detail: { target: marker.node },
							}));
						}
						showDiffTooltip(
							marker.node,
							marker.node.dataset.visualPostCompareTooltip || markerLabel(marker.status)
						);
					},
				}))
			)
			: null;

		return el(
			'section',
			{
				className: [
					'visual-post-compare-revision',
					config.tooltipsEnabled ? 'has-diff-tooltips' : '',
				].filter(Boolean).join(' '),
			},
			el(
				'div',
				{ className: 'visual-post-compare-revision__legend', 'aria-label': presentation.legendCaption },
				el('span', { className: 'is-added' }, presentation.addedCaption),
				el('span', { className: 'is-removed' }, presentation.removedCaption),
				el('span', { className: 'is-modified' }, presentation.modifiedCaption)
			),
			el(
				'div',
				{ className: 'visual-post-compare-revision__body' },
				diffResult.error
					? el(Notice, { status: 'error', isDismissible: false }, diffResult.error.message)
					: el(
						'div',
						{ className: 'visual-post-compare-revision__canvas-shell' },
						hasCustomFieldChanges ? el(
							'div',
							{ className: 'visual-post-compare-revision__canvas-tabs visual-post-compare-revision__sidebar-tabs', role: 'tablist' },
							el('button', {
								type: 'button', role: 'tab',
								className: activeCanvasTab === 'content' ? 'is-active' : '',
								'aria-selected': activeCanvasTab === 'content',
								onClick: () => setCanvasTab('content'),
							}, __('Content', 'revisionary')),
							el('button', {
								type: 'button', role: 'tab',
								className: activeCanvasTab === 'fields' ? 'is-active' : '',
								'aria-selected': activeCanvasTab === 'fields',
								onClick: () => setCanvasTab('fields'),
							}, __('Fields', 'revisionary'))
						) : null,
						el(
							'div',
							{ className: 'visual-post-compare-revision__canvas', ref: canvasRef },
							activeCanvasTab === 'fields' ? fieldsSection : [
							...editorStyleElements,
						el('style', null, REVISION_DIFF_STYLES + (classicDiff ? classicDiff.styles : '')),
							el('div', {
								className: 'visual-post-compare-revision__svg-filters',
								'aria-hidden': true,
								dangerouslySetInnerHTML: { __html: REVISION_REMOVED_FILTER_SVG },
							}),
							el(
							'div',
							{
								key: 'visual-post-compare-preview-' + displayedPost.id,
								className: [
									'editor-styles-wrapper is-root-container is-layout-constrained visual-post-compare-revision__live-preview',
									isCurrentSelected ? 'is-current-post-reference' : '',
								].filter(Boolean).join(' '),
							},
							displayedTitle || titleChanged || noChangesDetected
								? el('div', { className: 'visual-post-compare-revision__title-row' },
									(displayedTitle || titleChanged) ? el(
									'h1',
									{
										className: [
											'wp-block-post-title visual-post-compare-revision__post-title',
											titleChanged ? 'is-revision-modified' : '',
										].filter(Boolean).join(' '),
										'data-visual-post-compare-tooltip': titleChanged
											? __('Change Title', 'revisionary')
											: undefined,
									},
									titleChanged && currentTitle
										? el('del', { className: 'revision-diff-removed' }, currentTitle)
										: null,
									titleChanged && displayedTitle
										? el('ins', { className: 'revision-diff-added' }, displayedTitle)
										: (!titleChanged ? displayedTitle : null)
									) : null,
									noChangesDetected ? el(
										'div',
										{ className: 'notice notice-info inline visual-post-compare-revision__no-changes' },
										el('p', null, __('No changes detected.', 'revisionary'))
									) : null
								)
								: null,
							el('div', previewProps)
						),
							]
						),
						activeCanvasTab === 'content' ? diffMarkerNav : null
					),
				el(
					'aside',
					{ className: config.currentPostFirst ? 'visual-post-compare-revision__sidebar visual-post-compare-current-post-first' : 'visual-post-compare-revision__sidebar' },
					el(
						'div',
						{ className: 'visual-post-compare-revision__sidebar-tabs', role: 'tablist' },
						el('button', {
							type: 'button', role: 'tab',
							className: sidebarTab === 'revision' ? 'is-active' : '',
							'aria-selected': sidebarTab === 'revision',
							onClick: () => setSidebarTab('revision'),
						}, __('Revision', 'revisionary')),
						el('button', {
							type: 'button', role: 'tab',
							className: sidebarTab === 'block' ? 'is-active' : '',
							'aria-selected': sidebarTab === 'block',
							onClick: () => setSidebarTab('block'),
						}, isBlockEditorComparison ? __('Block', 'revisionary') : (classicInfo ? classicInfo.title : __('Info', 'revisionary')))
					),
					sidebarTab === 'revision'
						? revisionSidebarContent
						: (isBlockEditorComparison
							? el(BlockDiffSidebar, { block: selectedBlock, tooltip: selectedBlockTooltip })
							: el(ClassicInfoSidebar, { info: classicInfo })),
					Number(comparison.revision.id) !== Number(comparison.current.id) && comparison.revision.classicCompareURL
						? el('div', { className: 'visual-post-compare-classic' },
							el('a', { href: comparison.revision.classicCompareURL, className: 'visual-post-compare-classic-link', target: '_blank', rel: 'noopener noreferrer' },
								(comparison.presentation || {}).classicCompareCaption || __('Classic compare screen', 'revisionary'),
								el('span', { className: 'dashicons dashicons-external', 'aria-hidden': true })
							)
						) : null
				)
			)
		);
	}

	function normalizeCurrentPostPlacement(comparison) {
		if (!comparison || !Array.isArray(comparison.posts) || !comparison.current) {
			return comparison;
		}

		const currentId = Number(comparison.current.id);
		const currentPost = comparison.posts.find((post) => Number(post.id) === currentId);
		if (!currentPost) {
			return comparison;
		}

		const otherPosts = comparison.posts.filter((post) => Number(post.id) !== currentId);
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
				path: config.restPath + '?revision=' + encodeURIComponent(config.revision),
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
			return el('div', { className: 'visual-post-compare-loading' }, el(Spinner), config.loadingComparisonCaption);
		}

		const approve = async () => {
			if (isApproving || !comparison.revision.canApprove || Number(comparison.revision.id) === Number(comparison.current.id)) {
				return;
			}

			setIsApproving(true);
			setApprovalNotice(null);
			try {
				const updatedComparison = await apiFetch({
					path: config.approveRestPath + '?revision=' + encodeURIComponent(comparison.revision.id),
					method: 'POST',
				});
				setComparison(normalizeCurrentPostPlacement(updatedComparison));

				const presentation = comparison.presentation || {};

				setApprovalNotice({ status: 'success', message: presentation.revisionApplied });
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
					setComparison({ ...comparison, revision: post, selectedId: Number(post.id) });
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
