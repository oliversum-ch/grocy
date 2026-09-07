<?php

declare(strict_types=1);

function assertUpgrade471(bool $condition, string $message): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
}

function sourceFile(string $path): string
{
	$contents = file_get_contents(__DIR__ . '/../' . $path);
	assertUpgrade471($contents !== false, "Unable to read $path");
	return $contents;
}

$version = json_decode(sourceFile('version.json'), true, 512, JSON_THROW_ON_ERROR);
assertUpgrade471(($version['Version'] ?? null) === '4.7.1', 'The merged version must be Grocy 4.7.1');

$app = sourceFile('app.php');
assertUpgrade471(str_contains($app, '$migrationFingerprint'), 'Custom migration fingerprinting was lost');
assertUpgrade471(str_contains($app, '$hashInput .= file_get_contents'), 'Upstream dev route cache invalidation was lost');

$auth = sourceFile('middleware/Auth/BaseAuthMiddleware.php');
assertUpgrade471(str_contains($auth, "getRoute()->getPattern(), '/api/'"), 'Subdirectory-safe API route detection was lost');
assertUpgrade471(str_contains(sourceFile('public/viewjs/login.js'), 'new TextEncoder()'), 'UTF-8 password encoding was lost');

$routes = sourceFile('routes.php');
assertUpgrade471(str_contains($routes, "'/stock/products/{productId}/copy'"), 'The Grocy 4.7.1 product-copy endpoint is missing');
assertUpgrade471(str_contains($routes, "'/receipt-import/preview'"), 'The custom receipt importer routes were lost');

$stockService = sourceFile('services/StockService.php');
assertUpgrade471(str_contains($stockService, 'function CopyProduct('), 'The Grocy 4.7.1 product-copy service is missing');
assertUpgrade471(str_contains($stockService, "['__userfields']"), 'The custom OpenFoodFacts userfield import was lost');
assertUpgrade471(str_contains($stockService, '$compactStockEntries = true'), 'The custom receipt stock-compaction control was lost');

assertUpgrade471(str_contains(sourceFile('public/viewjs/equipment.js'), 'selected-equipment-location-name'), 'The custom equipment location display was lost');
assertUpgrade471(str_contains(sourceFile('public/viewjs/productform.js'), 'ReceiptImportProductCreated'), 'The custom receipt product handoff was lost');
assertUpgrade471(str_contains(sourceFile('public/viewjs/stockjournal.js'), '$("#daterange-filter").val("0");'), 'The custom Today stock-journal default was lost');

echo "Grocy471Integration: upstream and custom overlap checks passed\n";
