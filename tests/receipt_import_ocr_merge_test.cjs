const assert = require('node:assert/strict');
const ReceiptImportOcr = require('../public/viewjs/receiptimport.js');

const primary = [
	'ALDI SUISSE AG',
	'27622 Jumbo Erdniss 1.79 A',
	'556816 Low Carb Riegel HE',
	'433984 Flachpfir. 500g ).95 4',
	'157241 Cracker Mix 300 g 1.19 A',
	'fLDI PREIS 6.60'
].join('\n');
const recovery = [
	'29 Low Carb Riegel 269 A',
	'gts lachpfir, 500g 0.95 A',
	'Cracker Mix 300 g 1.19 A'
].join('\n');

assert.equal(ReceiptImportOcr.needsRecovery(primary), true, 'An article line without a price triggers recovery');
const merged = ReceiptImportOcr.merge(primary, recovery);
assert.match(merged, /556816 Low Carb Riegel 2[.]69 A/, 'The second OCR pass restores the matching missing price');
assert.match(merged, /433984 Flachpfir[.] 500g 0[.]95 4/, 'OCR-confused zero is normalized without a second-pass replacement');
assert.doesNotMatch(merged, /Cracker Mix 300 g 1[.]19 A\nCracker/, 'Recovery lines are not appended as duplicate products');
assert.equal(ReceiptImportOcr.needsRecovery(merged), false, 'All article-coded lines have a price after fusion');

const capturedMobilePrimary = [
	'ALDI SUISSE AG',
	'7622 Jumbo Erdniiss 1.79 A',
	'0816 ow Carb Riege] 69 A',
	'433984 Flachofi, 500g 95 A',
	'157241 Cracke, Mix 300 g 1.19 A'
].join('\n');
const capturedMobileRecovery = [
	'Rat Riegel 269 4',
	'157248 reckon Hi 5009 0.95 A',
	'A x 3009 1.194'
].join('\n');
const capturedMobileMerged = ReceiptImportOcr.merge(capturedMobilePrimary, capturedMobileRecovery);
assert.match(capturedMobileMerged, /0816 ow Carb Riege] 2[.]69 4/, 'A tax marker misread as 4 must not hide a recovered price');

console.log('ReceiptImportOCRMerge: unclear prices are recovered without duplicate lines');
