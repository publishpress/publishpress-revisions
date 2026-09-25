(function () {
	'use strict';

	if (!window.wp) return;

	const { blocks, i18n, richText } = window.wp;
	const { __ } = i18n;
	const { getFormatType, registerFormatType } = richText;
	const CLASSIC_DIFF_STYLES = `
		.visual-post-compare-image-diff--removed {
			opacity: 0.4;
			box-sizing: border-box;
			border: 3px dashed #d63638 !important;
			outline: 0 !important;
		}
		.visual-post-compare-image-diff--added {
			outline: 3px solid #00a32a !important;
			outline-offset: 2px;
		}
		.visual-post-compare-image-diff--modified {
			position: relative;
			display: inline-block;
			max-width: 100%;
			line-height: 0;
			vertical-align: middle;
			outline: 3px dotted #dba617;
			outline-offset: 2px;
		}
		.visual-post-compare-image-diff--modified.is-showing-previous {
			outline-color: #f0c54a;
			box-shadow: 0 0 0 2px color-mix(in srgb, #dba617 55%, transparent);
		}
		.has-diff-tooltips :is(.is-revision-added, .is-revision-removed) .visual-post-compare-image-diff--added:not(.is-diff-tooltip-active),
		.has-diff-tooltips :is(.is-revision-added, .is-revision-removed) .visual-post-compare-image-diff--modified:not(.is-diff-tooltip-active) {
			outline-color: transparent !important;
			box-shadow: none !important;
		}
		.has-diff-tooltips :is(.is-revision-added, .is-revision-removed) .visual-post-compare-image-diff--removed:not(.is-diff-tooltip-active) {
			border-color: transparent !important;
		}
		.has-diff-tooltips :is(.is-revision-added, .is-revision-removed) :is(
			.revision-diff-format-added,
			.revision-diff-format-removed,
			.revision-diff-format-changed,
			.revision-diff-link-changed
		):not(.is-diff-tooltip-active) {
			outline-color: transparent !important;
			border-bottom-color: transparent !important;
		}
		.visual-post-compare-image-diff--modified > img { float: none !important; }
		.visual-post-compare-image-diff__previous,
		.visual-post-compare-image-diff__backup {
			display: none !important;
		}
		.visual-post-compare-image-diff--modified.is-showing-previous > .visual-post-compare-image-diff__current {
			opacity: 0 !important;
		}
		.visual-post-compare-image-diff--modified.is-showing-previous > .visual-post-compare-image-diff__previous {
			position: absolute;
			inset: 0;
			display: block !important;
			box-sizing: border-box;
			width: 100% !important;
			height: 100% !important;
			max-width: none !important;
			opacity: 0.4;
			object-fit: contain;
		}
		.visual-post-compare-image-diff--modified.is-showing-previous > .visual-post-compare-image-diff__backup {
			position: absolute;
			right: 6px;
			bottom: 6px;
			z-index: 2;
			display: flex !important;
			align-items: center;
			justify-content: center;
			width: 28px;
			height: 28px;
			border-radius: 2px;
			background: rgba(29, 35, 39, 0.82);
			color: #fff;
			font-size: 20px;
			line-height: 1;
		}
		.revision-diff-format-added,
		.revision-diff-format-removed,
		.revision-diff-format-changed {
			border-bottom: 3px dotted #3c434a;
			text-decoration: none;
			box-decoration-break: clone;
			-webkit-box-decoration-break: clone;
		}
		.revision-diff-link-changed {
			border-bottom: 3px dotted #2271b1;
			text-decoration: none;
			box-decoration-break: clone;
			-webkit-box-decoration-break: clone;
		}
		.visual-post-compare-line-break-diff {
			display: inline-block;
			width: 0.45em;
			min-height: 1em;
			vertical-align: text-bottom;
		}
	`;

	[
		['revision/diff-format-added', __('Add Format', 'revisionary'), 'revision-diff-format-added'],
		['revision/diff-format-removed', __('Remove Format', 'revisionary'), 'revision-diff-format-removed'],
		['revision/diff-format-changed', __('Modify Format', 'revisionary'), 'revision-diff-format-changed'],
	].forEach(([name, title, className]) => {
		if (getFormatType && getFormatType(name)) return;
		try {
			registerFormatType(name, {
				title,
				tagName: 'span',
				className,
				attributes: { title: 'title' },
				edit: () => null,
			});
		} catch (error) {
			// Core or another module may already have registered the format.
		}
	});

	function containsSerializedBlocks(content) {
		return /<!--\s+\/?wp:[a-z0-9_-]+(?:\/[a-z0-9_-]+)?(?:\s|-->)/i.test(content || '');
	}

	function stableDiffValue(value) {
		if (Array.isArray(value)) return value.map(stableDiffValue);
		if (value && typeof value === 'object') {
			return Object.keys(value).sort().reduce((result, key) => {
				result[key] = stableDiffValue(value[key]);
				return result;
			}, {});
		}
		return value;
	}

	function applyNestedAttributeDiff(
		currentValue,
		previousValue,
		definition,
		diffStatus,
		applyRichTextDiff
	) {
		if (definition && definition.source === 'rich-text') {
			const { RichTextData } = richText;
			const currentRichText = currentValue instanceof RichTextData ? currentValue : new RichTextData();
			const previousRichText = previousValue instanceof RichTextData ? previousValue : new RichTextData();
			return applyRichTextDiff(currentRichText, previousRichText, true, diffStatus);
		}

		if (definition && definition.source === 'query' && definition.query) {
			const currentItems = Array.isArray(currentValue) ? currentValue : [];
			const previousItems = Array.isArray(previousValue) ? previousValue : [];
			const itemCount = Math.max(currentItems.length, previousItems.length);

			return Array.from({ length: itemCount }, (unused, index) => {
				const currentItem = currentItems[index];
				const previousItem = previousItems[index];
				const result = { ...(currentItem || previousItem || {}) };

				Object.entries(definition.query).forEach(([key, childDefinition]) => {
					result[key] = applyNestedAttributeDiff(
						currentItem && currentItem[key],
						previousItem && previousItem[key],
						childDefinition,
						diffStatus,
						applyRichTextDiff
					);
				});

				return result;
			});
		}

		return currentValue !== undefined ? currentValue : previousValue;
	}

	function hasUnrepresentedAttributeDifference(currentValue, previousValue, definition) {
		if (definition && definition.source === 'rich-text') return false;
		const attributeChangesEnabled = Boolean(
			window.VisualPostCompareStandalone && window.VisualPostCompareStandalone.htmlAttributeChanges
		);

		if (definition && definition.source === 'query' && definition.query) {
			const currentItems = Array.isArray(currentValue) ? currentValue : [];
			const previousItems = Array.isArray(previousValue) ? previousValue : [];
			if (currentItems.length !== previousItems.length) return true;

			return currentItems.some((currentItem, index) => Object.entries(definition.query).some(
				([key, childDefinition]) => hasUnrepresentedAttributeDifference(
					currentItem && currentItem[key],
					previousItems[index] && previousItems[index][key],
					childDefinition
				)
			));
		}
		if (!attributeChangesEnabled) return false;

		return JSON.stringify(stableDiffValue(currentValue))
			!== JSON.stringify(stableDiffValue(previousValue));
	}

	const STRUCTURAL_ELEMENTS = new Set([
		'address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt', 'fieldset',
		'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table',
		'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
	]);

	function classicElementAttributeSignature(element) {
		return JSON.stringify(Array.from(element.attributes)
			.filter((attribute) => !attribute.name.startsWith('data-visual-post-compare-'))
			.map((attribute) => [attribute.name, attribute.value])
			.sort(([a], [b]) => (a < b ? -1 : (a > b ? 1 : 0))));
	}

	function classicSemanticFormatName(element) {
		if (!element) return '';
		const tag = element.tagName.toLowerCase();
		if ('b' === tag || 'strong' === tag) return __('bolding', 'revisionary');
		if ('i' === tag || 'em' === tag) return __('italics', 'revisionary');
		if ('u' === tag || 'ins' === tag) return __('underscore', 'revisionary');
		if ('s' === tag || 'strike' === tag || 'del' === tag) return __('strikethrough', 'revisionary');
		return '';
	}

	function classicIsFontSizeFormat(element) {
		if (!element) return false;
		const tag = element.tagName.toLowerCase();
		return ['small', 'big', 'large', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(tag)
			|| Boolean(element.style && element.style.getPropertyValue('font-size'));
	}

	function classicSemanticFormatChanges(current, previous) {
		if (classicIsFontSizeFormat(current) || classicIsFontSizeFormat(previous)) {
			return [__('Change font size', 'revisionary')];
		}
		const currentFormat = classicSemanticFormatName(current);
		const previousFormat = classicSemanticFormatName(previous);
		if (currentFormat && currentFormat === previousFormat) return [];
		const changes = [];
		if (previousFormat) changes.push(__('Remove', 'revisionary') + ' ' + previousFormat);
		if (currentFormat) changes.push(__('Add', 'revisionary') + ' ' + currentFormat);
		return changes;
	}

	function classicHtmlAttributeChangesEnabled() {
		return Boolean(
			window.VisualPostCompareStandalone && window.VisualPostCompareStandalone.htmlAttributeChanges
		);
	}

	function classicFormatChangeSummary(current, previous) {
		if (!current) return '';
		const semanticChanges = classicSemanticFormatChanges(current, previous);
		if (!previous) {
			return semanticChanges.length
				? semanticChanges.join('\n')
				: (classicHtmlAttributeChangesEnabled()
					? __('Modified html tag:', 'revisionary') + ' ' + current.tagName.toLowerCase()
					: '');
		}
		if (current.tagName !== previous.tagName) {
			if (!semanticChanges.length
				&& classicSemanticFormatName(current) === classicSemanticFormatName(previous)) return '';
			return semanticChanges.length
				? semanticChanges.join('\n')
				: (classicHtmlAttributeChangesEnabled()
					? __('Modified html tag:', 'revisionary') + ' '
						+ previous.tagName.toLowerCase() + ', ' + current.tagName.toLowerCase()
					: '');
		}
		const currentAttributes = new Map(Array.from(current.attributes).map((attribute) => [attribute.name, attribute.value]));
		const previousAttributes = new Map(Array.from(previous.attributes).map((attribute) => [attribute.name, attribute.value]));
		const changes = [];
		const currentFontSize = current.style && current.style.getPropertyValue('font-size');
		const previousFontSize = previous.style && previous.style.getPropertyValue('font-size');
		if (currentFontSize !== previousFontSize) changes.push(__('Change font size', 'revisionary'));
		const attributeChangesEnabled = classicHtmlAttributeChangesEnabled();
		if (!attributeChangesEnabled) {
			return changes.length ? __('Modify format:', 'revisionary') + '\n ' + changes.join('\n ') : '';
		}
		if ((currentAttributes.get('id') || '') !== (previousAttributes.get('id') || '')) {
			changes.push(__('Change ID', 'revisionary'));
		}
		const currentClasses = new Set((currentAttributes.get('class') || '').split(/\s+/).filter(Boolean));
		const previousClasses = new Set((previousAttributes.get('class') || '').split(/\s+/).filter(Boolean));
		const addedClasses = Array.from(currentClasses).filter((className) => !previousClasses.has(className));
		const removedClasses = Array.from(previousClasses).filter((className) => !currentClasses.has(className));
		if (addedClasses.length) changes.push(__('Add classes: ', 'revisionary') + addedClasses.join(', '));
		if (removedClasses.length) changes.push(__('Remove classes: ', 'revisionary') + removedClasses.join(', '));
		if ((current.getAttribute('style') || '').replace(/font-size\s*:[^;]+;?/gi, '')
			!== (previous.getAttribute('style') || '').replace(/font-size\s*:[^;]+;?/gi, '')) {
			changes.push(__('Modify style', 'revisionary'));
		}
		const excluded = new Set(['id', 'class', 'style', 'href']);
		new Set([...currentAttributes.keys(), ...previousAttributes.keys()]).forEach((name) => {
			if (excluded.has(name) || name.startsWith('data-visual-post-compare-')) return;
			const currentValue = currentAttributes.get(name);
			const previousValue = previousAttributes.get(name);
			if (currentValue === previousValue) return;
			const label = name.replace(/[-_:]+/g, ' ').replace(/^./, (character) => character.toUpperCase());
			changes.push((currentValue === undefined
				? __('Remove ', 'revisionary')
				: (previousValue === undefined ? __('Add ', 'revisionary') : __('Change ', 'revisionary'))) + label.toLowerCase());
		});
		return changes.length ? __('Modify format:', 'revisionary') + '\n ' + changes.join('\n ') : '';
	}

	function structuralElements(template) {
		return Array.from(template.content.querySelectorAll('*')).filter(
			(element) => {
				const tagName = element.tagName.toLowerCase();
				if (!STRUCTURAL_ELEMENTS.has(tagName)) return false;
				return !(
					'figure' === tagName
					&& (
						element.classList.contains('wp-block-table')
						|| (element.querySelector('img') && !(element.textContent || '').trim())
					)
				);
			}
		);
	}

	function classicStructureSignature(rawBlock) {
		const template = document.createElement('template');
		template.innerHTML = rawBlock && rawBlock.innerHTML ? rawBlock.innerHTML : '';
		const elements = structuralElements(template);
		const positions = new Map(elements.map((element, index) => [element, index]));
		return JSON.stringify(elements.map((element) => {
			let parent = element.parentElement;
			while (parent && !positions.has(parent)) parent = parent.parentElement;
			return [element.tagName.toLowerCase(), parent ? positions.get(parent) : -1];
		}));
	}

	function hasCompatibleStructure(currentRawBlock, previousRawBlock) {
		return currentRawBlock.blockName === previousRawBlock.blockName
			&& classicStructureSignature(currentRawBlock) === classicStructureSignature(previousRawBlock);
	}

	function hasGalleryClass(currentRoot, previousRoot) {
		return /(?:^|\s)(?:gallery|tiled-gallery)(?:\s|$)/i.test(
			((currentRoot && currentRoot.className) || '') + ' '
			+ ((previousRoot && previousRoot.className) || '')
		);
	}

	function canBlendClassicStructure(currentRawBlock, previousRawBlock) {
		if (currentRawBlock.blockName !== previousRawBlock.blockName) return false;

		const currentTemplate = document.createElement('template');
		const previousTemplate = document.createElement('template');
		currentTemplate.innerHTML = currentRawBlock.innerHTML || '';
		previousTemplate.innerHTML = previousRawBlock.innerHTML || '';
		const currentRoot = currentTemplate.content.firstElementChild;
		const previousRoot = previousTemplate.content.firstElementChild;
		if (!currentRoot || !previousRoot || currentRoot.tagName !== previousRoot.tagName) return false;
		const currentCount = currentTemplate.content.querySelectorAll('img').length;
		const previousCount = previousTemplate.content.querySelectorAll('img').length;
		const galleryLike = hasGalleryClass(currentRoot, previousRoot);
		const imageDiffs = diffClassicImages(currentTemplate.innerHTML, previousTemplate.innerHTML);
		const relatedImageDiffs = imageDiffs.filter((diff) =>
			'unchanged' === diff.status
			|| (
				'modified' === diff.status
				&& imageSimilarity(diff.current, diff.previous) >= 0.4
			)
		);
		const hasRelatedImage = relatedImageDiffs.length > 0;
		if (galleryLike) return hasRelatedImage;
		if (hasCompatibleStructure(currentRawBlock, previousRawBlock)) return true;

		if (hasRelatedImage) {
			const currentTags = structuralElements(currentTemplate).map((element) => element.tagName.toLowerCase());
			const previousTags = structuralElements(previousTemplate).map((element) => element.tagName.toLowerCase());
			const available = previousTags.slice();
			let sharedTags = 0;
			currentTags.forEach((tagName) => {
				const index = available.indexOf(tagName);
				if (index >= 0) {
					sharedTags++;
					available.splice(index, 1);
				}
			});
			const structureSimilarity = currentTags.length || previousTags.length
				? sharedTags / Math.max(currentTags.length, previousTags.length)
				: 1;
			if (structureSimilarity >= 0.5) return true;
		}

		const currentClasses = new Set(Array.from(currentRoot.classList));
		const sharedClass = Array.from(previousRoot.classList).some((className) => currentClasses.has(className));
		if ((currentClasses.size || previousRoot.classList.length) && !sharedClass) return false;

		const smallerCount = Math.min(currentCount, previousCount);
		const largerCount = Math.max(currentCount, previousCount);
		if (!smallerCount) return false;

		const unchangedCount = relatedImageDiffs.length;
		if (smallerCount / largerCount < 0.5) return false;
		return unchangedCount / smallerCount >= 0.5;
	}

	function textNodes(root) {
		const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
			acceptNode(node) {
				const parent = node.parentElement;
				if (
					!node.nodeValue
					|| (parent && parent.closest('script, style, .revision-diff-removed'))
				) return NodeFilter.FILTER_REJECT;
				return NodeFilter.FILTER_ACCEPT;
			},
		});
		const result = [];
		while (walker.nextNode()) result.push(walker.currentNode);
		return result;
	}

	function wrapTextRange(root, start, end, className, tagName = 'span', summary = '') {
		if (end <= start) return false;
		let offset = 0;
		const matches = [];
		textNodes(root).forEach((node) => {
			const nodeStart = offset;
			const nodeEnd = nodeStart + node.nodeValue.length;
			const localStart = Math.max(0, start - nodeStart);
			const localEnd = Math.min(node.nodeValue.length, end - nodeStart);
			if (localEnd > localStart) matches.push({ node, localStart, localEnd });
			offset = nodeEnd;
		});
		matches.reverse().forEach(({ node, localStart, localEnd }) => {
			node.splitText(localEnd);
			const selected = node.splitText(localStart);
			const marker = document.createElement(tagName);
			marker.className = className;
			marker.setAttribute('title', __('Modify Format', 'revisionary'));
			if (summary) marker.dataset.visualPostCompareFormatSummary = summary;
			selected.parentNode.insertBefore(marker, selected);
			marker.appendChild(selected);
		});
		return matches.length > 0;
	}

	function insertRemovedText(root, offset, value) {
		const nodes = textNodes(root);
		const marker = document.createElement('del');
		marker.className = 'revision-diff-removed';
		marker.setAttribute('title', __('Remove', 'revisionary'));
		marker.textContent = value;
		let position = 0;
		for (const node of nodes) {
			const end = position + node.nodeValue.length;
			if (offset <= end) {
				const localOffset = Math.max(0, offset - position);
				if (localOffset === 0) node.parentNode.insertBefore(marker, node);
				else if (localOffset === node.nodeValue.length) node.parentNode.insertBefore(marker, node.nextSibling);
				else node.parentNode.insertBefore(marker, node.splitText(localOffset));
				return true;
			}
			position = end;
		}
		root.appendChild(marker);
		return true;
	}

	function imageItemContainer(image, boundary) {
		let item = image.parentElement;
		while (
			item
			&& item.parentElement
			&& item.parentElement !== boundary
			&& 1 === item.parentElement.querySelectorAll('img').length
		) {
			item = item.parentElement;
		}
		return item || image;
	}

	function wrapItemText(item, className, tagName, title) {
		textNodes(item).reverse().forEach((node) => {
			if (!(node.nodeValue || '').trim()) return;
			const marker = document.createElement(tagName);
			marker.className = className;
			marker.setAttribute('title', title);
			node.parentNode.insertBefore(marker, node);
			marker.appendChild(node);
		});
	}

	function tableItemSignature(item) {
		const images = Array.from(item.querySelectorAll('img')).map(
			(image) => image.getAttribute('src') || ''
		).filter(Boolean).sort();
		const text = (item.textContent || '').replace(/\s+/g, ' ').trim();
		return JSON.stringify({ images, text });
	}

	function matchTableItems(currentItems, previousItems) {
		const matches = new Map();
		const usedCurrent = new Set();
		if (currentItems.length === previousItems.length) {
			previousItems.forEach((previousItem, index) => {
				if (currentItems[index]) {
					matches.set(previousItem, currentItems[index]);
					usedCurrent.add(currentItems[index]);
				}
			});
			return { matches, usedCurrent };
		}

		const matchScore = (currentItem, previousItem) => {
			const currentImages = new Set(Array.from(currentItem.querySelectorAll('img')).map(
				(image) => image.getAttribute('src') || ''
			).filter(Boolean));
			const previousImages = Array.from(previousItem.querySelectorAll('img')).map(
				(image) => image.getAttribute('src') || ''
			).filter(Boolean);
			const sharedImages = previousImages.filter((src) => currentImages.has(src)).length;
			const currentText = (currentItem.textContent || '').replace(/\s+/g, ' ').trim();
			const previousText = (previousItem.textContent || '').replace(/\s+/g, ' ').trim();
			return 1
				+ (sharedImages * 10)
				+ (currentText && currentText === previousText ? 6 : 0)
				+ (tableItemSignature(currentItem) === tableItemSignature(previousItem) ? 4 : 0);
		};
		const rowCount = currentItems.length + 1;
		const columnCount = previousItems.length + 1;
		const scores = Array.from({ length: rowCount }, () => Array(columnCount).fill(0));
		const actions = Array.from({ length: rowCount }, () => Array(columnCount).fill(''));
		for (let currentIndex = 1; currentIndex < rowCount; currentIndex++) {
			for (let previousIndex = 1; previousIndex < columnCount; previousIndex++) {
				let score = scores[currentIndex - 1][previousIndex - 1]
					+ matchScore(currentItems[currentIndex - 1], previousItems[previousIndex - 1]);
				let action = 'match';
				if (scores[currentIndex - 1][previousIndex] > score) {
					score = scores[currentIndex - 1][previousIndex];
					action = 'add';
				}
				if (scores[currentIndex][previousIndex - 1] > score) {
					score = scores[currentIndex][previousIndex - 1];
					action = 'remove';
				}
				scores[currentIndex][previousIndex] = score;
				actions[currentIndex][previousIndex] = action;
			}
		}
		let currentIndex = currentItems.length;
		let previousIndex = previousItems.length;
		while (currentIndex > 0 && previousIndex > 0) {
			const action = actions[currentIndex][previousIndex];
			if ('match' === action) {
				const currentItem = currentItems[currentIndex - 1];
				const previousItem = previousItems[previousIndex - 1];
				matches.set(previousItem, currentItem);
				usedCurrent.add(currentItem);
				currentIndex--;
				previousIndex--;
			} else if ('add' === action) {
				currentIndex--;
			} else {
				previousIndex--;
			}
		}
		return { matches, usedCurrent };
	}

	function markRemovedTableStructure(structure) {
		structure.classList.add('is-revision-removed');
		structure.dataset.visualPostCompareSynthesized = 'removed';
		Array.from(structure.querySelectorAll('img')).forEach((image) => {
			image.classList.add('visual-post-compare-image-diff--removed');
			image.dataset.visualPostCompareImageDiff = 'removed';
		});
		wrapItemText(structure, 'revision-diff-removed', 'del', __('Remove', 'revisionary'));
	}

	function prepareClassicTableBlend(currentRoot, previousRoot) {
		const result = {
			cellMatches: new Map(),
			handledPreviousImages: new Set(),
			marked: false,
		};
		if (!currentRoot.matches || !previousRoot.matches) return result;
		const currentTable = currentRoot.matches('table') ? currentRoot : currentRoot.querySelector('table');
		const previousTable = previousRoot.matches('table') ? previousRoot : previousRoot.querySelector('table');
		if (!currentTable || !previousTable) return result;

		const currentRows = Array.from(currentTable.querySelectorAll('tr'));
		const previousRows = Array.from(previousTable.querySelectorAll('tr'));
		const rowMatch = matchTableItems(currentRows, previousRows);
		previousRows.forEach((previousRow) => {
			const currentRow = rowMatch.matches.get(previousRow);
			if (!currentRow) {
				const removedRow = previousRow.cloneNode(true);
				markRemovedTableStructure(removedRow);
				Array.from(previousRow.querySelectorAll('img')).forEach(
					(image) => result.handledPreviousImages.add(image)
				);
				const nextMatchedRow = previousRows.slice(previousRows.indexOf(previousRow) + 1)
					.map((row) => rowMatch.matches.get(row)).find(Boolean);
				const sectionName = previousRow.parentElement && previousRow.parentElement.tagName.toLowerCase();
				const section = (sectionName && currentTable.querySelector(sectionName)) || currentTable.tBodies[0] || currentTable;
				section.insertBefore(removedRow, nextMatchedRow && nextMatchedRow.parentElement === section ? nextMatchedRow : null);
				result.marked = true;
				return;
			}

			const currentCells = Array.from(currentRow.cells);
			const previousCells = Array.from(previousRow.cells);
			const cellMatch = matchTableItems(currentCells, previousCells);
			cellMatch.matches.forEach((currentCell, previousCell) => {
				result.cellMatches.set(previousCell, currentCell);
			});
			previousCells.forEach((previousCell) => {
				if (cellMatch.matches.has(previousCell)) return;
				const removedCell = previousCell.cloneNode(true);
				markRemovedTableStructure(removedCell);
				Array.from(previousCell.querySelectorAll('img')).forEach(
					(image) => result.handledPreviousImages.add(image)
				);
				const nextMatchedCell = previousCells.slice(previousCells.indexOf(previousCell) + 1)
					.map((cell) => cellMatch.matches.get(cell)).find(Boolean);
				currentRow.insertBefore(removedCell, nextMatchedCell || null);
				result.marked = true;
			});
			currentCells.filter((cell) => !cellMatch.usedCurrent.has(cell)).forEach((cell) => {
				cell.classList.add('is-revision-added');
				result.marked = true;
			});
		});
		currentRows.filter((row) => !rowMatch.usedCurrent.has(row)).forEach((row) => {
			row.classList.add('is-revision-added');
			result.marked = true;
		});
		return result;
	}

	function blendClassicImageStructure(currentRawBlock, previousRawBlock) {
		if (!canBlendClassicStructure(currentRawBlock, previousRawBlock)) return null;

		const currentTemplate = document.createElement('template');
		const previousTemplate = document.createElement('template');
		currentTemplate.innerHTML = currentRawBlock.innerHTML || '';
		previousTemplate.innerHTML = previousRawBlock.innerHTML || '';
		const currentRoot = currentTemplate.content.firstElementChild || currentTemplate.content;
		const previousRoot = previousTemplate.content.firstElementChild || previousTemplate.content;
		const currentImages = Array.from(currentTemplate.content.querySelectorAll('img'));
		const previousImages = Array.from(previousTemplate.content.querySelectorAll('img'));
		const imageDiffs = diffClassicImages(currentTemplate.innerHTML, previousTemplate.innerHTML);
		const galleryLike = hasGalleryClass(currentRoot, previousRoot);
		if (galleryLike && currentRoot.nodeType === Node.ELEMENT_NODE) {
			currentRoot.dataset.visualPostCompareContainerType = 'gallery';
		}
		const tableBlend = prepareClassicTableBlend(currentRoot, previousRoot);
		let marked = tableBlend.marked;
		const markAddedImage = (image) => {
			if (!image) return;
			image.classList.add('visual-post-compare-image-diff--added');
			image.dataset.visualPostCompareImageDiff = 'added';
			wrapItemText(
				imageItemContainer(image, currentRoot),
				'revision-diff-added',
				'ins',
				__('Add', 'revisionary')
			);
			marked = true;
		};
		const insertRemovedImage = (previousImage, nextImage = null) => {
			if (!previousImage || tableBlend.handledPreviousImages.has(previousImage)) return;
			const removedItem = imageItemContainer(previousImage, previousRoot).cloneNode(true);
			removedItem.dataset.visualPostCompareSynthesized = 'removed';
			Array.from(removedItem.querySelectorAll('img')).forEach((image) => {
				image.classList.add('visual-post-compare-image-diff--removed');
				image.dataset.visualPostCompareImageDiff = 'removed';
			});
			wrapItemText(removedItem, 'revision-diff-removed', 'del', __('Remove', 'revisionary'));
			const previousCell = previousImage.closest('td, th');
			const matchedCell = previousCell && tableBlend.cellMatches.get(previousCell);
			if (matchedCell && removedItem.matches('td, th')) {
				Array.from(removedItem.childNodes).forEach((child) => {
					if (child.nodeType === Node.ELEMENT_NODE) {
						child.dataset.visualPostCompareSynthesized = 'removed';
					}
					matchedCell.appendChild(child);
				});
				marked = true;
				return;
			}
			const nextItem = nextImage ? imageItemContainer(nextImage, currentRoot) : null;
			if (nextItem && nextItem.parentElement) nextItem.parentElement.insertBefore(removedItem, nextItem);
			else currentRoot.appendChild(removedItem);
			marked = true;
		};

		imageDiffs.forEach((diff, diffIndex) => {
			if ('unchanged' === diff.status) return;
			if ('modified' === diff.status) {
				// Galleries present replacements as discrete removals and additions.
				// Non-gallery containers retain one image in its original structure so
				// the rendered-preview decorator can apply previous-image hover swapping.
				if (galleryLike) {
					const currentImage = currentImages[diff.current.index];
					insertRemovedImage(previousImages[diff.previous.index], currentImage);
					markAddedImage(currentImage);
				} else {
					marked = true;
				}
				return;
			}
			if ('added' === diff.status) {
				markAddedImage(currentImages[diff.current.index]);
				return;
			}

			const previousImage = previousImages[diff.previous.index];
			const nextCurrentDiff = imageDiffs.slice(diffIndex + 1).find((item) => item.current);
			const nextImage = nextCurrentDiff ? currentImages[nextCurrentDiff.current.index] : null;
			insertRemovedImage(previousImage, nextImage);
		});

		return marked
			? { html: currentTemplate.innerHTML, complete: true, forceContainerModified: true }
			: null;
	}

	function markClassicHtmlFormattingChanges(currentRawBlock, previousRawBlock, diffText) {
		const currentProbe = document.createElement('template');
		const previousProbe = document.createElement('template');
		currentProbe.innerHTML = currentRawBlock.innerHTML || '';
		previousProbe.innerHTML = previousRawBlock.innerHTML || '';
		const galleryImageChange = hasGalleryClass(
			currentProbe.content.firstElementChild,
			previousProbe.content.firstElementChild
		) && diffClassicImages(currentProbe.innerHTML, previousProbe.innerHTML).some(
			(diff) => 'unchanged' !== diff.status
		);
		if (!hasCompatibleStructure(currentRawBlock, previousRawBlock) || galleryImageChange) {
			return blendClassicImageStructure(currentRawBlock, previousRawBlock);
		}

		const currentTemplate = document.createElement('template');
		const previousTemplate = document.createElement('template');
		currentTemplate.innerHTML = currentRawBlock.innerHTML || '';
		previousTemplate.innerHTML = previousRawBlock.innerHTML || '';
		const currentElements = Array.from(currentTemplate.content.querySelectorAll('*'));
		const previousElements = Array.from(previousTemplate.content.querySelectorAll('*'));
		const currentStructural = structuralElements(currentTemplate);
		const previousStructural = structuralElements(previousTemplate);
		const currentRoot = currentTemplate.content.firstElementChild;
		const previousRoot = previousTemplate.content.firstElementChild;
		if (hasGalleryClass(currentRoot, previousRoot) && currentRoot) {
			currentRoot.dataset.visualPostCompareContainerType = 'gallery';
		}
		let marked = false;
		const containsImage = (element) => Boolean(element && element.querySelector('img'));
		const linkAttributeChanges = (element, previousElement) => {
			if (!element || !previousElement || 'a' !== element.tagName.toLowerCase()) return [];
			const currentAttributes = new Map(Array.from(element.attributes).map((attribute) => [attribute.name, attribute.value]));
			const previousAttributes = new Map(Array.from(previousElement.attributes).map((attribute) => [attribute.name, attribute.value]));
			return Array.from(new Set([...currentAttributes.keys(), ...previousAttributes.keys()]))
				.filter((name) => !name.startsWith('data-visual-post-compare-'))
				.filter((name) => currentAttributes.get(name) !== previousAttributes.get(name))
				.map((name) => ({
					label: 'href' === name ? 'url:' : name,
					current: currentAttributes.get(name) || '',
					previous: previousAttributes.get(name) || '',
				}));
		};

		const mark = (element, previousElement = null, allowImageContainer = false) => {
			// Image additions, removals and replacements are decorated after the
			// rendered preview is available. Do not also classify an inline image
			// wrapper as a text-format change: gallery reordering can otherwise
			// pair the wrapper with a neighbouring image's markup.
			if (!element || 'img' === element.tagName.toLowerCase()) return false;
			const changedLinkAttributes = linkAttributeChanges(element, previousElement);
			const linkChanged = changedLinkAttributes.length > 0;
			if (!allowImageContainer && containsImage(element) && !linkChanged) return false;
			if (linkChanged) {
				element.classList.add('revision-diff-link-changed');
				element.dataset.visualPostCompareLinkAttributes = JSON.stringify(changedLinkAttributes);
			}
			const summary = linkChanged ? '' : classicFormatChangeSummary(element, previousElement);
			if (!summary) {
				if (linkChanged) marked = true;
				return linkChanged;
			}
			element.classList.add('revision-diff-format-changed');
			if (summary) element.dataset.visualPostCompareFormatSummary = summary;
			marked = true;
			return true;
		};

		currentStructural.forEach((element, index) => {
			if (classicElementAttributeSignature(element)
				!== classicElementAttributeSignature(previousStructural[index])) {
				mark(element, previousStructural[index], true);
			}
		});

		const previousInline = previousElements.filter(
			(element) => !STRUCTURAL_ELEMENTS.has(element.tagName.toLowerCase())
				&& 'img' !== element.tagName.toLowerCase()
				&& 'br' !== element.tagName.toLowerCase()
				&& (!containsImage(element) || classicSemanticFormatName(element) || 'a' === element.tagName.toLowerCase())
				&& !element.hasAttribute('data-visual-post-compare-shortcode-start')
				&& !element.hasAttribute('data-visual-post-compare-shortcode-end')
		);
		const linkedImageUrl = (element) => {
			if (!element || 'a' !== element.tagName.toLowerCase()) return '';
			const image = element.querySelector('img');
			return image ? image.getAttribute('src') || '' : '';
		};
		const usedPrevious = new Set();
		currentElements.filter(
			(element) => !STRUCTURAL_ELEMENTS.has(element.tagName.toLowerCase())
				&& 'img' !== element.tagName.toLowerCase()
				&& 'br' !== element.tagName.toLowerCase()
				&& (!containsImage(element) || classicSemanticFormatName(element) || 'a' === element.tagName.toLowerCase())
				&& !element.hasAttribute('data-visual-post-compare-shortcode-start')
				&& !element.hasAttribute('data-visual-post-compare-shortcode-end')
		).forEach((element) => {
			const tagName = element.tagName.toLowerCase();
			const text = element.textContent || '';
			const imageUrl = linkedImageUrl(element);
			let match = previousInline.find((candidate) =>
				!usedPrevious.has(candidate)
				&& (
					candidate.tagName.toLowerCase() === tagName
					|| (
						classicSemanticFormatName(candidate)
						&& classicSemanticFormatName(candidate) === classicSemanticFormatName(element)
					)
				)
				&& (imageUrl
					? linkedImageUrl(candidate) === imageUrl
					: (candidate.textContent || '') === text)
			);
			if (!match && !imageUrl) {
				const semanticName = classicSemanticFormatName(element);
				match = previousInline.find((candidate) =>
					!usedPrevious.has(candidate)
					&& (
						candidate.tagName.toLowerCase() === tagName
						|| (semanticName && semanticName === classicSemanticFormatName(candidate))
						|| (classicIsFontSizeFormat(element) && classicIsFontSizeFormat(candidate))
					)
				);
			}
			if (match) {
				usedPrevious.add(match);
				if (classicElementAttributeSignature(element) !== classicElementAttributeSignature(match)) mark(element, match);
			} else {
				mark(element);
			}
		});

		const currentText = currentTemplate.content.textContent || '';
		previousInline.filter((element) => !usedPrevious.has(element)).forEach((element) => {
			const text = element.textContent || '';
			const start = text ? currentText.indexOf(text) : -1;
			if (start >= 0 && currentText.indexOf(text, start + 1) < 0) {
				const semanticChanges = classicSemanticFormatChanges(null, element);
				const summary = semanticChanges.length
					? semanticChanges.join('\n')
					: (classicHtmlAttributeChangesEnabled()
						? __('Modified html tag:', 'revisionary') + ' ' + element.tagName.toLowerCase()
						: '');
				if (!summary) return;
				marked = wrapTextRange(
					currentTemplate.content,
					start,
					start + text.length,
					'revision-diff-format-changed',
					'span',
					summary
				) || marked;
			}
		});

		const parts = typeof diffText === 'function'
			? diffText(previousTemplate.content.textContent || '', currentText)
			: [];
		const additions = [];
		const removals = [];
		let currentOffset = 0;
		parts.forEach((part) => {
			if (/^\s+$/u.test(part.value)) {
				if (part.type !== 'removed') currentOffset += part.value.length;
				return;
			}
			if (part.type === 'added') {
				additions.push({ start: currentOffset, end: currentOffset + part.value.length });
				currentOffset += part.value.length;
			} else if (part.type === 'removed') {
				removals.push({ offset: currentOffset, value: part.value });
			} else {
				currentOffset += part.value.length;
			}
		});
		removals.reverse().forEach(({ offset, value }) => {
			marked = insertRemovedText(currentTemplate.content, offset, value) || marked;
		});
		additions.reverse().forEach(({ start, end }) => {
			marked = wrapTextRange(
				currentTemplate.content,
				start,
				end,
				'revision-diff-added',
				'ins'
			) || marked;
		});

		const currentBreaks = Array.from(currentTemplate.content.querySelectorAll('br'));
		const previousBreaks = Array.from(previousTemplate.content.querySelectorAll('br'));
		if (currentBreaks.length > previousBreaks.length) {
			currentBreaks.slice(previousBreaks.length).forEach((lineBreak) => {
				const marker = document.createElement('ins');
				marker.className = 'revision-diff-added visual-post-compare-line-break-diff';
				marker.dataset.visualPostCompareTooltip = __('Add text: line break', 'revisionary');
				lineBreak.parentNode.insertBefore(marker, lineBreak);
				marker.appendChild(lineBreak);
				marked = true;
			});
		} else if (previousBreaks.length > currentBreaks.length) {
			previousBreaks.slice(currentBreaks.length).forEach(() => {
				const marker = document.createElement('del');
				marker.className = 'revision-diff-removed visual-post-compare-line-break-diff';
				marker.dataset.visualPostCompareTooltip = __('Remove text: line break', 'revisionary');
				marker.appendChild(document.createElement('br'));
				currentTemplate.content.appendChild(marker);
				marked = true;
			});
		}

		const currentImages = Array.from(currentTemplate.content.querySelectorAll('img'));
		const previousImages = Array.from(previousTemplate.content.querySelectorAll('img'));
		const imageDiffs = diffClassicImages(currentTemplate.innerHTML, previousTemplate.innerHTML);
		let currentImageIndex = 0;
		let previousImageIndex = 0;
		imageDiffs.forEach((diff) => {
			if ('unchanged' === diff.status) {
				currentImageIndex++;
				previousImageIndex++;
				return;
			}
			if ('modified' === diff.status) {
				currentImageIndex++;
				previousImageIndex++;
				marked = true;
				return;
			}
			if ('added' === diff.status) {
				const image = currentImages[currentImageIndex++];
				if (image) {
					image.classList.add('visual-post-compare-image-diff--added');
					image.dataset.visualPostCompareImageDiff = 'added';
					marked = true;
				}
				return;
			}

			const previousImage = previousImages[previousImageIndex++];
			if (!previousImage) return;
			let previousContainer = previousImage.parentElement;
			while (previousContainer && !previousStructural.includes(previousContainer)) {
				previousContainer = previousContainer.parentElement;
			}
			const containerIndex = previousStructural.indexOf(previousContainer);
			const currentContainer = currentStructural[containerIndex] || currentTemplate.content;
			const removedImage = previousImage.cloneNode(true);
			removedImage.classList.add('visual-post-compare-image-diff--removed');
			removedImage.dataset.visualPostCompareImageDiff = 'removed';
			removedImage.dataset.visualPostCompareSynthesized = 'removed';
			currentContainer.appendChild(removedImage);
			marked = true;
		});

		if (!marked) return null;
		return {
			html: currentTemplate.innerHTML,
			complete: true,
			forceContainerModified: additions.length > 1
				|| removals.length > 1
				|| imageDiffs.some((diff) => diff.status !== 'unchanged'),
		};
	}

	function hasClassicImageOnlyDifference(currentRawBlock, previousRawBlock) {
		if (currentRawBlock.blockName !== previousRawBlock.blockName) return false;

		const currentTemplate = document.createElement('template');
		const previousTemplate = document.createElement('template');
		currentTemplate.innerHTML = currentRawBlock.innerHTML || '';
		previousTemplate.innerHTML = previousRawBlock.innerHTML || '';
		const currentImages = Array.from(currentTemplate.content.querySelectorAll('img'));
		const previousImages = Array.from(previousTemplate.content.querySelectorAll('img'));
		if (!currentImages.length || currentImages.length !== previousImages.length) return false;

		const attributeSignature = (image) => JSON.stringify(Array.from(image.attributes)
			.map((attribute) => [attribute.name, attribute.value])
			.sort(([a], [b]) => (a < b ? -1 : (a > b ? 1 : 0))));
		const hasImageDifference = currentImages.some(
			(image, index) => attributeSignature(image) !== attributeSignature(previousImages[index])
		);
		if (!hasImageDifference) return false;

		[currentImages, previousImages].forEach((images) => images.forEach((image) => {
			Array.from(image.attributes).forEach((attribute) => image.removeAttribute(attribute.name));
			image.setAttribute('data-visual-post-compare-image', '');
		}));

		return currentTemplate.innerHTML === previousTemplate.innerHTML;
	}


	function normalizeClassicHtml(content) {
		const template = document.createElement('template');
		template.innerHTML = content;

		// A top-level inline sentinel can be absorbed into the preceding paragraph
		// by rawHandler(). A trailing space then changes the RichText insertion
		// boundary and may cause the paragraph's own markup to be rendered as its
		// content. Keep block-level shortcode boundaries in independent hidden
		// blocks; sentinels genuinely embedded in inline content remain inline.
		Array.from(template.content.querySelectorAll(
			'[data-visual-post-compare-shortcode-start], [data-visual-post-compare-shortcode-end]'
		)).forEach((marker) => {
			if (marker.parentNode !== template.content || marker.tagName.toLowerCase() !== 'span') return;
			const isStart = marker.hasAttribute('data-visual-post-compare-shortcode-start');
			const contextComment = isStart ? marker.previousSibling : marker.nextSibling;
			if (
				contextComment
				&& contextComment.nodeType === Node.COMMENT_NODE
				&& /^\s*\/?visual-post-compare-shortcode:/.test(contextComment.nodeValue || '')
			) {
				contextComment.remove();
			}
			const boundaryText = isStart ? marker.previousSibling : marker.nextSibling;
			if (boundaryText && boundaryText.nodeType === Node.TEXT_NODE) {
				boundaryText.nodeValue = isStart
					? boundaryText.nodeValue.replace(/[\t\r\n ]+$/g, '') + '\n\n'
					: '\n\n' + boundaryText.nodeValue.replace(/^[\t\r\n ]+/g, '');
			}
			const blockMarker = document.createElement('div');
			Array.from(marker.attributes).forEach((attribute) => {
				blockMarker.setAttribute(attribute.name, attribute.value);
			});
			blockMarker.hidden = true;
			marker.replaceWith(blockMarker);
		});

		// TinyMCE removes the obsolete presentational <center> wrapper when it
		// rewrites legacy HTML. Treat that editor migration as normalization while
		// preserving and comparing the wrapper's actual child content.
		Array.from(template.content.querySelectorAll('center')).forEach((center) => {
			const parent = center.parentNode;
			while (center.firstChild) {
				parent.insertBefore(center.firstChild, center);
			}
			parent.removeChild(center);
		});

		Array.from(template.content.querySelectorAll('*')).forEach((element) => {
			if (element.hasAttribute('style')) {
				const canonicalStyle = element.style.cssText;
				if (canonicalStyle) {
					element.setAttribute('style', canonicalStyle);
				} else {
					element.removeAttribute('style');
				}
			}

			['class', 'rel'].forEach((attributeName) => {
				if (element.hasAttribute(attributeName)) {
					element.setAttribute(
						attributeName,
						element.getAttribute(attributeName).trim().split(/\s+/).filter(Boolean).sort().join(' ')
					);
				}
			});

			const attributes = Array.from(element.attributes)
				.map((attribute) => [attribute.name, attribute.value])
				.sort(([a], [b]) => (a < b ? -1 : (a > b ? 1 : 0)));
			attributes.forEach(([name]) => element.removeAttribute(name));
			attributes.forEach(([name, value]) => element.setAttribute(name, value));
		});

		return template.innerHTML;
	}

	function normalizeGeneratedAttachmentAnchors(serializedContent, normalizedHtml) {
		const template = document.createElement('template');
		template.innerHTML = normalizedHtml;
		const attachmentIds = new Set();
		Array.from(template.content.querySelectorAll('img[class*="wp-image-"]')).forEach((image) => {
			const match = image.className.match(/(?:^|\s)wp-image-(\d+)(?:\s|$)/);
			if (match) attachmentIds.add(match[1]);
		});
		let result = serializedContent;
		attachmentIds.forEach((attachmentId) => {
			result = result.replace(
				new RegExp('attachment_' + attachmentId + '(?:-\\d+)+', 'g'),
				'attachment_' + attachmentId
			);
		});
		return result;
	}

	function prepareContentForDiff(content) {
		const value = String(content || '');
		if (!value.trim() || containsSerializedBlocks(value)) return value;
		if (typeof blocks.rawHandler !== 'function' || typeof blocks.serialize !== 'function') {
			throw new Error(__('WordPress Classic Editor content conversion APIs are unavailable.', 'revisionary'));
		}
		const normalizedHtml = normalizeClassicHtml(value);
		const serializedContent = blocks.serialize(blocks.rawHandler({ HTML: normalizedHtml }));
		return normalizeGeneratedAttachmentAnchors(serializedContent, normalizedHtml);
	}

	function classicImageDescriptor(image) {
		const attributes = Array.from(image.attributes)
			.map((attribute) => [attribute.name, attribute.value])
			.sort(([a], [b]) => (a < b ? -1 : (a > b ? 1 : 0)));
		const cell = image.closest('td, th');
		const parent = cell || image.parentElement;

		const link = image.closest('a[href]');
		const href = link ? link.getAttribute('href') || '' : '';

		return {
			attributes,
			// A changed image link is an image-level modification too. Keeping it
			// in the descriptor lets us exclude the link wrapper from formatting
			// highlighting without losing a meaningful URL change.
			signature: JSON.stringify({ attributes, href }),
			src: image.getAttribute('src') || '',
			href,
			alt: image.getAttribute('alt') || '',
			width: image.getAttribute('width') || image.style.width || '',
			height: image.getAttribute('height') || image.style.height || '',
			context: parent ? (parent.textContent || '').trim().replace(/\s+/g, ' ') : '',
		};
	}

	function classicContentImages(content) {
		if (!content || containsSerializedBlocks(content)) {
			return [];
		}

		const template = document.createElement('template');
		template.innerHTML = normalizeClassicHtml(String(content));
		return Array.from(template.content.querySelectorAll('img')).map((image, index) => ({
			...classicImageDescriptor(image),
			index,
		}));
	}

	function imageSimilarity(a, b) {
		let score = 0;
		if (a.alt && a.alt === b.alt) score += 0.4;
		if (a.width && a.width === b.width) score += 0.15;
		if (a.height && a.height === b.height) score += 0.15;
		if (a.context && a.context === b.context) score += 0.3;
		return score;
	}

	function diffClassicImages(currentContent, previousContent) {
		const currentImages = classicContentImages(currentContent);
		const previousImages = classicContentImages(previousContent);
		if (!currentImages.length && !previousImages.length) {
			return [];
		}

		const rows = previousImages.length + 1;
		const cols = currentImages.length + 1;
		const table = Array.from({ length: rows }, () => new Uint32Array(cols));
		for (let i = previousImages.length - 1; i >= 0; i--) {
			for (let j = currentImages.length - 1; j >= 0; j--) {
				table[i][j] = previousImages[i].signature === currentImages[j].signature
					? table[i + 1][j + 1] + 1
					: Math.max(table[i + 1][j], table[i][j + 1]);
			}
		}

		const result = [];
		let i = 0;
		let j = 0;
		while (i < previousImages.length || j < currentImages.length) {
			if (
				i < previousImages.length
				&& j < currentImages.length
				&& previousImages[i].signature === currentImages[j].signature
			) {
				result.push({ status: 'unchanged', current: currentImages[j++], previous: previousImages[i++] });
			} else if (j < currentImages.length && (i >= previousImages.length || table[i][j + 1] >= table[i + 1][j])) {
				result.push({ status: 'added', current: currentImages[j++] });
			} else {
				result.push({ status: 'removed', previous: previousImages[i++] });
			}
		}

		// Pair replacements only inside one contiguous LCS change hunk. Equal-size
		// hunks are positional replacements; unequal hunks require matching image
		// metadata or cell context before they are considered modifications.
		const merged = [];
		for (let index = 0; index < result.length;) {
			if (result[index].status === 'unchanged') {
				merged.push(result[index++]);
				continue;
			}

			let end = index;
			while (end < result.length && result[end].status !== 'unchanged') end++;
			const hunk = result.slice(index, end);
			const removed = hunk.filter((item) => item.status === 'removed');
			const added = hunk.filter((item) => item.status === 'added');
			const pairedRemoved = new Set();
			const pairedAdded = new Map();

			const candidates = [];
			added.forEach((addition) => removed.forEach((removal) => {
				candidates.push({ addition, removal, score: imageSimilarity(addition.current, removal.previous) });
			}));
			candidates.sort((a, b) => b.score - a.score);
			candidates.forEach(({ addition, removal, score }) => {
				if (score >= 0.4 && !pairedAdded.has(addition) && !pairedRemoved.has(removal)) {
					pairedAdded.set(addition, removal.previous);
					pairedRemoved.add(removal);
				}
			});
			if (removed.length === added.length) {
				const remainingRemoved = removed.filter((item) => !pairedRemoved.has(item));
				added.filter((item) => !pairedAdded.has(item)).forEach((item, offset) => {
					pairedAdded.set(item, remainingRemoved[offset].previous);
					pairedRemoved.add(remainingRemoved[offset]);
				});
			}

			hunk.forEach((item) => {
				if (pairedRemoved.has(item)) return;
				if (pairedAdded.has(item)) {
					merged.push({ status: 'modified', current: item.current, previous: pairedAdded.get(item) });
				} else {
					merged.push(item);
				}
			});

			index = end;
		}

		return merged;
	}

	const classicImageCleanup = new WeakMap();
	const classicImagePointerCleanup = new WeakMap();
	const IMAGE_TEXT_DIFF_CLASSES = [
		'revision-diff-format-added',
		'revision-diff-format-removed',
		'revision-diff-format-changed',
		'revision-diff-added',
		'revision-diff-removed',
	];
	const IMAGE_TEXT_DIFF_SELECTOR = IMAGE_TEXT_DIFF_CLASSES.map((className) => `.${className}`).join(', ');

	function clearRedundantImageFormatting(preview) {
		if (!preview) return;
		Array.from(preview.querySelectorAll('img:not(.visual-post-compare-image-diff__previous)')).forEach((image) => {
			IMAGE_TEXT_DIFF_CLASSES.forEach((className) => image.classList.remove(className));
			let wrapper = image.closest(IMAGE_TEXT_DIFF_SELECTOR);
			while (wrapper && wrapper !== preview && preview.contains(wrapper)) {
				// Preserve formatting highlights that also contain a real caption or
				// other text. Image-only plugin wrappers are redundant and can make
				// an unchanged gallery image look added or format-modified.
				if ((wrapper.textContent || '').trim()) break;
				const next = wrapper.parentElement
					? wrapper.parentElement.closest(IMAGE_TEXT_DIFF_SELECTOR)
					: null;
				IMAGE_TEXT_DIFF_CLASSES.forEach((className) => wrapper.classList.remove(className));
				if (!wrapper.className.trim() && ['span', 'ins', 'del'].includes(wrapper.tagName.toLowerCase())) {
					const parent = wrapper.parentNode;
					while (wrapper.firstChild) parent.insertBefore(wrapper.firstChild, wrapper);
					wrapper.remove();
				}
				wrapper = next;
			}
		});
	}

	function enableClassicImageHover(preview) {
		if (classicImagePointerCleanup.has(preview)) return;
		let active = null;
		let suppressed = null;
		const containsPoint = (wrapper, event) => {
			const rect = wrapper.getBoundingClientRect();
			return rect.width > 0 && rect.height > 0
				&& event.clientX >= rect.left && event.clientX < rect.right
				&& event.clientY >= rect.top && event.clientY < rect.bottom;
		};
		const clear = () => {
			if (active) active.classList.remove('is-showing-previous');
			active = null;
		};
		// WordPress makes preview blocks inert. Native mouseenter and :hover
		// never reach their descendants. The live preview still receives mouse
		// movement, so hit-test only our modified images without enabling editing.
		const move = (event) => {
			if (suppressed && !containsPoint(suppressed, event)) suppressed = null;
			const next = Array.from(preview.querySelectorAll('.visual-post-compare-image-diff--modified'))
				.find((wrapper) => wrapper !== suppressed && containsPoint(wrapper, event));
			if (next === active) return;
			clear();
			if (next) {
				active = next;
				active.classList.add('is-showing-previous');
			}
		};
		const click = (event) => {
			const previous = active && active.querySelector('.visual-post-compare-image-diff__previous');
			if (!previous || !containsPoint(active, event)) return;
			suppressed = active;
			clear();
		};
		preview.addEventListener('mousemove', move);
		preview.addEventListener('click', click);
		preview.addEventListener('mouseleave', clear);
		const canvas = preview.closest('.visual-post-compare-revision__canvas');
		if (canvas) canvas.addEventListener('scroll', clear);
		classicImagePointerCleanup.set(preview, () => {
			clear();
			preview.removeEventListener('mousemove', move);
			preview.removeEventListener('click', click);
			preview.removeEventListener('mouseleave', clear);
			if (canvas) canvas.removeEventListener('scroll', clear);
		});
	}

	function decorateClassicImageDiffs(preview, currentContent, previousContent) {
		if (!preview || containsSerializedBlocks(currentContent) || containsSerializedBlocks(previousContent)) {
			return;
		}

		clearRedundantImageFormatting(preview);
		const imageDiffs = diffClassicImages(currentContent, previousContent);
		const renderedImages = Array.from(preview.querySelectorAll('img:not(.visual-post-compare-image-diff__previous)'));
		if (!renderedImages.length) {
			return;
		}
		// rawHandler and the block renderer rewrite attributes and may retain
		// both sides of a replacement. Match image resources, not global counts
		// or serialized attribute lists from different rendering pipelines.
		const sourceKey = (src) => {
			try { return new URL(src, document.baseURI).href; } catch (error) { return src; }
		};
		const claimed = new Set();
		const claim = (descriptor) => {
			const image = renderedImages.find((candidate) => !claimed.has(candidate)
				&& sourceKey(candidate.getAttribute('src') || '') === sourceKey(descriptor.src));
			if (image) claimed.add(image);
			return image;
		};

		imageDiffs.forEach((diff) => {
			const image = claim(diff.current || diff.previous);
			if (!image) return;
			if (image.closest('[data-visual-post-compare-image-diff]')) return;
			if (diff.status === 'removed') {
				image.classList.add('visual-post-compare-image-diff--removed');
				image.dataset.visualPostCompareImageDiff = 'removed';
				return;
			}

			if (diff.status !== 'modified') return;
			const diffContainer = image.closest(
				'.is-revision-added, .is-revision-removed, .is-revision-modified, .is-revision-changed'
			);
			if (!diffContainer || !diffContainer.matches('.is-revision-modified, .is-revision-changed')) return;
			const oldRendered = diff.current.src !== diff.previous.src ? claim(diff.previous) : null;
			const oldStyle = oldRendered ? oldRendered.getAttribute('style') : null;
			if (oldRendered) oldRendered.style.setProperty('display', 'none', 'important');
			const wrapper = document.createElement('span');
			wrapper.className = 'visual-post-compare-image-diff--modified';
			wrapper.dataset.visualPostCompareImageDiff = 'modified';
			wrapper.dataset.visualPostCompareImageAttributesOnly = String(
				diff.current.src === diff.previous.src && diff.current.href === diff.previous.href
			);
			const currentAttributes = new Map(diff.current.attributes);
			const previousAttributes = new Map(diff.previous.attributes);
			wrapper.dataset.visualPostCompareImageAttributes = JSON.stringify(
				Array.from(new Set([...currentAttributes.keys(), ...previousAttributes.keys()]))
					.filter((name) => currentAttributes.get(name) !== previousAttributes.get(name))
					.map((name) => ({
						label: 'src' === name ? 'image url:' : name,
						current: currentAttributes.get(name) || '',
						previous: previousAttributes.get(name) || '',
					}))
					.concat(diff.current.href !== diff.previous.href ? [{
						label: 'link url:',
						current: diff.current.href,
						previous: diff.previous.href,
					}] : [])
			);
			wrapper.addEventListener('mouseenter', () => wrapper.classList.add('is-showing-previous'));
			wrapper.addEventListener('mouseleave', () => wrapper.classList.remove('is-showing-previous'));
			image.parentNode.insertBefore(wrapper, image);
			wrapper.appendChild(image);
			image.classList.add('visual-post-compare-image-diff__current');

			const previousImage = document.createElement('img');
			diff.previous.attributes.forEach(([name, value]) => {
				if ('id' !== name) previousImage.setAttribute(name, value);
			});
			previousImage.setAttribute('alt', '');
			previousImage.setAttribute('aria-hidden', 'true');
			previousImage.classList.add('visual-post-compare-image-diff__previous');
			wrapper.appendChild(previousImage);

			const backupIcon = document.createElement('span');
			backupIcon.className = 'dashicons dashicons-backup visual-post-compare-image-diff__backup';
			backupIcon.setAttribute('aria-hidden', 'true');
			wrapper.appendChild(backupIcon);
			classicImageCleanup.set(wrapper, () => {
				if (oldRendered) {
					if (oldStyle === null) oldRendered.removeAttribute('style');
					else oldRendered.setAttribute('style', oldStyle);
				}
			});
		});
		Array.from(preview.querySelectorAll(
			'.is-revision-added img:not(.visual-post-compare-image-diff__previous)'
		)).forEach((image) => {
			if (image.closest('[data-visual-post-compare-image-diff]')) return;
			image.classList.add('visual-post-compare-image-diff--added');
			image.dataset.visualPostCompareImageDiff = 'added';
		});
		Array.from(preview.querySelectorAll(
			'.is-revision-removed img:not(.visual-post-compare-image-diff__previous)'
		)).forEach((image) => {
			if (image.closest('[data-visual-post-compare-image-diff]')) return;
			image.classList.add('visual-post-compare-image-diff--removed');
			image.dataset.visualPostCompareImageDiff = 'removed';
		});
		if (preview.querySelector('.visual-post-compare-image-diff--modified')) enableClassicImageHover(preview);
	}

	function clearClassicImageDiffs(preview) {
		if (!preview) return;
		const pointerCleanup = classicImagePointerCleanup.get(preview);
		if (pointerCleanup) pointerCleanup();
		classicImagePointerCleanup.delete(preview);

		Array.from(preview.querySelectorAll('.visual-post-compare-image-diff--modified')).forEach((wrapper) => {
			const cleanup = classicImageCleanup.get(wrapper);
			if (cleanup) cleanup();
			classicImageCleanup.delete(wrapper);
			const image = wrapper.querySelector('.visual-post-compare-image-diff__current');
			if (image && wrapper.parentNode) {
				image.classList.remove('visual-post-compare-image-diff__current');
				wrapper.parentNode.insertBefore(image, wrapper);
			}
			wrapper.remove();
		});

		Array.from(preview.querySelectorAll('[data-visual-post-compare-synthesized="removed"]')).forEach(
			(image) => image.remove()
		);
		Array.from(preview.querySelectorAll('.visual-post-compare-image-diff--removed')).forEach((image) => {
			image.classList.remove('visual-post-compare-image-diff--removed');
			delete image.dataset.visualPostCompareImageDiff;
		});
		Array.from(preview.querySelectorAll('.visual-post-compare-image-diff--added')).forEach((image) => {
			image.classList.remove('visual-post-compare-image-diff--added');
			delete image.dataset.visualPostCompareImageDiff;
		});
	}

	window.VisualPostCompareClassicDiff = {
		styles: CLASSIC_DIFF_STYLES,
		prepareContentForDiff,
		applyNestedAttributeDiff,
		hasUnrepresentedAttributeDifference,
		hasCompatibleStructure,
		canBlendStructure: canBlendClassicStructure,
		markHtmlFormattingChanges: markClassicHtmlFormattingChanges,
		hasImageOnlyDifference: hasClassicImageOnlyDifference,
		decorateImageDiffs: decorateClassicImageDiffs,
		clearImageDiffs: clearClassicImageDiffs,
		clearRedundantImageFormatting,
	};
}());
