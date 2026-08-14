const ReceiptImportOcr = (function()
{
	'use strict';

	function normalizedMoney(line)
	{
		return String(line || '')
			.replace(/([\p{L}\d])\s+[)OQ][.,](\d{2})(\s+[A-Z0-9*])?$/ui, '$1 0.$2$3')
			.replace(/\b(\d)(\d{2})(\s+[A-Z0-9*])$/u, '$1.$2$3');
	}

	function terminalMoney(line)
	{
		const match = normalizedMoney(line).match(/(\d{1,6}[.,]\d{2})(\s+[A-Z0-9*])?$/u);
		return match ? match[1].replace(',', '.') + (match[2] || '') : null;
	}

	function tokens(line)
	{
		return normalizedMoney(line).normalize('NFD').replace(/[\u0300-\u036f]/g, '')
			.toLowerCase().replace(/\d{3,}|\d+[.,]\d+|[^a-z]+/g, ' ').trim().split(/\s+/)
			.filter(token => token.length >= 4 && !['artikel', 'total', 'preis', 'zahlung'].includes(token));
	}

	function editDistance(left, right)
	{
		const row = Array.from({ length: right.length + 1 }, (_, index) => index);
		for (let leftIndex = 1; leftIndex <= left.length; leftIndex++)
		{
			let diagonal = row[0];
			row[0] = leftIndex;
			for (let rightIndex = 1; rightIndex <= right.length; rightIndex++)
			{
				const above = row[rightIndex];
				row[rightIndex] = Math.min(row[rightIndex] + 1, row[rightIndex - 1] + 1,
					diagonal + (left[leftIndex - 1] === right[rightIndex - 1] ? 0 : 1));
				diagonal = above;
			}
		}
		return row[right.length];
	}

	function lineMatchScore(primaryLine, recoveryLine)
	{
		const primaryArticle = primaryLine.match(/\b\d{4,14}\b/);
		const recoveryArticle = recoveryLine.match(/\b\d{4,14}\b/);
		if (primaryArticle && recoveryArticle && primaryArticle[0] === recoveryArticle[0])
		{
			return 100;
		}
		let score = 0;
		tokens(primaryLine).forEach(function(primaryToken)
		{
			tokens(recoveryLine).forEach(function(recoveryToken)
			{
				if (primaryToken === recoveryToken)
				{
					score += 20;
				}
				else if (Math.min(primaryToken.length, recoveryToken.length) >= 5 && editDistance(primaryToken, recoveryToken) <= 1)
				{
					score += 20;
				}
				else if (Math.min(primaryToken.length, recoveryToken.length) >= 6 && editDistance(primaryToken, recoveryToken) <= 2)
				{
					score += 10;
				}
			});
		});
		return score;
	}

	function needsRecovery(text)
	{
		return String(text || '').split(/\r?\n/).some(function(line)
		{
			return /\b\d{4,14}\b/.test(line) && /[\p{L}]/u.test(line) && terminalMoney(line) === null;
		});
	}

	function merge(primaryText, recoveryText)
	{
		const recoveryLines = String(recoveryText || '').split(/\r?\n/).map(normalizedMoney)
			.filter(line => terminalMoney(line) !== null);
		return String(primaryText || '').split(/\r?\n/).map(normalizedMoney).map(function(primaryLine)
		{
			if (terminalMoney(primaryLine) !== null || !/[\p{L}]/u.test(primaryLine))
			{
				return primaryLine;
			}
			let bestLine = null;
			let bestScore = 0;
			recoveryLines.forEach(function(recoveryLine)
			{
				const score = lineMatchScore(primaryLine, recoveryLine);
				if (score > bestScore)
				{
					bestScore = score;
					bestLine = recoveryLine;
				}
			});
			const recoveredMoney = bestLine && bestScore >= 20 ? terminalMoney(bestLine) : null;
			return recoveredMoney === null ? primaryLine : primaryLine.replace(/\s+(?:\d{1,3}\s+)?[A-Z0-9*]{1,3}$/u, '') + ' ' + recoveredMoney;
		}).join('\n');
	}

	return { merge: merge, needsRecovery: needsRecovery, normalizedMoney: normalizedMoney };
})();

if (typeof module !== 'undefined' && module.exports)
{
	module.exports = ReceiptImportOcr;
}

