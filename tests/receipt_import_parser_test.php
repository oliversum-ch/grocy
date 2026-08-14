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

$aldiFixture = file_get_contents(__DIR__ . '/fixtures/aldi-ch-20260813.txt');
$aldiReceipt = $parser->Parse($aldiFixture);
assertSameValue('aldi_ch', $aldiReceipt['retailer_key'], 'Aldi retailer key');
assertSameValue('Aldi Switzerland', $aldiReceipt['retailer_name'], 'Aldi retailer name');
assertSameValue('2026-08-13', $aldiReceipt['receipt_date'], 'Aldi receipt date');
assertSameValue('8001 Zürich, Stadelhoferstrasse 10', $aldiReceipt['store_label'], 'Aldi store label');
assertSameValue('CHF', $aldiReceipt['currency'], 'Aldi currency');
assertSameValue(4, count($aldiReceipt['items']), 'Aldi line item count');
assertNear(6.60, $aldiReceipt['receipt_total'], 'Aldi receipt total');
assertNear(6.60, $aldiReceipt['parsed_total'], 'Aldi reconciled line total');
assertNear(0.02, $aldiReceipt['discount_total'], 'Aldi rounding adjustment allocation');
assertSameValue('Jumbo Erdnüsse', $aldiReceipt['items'][0]['raw_label'], 'Aldi article number removal');
assertNear(1.17, $aldiReceipt['items'][3]['net_total'], 'Aldi rounding is allocated to the last line');

$ocrDamagedAldiReceipt = $parser->Parse(implode("\n", [
	'ALDI SUISSE AG',
	'8001 ZÃ¼rich',
	'13.08.26 10:55',
	'CHF',
	'Jumbo ErdnÃ¼sse 1.79 A',
	'Low Carb Riegel 2.69 A',
	'Fladenbrot 0.95 A',
	'Cracker Mix 1.19 A',
	'Zwischensumme 6.62',
	'Rundung -0.02',
	'fLDI PREIS 6.60',
	'Kartenzahlung CHF 6.60'
]));
assertNear(6.60, $ocrDamagedAldiReceipt['receipt_total'], 'OCR-damaged Aldi total label');
assertSameValue(4, count($ocrDamagedAldiReceipt['items']), 'OCR-damaged Aldi line item count');

$mobileAldiOcr = implode("\n", [
	'ALDI SUISSE AG',
	'8001 ZÃ¼rich',
	'13.08.26 10:55',
	'CHF',
	'27622 Jumbo Erdniss 1.79 A',
	'556816 Low Carb Riegel HE',
	'433984 Flachpfir. 500g ).95 4',
	'157241 Cracker Mix 300 g 1.19 A',
	'Zwischensumme 6.62',
	'Rundung -0.02',
	'fLDI PREIS 6.60',
	'4 Artikel',
	'Kartenzahlung CHF 6.60'
]);
$mobileAldiReceipt = $parser->Parse($mobileAldiOcr);
assertSameValue(4, count($mobileAldiReceipt['items']), 'Mobile Aldi OCR recovers all declared article lines');
assertSameValue('Low Carb Riegel', $mobileAldiReceipt['items'][1]['raw_label'], 'Mobile Aldi inferred article label');
assertNear(2.69, $mobileAldiReceipt['items'][1]['gross_total'], 'Mobile Aldi inferred missing price');
assertSameValue(true, $mobileAldiReceipt['items'][1]['price_inferred'], 'Mobile Aldi inferred price is marked for review');
assertNear(0.95, $mobileAldiReceipt['items'][2]['gross_total'], 'Mobile Aldi repairs OCR zero in decimal price');
assertNear(6.60, $mobileAldiReceipt['parsed_total'], 'Mobile Aldi OCR receipt reconciles');
assertThrows(
	fn() => $parser->Parse(str_replace("4 Artikel\n", '', $mobileAldiOcr)),
	'does not match receipt total',
	'Missing prices must not be inferred without a matching printed article count'
);

