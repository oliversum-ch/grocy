import assert from 'node:assert/strict';
import { createWorker, OEM, PSM } from '../public/packages/tesseract.js/src/index.js';

const imagePath = process.argv[2];
assert.ok(imagePath, 'Pass a receipt image path as the first argument');

const worker = await createWorker(['deu', 'eng'], OEM.LSTM_ONLY);
try
{
	await worker.setParameters({
		tessedit_pageseg_mode: PSM.SINGLE_BLOCK,
		preserve_interword_spaces: '1',
		user_defined_dpi: '300'
	});
	const result = await worker.recognize(imagePath, { rotateAuto: true });
	assert.match(result.data.text, /Veganes Cordon Bleu/i);
	assert.match(result.data.text, /Broccoli/i);
	assert.match(result.data.text, /25[.,]74/);
	console.log('ReceiptImportOCR: local photo OCR passed');
}
finally
{
	await worker.terminate();
}