if (typeof window !== 'undefined')
(function()
{
	'use strict';

	const MAX_FILE_SIZE = 20 * 1024 * 1024;
	const OCR_DIAGNOSTICS_ENABLED = window.location.hostname.toLowerCase().startsWith('grocy-test.');
	const State = {
		file: null,
		receiptHash: null,
		rawText: null,
		preview: null,
		lines: new Map(),
		activeLineIndex: null,
		objectUrl: null,
		lastImportId: null,
		productWindow: null,
		ocrDiagnostic: null
	};
	let Products = [];
	const ProductsById = new Map();

	function setProductCatalog(products)
	{
		Products = (products || []).map(function(product)
		{
			return {
				id: Number(product.id),
				name: product.name,
				stockUnitName: product.stock_unit_name,
				stockUnitNamePlural: product.stock_unit_name_plural,
				purchaseUnitName: product.purchase_unit_name,
				purchaseUnitNamePlural: product.purchase_unit_name_plural,
				purchaseToStockFactor: Number(product.purchase_to_stock_factor) || 1,
				searchName: normalizeSearch(product.name)
			};
		});
		ProductsById.clear();
		Products.forEach(product => ProductsById.set(product.id, product));
	}

	setProductCatalog(Grocy.ReceiptImport.products || []);

	function postApi(path, data)
	{
		return new Promise(function(resolve, reject)
		{
			Grocy.Api.Post(path, data, resolve, function(xhr)
			{
				reject(apiError(xhr));
			});
		});
	}

	function getApi(path)
	{
		return new Promise(function(resolve, reject)
		{
			Grocy.Api.Get(path, resolve, function(xhr)
			{
				reject(apiError(xhr));
			});
		});
	}

	function apiError(xhr)
	{
		try
		{
			const response = JSON.parse(xhr.responseText || xhr.response || '{}');
			return new Error(response.error_message || __t('The receipt operation failed'));
		}
		catch (error)
		{
			return new Error(__t('The receipt operation failed'));
		}
	}

	function setLiveMessage(message)
	{
		$('#receipt-import-live').text(message);
	}

	function setProcessing(stage, detail, progress)
	{
		$('#receipt-processing-stage').text(stage);
		$('#receipt-processing-detail').text(detail || '');
		$('#receipt-processing-progress').css('width', Math.max(3, Math.min(100, progress || 3)) + '%');
		setLiveMessage(stage + '. ' + (detail || ''));
	}

	function showOnly(sectionId)
	{
		['#receipt-capture', '#receipt-processing', '#receipt-review', '#receipt-success'].forEach(function(selector)
		{
			$(selector).toggleClass('d-none', selector !== sectionId);
		});
	}

	async function handleFile(file)
	{
		if (!file)
		{
			return;
		}
		if (file.size > MAX_FILE_SIZE)
		{
			toastr.error(__t('The receipt file is larger than 20 MB'));
			return;
		}

		const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
		const isImage = file.type.startsWith('image/');
		if (!isPdf && !isImage)
		{
			toastr.error(__t('Choose a PDF or image receipt'));
			return;
		}

		resetState(false);
		State.file = file;
		$('#receipt-reset-button').removeClass('d-none');
		showOnly('#receipt-processing');
		setProcessing(__t('Preparing file'), file.name, 5);

		try
		{
			const bytes = await file.arrayBuffer();
			State.receiptHash = await sha256(bytes);
			setProcessing(__t('Reading receipt'), isPdf ? __t('Extracting embedded PDF text') : __t('Running private on-device OCR'), 15);

			if (isPdf)
			{
				State.rawText = await extractPdfText(bytes.slice(0));
			}
			else
			{
				State.rawText = await extractImageText(file);
			}

			if (!State.rawText || State.rawText.trim().length < 30)
			{
				throw new Error(__t('Too little receipt text was detected. Try a sharper, evenly lit photo.'));
			}

			setProcessing(__t('Matching products'), __t('Checking learned aliases and Grocy product names'), 82);
			State.preview = await postApi('receipt-import/preview', {
				raw_text: State.rawText,
				receipt_hash: State.receiptHash
			});
			setProcessing(__t('Preparing review'), __t('Building the receipt review'), 96);
			renderPreview();
			showOnly('#receipt-review');
			if (State.ocrDiagnostic)
			{
				State.ocrDiagnostic.result = 'Preview accepted: ' + State.preview.retailer_name + ', ' + State.preview.items.length + ' lines, ' + State.preview.currency + ' ' + Number(State.preview.receipt_total).toFixed(2);
			}
			renderOcrDiagnostic(false);
			$('#receipt-commit-bar').removeClass('d-none');
			setLiveMessage(__t('Receipt ready for review'));
		}
		catch (error)
		{
			console.error(error);
			showOnly('#receipt-capture');
			if (State.ocrDiagnostic)
			{
				State.ocrDiagnostic.result = 'Error: ' + error.message;
			}
			renderOcrDiagnostic(true);
			toastr.error(escapeHtml(error.message));
			setLiveMessage(error.message);
		}
	}

	async function sha256(arrayBuffer)
	{
		if (!window.crypto || !window.crypto.subtle)
		{
			throw new Error(__t('Receipt fingerprinting requires a secure HTTPS connection'));
		}
		const digest = await window.crypto.subtle.digest('SHA-256', arrayBuffer);
		return Array.from(new Uint8Array(digest)).map(byte => byte.toString(16).padStart(2, '0')).join('');
	}

	async function extractPdfText(arrayBuffer)
	{
		setProcessing(__t('Reading receipt'), __t('Loading the PDF reader'), 20);
		const pdfjs = await import(Grocy.ReceiptImport.assets.pdfModule);
		pdfjs.GlobalWorkerOptions.workerSrc = Grocy.ReceiptImport.assets.pdfWorker;
		const pdf = await pdfjs.getDocument({ data: arrayBuffer }).promise;
		const pageTexts = [];

		for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++)
		{
			setProcessing(__t('Reading receipt'), __t('Extracting PDF page %1$s of %2$s', pageNumber, pdf.numPages), 20 + (pageNumber / pdf.numPages) * 48);
			const page = await pdf.getPage(pageNumber);
			const content = await page.getTextContent();
			pageTexts.push(reconstructPdfLines(content.items));
			if (pageNumber === 1)
			{
				await renderPdfPreview(page);
			}
		}

		return pageTexts.join('\n');
	}

	function reconstructPdfLines(items)
	{
		const positioned = items
			.filter(item => item.str && item.str.trim() !== '')
			.map(item => ({ text: item.str.trim(), x: item.transform[4], y: item.transform[5], width: item.width || 0 }))
			.sort((a, b) => Math.abs(b.y - a.y) > 2.5 ? b.y - a.y : a.x - b.x);
		const rows = [];

		positioned.forEach(function(item)
		{
			let row = rows.find(candidate => Math.abs(candidate.y - item.y) <= 2.5);
			if (!row)
			{
				row = { y: item.y, items: [] };
				rows.push(row);
			}
			row.items.push(item);
		});

		return rows
			.sort((a, b) => b.y - a.y)
			.map(function(row)
			{
				return row.items.sort((a, b) => a.x - b.x).map(item => item.text).join(' ');
			})
			.join('\n');
	}

	async function renderPdfPreview(page)
	{
		const baseViewport = page.getViewport({ scale: 1 });
		const scale = Math.min(2, 900 / baseViewport.width);
		const viewport = page.getViewport({ scale: scale });
		const canvas = document.getElementById('receipt-pdf-preview');
		const context = canvas.getContext('2d', { alpha: false });
		canvas.width = Math.floor(viewport.width);
		canvas.height = Math.floor(viewport.height);
		await page.render({ canvasContext: context, viewport: viewport }).promise;
		$('#receipt-pdf-preview').removeClass('d-none');
		$('#receipt-image-preview').addClass('d-none');
	}

	async function extractImageText(file)
	{
		if (!window.Tesseract)
		{
			throw new Error(__t('The browser OCR library could not be loaded'));
		}

		State.objectUrl = URL.createObjectURL(file);
		$('#receipt-image-preview').attr('src', State.objectUrl).removeClass('d-none');
		$('#receipt-pdf-preview').addClass('d-none');
		const imageBitmap = await createImageBitmap(file);
		const preparedImages = prepareReceiptImage(imageBitmap);
		imageBitmap.close();

		let worker;
		try
		{
			worker = await Tesseract.createWorker(['deu', 'eng'], Tesseract.OEM.LSTM_ONLY, {
				workerPath: Grocy.ReceiptImport.assets.tesseractWorker,
				corePath: Grocy.ReceiptImport.assets.tesseractCore,
				logger: function(message)
				{
					if (message.status === 'recognizing text')
					{
						const percent = Math.round((message.progress || 0) * 100);
						setProcessing(__t('Reading photo'), __t('OCR progress: %1$s%%', percent), 22 + percent * 0.52);
					}
				}
			});
			await worker.setParameters({
				tessedit_pageseg_mode: Tesseract.PSM.SINGLE_BLOCK,
				preserve_interword_spaces: '1',
				user_defined_dpi: '300'
			});
			let result = await worker.recognize(preparedImages.primary, { rotateAuto: true });
			const primaryText = result.data.text;
			let text = ReceiptImportOcr.normalizedMoney(primaryText);
			const primaryScore = receiptTextScore(text);
			const recoveryNeeded = ReceiptImportOcr.needsRecovery(text);
			let recoveryText = '';
			let mergedText = text;
			let rotatedText = '';
			let selectedPass = 'primary';
			if (recoveryNeeded || OCR_DIAGNOSTICS_ENABLED)
			{
				setProcessing(__t('Reading photo'), __t('Recovering unclear receipt prices'), 66);
				const recoveryResult = await worker.recognize(preparedImages.recovery, { rotateAuto: false });
				recoveryText = recoveryResult.data.text;
				mergedText = ReceiptImportOcr.merge(text, recoveryText);
				if (recoveryNeeded)
				{
					text = mergedText;
					selectedPass = 'merged';
				}
			}
			if (receiptTextScore(text) < 60)
			{
				setProcessing(__t('Reading photo'), __t('Trying the opposite receipt orientation'), 66);
				const rotatedCanvas = rotateCanvasHalfTurn(preparedImages.primary);
				result = await worker.recognize(rotatedCanvas, { rotateAuto: false });
				rotatedText = result.data.text;
				if (receiptTextScore(result.data.text) > receiptTextScore(text))
				{
					text = result.data.text;
					selectedPass = 'rotated';
				}
			}
			State.ocrDiagnostic = {
				file: file.name,
				image: preparedImages.meta,
				primaryScore: primaryScore,
				recoveryNeeded: recoveryNeeded,
				selectedPass: selectedPass,
				primary: primaryText,
				recovery: recoveryText,
				merged: mergedText,
				rotated: rotatedText,
				selected: text,
				result: 'OCR complete; waiting for parser'
			};
			return text;
		}
		finally
		{
			if (worker)
			{
				await worker.terminate();
			}
		}
	}

	function prepareReceiptImage(imageBitmap)
	{
		const sourceCanvas = document.createElement('canvas');
		sourceCanvas.width = imageBitmap.width;
		sourceCanvas.height = imageBitmap.height;
		const sourceContext = sourceCanvas.getContext('2d', { willReadFrequently: true });
		sourceContext.drawImage(imageBitmap, 0, 0);
		const bounds = findReceiptBounds(sourceContext, sourceCanvas.width, sourceCanvas.height);
		const sourceWidth = bounds.right - bounds.left;
		const sourceHeight = bounds.bottom - bounds.top;
		const rotate = sourceWidth > sourceHeight * 1.15;
		const orientedWidth = rotate ? sourceHeight : sourceWidth;
		const orientedHeight = rotate ? sourceWidth : sourceHeight;
		const scale = Math.min(3, 1400 / orientedWidth, 5200 / orientedHeight);
		const canvas = document.createElement('canvas');
		canvas.width = Math.max(1, Math.round(orientedWidth * scale));
		canvas.height = Math.max(1, Math.round(orientedHeight * scale));
		const context = canvas.getContext('2d', { willReadFrequently: true });
		context.fillStyle = '#ffffff';
		context.fillRect(0, 0, canvas.width, canvas.height);
		context.save();
		if (rotate)
		{
			context.translate(canvas.width, 0);
			context.rotate(Math.PI / 2);
			context.drawImage(sourceCanvas, bounds.left, bounds.top, sourceWidth, sourceHeight, 0, 0, canvas.height, canvas.width);
		}
		else
		{
			context.drawImage(sourceCanvas, bounds.left, bounds.top, sourceWidth, sourceHeight, 0, 0, canvas.width, canvas.height);
		}
		context.restore();
		const recoveryCanvas = document.createElement('canvas');
		recoveryCanvas.width = canvas.width;
		recoveryCanvas.height = canvas.height;
		const recoveryContext = recoveryCanvas.getContext('2d', { willReadFrequently: true });
		recoveryContext.drawImage(canvas, 0, 0);
		const recoveryPixels = recoveryContext.getImageData(0, 0, recoveryCanvas.width, recoveryCanvas.height);
		for (let index = 0; index < recoveryPixels.data.length; index += 4)
		{
			const gray = recoveryPixels.data[index] * 0.299 + recoveryPixels.data[index + 1] * 0.587 + recoveryPixels.data[index + 2] * 0.114;
			const value = gray >= 200 ? 255 : 0;
			recoveryPixels.data[index] = value;
			recoveryPixels.data[index + 1] = value;
			recoveryPixels.data[index + 2] = value;
		}
		recoveryContext.putImageData(recoveryPixels, 0, 0);

		const pixels = context.getImageData(0, 0, canvas.width, canvas.height);

		for (let index = 0; index < pixels.data.length; index += 4)
		{
			const gray = pixels.data[index] * 0.299 + pixels.data[index + 1] * 0.587 + pixels.data[index + 2] * 0.114;
			const contrasted = Math.max(0, Math.min(255, (gray - 128) * 1.35 + 140));
			pixels.data[index] = contrasted;
			pixels.data[index + 1] = contrasted;
			pixels.data[index + 2] = contrasted;
		}
		context.putImageData(pixels, 0, 0);
		return {
			primary: canvas,
			recovery: recoveryCanvas,
			meta: {
				source: imageBitmap.width + ' × ' + imageBitmap.height,
				bounds: bounds.left + ',' + bounds.top + ' → ' + bounds.right + ',' + bounds.bottom,
				autoRotated: rotate,
				prepared: canvas.width + ' × ' + canvas.height,
				scale: Number(scale.toFixed(3))
			}
		};
	}

	function findReceiptBounds(context, width, height)
	{
		const pixels = context.getImageData(0, 0, width, height).data;
		const step = Math.max(1, Math.floor(Math.min(width, height) / 500));
		const columnCounts = new Array(Math.ceil(width / step)).fill(0);
		const rowCounts = new Array(Math.ceil(height / step)).fill(0);
		let sampledRows = 0;
		let sampledColumns = 0;

		for (let y = 0; y < height; y += step)
		{
			sampledRows++;
			let columnIndex = 0;
			for (let x = 0; x < width; x += step)
			{
				if (y === 0)
				{
					sampledColumns++;
				}
				const index = (y * width + x) * 4;
				const red = pixels[index];
				const green = pixels[index + 1];
				const blue = pixels[index + 2];
				const brightness = red * 0.299 + green * 0.587 + blue * 0.114;
				const saturation = Math.max(red, green, blue) - Math.min(red, green, blue);
				if (brightness >= 170 && saturation <= 55)
				{
					rowCounts[Math.floor(y / step)]++;
					columnCounts[columnIndex]++;
				}
				columnIndex++;
			}
		}

		const rowIndexes = rowCounts.map((count, index) => count >= sampledColumns * 0.14 ? index : -1).filter(index => index >= 0);
		const columnIndexes = columnCounts.map((count, index) => count >= sampledRows * 0.12 ? index : -1).filter(index => index >= 0);
		if (rowIndexes.length < 10 || columnIndexes.length < 10)
		{
			return { left: 0, top: 0, right: width, bottom: height };
		}

		const padding = Math.max(8, Math.round(Math.min(width, height) * 0.015));
		const bounds = {
			left: Math.max(0, columnIndexes[0] * step - padding),
			top: Math.max(0, rowIndexes[0] * step - padding),
			right: Math.min(width, (columnIndexes[columnIndexes.length - 1] + 1) * step + padding),
			bottom: Math.min(height, (rowIndexes[rowIndexes.length - 1] + 1) * step + padding)
		};
		if ((bounds.right - bounds.left) * (bounds.bottom - bounds.top) < width * height * 0.15)
		{
			return { left: 0, top: 0, right: width, bottom: height };
		}
		return bounds;
	}

	function receiptTextScore(text)
	{
		const value = String(text || '');
		const moneyLines = (value.match(/\d{1,6}[.,]\d{2}/g) || []).length;
		const receiptWords = (value.match(/\b(?:total|preis|zahlen|summe|aldi|lidl|coop|migros|chf|eur|mwst|vat)\b/gi) || []).length;
		const dates = (value.match(/\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}\b/g) || []).length;
		return Math.min(35, moneyLines * 7) + Math.min(50, receiptWords * 5) + Math.min(15, dates * 15);
	}

	function rotateCanvasHalfTurn(sourceCanvas)
	{
		const canvas = document.createElement('canvas');
		canvas.width = sourceCanvas.width;
		canvas.height = sourceCanvas.height;
		const context = canvas.getContext('2d');
		context.translate(canvas.width, canvas.height);
		context.rotate(Math.PI);
		context.drawImage(sourceCanvas, 0, 0);
		return canvas;
	}

	function renderPreview()
	{
		State.lines.clear();
		$('#receipt-preview-filename').text(State.file.name);
		$('#receipt-retailer').text(State.preview.retailer_name);
		$('#receipt-date').text(formatDate(State.preview.receipt_date));
		$('#receipt-total').text(formatCurrency(State.preview.receipt_total));
		$('#receipt-discounts').text(formatCurrency(State.preview.discount_total));
		$('#receipt-reconciliation-detail').text(__t('%1$s across %2$s receipt lines', formatCurrency(State.preview.parsed_total), State.preview.items.length));
		renderDuplicateWarning();
		selectLikelyShoppingLocation();

		State.preview.items.forEach(function(item)
		{
			const match = item.match;
			const product = match ? ProductsById.get(Number(match.product_id)) : null;
			const amount = product ? suggestedStockAmount(item, product, match.amount_multiplier) : item.receipt_quantity;
			State.lines.set(Number(item.line_index), {
				item: item,
				enabled: true,
				productId: product ? product.id : null,
				stockAmount: amount,
				suggestions: item.suggestions || []
			});
		});

		renderLines();
		updateCommitState();
	}

	function renderDuplicateWarning()
	{
		const warning = $('#receipt-duplicate-warning');
		if (!State.preview.duplicate)
		{
			warning.addClass('d-none').empty();
			return;
		}

		warning
			.removeClass('d-none')
			.text(__t('Already imported as receipt #%1$s on %2$s. Duplicate import is blocked.', State.preview.duplicate.id, formatDate(State.preview.duplicate.receipt_date)));
	}

	function selectLikelyShoppingLocation()
	{
		const locationId = Grocy.ReceiptImportStoreMatcher.findLikelyShoppingLocationId(
			State.preview,
			Grocy.ReceiptImport.shoppingLocations || []
		);
		$('#receipt-shopping-location').val(locationId === null ? '' : String(locationId));
	}

	function renderLines()
	{
		const container = $('#receipt-lines').empty();
		Array.from(State.lines.values()).forEach(function(line, position)
		{
			const item = line.item;
			const product = line.productId ? ProductsById.get(line.productId) : null;
			const unresolved = line.enabled && !product;
			const discount = Number(item.discount_total) > 0
				? '<span class="receipt-line-discount">-' + formatCurrency(item.discount_total) + ' ' + escapeHtml(__t('discount')) + '</span>'
				: '';
			const inferredPrice = item.price_inferred
				? '<span class="receipt-line-meta text-warning"><i class="fa-solid fa-triangle-exclamation mr-1"></i>' + escapeHtml(__t('Price inferred from receipt total; please check')) + '</span>'
				: '';
			const choiceText = product ? product.name : __t('Choose Grocy product');
			const unitName = product ? product.stockUnitName : __t('unit');
			const unitPrice = product && line.stockAmount > 0 ? formatCurrency(Number(item.net_total) / line.stockAmount) + ' / ' + escapeHtml(unitName) : '';
			const row = $(
				'<article class="receipt-line' + (unresolved ? ' is-unresolved' : '') + (!line.enabled ? ' is-disabled' : '') + '" data-line-index="' + item.line_index + '" style="animation-delay:' + Math.min(position * 35, 350) + 'ms">' +
					'<div class="receipt-line-toggle form-check">' +
						'<input class="form-check-input receipt-line-enabled" type="checkbox" aria-label="' + escapeHtml(__t('Import %1$s', item.raw_label)) + '" ' + (line.enabled ? 'checked' : '') + '>' +
					'</div>' +
					'<div>' +
						'<div class="receipt-line-label">' + escapeHtml(item.raw_label) + '</div>' +
						'<span class="receipt-line-meta">' + escapeHtml(receiptQuantityLabel(item)) + '</span>' + inferredPrice +
					'</div>' +
					'<div class="receipt-line-pricing">' +
						'<strong>' + formatCurrency(item.net_total) + '</strong>' + discount +
					'</div>' +
					'<div class="receipt-line-controls">' +
						'<button type="button" class="receipt-product-choice" data-line-index="' + item.line_index + '">' +
							'<span>' + escapeHtml(choiceText) + '</span><i class="fa-solid fa-chevron-right"></i>' +
						'</button>' +
						'<div>' +
							'<label class="sr-only" for="receipt-amount-' + item.line_index + '">' + escapeHtml(__t('Stock amount')) + '</label>' +
							'<div class="receipt-amount-wrap">' +
								'<input id="receipt-amount-' + item.line_index + '" class="form-control receipt-stock-amount" type="number" min="0.0001" step="any" value="' + escapeHtml(formatAmountInput(line.stockAmount)) + '" ' + (!line.enabled || !product ? 'disabled' : '') + '>' +
								'<span class="receipt-amount-unit" title="' + escapeHtml(unitName) + '">' + escapeHtml(unitName) + '</span>' +
							'</div>' +
							'<span class="receipt-line-meta receipt-unit-price">' + unitPrice + '</span>' +
						'</div>' +
					'</div>' +
				'</article>'
			);
			container.append(row);
		});

		bindLineEvents();
	}

	function bindLineEvents()
	{
		$('.receipt-line-enabled').on('change', function()
		{
			const index = Number($(this).closest('.receipt-line').attr('data-line-index'));
			State.lines.get(index).enabled = this.checked;
			renderLines();
			updateCommitState();
		});

		$('.receipt-product-choice').on('click', function()
		{
			State.activeLineIndex = Number($(this).attr('data-line-index'));
			openProductChooser();
		});

		$('.receipt-stock-amount').on('input change', function()
		{
			const index = Number($(this).closest('.receipt-line').attr('data-line-index'));
			const line = State.lines.get(index);
			line.stockAmount = Number(this.value);
			const product = ProductsById.get(line.productId);
			const unitPrice = product && line.stockAmount > 0 ? formatCurrency(Number(line.item.net_total) / line.stockAmount) + ' / ' + product.stockUnitName : '';
			$(this).closest('.receipt-line-controls').find('.receipt-unit-price').text(unitPrice);
			updateCommitState();
		});
	}

	function openProductChooser()
	{
		const line = State.lines.get(State.activeLineIndex);
		$('#receipt-product-search').val('');
		$('#receipt-new-product-barcode').val('');
		renderProductSuggestions(line);
		renderProductResults('');
		$('#receipt-product-modal').modal('show');
		$('#receipt-product-modal').one('shown.bs.modal', function()
		{
			if (Grocy.Components.CameraBarcodeScanner && !Grocy.Components.CameraBarcodeScanner.InitDone)
			{
				Grocy.Components.CameraBarcodeScanner.Init();
			}
			$('#receipt-product-search').trigger('focus');
		});
	}

	function renderProductSuggestions(line)
	{
		const container = $('#receipt-product-suggestions').empty();
		if (!line.suggestions || line.suggestions.length === 0)
		{
			return;
		}
		container.append('<div class="receipt-product-group-label">' + escapeHtml(__t('Suggested')) + '</div>');
		line.suggestions.forEach(function(suggestion)
		{
			const product = ProductsById.get(Number(suggestion.product_id));
			if (product)
			{
				container.append(productResultButton(product));
			}
		});
	}

	function renderProductResults(query)
	{
		const normalizedQuery = normalizeSearch(query);
		const results = Products
			.filter(product => normalizedQuery === '' || product.searchName.includes(normalizedQuery))
			.slice(0, 60);
		const container = $('#receipt-product-results').empty();
		container.append('<div class="receipt-product-group-label">' + escapeHtml(normalizedQuery ? __t('Search results') : __t('All products')) + '</div>');
		results.forEach(product => container.append(productResultButton(product)));
		if (results.length === 0)
		{
			container.append('<p class="receipt-history-empty">' + escapeHtml(__t('No matching Grocy product found')) + '</p>');
		}
		$('.receipt-product-result').off('click').on('click', function()
		{
			chooseProduct(Number($(this).attr('data-product-id')));
		});
	}

	function productResultButton(product)
	{
		return '<button type="button" class="receipt-product-result" data-product-id="' + product.id + '">' +
			'<strong>' + escapeHtml(product.name) + '</strong>' +
			'<span>' + escapeHtml(product.stockUnitName) + '</span>' +
		'</button>';
	}

	function chooseProduct(productId)
	{
		const line = State.lines.get(State.activeLineIndex);
		const product = ProductsById.get(productId);
		if (!line || !product)
		{
			return;
		}
		line.productId = productId;
		line.stockAmount = suggestedStockAmount(line.item, product, null);
		$('#receipt-product-modal').modal('hide');
		renderLines();
		updateCommitState();
	}

	async function refreshProductCatalog(preferredProductId)
	{
		const products = await getApi('receipt-import/products');
		setProductCatalog(products);

		State.lines.forEach(function(line)
		{
			if (line.productId && ProductsById.has(line.productId))
			{
				return;
			}

			const exactProduct = Products.find(product => product.searchName === line.item.normalized_label);
			if (exactProduct)
			{
				line.productId = exactProduct.id;
				line.stockAmount = suggestedStockAmount(line.item, exactProduct, null);
			}
		});

		if (preferredProductId && State.activeLineIndex !== null && ProductsById.has(Number(preferredProductId)))
		{
			const line = State.lines.get(State.activeLineIndex);
			const product = ProductsById.get(Number(preferredProductId));
			line.productId = product.id;
			line.stockAmount = suggestedStockAmount(line.item, product, null);
		}

		renderLines();
		updateCommitState();
		setLiveMessage(__t('Product list refreshed'));
	}

	function openManualProduct(barcode)
	{
		const line = State.lines.get(State.activeLineIndex);
		if (!line)
		{
			return;
		}

		const params = new URLSearchParams({
			flow: 'ReceiptImportProduct',
			name: line.item.raw_label,
			closeAfterCreation: 'true'
		});
		if (barcode)
		{
			params.set('barcode', barcode);
		}
		State.productWindow = window.open(U('/product/new?' + params.toString()), 'receipt-import-product');
		if (!State.productWindow)
		{
			toastr.warning(__t('Allow pop-ups to create a product without leaving the receipt'));
			return;
		}
		$('#receipt-product-modal').modal('hide');
		setLiveMessage(__t('Create the product in the new tab, then return here'));
	}

	async function lookupNewProductBarcode()
	{
		const barcode = String($('#receipt-new-product-barcode').val() || '').trim();
		if (!/^[A-Za-z0-9-]{4,64}$/.test(barcode))
		{
			toastr.warning(__t('Scan or enter a valid barcode'));
			return;
		}

		const button = $('#receipt-barcode-lookup-button');
		button.prop('disabled', true).addClass('disabled');
		try
		{
			const existingBarcodes = await getApi('objects/product_barcodes_view?query[]=barcode=' + encodeURIComponent(barcode));
			if (existingBarcodes.length > 0 && existingBarcodes[0].product_id)
			{
				const existingProductId = Number(existingBarcodes[0].product_id);
				await refreshProductCatalog(existingProductId);
				$('#receipt-product-modal').modal('hide');
				const existingProduct = ProductsById.get(existingProductId);
				toastr.success(__t('%s was matched', existingProduct ? existingProduct.name : barcode));
				return;
			}

			const product = await getApi('stock/barcodes/external-lookup/' + encodeURIComponent(barcode) + '?add=true');
			if (!product || !product.id)
			{
				toastr.info(__t('No product data was found. Complete the prefilled product form instead.'));
				openManualProduct(barcode);
				return;
			}

			await refreshProductCatalog(Number(product.id));
			$('#receipt-product-modal').modal('hide');
			toastr.success(__t('%s was created and matched', product.name));
		}
		catch (error)
		{
			toastr.error(error.message);
		}
		finally
		{
			button.prop('disabled', false).removeClass('disabled');
		}
	}

	function suggestedStockAmount(item, product, learnedMultiplier)
	{
		const quantity = Number(item.receipt_quantity) || 1;
		if (learnedMultiplier !== null && learnedMultiplier !== undefined && Number(learnedMultiplier) > 0)
		{
			return roundAmount(quantity * Number(learnedMultiplier));
		}

		const stockUnit = normalizeSearch(product.stockUnitName);
		const purchaseUnit = normalizeSearch(product.purchaseUnitName);
		if (item.receipt_unit === 'kg')
		{
			if (isKilogramUnit(stockUnit)) return roundAmount(quantity);
			if (isGramUnit(stockUnit)) return roundAmount(quantity * 1000);
			if (isKilogramUnit(purchaseUnit)) return roundAmount(quantity * product.purchaseToStockFactor);
			if (isGramUnit(purchaseUnit)) return roundAmount(quantity * 1000 * product.purchaseToStockFactor);
		}
		if (item.receipt_unit === 'g')
		{
			if (isGramUnit(stockUnit)) return roundAmount(quantity);
			if (isKilogramUnit(stockUnit)) return roundAmount(quantity / 1000);
		}

		return roundAmount(quantity * product.purchaseToStockFactor);
	}

	function isKilogramUnit(unit)
	{
		return ['kg', 'kilogramm', 'kilogram', 'kilograms'].includes(unit);
	}

	function isGramUnit(unit)
	{
		return ['g', 'gramm', 'gram', 'grams'].includes(unit);
	}

	function roundAmount(value)
	{
		return Math.round((Number(value) + Number.EPSILON) * 1000000) / 1000000;
	}

	function updateCommitState()
	{
		const enabledLines = Array.from(State.lines.values()).filter(line => line.enabled);
		const validLines = enabledLines.filter(line => line.productId && Number.isFinite(line.stockAmount) && line.stockAmount > 0);
		const allValid = enabledLines.length > 0 && validLines.length === enabledLines.length && !(State.preview && State.preview.duplicate);
		const selectedTotal = enabledLines.reduce((sum, line) => sum + Number(line.item.net_total), 0);
		$('#receipt-resolution-count').text(__t('%1$s of %2$s matched', validLines.length, enabledLines.length));
		$('#receipt-commit-summary').text(__t('%1$s products · %2$s', validLines.length, formatCurrency(selectedTotal)));
		$('#receipt-commit-button').prop('disabled', !allValid);
	}

	async function commitReceipt()
	{
		const button = $('#receipt-commit-button');
		if (button.prop('disabled'))
		{
			return;
		}
		const selectedLines = Array.from(State.lines.entries())
			.filter(([, line]) => line.enabled)
			.map(([lineIndex, line]) => ({
				line_index: lineIndex,
				product_id: line.productId,
				stock_amount: line.stockAmount
			}));

		button.prop('disabled', true);
		button.find('.receipt-commit-label').addClass('d-none');
		button.find('.receipt-commit-busy').removeClass('d-none');
		setLiveMessage(__t('Importing receipt'));

		try
		{
			const result = await postApi('receipt-import/commit', {
				raw_text: State.rawText,
				receipt_hash: State.receiptHash,
				source_filename: State.file.name,
				shopping_location_id: $('#receipt-shopping-location').val() || null,
				selected_lines: selectedLines
			});
			State.lastImportId = Number(result.receipt_import_id);
			$('#receipt-review, #receipt-commit-bar').addClass('d-none');
			$('#receipt-success').removeClass('d-none');
			$('#receipt-success-detail').text(__t('%1$s receipt lines were added and verified.', result.items.length));
			$('#receipt-success-undo').toggleClass('d-none', !canUndoStock());
			setLiveMessage(__t('Receipt import complete'));
			await refreshHistory();
			toastr.success(__t('Receipt imported successfully'));
		}
		catch (error)
		{
			console.error(error);
			toastr.error(escapeHtml(error.message));
			updateCommitState();
		}
		finally
		{
			button.find('.receipt-commit-label').removeClass('d-none');
			button.find('.receipt-commit-busy').addClass('d-none');
		}
	}

	async function undoReceipt(receiptImportId)
	{
		try
		{
			await postApi('receipt-import/' + receiptImportId + '/undo', {});
			if (State.lastImportId === receiptImportId)
			{
				$('#receipt-success-detail').text(__t('The complete receipt import was undone.'));
				$('#receipt-success-undo').addClass('d-none');
			}
			await refreshHistory();
			toastr.success(__t('Receipt import successfully undone'));
			setLiveMessage(__t('Receipt import undone'));
		}
		catch (error)
		{
			toastr.error(escapeHtml(error.message));
		}
	}

	function confirmUndo(receiptImportId)
	{
		bootbox.confirm({
			title: __t('Undo entire receipt?'),
			message: __t('All stock transactions created by this receipt will be undone. This can fail if any of those stock entries have dependent later bookings.'),
			buttons: {
				confirm: { label: __t('Undo receipt'), className: 'btn-danger' },
				cancel: { label: __t('Cancel'), className: 'btn-secondary' }
			},
			callback: function(confirmed)
			{
				if (confirmed) undoReceipt(receiptImportId);
			}
		});
	}

	async function refreshHistory()
	{
		try
		{
			Grocy.ReceiptImport.history = await getApi('receipt-import/history');
			renderHistory();
		}
		catch (error)
		{
			toastr.error(escapeHtml(error.message));
		}
	}

	function renderHistory()
	{
		const history = Grocy.ReceiptImport.history || [];
		const container = $('#receipt-history-list').empty();
		if (history.length === 0)
		{
			container.append('<p class="receipt-history-empty">' + escapeHtml(__t('No receipts imported yet')) + '</p>');
			return;
		}

		history.forEach(function(receipt)
		{
			const canUndo = canUndoStock() && receipt.status === 'imported';
			const importedLineCount = Number(receipt.imported_line_count) || 0;
			const lineCountLabel = importedLineCount + ' ' + __t(importedLineCount === 1 ? 'line' : 'lines');
			container.append(
				'<div class="receipt-history-row">' +
					'<div><strong>' + escapeHtml(receipt.retailer_name) + '</strong><span class="receipt-history-meta">#' + receipt.id + ' · ' + escapeHtml(receipt.source_filename || __t('Receipt')) + '</span></div>' +
					'<div><strong>' + escapeHtml(formatDate(receipt.receipt_date)) + '</strong><span class="receipt-history-meta">' + escapeHtml(receipt.shopping_location_name || __t('No store')) + '</span></div>' +
					'<div><strong>' + formatCurrency(receipt.receipt_total) + '</strong><span class="receipt-history-meta">' + escapeHtml(lineCountLabel) + '</span></div>' +
					'<div><span class="receipt-history-status is-' + escapeHtml(receipt.status) + '">' + escapeHtml(receipt.status === 'imported' ? __t('Imported') : __t('Undone')) + '</span>' +
						(canUndo ? '<button type="button" class="btn btn-sm btn-link receipt-history-undo" data-receipt-id="' + receipt.id + '">' + escapeHtml(__t('Undo')) + '</button>' : '') +
					'</div>' +
				'</div>'
			);
		});

		$('.receipt-history-undo').on('click', function()
		{
			confirmUndo(Number($(this).attr('data-receipt-id')));
		});
	}

	function canUndoStock()
	{
		return (Grocy.UserPermissions || []).some(permission => permission.permission_name === 'STOCK_EDIT');
	}

	function resetState(showCapture)
	{
		if (State.objectUrl)
		{
			URL.revokeObjectURL(State.objectUrl);
		}
		State.file = null;
		State.receiptHash = null;
		State.rawText = null;
		State.preview = null;
		State.lines.clear();
		State.activeLineIndex = null;
		State.objectUrl = null;
		State.lastImportId = null;
		State.ocrDiagnostic = null;
		$('#receipt-camera-input, #receipt-file-input').val('');
		$('#receipt-ocr-diagnostic').addClass('d-none').prop('open', false);
		$('#receipt-ocr-diagnostic-meta, #receipt-ocr-primary, #receipt-ocr-recovery, #receipt-ocr-merged, #receipt-ocr-rotated, #receipt-ocr-selected').text('');
		$('#receipt-pdf-preview, #receipt-image-preview').addClass('d-none');
		$('#receipt-commit-bar, #receipt-success').addClass('d-none');
		$('#receipt-reset-button').toggleClass('d-none', !!showCapture);
		if (showCapture)
		{
			showOnly('#receipt-capture');
		}
	}

	function renderOcrDiagnostic(openPanel)
	{
		if (!OCR_DIAGNOSTICS_ENABLED || !State.ocrDiagnostic)
		{
			return;
		}
		const diagnostic = State.ocrDiagnostic;
		$('#receipt-ocr-diagnostic-meta').text(JSON.stringify({
			file: diagnostic.file,
			image: diagnostic.image,
			primaryScore: diagnostic.primaryScore,
			recoveryNeeded: diagnostic.recoveryNeeded,
			selectedPass: diagnostic.selectedPass,
			result: diagnostic.result
		}, null, 2));
		$('#receipt-ocr-primary').text(diagnostic.primary || '(empty)');
		$('#receipt-ocr-recovery').text(diagnostic.recovery || '(not run)');
		$('#receipt-ocr-merged').text(diagnostic.merged || '(empty)');
		$('#receipt-ocr-rotated').text(diagnostic.rotated || '(not run)');
		$('#receipt-ocr-selected').text(diagnostic.selected || '(empty)');
		$('#receipt-ocr-diagnostic').removeClass('d-none').prop('open', !!openPanel);
	}

	function receiptQuantityLabel(item)
	{
		if (item.receipt_unit === 'kg' || item.receipt_unit === 'g')
		{
			return formatAmountInput(item.receipt_quantity) + ' ' + item.receipt_unit + ' × ' + formatCurrency(item.listed_unit_price) + '/' + item.receipt_unit;
		}
		if (Number(item.receipt_quantity) !== 1 && item.listed_unit_price !== null)
		{
			return formatAmountInput(item.receipt_quantity) + ' × ' + formatCurrency(item.listed_unit_price);
		}
		return __t('1 receipt line');
	}

	function formatCurrency(value)
	{
		return Number(value).toLocaleString(undefined, { style: 'currency', currency: State.preview ? State.preview.currency : 'CHF', minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function formatDate(value)
	{
		const parts = String(value).split('-');
		if (parts.length !== 3) return value;
		return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])).toLocaleDateString();
	}

	function formatAmountInput(value)
	{
		return Number(value).toFixed(6).replace(/0+$/, '').replace(/[.]$/, '');
	}

	function normalizeSearch(value)
	{
		return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
	}

	function escapeHtml(value)
	{
		return $('<div>').text(String(value === null || value === undefined ? '' : value)).html();
	}

	$('#receipt-camera-input, #receipt-file-input').on('change', function()
	{
		handleFile(this.files && this.files[0]);
	});

	$('#receipt-reset-button').on('click', function()
	{
		resetState(true);
	});

	$('#receipt-commit-button').on('click', commitReceipt);
	$('#receipt-success-undo').on('click', function()
	{
		if (State.lastImportId) confirmUndo(State.lastImportId);
	});
	$('#receipt-history-refresh').on('click', refreshHistory);
	$('#receipt-product-search').on('input', function()
	{
		renderProductResults(this.value);
	});
	$('#receipt-create-product-button').on('click', function()
	{
		openManualProduct(null);
	});
	$('#receipt-barcode-lookup-button').on('click', lookupNewProductBarcode);
	$('#receipt-new-product-barcode').on('keydown', function(event)
	{
		if (event.key === 'Enter')
		{
			event.preventDefault();
			lookupNewProductBarcode();
		}
	});
	$(document).on('Grocy.BarcodeScanned', function(event, barcode, target)
	{
		if (target !== '@receiptimportnewproduct')
		{
			return;
		}
		$('#receipt-new-product-barcode').val(barcode);
		lookupNewProductBarcode();
	});
	window.addEventListener('message', async function(event)
	{
		if (event.origin !== window.location.origin || !event.data || event.data.Message !== 'ReceiptImportProductCreated')
		{
			return;
		}
		try
		{
			await refreshProductCatalog(Number(event.data.product_id));
			toastr.success(__t('%s was created and matched', event.data.product_name));
		}
		catch (error)
		{
			toastr.error(error.message);
		}
		State.productWindow = null;
	});
	window.addEventListener('focus', async function()
	{
		if (!State.productWindow || !State.productWindow.closed)
		{
			return;
		}
		State.productWindow = null;
		try
		{
			await refreshProductCatalog(null);
		}
		catch (error)
		{
			toastr.error(error.message);
		}
	});
	$('#receipt-preview-toggle').on('click', function()
	{
		const frame = $('#receipt-preview-frame');
		const willHide = !frame.hasClass('d-none');
		frame.toggleClass('d-none', willHide);
		$(this).attr('aria-expanded', String(!willHide)).text(willHide ? __t('Show preview') : __t('Hide preview'));
	});

	renderHistory();
})();