$capturedMobileAldiOcr = implode("\n", [
	'ALDI SUISSE AG',
	'8001 7 icl',
	'Stadelhoferstrasse 1(',
	'13.08.26 10:55',
	'CHF',
	'7622 Jumbo Erdniiss 1.79 A',
	'0816 ow Carb Riege] 2.69 4',
	'433984 Flachofi, 500g 95 A',
	'157241 Cracke, Mix 300 g 1.19 A',
	'Zwischensumme 6.62',
	'Rundung 0.07',
	'ALDI PREIS 6.60',
	'4 Artikel',
	'Kartenzahlung CHF 5.60'
]);
$capturedMobileAldiReceipt = $parser->Parse($capturedMobileAldiOcr);
assertSameValue(4, count($capturedMobileAldiReceipt['items']), 'Captured mobile Aldi OCR recovers all four article lines');
assertNear(0.95, $capturedMobileAldiReceipt['items'][2]['gross_total'], 'Captured mobile Aldi OCR infers the remaining damaged price');
assertSameValue('Flachofi, 500g', $capturedMobileAldiReceipt['items'][2]['raw_label'], 'Captured mobile Aldi OCR removes dangling cents from the inferred label');
assertNear(6.60, $capturedMobileAldiReceipt['parsed_total'], 'Captured mobile Aldi OCR reconciles using subtotal-derived rounding');

$splitAldiTotalOcr = implode("\n", [
	'ALDT SUISSE AG',
	'8001 ZÃƒÂ¼rich',
	'Stadelhoferstrasse 10',
	'CHF',
	'622 Jumbo ErdnÃƒÂ¼ss, 1.79 4',
	'pe = Carb Riegel 2.694',
	'157201 flachpfir, 500g 0.954',
	': racker Mix 300 g 1.194',
	'Zwischen u Ensumme a',
	'Run 6,62',
	're -0.02',
	'ALDI PREI',
	'- S 4 Artike] 6.60',
	'Kartenzahiung CHE 5.60',
	'*5107 E190/003/801 13.08.26 10:55',
	'aldi-suisse.ch'
]);
$splitAldiTotalReceipt = $parser->Parse($splitAldiTotalOcr);
assertSameValue('aldi_ch', $splitAldiTotalReceipt['retailer_key'], 'Split-total Aldi retailer');
assertNear(6.60, $splitAldiTotalReceipt['receipt_total'], 'Split ALDI PREIS label retains its following total');
assertSameValue(4, count($splitAldiTotalReceipt['items']), 'Split-total Aldi keeps all product lines');
assertNear(6.60, $splitAldiTotalReceipt['parsed_total'], 'Split-total Aldi reconciles its two-cent rounding');

$paddleAldiOcr = implode("\n", [
	'ALDI SUISSE AG',
	'8001 zÃƒÂ¼r ich',
	'Stadelhoferstrasse 10',
	'CHF',
	'297622 Jumbo ErdnÃƒÂ¼ss. 1.79 A',
	'556816 Low Carb Riegel 2.69 A',
	'433984 Flachpf ir. 500g 0.95 A',
	'157241 Cracker Mix 300 g 1.19 A',
	'Zwischensumme 6.62',
	'Rundung -0.02',
	'ALDI PREIS 6.60',
	'4 Artikel',
	'Kartenzahlung CHF 6.60',
	'A 02.6% Netto 6.45 MWSt 0.17',
	'*5107 E190/003/80113.08.2610:55',
	'CHE-110.576.236 WST'
]);
$paddleAldiReceipt = $parser->Parse($paddleAldiOcr);
assertSameValue('aldi_ch', $paddleAldiReceipt['retailer_key'], 'PaddleOCR Aldi retailer');
assertSameValue('2026-08-13', $paddleAldiReceipt['receipt_date'], 'PaddleOCR Aldi joined date and time');
assertSameValue(4, count($paddleAldiReceipt['items']), 'PaddleOCR Aldi line item count');
assertNear(6.60, $paddleAldiReceipt['receipt_total'], 'PaddleOCR Aldi total');
assertNear(6.60, $paddleAldiReceipt['parsed_total'], 'PaddleOCR Aldi reconciliation');

