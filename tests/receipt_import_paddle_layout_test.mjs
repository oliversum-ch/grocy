import assert from 'node:assert/strict';
import ReceiptImportOcr from '../public/viewjs/receiptimport.js';

const text = ReceiptImportOcr.paddleText([
	{ text: 'ALDI PREIS', score: 0.97, poly: [[10, 100], [210, 100], [210, 130], [10, 130]] },
	{ text: '4 Artikel', score: 0.95, poly: [[10, 151], [180, 151], [180, 181], [10, 181]] },
	{ text: '6.60', score: 0.99, poly: [[550, 150], [650, 150], [650, 182], [550, 182]] },
	{ text: 'Kartenzahlung CHF', score: 0.96, poly: [[10, 200], [330, 200], [330, 231], [10, 231]] },
	{ text: '6.60', score: 0.99, poly: [[550, 200], [650, 200], [650, 232], [550, 232]] }
]);

assert.equal(text, 'ALDI PREIS\n4 Artikel 6.60\nKartenzahlung CHF 6.60');

const perspectiveText = ReceiptImportOcr.paddleText([
	{ text: 'Low Carb Riegel', score: 0.99, poly: [[358, 238], [544, 251], [542, 276], [356, 263]] },
	{ text: '2.69 A', score: 0.99, poly: [[607, 252], [667, 252], [667, 277], [607, 277]] },
	{ text: 'Flachpfirsich 500g', score: 0.99, poly: [[358, 259], [545, 273], [543, 298], [356, 284]] },
	{ text: '0.95 A', score: 0.99, poly: [[606, 271], [667, 273], [666, 299], [605, 297]] },
	{ text: 'Zwischensumme', score: 0.99, poly: [[325, 317], [440, 330], [437, 358], [322, 345]] },
	{ text: '6.62', score: 0.99, poly: [[603, 338], [645, 338], [645, 362], [603, 362]] },
	{ text: 'Rundung', score: 0.99, poly: [[327, 339], [390, 348], [386, 374], [323, 365]] },
	{ text: '-0.02', score: 0.99, poly: [[595, 359], [645, 359], [645, 385], [595, 385]] }
]);
assert.equal(perspectiveText, 'Low Carb Riegel 2.69 A\nFlachpfirsich 500g 0.95 A\nZwischensumme 6.62\nRundung -0.02');
console.log('ReceiptImportPaddleLayout: all tests passed');
