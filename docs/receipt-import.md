# Receipt import

The receipt importer adds reviewed Lidl Switzerland purchases to Grocy stock from either a digital PDF or a photograph of a paper receipt.

## What it does

- Reads digital PDF text in the browser with PDF.js.
- Reads receipt photos in the browser with Tesseract.js. The original PDF or image is not uploaded to Grocy.
- Parses Lidl Switzerland line items, quantities, weighted produce, item discounts, date, store label, currency, and total.
- Rejects receipts whose parsed line total does not reconcile with the printed receipt total.
- Matches exact Grocy product names and previously learned retailer aliases.
- Requires every enabled receipt line to have a reviewed Grocy product and positive stock amount.
- Adds selected lines through Grocy's stock service inside one database transaction.
- Verifies each resulting stock transaction before completing the import.
- Blocks duplicate receipt fingerprints.
- Records the transaction IDs needed to undo the complete receipt later.

The first version intentionally does not create new Grocy products automatically. Add an unknown product in Grocy first, then return to the receipt review and select it.

## Installation

Deploy the complete Grocy release, including `public/packages/pdfjs-dist`, `public/packages/tesseract.js`, and `public/packages/tesseract.js-core`. On first request after deploying the code, Grocy migration `0258` creates the three isolated receipt-import tables.

The first photo scan downloads the German and English Tesseract recognition models from the public jsDelivr package CDN and the browser then caches them. The receipt image itself stays in the browser and is never sent to that CDN or another OCR service. A fully offline installation can self-host the same `@tesseract.js-data/deu` and `@tesseract.js-data/eng` model files and set Tesseract's `langPath` to that local directory.

The web server must serve Grocy over HTTPS. Browser cryptographic receipt fingerprints and phone camera access require a secure context.

No additional server service, API key, Python installation, container, GPU, or cloud OCR account is required.

## Permissions

- `STOCK_PURCHASE` is required to open the importer, preview receipts, and commit a reviewed import.
- `STOCK_EDIT` is additionally required to undo an imported receipt.

For a dedicated household importer account, grant only the existing Grocy areas and actions that person otherwise needs. Receipt import does not require administrator or master-data-edit permission.

## Workflow

1. Open **Receipt import** below **Purchase** in the Grocy sidebar.
2. Take a receipt photo or choose a PDF/image.
3. Wait for local extraction. A digital Lidl PDF normally uses its embedded text; photographs run local OCR in the browser.
4. Review the receipt date, total, discounts, Grocy store, product mapping, stock amount, and stock unit.
5. Disable any line which should not be tracked in Grocy.
6. Select **Add to Grocy** only after every enabled line is resolved.
7. Use **Undo entire receipt** if the complete import should be reversed. Undo can fail after a stock entry has dependent later bookings, matching Grocy's existing transaction-undo rules.

Each accepted product mapping is stored as a retailer-specific alias. A future receipt with the same normalized Lidl label proposes the same Grocy product and quantity multiplier.

## Amount and price handling

The review screen always shows the final amount that will be added in the selected product's Grocy stock unit.

- Piece-count lines use the product's purchase-to-stock conversion factor.
- Receipt weights in kilograms or grams are converted when the Grocy stock unit clearly represents kilograms or grams.
- Learned aliases retain the reviewed multiplier for future receipts.
- Item discounts are allocated to their receipt line.
- Grocy receives the net price per stock unit.
- The printed receipt purchase date is preserved.
- When the receipt has no expiry, the product's default expiry is calculated from the purchase date rather than the import date.

## Photo guidance

For best OCR results:

- place the receipt flat on a plain, contrasting surface;
- use bright, even light without glare;
- include the complete receipt and keep the camera parallel to it;
- fill most of the frame with the receipt;
- retake faded or blurred thermal receipts rather than approving uncertain values.

OCR output is never trusted directly: Lidl parsing, total reconciliation, product matching, and the final human review all occur before stock changes.

## Adding another retailer

Retailer parsing is isolated in `services/ReceiptImportParser.php`. Add retailer detection, a deterministic parser, and fixtures representing each supported receipt layout. A new parser must preserve these invariants:

- line discounts belong to their product line;
- quantity and weight syntax are explicit;
- the sum of parsed net lines reconciles with the printed total;
- unsupported or ambiguous layouts fail closed;
- no unreviewed product creation or stock write occurs.

## Validation

Run the deterministic parser test:

```powershell
php .\tests\receipt_import_parser_test.php
```

The sample PDF extraction test accepts the PDF path as its argument:

```powershell
node .\tests\receipt_import_pdf_text_test.mjs C:\path\to\receipt.pdf
```

The optional photo OCR test accepts a rendered or photographed receipt image. It downloads the Tesseract language models on first run:

```powershell
$env:NODE_PATH = (Resolve-Path .\public\packages).Path
node .\tests\receipt_import_ocr_test.mjs C:\path\to\receipt-photo.png
```

Before deploying, also run PHP syntax checks for the new controllers/services, the migration test, a JavaScript syntax check, `git diff --check`, and the HTTP integration test against a disposable Grocy database. The integration test commits a stock line and undoes it again, so never point it at a real Grocy database.
