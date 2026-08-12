const assert = require('node:assert/strict');
const matcher = require('../public/viewjs/receiptimportstore.js');

const locations = [
	{ id: 1, name: 'LIDL Schweiz' },
	{ id: 5, name: 'LIDL Deutschland' },
	{ id: 7, name: 'DM' }
];

assert.equal(
	matcher.findLikelyShoppingLocationId({ retailer_key: 'lidl_ch', retailer_name: 'Lidl Switzerland' }, locations),
	1,
	'Swiss Lidl receipts must prefer LIDL Schweiz when another Lidl country exists'
);

assert.equal(
	matcher.findLikelyShoppingLocationId({ retailer_key: 'lidl_de', retailer_name: 'Lidl Germany' }, locations),
	5,
	'German Lidl receipts must prefer LIDL Deutschland'
);

assert.equal(
	matcher.findLikelyShoppingLocationId({ retailer_key: 'unknown', retailer_name: 'Lidl' }, locations),
	null,
	'A brand-only retailer must stay unresolved when multiple country locations match'
);

console.log('ReceiptImportStoreMatcher: country-aware unambiguous store selection passed');
