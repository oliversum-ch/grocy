import assert from 'node:assert/strict';
import { createWorker, OEM, PSM } from '../public/packages/tesseract.js/src/index.js';

const imagePath = process.argv[2];
assert.ok(imagePath, 'Pass a receipt image path as the first argument');
const rotateArgument = process.argv.find(argument => argument.startsWith('--rotate='));
const rotateDegrees = rotateArgument ? Number(rotateArgument.split('=')[1]) : null;
const expectedTokens = process.argv
	.filter(argument => argument.startsWith('--expect='))
	.map(argument => argument.slice('--expect='.length));

const worker = await createWorker(['deu', 'eng'], OEM.LSTM_ONLY);
try
{
	await worker.setParameters({
		tessedit_pageseg_mode: PSM.SINGLE_BLOCK,
		preserve_interword_spaces: '1',
		user_defined_dpi: '300'
	});
	const result = await worker.recognize(imagePath, rotateDegrees === null
		? { rotateAuto: true }
		: { rotateAuto: false, rotateRadians: rotateDegrees * Math.PI / 180 });
	const expectations = expectedTokens.length > 0 ? expectedTokens : ['Veganes Cordon Bleu', 'Broccoli', '25.74'];
	expectations.forEach(token => assert.match(result.data.text, new RegExp(token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i')));
	console.log('ReceiptImportOCR: local photo OCR passed');
}
finally
{
	await worker.terminate();
}
