<?php

require_once __DIR__ . '/../services/ReceiptImportParser.php';

use Grocy\Services\ReceiptImportParser;

function assertSameValue($expected, $actual, string $message): void
{
	if ($expected !== $actual)
	{
		throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
	}
}

function assertNear(float $expected, float $actual, string $message, float $epsilon = 0.0001): void
{
	if (abs($expected - $actual) > $epsilon)
	{
		throw new RuntimeException($message . "\nExpected: $expected\nActual: $actual");
	}
}

function assertThrows(callable $callback, string $expectedMessage, string $message): void
{
	try
	{
		$callback();
	}
	catch (Throwable $ex)
	{
		if (!str_contains($ex->getMessage(), $expectedMessage))
		{
			throw new RuntimeException($message . '\nUnexpected exception: ' . $ex->getMessage());
		}
		return;
	}

	throw new RuntimeException($message . '\nNo exception was thrown');
}

$parser = new ReceiptImportParser();
$fixture = file_get_contents(__DIR__ . '/fixtures/lidl-ch-20260812.txt');
$receipt = $parser->Parse($fixture);

assertSameValue('lidl_ch', $receipt['retailer_key'], 'Retailer key');
assertSameValue('Lidl Switzerland', $receipt['retailer_name'], 'Retailer name');
assertSameValue('2026-08-12', $receipt['receipt_date'], 'Receipt date');
assertSameValue('Zürich', $receipt['store_label'], 'Store label');
assertSameValue('CHF', $receipt['currency'], 'Currency');
assertSameValue('0275-087019/90', $receipt['receipt_reference'], 'Receipt reference');
assertSameValue(10, count($receipt['items']), 'Line item count');
assertNear(25.74, $receipt['receipt_total'], 'Receipt total');
assertNear(25.74, $receipt['parsed_total'], 'Parsed total');
assertNear(3.58, $receipt['discount_total'], 'Discount total');
assertSameValue(true, $receipt['is_reconciled'], 'Reconciliation status');

$cordonBleu = $receipt['items'][0];
assertSameValue('Veganes Cordon Bleu', $cordonBleu['raw_label'], 'First product label');
assertSameValue('piece', $cordonBleu['receipt_unit'], 'First product unit');
assertNear(3, $cordonBleu['receipt_quantity'], 'First product quantity');
assertNear(2.55, $cordonBleu['listed_unit_price'], 'First product listed unit price');
assertNear(1.62, $cordonBleu['discount_total'], 'First product discount');
assertNear(6.03, $cordonBleu['net_total'], 'First product net total');

$broccoli = $receipt['items'][3];
assertSameValue('Broccoli', $broccoli['raw_label'], 'Weighted product label');
assertSameValue('kg', $broccoli['receipt_unit'], 'Weighted product unit');
assertNear(0.542, $broccoli['receipt_quantity'], 'Weighted product quantity');
assertNear(4.49, $broccoli['listed_unit_price'], 'Weighted product listed price');
assertNear(2.25, $broccoli['net_total'], 'Weighted product net total');

assertSameValue('high protein griesspuddin', $parser->NormalizeLabel('High Protein Grießpuddin'), 'Swiss/German label normalization');

$commaReceipt = str_replace(['7.65 A', '3 x 2.55', '25.74'], ['7,65 A', '3 x 2,55', '25,74'], $fixture);
$commaParsed = $parser->Parse($commaReceipt);
assertNear(25.74, $commaParsed['receipt_total'], 'OCR decimal comma receipt total');
assertNear(2.55, $commaParsed['items'][0]['listed_unit_price'], 'OCR decimal comma unit price');

$brokenTotal = preg_replace('/zu zahlen 25[.]74/', 'zu zahlen 25.80', $fixture);
assertThrows(fn() => $parser->Parse($brokenTotal), 'does not match receipt total', 'Mismatched totals must be rejected');
assertThrows(fn() => $parser->Parse("Other store\nMilk 2.00\nTotal 2.00"), 'supports Lidl Switzerland', 'Unsupported retailers must be rejected');

echo "ReceiptImportParser: all tests passed\n";
