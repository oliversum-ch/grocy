import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

// PDF.js only needs these browser geometry types for rendering; text extraction does not.
globalThis.DOMMatrix ??= class DOMMatrix {};
globalThis.Path2D ??= class Path2D {};
globalThis.ImageData ??= class ImageData {};

const { getDocument } = await import('../public/packages/pdfjs-dist/legacy/build/pdf.mjs');

function reconstructPdfLines(items)
{
	const positioned = items
		.filter(item => item.str && item.str.trim() !== '')
		.map(item => ({ text: item.str.trim(), x: item.transform[4], y: item.transform[5] }))
		.sort((a, b) => Math.abs(b.y - a.y) > 2.5 ? b.y - a.y : a.x - b.x);
	const rows = [];
	for (const item of positioned)
	{
		let row = rows.find(candidate => Math.abs(candidate.y - item.y) <= 2.5);
		if (!row)
		{
			row = { y: item.y, items: [] };
			rows.push(row);
		}
		row.items.push(item);
	}
	return rows
		.sort((a, b) => b.y - a.y)
		.map(row => row.items.sort((a, b) => a.x - b.x).map(item => item.text).join(' '))
		.join('\n');
}

const pdfPath = process.argv[2];
assert.ok(pdfPath, 'Pass the Lidl PDF path as the first argument');
const pdfBytes = new Uint8Array(await readFile(pdfPath));
const pdf = await getDocument({ data: pdfBytes, disableWorker: true }).promise;
assert.ok(pdf.numPages >= 1, 'The sample receipt should have at least one page');
const pages = [];
for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++)
{
	const page = await pdf.getPage(pageNumber);
	const content = await page.getTextContent();
	pages.push(reconstructPdfLines(content.items));
}
const text = pages.join('\n');

assert.match(text, /Veganes Cordon Bleu/);
assert.match(text, /Broccoli/);
assert.match(text, /zu zahlen\s+25[.,]74/i);
assert.match(text, /12\. Aug\. 2026/);

console.log('ReceiptImportPDF: browser-compatible PDF text extraction passed');