$paddleLidlOcr = implode("\n", [
	'Digitaler Kassenbon',
	'Lidl Schweiz',
	'CHF',
	'High Protein Drink Pista 1.25 A',
	'Lidl Plus Rabatt -0.26',
	'BIo Karotten 3.29 A',
	'Energy Drink light 0,51 9.75 A',
	'25 x 0.39',
	'zu zahlen 14.03',
	'Karte 14.03',
	'14.08.2026 10:40:08',
	'0275 278747/81 14.08.26 10:37',
	'Zurich (FiLNr. 0275)',
	'8001 ZÃƒÂ¼rich'
]);
$paddleLidlReceipt = $parser->Parse($paddleLidlOcr);
assertSameValue('lidl_ch', $paddleLidlReceipt['retailer_key'], 'PaddleOCR Lidl retailer');
assertSameValue(3, count($paddleLidlReceipt['items']), 'PaddleOCR Lidl line item count');
assertNear(14.03, $paddleLidlReceipt['receipt_total'], 'PaddleOCR Lidl total');
assertNear(14.03, $paddleLidlReceipt['parsed_total'], 'PaddleOCR Lidl reconciliation');

$genericReceipt = $parser->Parse(implode("\n", [
	'Corner Market',
	'18 High Street',
	'14/08/2026 17:42',
	'EUR',
	'Milk 1.50',
	'Bread 2.25',
	'Grand Total 3.75'
]));
assertSameValue('corner_market', $genericReceipt['retailer_key'], 'Unknown retailer gets a stable key');
assertSameValue('Corner Market', $genericReceipt['retailer_name'], 'Unknown retailer name');
assertSameValue('2026-08-14', $genericReceipt['receipt_date'], 'Generic slash date');
assertSameValue('EUR', $genericReceipt['currency'], 'Generic currency');
assertSameValue(2, count($genericReceipt['items']), 'Generic line item count');
assertNear(3.75, $genericReceipt['receipt_total'], 'Generic receipt total');
assertSameValue(true, $genericReceipt['is_reconciled'], 'Generic receipt reconciliation');

$quantityReceipt = $parser->Parse(implode("\n", [
	'Neighbourhood Foods',
	'2026-08-15 09:10',
	'EUR',
	'2 x Oat Drink 1.50 3.00',
	'Apples 2.50',
	'0.500 kg x 5.00 EUR/kg',
	'Coupon -0.50',
	'Total 5.00'
]));
assertSameValue(2, count($quantityReceipt['items']), 'Generic quantity line item count');
assertSameValue('Oat Drink', $quantityReceipt['items'][0]['raw_label'], 'Inline quantity label');
assertNear(2, $quantityReceipt['items'][0]['receipt_quantity'], 'Inline quantity');
assertNear(1.50, $quantityReceipt['items'][0]['listed_unit_price'], 'Inline unit price');
assertSameValue('kg', $quantityReceipt['items'][1]['receipt_unit'], 'Generic weighted unit');
assertNear(0.5, $quantityReceipt['items'][1]['receipt_quantity'], 'Generic weighted quantity');
assertNear(0.5, $quantityReceipt['items'][1]['discount_total'], 'Generic line discount');
assertNear(5.00, $quantityReceipt['parsed_total'], 'Generic quantity receipt reconciliation');

$spaceDecimalReceipt = $parser->Parse("Tiny Shop\n16.08.26\nCHF\nTea 1 50\nTotal 1 50");
assertNear(1.50, $spaceDecimalReceipt['items'][0]['net_total'], 'OCR space decimal item');
assertNear(1.50, $spaceDecimalReceipt['receipt_total'], 'OCR space decimal total');

$brokenGeneric = "Corner Market\n14/08/2026\nMilk 1.50\nBread 2.25\nTotal 4.25";
assertThrows(fn() => $parser->Parse($brokenGeneric), 'does not match receipt total', 'Generic mismatched totals must be rejected');

echo "ReceiptImportParser: all tests passed\n";
