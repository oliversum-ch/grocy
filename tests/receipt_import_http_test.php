<?php

declare(strict_types=1);

function assertCondition(bool $condition, string $message): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
}

function requestJson(string $method, string $url, ?array $body = null): array
{
	$curl = curl_init($url);
	$options = [
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => false,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTPHEADER => ['Content-Type: application/json']
	];
	if ($body !== null)
	{
		$options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
	}
	curl_setopt_array($curl, $options);
	$responseBody = curl_exec($curl);
	if ($responseBody === false)
	{
		throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
	}
	$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	$data = json_decode($responseBody, true);
	return ['status' => $status, 'data' => $data, 'raw' => $responseBody];
}

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8765', '/');
$databasePath = $argv[2] ?? (__DIR__ . '/../_receipt_qa/grocy_de.db');
$rawText = (string)file_get_contents(__DIR__ . '/fixtures/lidl-ch-20260812.txt');
$receiptHash = hash('sha256', $rawText);
$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$product = $db->query("SELECT id, name FROM products WHERE active = 1 AND no_own_stock = 0 ORDER BY id LIMIT 1")
	->fetch(PDO::FETCH_ASSOC);
assertCondition($product !== false, 'The test database needs one active stock product');
$productId = (int)$product['id'];
$stockBefore = (float)$db->query("SELECT IFNULL(SUM(amount), 0) FROM stock WHERE product_id = $productId")->fetchColumn();

$catalog = requestJson('GET', $baseUrl . '/api/receipt-import/products');
assertCondition($catalog['status'] === 200, 'Product catalogue refresh should return HTTP 200: ' . $catalog['raw']);
assertCondition(count($catalog['data'] ?? []) > 0, 'Product catalogue refresh should return active stock products');
assertCondition(
	count(array_filter($catalog['data'], fn(array $catalogProduct): bool => (int)$catalogProduct['id'] === $productId)) === 1,
	'Product catalogue refresh should contain the selected product'
);

$preview = requestJson('POST', $baseUrl . '/api/receipt-import/preview', [
	'raw_text' => $rawText,
	'receipt_hash' => $receiptHash
]);
assertCondition($preview['status'] === 200, 'Preview should return HTTP 200: ' . $preview['raw']);
assertCondition(abs((float)$preview['data']['receipt_total'] - 25.74) < 0.001, 'Preview total should be CHF 25.74: ' . $preview['raw']);
assertCondition(count($preview['data']['items'] ?? []) === 10, 'Preview should contain ten product lines');
assertCondition($preview['data']['duplicate'] === null, 'A new receipt should not be marked as duplicate');

$commit = requestJson('POST', $baseUrl . '/api/receipt-import/commit', [
	'raw_text' => $rawText,
	'receipt_hash' => $receiptHash,
	'source_filename' => '20260812_lidl.pdf',
	'shopping_location_id' => null,
	'selected_lines' => [[
		'line_index' => (int)$preview['data']['items'][0]['line_index'],
		'product_id' => $productId,
		'stock_amount' => 1
	]]
]);
assertCondition($commit['status'] === 200, 'Commit should return HTTP 200: ' . $commit['raw']);
assertCondition(($commit['data']['status'] ?? null) === 'imported', 'Commit should report imported status');
$receiptImportId = (int)$commit['data']['receipt_import_id'];
$transactionId = (string)$commit['data']['items'][0]['transaction_id'];
assertCondition($receiptImportId > 0 && $transactionId !== '', 'Commit should return receipt and transaction identifiers');

$stockAfterCommit = (float)$db->query("SELECT IFNULL(SUM(amount), 0) FROM stock WHERE product_id = $productId")->fetchColumn();
assertCondition(abs($stockAfterCommit - ($stockBefore + 1)) < 0.000001, 'Commit should add exactly one stock unit');
$lineCount = (int)$db->query("SELECT COUNT(*) FROM receipt_import_lines WHERE receipt_import_id = $receiptImportId AND transaction_id = " . $db->quote($transactionId))->fetchColumn();
assertCondition($lineCount === 1, 'Commit should persist its verified stock transaction');

$duplicatePreview = requestJson('POST', $baseUrl . '/api/receipt-import/preview', [
	'raw_text' => $rawText,
	'receipt_hash' => $receiptHash
]);
assertCondition((int)($duplicatePreview['data']['duplicate']['id'] ?? 0) === $receiptImportId, 'Preview should identify a duplicate receipt');
$duplicateCommit = requestJson('POST', $baseUrl . '/api/receipt-import/commit', [
	'raw_text' => $rawText,
	'receipt_hash' => $receiptHash,
	'selected_lines' => [['line_index' => 0, 'product_id' => $productId, 'stock_amount' => 1]]
]);
assertCondition($duplicateCommit['status'] === 400, 'A duplicate commit should be rejected with HTTP 400');

$undo = requestJson('POST', $baseUrl . '/api/receipt-import/' . $receiptImportId . '/undo', []);
assertCondition($undo['status'] === 200, 'Undo should return HTTP 200: ' . $undo['raw']);
assertCondition(($undo['data']['status'] ?? null) === 'undone', 'Undo should report undone status');
$stockAfterUndo = (float)$db->query("SELECT IFNULL(SUM(amount), 0) FROM stock WHERE product_id = $productId")->fetchColumn();
assertCondition(abs($stockAfterUndo - $stockBefore) < 0.000001, 'Undo should restore the original stock amount');
$receiptStatus = (string)$db->query("SELECT status FROM receipt_imports WHERE id = $receiptImportId")->fetchColumn();
assertCondition($receiptStatus === 'undone', 'Undo should be recorded in receipt history');

// Keep the disposable fixture reusable while preserving the behavior exercised above.
$db->exec("DELETE FROM receipt_import_aliases WHERE retailer_key = 'lidl_ch' AND normalized_label = 'veganes cordon bleu' AND product_id = $productId");
$db->exec("DELETE FROM receipt_import_lines WHERE receipt_import_id = $receiptImportId");
$db->exec("DELETE FROM receipt_imports WHERE id = $receiptImportId");

echo "ReceiptImportHTTP: catalogue refresh, preview, commit, duplicate protection and undo passed\n";
