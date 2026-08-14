@extends('layout.default')

@if($canCreateProducts && !empty($externalBarcodeLookupPluginName))
@include('components.camerabarcodescanner')
@endif

@section('title', $__t('Receipt import'))

@push('pageStyles')
<link href="{{ $U('/css/receiptimport.css?v=', true) }}{{ $version }}-receipt-product-handoff-1"
	rel="stylesheet">
@endpush

@push('pageScripts')
<script src="{{ $U('/packages/tesseract.js/dist/tesseract.min.js?v=', true) }}{{ $version }}"></script>
<script src="{{ $U('/viewjs/receiptimportstore.js?v=', true) }}{{ $version }}-receipt-store-matcher-1"></script>
@endpush

@section('content')
<script>
	Grocy.ReceiptImport = {
		products: {!! json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!},
		shoppingLocations: {!! json_encode($shoppingLocations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!},
		history: {!! json_encode($importHistory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!},
		canCreateProducts: {{ BoolToString($canCreateProducts) }},
		externalBarcodeLookupPluginName: {!! json_encode($externalBarcodeLookupPluginName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!},
		assets: {
			pdfModule: "{{ $U('/packages/pdfjs-dist/build/pdf.mjs', true) }}",
			pdfWorker: "{{ $U('/packages/pdfjs-dist/build/pdf.worker.mjs', true) }}",
			paddleModule: "{{ $U('/viewjs/vendor/receipt-ocr/paddleocr-0.4.2.mjs?v=', true) }}{{ $version }}",
			paddleDetectionModel: "{{ $U('/viewjs/vendor/receipt-ocr/PP-OCRv6_tiny_det_onnx_infer.tar?v=', true) }}{{ $version }}",
			paddleRecognitionModel: "{{ $U('/viewjs/vendor/receipt-ocr/PP-OCRv6_tiny_rec_onnx_infer.tar?v=', true) }}{{ $version }}",
			paddleOrt: "{{ $U('/viewjs/vendor/receipt-ocr/ort-1.27.0/', true) }}",
			tesseractWorker: "{{ $U('/packages/tesseract.js/dist/worker.min.js', true) }}",
			tesseractCore: "{{ $U('/packages/tesseract.js-core', true) }}"
		}
	};
</script>

<div class="receipt-import-shell">
	<header class="receipt-import-header">
		<div>
			<p class="receipt-import-kicker">{{ $__t('Stock purchase') }}</p>
			<h2>{{ $__t('Receipt import') }}</h2>
			<p class="receipt-import-intro">{{ $__t('Scan an itemized receipt, match each line once, then add the reviewed purchase to stock.') }}</p>
		</div>
		<button id="receipt-reset-button"
			class="btn btn-outline-secondary d-none"
			type="button">
			<i class="fa-solid fa-rotate-left mr-1"></i>{{ $__t('Start over') }}
		</button>
	</header>

	<div id="receipt-import-live"
		class="sr-only"
		role="status"
		aria-live="polite"></div>

	<section id="receipt-capture"
		class="receipt-capture"
		aria-labelledby="receipt-capture-title">
		<div class="receipt-capture-copy">
			<span class="receipt-capture-mark"><i class="fa-solid fa-receipt"></i></span>
			<div>
				<h3 id="receipt-capture-title">{{ $__t('Add a receipt') }}</h3>
				<p>{{ $__t('PDF text and photo OCR are processed in this browser. The original file is not uploaded.') }}</p>
			</div>
		</div>
		<div class="receipt-capture-actions">
			<label class="btn btn-primary receipt-file-action" for="receipt-camera-input">
				<i class="fa-solid fa-camera mr-2"></i>{{ $__t('Take photo') }}
			</label>
			<input id="receipt-camera-input"
				class="sr-only"
				type="file"
				accept="image/*"
				capture="environment">

			<label class="btn btn-outline-dark receipt-file-action" for="receipt-file-input">
				<i class="fa-solid fa-file-arrow-up mr-2"></i>{{ $__t('Choose PDF or image') }}
			</label>
			<input id="receipt-file-input"
				class="sr-only"
				type="file"
				accept="application/pdf,image/*">
		</div>
		<p class="receipt-capture-footnote">{{ $__t('Supports common itemized receipt layouts. Maximum file size 20 MB.') }}</p>
	</section>

	<section id="receipt-processing"
		class="receipt-processing d-none"
		aria-labelledby="receipt-processing-title">
		<div class="receipt-processing-bar"><span id="receipt-processing-progress"></span></div>
		<div>
			<p class="receipt-import-kicker" id="receipt-processing-stage">{{ $__t('Preparing file') }}</p>
			<h3 id="receipt-processing-title">{{ $__t('Reading receipt') }}</h3>
			<p id="receipt-processing-detail">{{ $__t('This can take a moment for a photo.') }}</p>
		</div>
	</section>

	<details id="receipt-ocr-diagnostic" class="card mt-4 mb-4 d-none">
		<summary class="card-header"><strong>OCR debug information (test only)</strong></summary>
		<div class="card-body">
			<p class="text-muted">This panel remains available on the test instance while receipt recognition is being tuned.</p>
			<h4>Run details</h4>
			<pre id="receipt-ocr-diagnostic-meta" class="border rounded p-2 bg-light text-wrap"></pre>
			<h4>PaddleOCR</h4>
			<pre id="receipt-ocr-paddle" class="border rounded p-2 bg-light text-wrap"></pre>
			<h4>Tesseract primary fallback</h4>
			<pre id="receipt-ocr-primary" class="border rounded p-2 bg-light text-wrap"></pre>
			<h4>Low-contrast recovery OCR</h4>
			<pre id="receipt-ocr-recovery" class="border rounded p-2 bg-light text-wrap"></pre>
			<h4>Merged OCR</h4>
			<pre id="receipt-ocr-merged" class="border rounded p-2 bg-light text-wrap"></pre>
			<h4>High-threshold diagnostic OCR</h4>
			<pre id="receipt-ocr-threshold" class="border rounded p-2 bg-light text-wrap"></pre>
			<h4>Opposite orientation OCR</h4>
			<pre id="receipt-ocr-rotated" class="border rounded p-2 bg-light text-wrap"></pre>
			<h4>Text sent to the parser</h4>
			<pre id="receipt-ocr-selected" class="border rounded p-2 bg-light text-wrap"></pre>
		</div>
	</details>

	<section id="receipt-review"
		class="receipt-review d-none"
		aria-labelledby="receipt-review-title">
		<div class="receipt-preview-column">
			<div class="receipt-preview-heading">
				<div>
					<p class="receipt-import-kicker">{{ $__t('Source') }}</p>
					<h3 id="receipt-preview-filename"></h3>
				</div>
				<button id="receipt-preview-toggle"
					class="btn btn-sm btn-outline-secondary d-lg-none"
					type="button"
					aria-expanded="true">
					{{ $__t('Hide preview') }}
				</button>
			</div>
			<div id="receipt-preview-frame" class="receipt-preview-frame">
				<canvas id="receipt-pdf-preview" class="d-none"></canvas>
				<img id="receipt-image-preview" class="d-none" alt="{{ $__t('Receipt preview') }}">
			</div>
		</div>

		<div class="receipt-review-column">
			<div class="receipt-review-heading">
				<div>
					<p class="receipt-import-kicker">{{ $__t('Review') }}</p>
					<h3 id="receipt-review-title">{{ $__t('Match purchased products') }}</h3>
				</div>
				<span id="receipt-resolution-count" class="receipt-resolution-count"></span>
			</div>

			<div id="receipt-duplicate-warning" class="receipt-warning d-none" role="alert"></div>

			<div class="receipt-meta">
				<div><span>{{ $__t('Retailer') }}</span><strong id="receipt-retailer"></strong></div>
				<div><span>{{ $__t('Date') }}</span><strong id="receipt-date"></strong></div>
				<div><span>{{ $__t('Total') }}</span><strong id="receipt-total"></strong></div>
				<div><span>{{ $__t('Discounts') }}</span><strong id="receipt-discounts"></strong></div>
			</div>

			<div class="form-group receipt-store-field">
				<label for="receipt-shopping-location">{{ $__t('Grocy store') }}</label>
				<select id="receipt-shopping-location" class="form-control">
					<option value="">{{ $__t('No store') }}</option>
					@foreach($shoppingLocations as $shoppingLocation)
					<option value="{{ $shoppingLocation['id'] }}">{{ $shoppingLocation['name'] }}</option>
					@endforeach
				</select>
			</div>

			<div id="receipt-lines" class="receipt-lines"></div>

			<div class="receipt-reconciliation">
				<i class="fa-solid fa-circle-check"></i>
				<div>
					<strong>{{ $__t('Receipt total reconciled') }}</strong>
					<span id="receipt-reconciliation-detail"></span>
				</div>
			</div>
		</div>
	</section>

	<div id="receipt-commit-bar" class="receipt-commit-bar d-none">
		<div>
			<strong id="receipt-commit-summary"></strong>
			<span>{{ $__t('Only matched, enabled lines will be added.') }}</span>
		</div>
		<button id="receipt-commit-button" class="btn btn-primary" type="button" disabled>
			<span class="receipt-commit-label"><i class="fa-solid fa-cart-plus mr-2"></i>{{ $__t('Add to Grocy') }}</span>
			<span class="receipt-commit-busy d-none"><i class="fa-solid fa-circle-notch fa-spin mr-2"></i>{{ $__t('Importing') }}</span>
		</button>
	</div>

	<section id="receipt-success" class="receipt-success d-none" aria-labelledby="receipt-success-title">
		<span class="receipt-success-mark"><i class="fa-solid fa-check"></i></span>
		<div>
			<p class="receipt-import-kicker">{{ $__t('Import complete') }}</p>
			<h3 id="receipt-success-title">{{ $__t('Purchase added to stock') }}</h3>
			<p id="receipt-success-detail"></p>
		</div>
		<button id="receipt-success-undo" class="btn btn-outline-dark d-none" type="button">
			<i class="fa-solid fa-undo mr-2"></i>{{ $__t('Undo entire receipt') }}
		</button>
	</section>

	<section class="receipt-history" aria-labelledby="receipt-history-title">
		<div class="receipt-history-heading">
			<div>
				<p class="receipt-import-kicker">{{ $__t('Recent activity') }}</p>
				<h3 id="receipt-history-title">{{ $__t('Imported receipts') }}</h3>
			</div>
			<button id="receipt-history-refresh" class="btn btn-sm btn-outline-secondary" type="button">
				<i class="fa-solid fa-rotate mr-1"></i>{{ $__t('Refresh') }}
			</button>
		</div>
		<div id="receipt-history-list" class="receipt-history-list"></div>
	</section>
</div>

<div class="modal fade receipt-product-modal"
	id="receipt-product-modal"
	tabindex="-1"
	role="dialog"
	aria-labelledby="receipt-product-modal-title"
	aria-hidden="true">
	<div class="modal-dialog modal-dialog-scrollable" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<div>
					<p class="receipt-import-kicker mb-1">{{ $__t('Grocy product') }}</p>
					<h4 class="modal-title" id="receipt-product-modal-title">{{ $__t('Choose a match') }}</h4>
				</div>
				<button type="button" class="close" data-dismiss="modal" aria-label="{{ $__t('Close') }}"><span aria-hidden="true">&times;</span></button>
			</div>
			<div class="modal-body">
				<label class="sr-only" for="receipt-product-search">{{ $__t('Search products') }}</label>
				<div class="receipt-product-search-wrap">
					<i class="fa-solid fa-magnifying-glass"></i>
					<input id="receipt-product-search" class="form-control" type="search" autocomplete="off" placeholder="{{ $__t('Search products') }}">
				</div>
				<div id="receipt-product-suggestions" class="receipt-product-suggestions"></div>
				<div id="receipt-product-results" class="receipt-product-results"></div>
			</div>
			@if($canCreateProducts)
			<div class="modal-footer receipt-product-create">
				<div class="receipt-product-create-copy">
					<strong>{{ $__t('Product not in Grocy?') }}</strong>
					<span>{{ $__t('Create it without losing this receipt review.') }}</span>
				</div>
				@if(!empty($externalBarcodeLookupPluginName))
				<div class="receipt-barcode-create">
					<div class="receipt-barcode-input-wrap">
						<label class="sr-only" for="receipt-new-product-barcode">{{ $__t('New product barcode') }}</label>
						<input id="receipt-new-product-barcode"
							class="form-control barcodescanner-input"
							type="text"
							inputmode="numeric"
							autocomplete="off"
							data-target="@receiptimportnewproduct"
							placeholder="{{ $__t('Scan or enter barcode') }}">
					</div>
					<button id="receipt-barcode-lookup-button" class="btn btn-dark" type="button">
						<i class="fa-solid fa-barcode mr-1"></i>{{ $__t('Look up') }}
					</button>
				</div>
				<small>{{ $__t('Barcode lookup via %s', $externalBarcodeLookupPluginName) }}</small>
				@endif
				<button id="receipt-create-product-button" class="btn btn-outline-primary" type="button">
					<i class="fa-solid fa-plus mr-1"></i>{{ $__t('Create product manually') }}
				</button>
			</div>
			@endif
		</div>
	</div>
</div>
@endsection
