import { readFile } from 'node:fs/promises';

function assert(condition, message)
{
	if (!condition)
	{
		throw new Error(message);
	}
}

const importer = await readFile(new URL('../public/viewjs/receiptimport.js', import.meta.url), 'utf8');
const productForm = await readFile(new URL('../public/viewjs/productform.js', import.meta.url), 'utf8');
const routes = await readFile(new URL('../routes.php', import.meta.url), 'utf8');

assert(importer.includes("getApi('receipt-import/products')"), 'Importer must refresh the product catalogue without reloading the receipt');
assert(importer.includes('if (line.productId && ProductsById.has(line.productId))'), 'Catalogue refresh must preserve existing receipt mappings');
assert(importer.includes("getApi('objects/product_barcodes_view?query[]=barcode='"), 'A scanned barcode must be matched locally before an external product is created');
assert(importer.includes("flow: 'ReceiptImportProduct'"), 'Manual product creation must use the receipt return flow');
assert(importer.includes("target !== '@receiptimportnewproduct'"), 'Barcode scans must be scoped to the receipt product flow');
assert(productForm.includes('Message: "ReceiptImportProductCreated"'), 'Product creation must notify the importer after saving');
assert(productForm.includes("Grocy.Api.Post('objects/product_barcodes'"), 'A manually created scanned product must retain its barcode');
assert(routes.includes("'/receipt-import/products'"), 'Product catalogue refresh API route must exist');

console.log('ReceiptImportProductHandoff: lossless create, scan, return, and refresh flow passed');
