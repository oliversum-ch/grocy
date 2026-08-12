<?php

namespace Grocy\Services;

class ReceiptImportService extends BaseService
{
	private const MAX_RAW_TEXT_LENGTH = 250000;
	private const MAX_LINE_COUNT = 250;

	private ReceiptImportParser $Parser;

	public function __construct()
	{
		$this->Parser = new ReceiptImportParser();
	}

	public function GetProductCatalog(): array
	{
		$sql = "SELECT p.id,
			p.name,
			qs.name AS stock_unit_name,
			qs.name_plural AS stock_unit_name_plural,
			qp.name AS purchase_unit_name,
			qp.name_plural AS purchase_unit_name_plural,
			d.qu_factor_purchase_to_stock AS purchase_to_stock_factor
		FROM products p
		JOIN quantity_units qs ON qs.id = p.qu_id_stock
		JOIN quantity_units qp ON qp.id = p.qu_id_purchase
		JOIN uihelper_product_details d ON d.id = p.id
		WHERE p.active = 1 AND p.no_own_stock = 0
		ORDER BY p.name COLLATE NOCASE";

		return $this->getDatabaseService()->GetDbConnectionRaw()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function GetShoppingLocations(): array
	{
		return $this->getDatabaseService()->GetDbConnectionRaw()
			->query('SELECT id, name FROM shopping_locations WHERE active = 1 ORDER BY name COLLATE NOCASE')
			->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function Preview(string $rawText, string $receiptHash): array
	{
		$this->ValidateRawText($rawText);
		$this->ValidateHash($receiptHash);

		$receipt = $this->Parser->Parse($rawText);
		$storedReceiptHash = $this->GetStoredReceiptHash($receipt, $receiptHash);
		$receipt['duplicate'] = $this->FindDuplicate($storedReceiptHash);
		$products = $this->GetProductCatalog();
		$productsByNormalizedName = [];

		foreach ($products as $product)
		{
			$productsByNormalizedName[$this->Parser->NormalizeLabel($product['name'])] = $product;
		}

		$aliasStatement = $this->getDatabaseService()->GetDbConnectionRaw()->prepare(
			'SELECT a.product_id, a.amount_multiplier, p.name
			 FROM receipt_import_aliases a
			 JOIN products p ON p.id = a.product_id
			 WHERE a.retailer_key = ? AND a.normalized_label = ? AND p.active = 1 AND p.no_own_stock = 0'
		);

		foreach ($receipt['items'] as &$item)
		{
			$aliasStatement->execute([$receipt['retailer_key'], $item['normalized_label']]);
			$alias = $aliasStatement->fetch(\PDO::FETCH_ASSOC);
			$item['match'] = null;
			$item['suggestions'] = [];

			if ($alias !== false)
			{
				$item['match'] = [
					'product_id' => intval($alias['product_id']),
					'product_name' => $alias['name'],
					'amount_multiplier' => floatval($alias['amount_multiplier']),
					'source' => 'learned_alias'
				];
			}
			elseif (isset($productsByNormalizedName[$item['normalized_label']]))
			{
				$product = $productsByNormalizedName[$item['normalized_label']];
				$item['match'] = [
					'product_id' => intval($product['id']),
					'product_name' => $product['name'],
					'amount_multiplier' => null,
					'source' => 'exact_product_name'
				];
			}

			$item['suggestions'] = $this->FindSuggestions($item['normalized_label'], $products);
		}
		unset($item);

		return $receipt;
	}

	public function Commit(string $rawText, string $receiptHash, ?string $sourceFilename, ?int $shoppingLocationId, array $selectedLines, int $userId): array
	{
		$this->ValidateRawText($rawText);
		$this->ValidateHash($receiptHash);
		$receipt = $this->Parser->Parse($rawText);
		$storedReceiptHash = $this->GetStoredReceiptHash($receipt, $receiptHash);

		if (count($receipt['items']) > self::MAX_LINE_COUNT)
		{
			throw new \InvalidArgumentException('The receipt contains too many line items');
		}

		$selectedByIndex = $this->ValidateSelectedLines($selectedLines, count($receipt['items']));
		$this->ValidateShoppingLocation($shoppingLocationId);
		if ($this->FindDuplicate($storedReceiptHash) !== null)
		{
			throw new \InvalidArgumentException('This receipt has already been imported');
		}

		$pdo = $this->getDatabaseService()->GetDbConnectionRaw();
		$pdo->beginTransaction();

		try
		{
			$productIdsToCompact = [];
			$insertReceipt = $pdo->prepare(
				'INSERT INTO receipt_imports
				 (receipt_hash, retailer_key, retailer_name, receipt_date, currency, receipt_total, discount_total, shopping_location_id, source_filename, raw_text, status, user_id)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
			);
			$insertReceipt->execute([
				$storedReceiptHash,
				$receipt['retailer_key'],
				$receipt['retailer_name'],
				$receipt['receipt_date'],
				$receipt['currency'],
				$receipt['receipt_total'],
				$receipt['discount_total'],
				$shoppingLocationId,
				$this->CleanFilename($sourceFilename),
				$rawText,
				'imported',
				$userId
			]);
			$receiptImportId = intval($pdo->lastInsertId());

			$insertLine = $pdo->prepare(
				'INSERT INTO receipt_import_lines
				 (receipt_import_id, line_index, raw_label, normalized_label, product_id, receipt_quantity, receipt_unit, stock_amount, gross_total, discount_total, net_total, unit_price, best_before_date, transaction_id)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
			);
			$upsertAlias = $pdo->prepare(
				'INSERT INTO receipt_import_aliases (retailer_key, normalized_label, product_id, amount_multiplier)
				 VALUES (?, ?, ?, ?)
				 ON CONFLICT(retailer_key, normalized_label) DO UPDATE SET
				 product_id = excluded.product_id,
				 amount_multiplier = excluded.amount_multiplier,
				 row_updated_timestamp = datetime(\'now\', \'localtime\')'
			);

			$importedItems = [];
			foreach ($receipt['items'] as $item)
			{
				if (!isset($selectedByIndex[$item['line_index']]))
				{
					continue;
				}

				$selection = $selectedByIndex[$item['line_index']];
				$product = $this->GetValidatedProduct($selection['product_id']);
				$stockAmount = $selection['stock_amount'];
				$unitPrice = round($item['net_total'] / $stockAmount, 6);
				$bestBeforeDate = $this->ResolveBestBeforeDate($product, $receipt['receipt_date']);
				$transactionId = null;
				$note = sprintf('Receipt import #%d: %s', $receiptImportId, mb_substr($item['raw_label'], 0, 120));

				$this->getStockService()->AddProduct(
					intval($product['id']),
					$stockAmount,
					$bestBeforeDate,
					StockService::TRANSACTION_TYPE_PURCHASE,
					$receipt['receipt_date'],
					$unitPrice,
					null,
					$shoppingLocationId,
					$transactionId,
					0,
					false,
					$note,
					false
				);

				if (empty($transactionId) || !$this->TransactionExists($transactionId, intval($product['id'])))
				{
					throw new \RuntimeException('A Grocy stock transaction could not be verified');
				}

				$insertLine->execute([
					$receiptImportId,
					$item['line_index'],
					$item['raw_label'],
					$item['normalized_label'],
					$product['id'],
					$item['receipt_quantity'],
					$item['receipt_unit'],
					$stockAmount,
					$item['gross_total'],
					$item['discount_total'],
					$item['net_total'],
					$unitPrice,
					$bestBeforeDate,
					$transactionId
				]);

				$amountMultiplier = $stockAmount / $item['receipt_quantity'];
				$upsertAlias->execute([
					$receipt['retailer_key'],
					$item['normalized_label'],
					$product['id'],
					$amountMultiplier
				]);

				$importedItems[] = [
					'line_index' => $item['line_index'],
					'product_id' => intval($product['id']),
					'product_name' => $product['name'],
					'stock_amount' => $stockAmount,
					'stock_unit_name' => $product['stock_unit_name'],
					'unit_price' => $unitPrice,
					'transaction_id' => $transactionId
				];
				$productIdsToCompact[intval($product['id'])] = true;
			}

			$pdo->commit();
			$warnings = [];
			foreach (array_keys($productIdsToCompact) as $productId)
			{
				try
				{
					$this->getStockService()->CompactStockEntries($productId);
				}
				catch (\Throwable $ex)
				{
					$warnings[] = sprintf('Stock entries for product %d could not be compacted automatically', $productId);
				}
			}

			return [
				'receipt_import_id' => $receiptImportId,
				'status' => 'imported',
				'items' => $importedItems,
				'warnings' => $warnings
			];
		}
		catch (\Throwable $ex)
		{
			if ($pdo->inTransaction())
			{
				$pdo->rollBack();
			}
			throw $ex;
		}
	}

	public function Undo(int $receiptImportId): array
	{
		$pdo = $this->getDatabaseService()->GetDbConnectionRaw();
		$receiptStatement = $pdo->prepare('SELECT id, status FROM receipt_imports WHERE id = ?');
		$receiptStatement->execute([$receiptImportId]);
		$receipt = $receiptStatement->fetch(\PDO::FETCH_ASSOC);
		if ($receipt === false)
		{
			throw new \InvalidArgumentException('Receipt import not found');
		}
		if ($receipt['status'] !== 'imported')
		{
			throw new \InvalidArgumentException('This receipt import has already been undone');
		}

		$transactionStatement = $pdo->prepare('SELECT transaction_id FROM receipt_import_lines WHERE receipt_import_id = ? ORDER BY id DESC');
		$transactionStatement->execute([$receiptImportId]);
		$transactionIds = $transactionStatement->fetchAll(\PDO::FETCH_COLUMN);
		if (count($transactionIds) === 0)
		{
			throw new \RuntimeException('This receipt import has no recorded stock transactions');
		}

		$pdo->beginTransaction();
		try
		{
			foreach ($transactionIds as $transactionId)
			{
				$this->getStockService()->UndoTransaction($transactionId);
			}

			$update = $pdo->prepare("UPDATE receipt_imports SET status = 'undone', undone_timestamp = datetime('now', 'localtime') WHERE id = ?");
			$update->execute([$receiptImportId]);
			$pdo->commit();
		}
		catch (\Throwable $ex)
		{
			if ($pdo->inTransaction())
			{
				$pdo->rollBack();
			}
			throw $ex;
		}

		return ['receipt_import_id' => $receiptImportId, 'status' => 'undone'];
	}

	public function GetHistory(int $limit = 20): array
	{
		$limit = max(1, min(100, $limit));
		$sql = "SELECT r.id, r.retailer_name, r.receipt_date, r.currency, r.receipt_total, r.discount_total,
			r.status, r.source_filename, r.row_created_timestamp, r.undone_timestamp,
			s.name AS shopping_location_name, COUNT(l.id) AS imported_line_count
		FROM receipt_imports r
		LEFT JOIN receipt_import_lines l ON l.receipt_import_id = r.id
		LEFT JOIN shopping_locations s ON s.id = r.shopping_location_id
		GROUP BY r.id
		ORDER BY r.id DESC
		LIMIT " . $limit;

		return $this->getDatabaseService()->GetDbConnectionRaw()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
	}

	private function FindDuplicate(string $receiptHash): ?array
	{
		$statement = $this->getDatabaseService()->GetDbConnectionRaw()->prepare(
			'SELECT id, retailer_name, receipt_date, receipt_total, status FROM receipt_imports WHERE receipt_hash = ?'
		);
		$statement->execute([$receiptHash]);
		$result = $statement->fetch(\PDO::FETCH_ASSOC);
		return $result === false ? null : $result;
	}

	private function GetStoredReceiptHash(array $receipt, string $fileHash): string
	{
		if (!empty($receipt['receipt_reference']))
		{
			return hash('sha256', implode('|', [
				$receipt['retailer_key'],
				$receipt['receipt_date'],
				$receipt['receipt_reference'],
				number_format(floatval($receipt['receipt_total']), 2, '.', '')
			]));
		}

		return $fileHash;
	}

	private function FindSuggestions(string $normalizedLabel, array $products): array
	{
		$suggestions = [];
		foreach ($products as $product)
		{
			$normalizedProduct = $this->Parser->NormalizeLabel($product['name']);
			$maxLength = max(strlen($normalizedLabel), strlen($normalizedProduct));
			if ($maxLength === 0)
			{
				continue;
			}

			$score = 1 - (levenshtein($normalizedLabel, $normalizedProduct) / $maxLength);
			if (str_contains($normalizedProduct, $normalizedLabel) || str_contains($normalizedLabel, $normalizedProduct))
			{
				$score += 0.12;
			}

			if ($score >= 0.35)
			{
				$suggestions[] = [
					'product_id' => intval($product['id']),
					'product_name' => $product['name'],
					'score' => round(min(1, $score), 3)
				];
			}
		}

		usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);
		return array_slice($suggestions, 0, 5);
	}

	private function ValidateSelectedLines(array $selectedLines, int $parsedLineCount): array
	{
		$selectedByIndex = [];
		foreach ($selectedLines as $selection)
		{
			if (!is_array($selection) || !isset($selection['line_index'], $selection['product_id'], $selection['stock_amount']))
			{
				throw new \InvalidArgumentException('Every selected receipt line needs a line index, product and stock amount');
			}

			$lineIndex = filter_var($selection['line_index'], FILTER_VALIDATE_INT);
			$productId = filter_var($selection['product_id'], FILTER_VALIDATE_INT);
			$stockAmount = filter_var($selection['stock_amount'], FILTER_VALIDATE_FLOAT);
			if ($lineIndex === false || $lineIndex < 0 || $lineIndex >= $parsedLineCount || isset($selectedByIndex[$lineIndex]))
			{
				throw new \InvalidArgumentException('A selected receipt line index is invalid or duplicated');
			}
			if ($productId === false || $productId <= 0)
			{
				throw new \InvalidArgumentException('A selected Grocy product is invalid');
			}
			if ($stockAmount === false || !is_finite($stockAmount) || $stockAmount <= 0 || $stockAmount > 1000000)
			{
				throw new \InvalidArgumentException('A selected stock amount is invalid');
			}

			$selectedByIndex[$lineIndex] = [
				'product_id' => intval($productId),
				'stock_amount' => floatval($stockAmount)
			];
		}

		if (count($selectedByIndex) === 0)
		{
			throw new \InvalidArgumentException('Select at least one receipt line to import');
		}

		return $selectedByIndex;
	}

	private function GetValidatedProduct(int $productId): array
	{
		$statement = $this->getDatabaseService()->GetDbConnectionRaw()->prepare(
			'SELECT p.*, qs.name AS stock_unit_name, l.is_freezer AS default_location_is_freezer
			 FROM products p
			 JOIN quantity_units qs ON qs.id = p.qu_id_stock
			 LEFT JOIN locations l ON l.id = p.location_id
			 WHERE p.id = ? AND p.active = 1 AND p.no_own_stock = 0'
		);
		$statement->execute([$productId]);
		$product = $statement->fetch(\PDO::FETCH_ASSOC);
		if ($product === false)
		{
			throw new \InvalidArgumentException('A selected Grocy product does not exist, is inactive, or cannot hold stock');
		}

		return $product;
	}

	private function ResolveBestBeforeDate(array $product, string $purchasedDate): string
	{
		$days = intval($product['default_best_before_days']);
		if (defined('GROCY_FEATURE_FLAG_STOCK_PRODUCT_FREEZING')
			&& GROCY_FEATURE_FLAG_STOCK_PRODUCT_FREEZING
			&& intval($product['default_location_is_freezer'] ?? 0) === 1
			&& intval($product['default_best_before_days_after_freezing']) >= -1)
		{
			$days = intval($product['default_best_before_days_after_freezing']);
		}

		if ($days === -1)
		{
			return '2999-12-31';
		}

		$date = new \DateTimeImmutable($purchasedDate);
		return $date->modify('+' . max(0, $days) . ' days')->format('Y-m-d');
	}

	private function ValidateShoppingLocation(?int $shoppingLocationId): void
	{
		if ($shoppingLocationId === null)
		{
			return;
		}

		$statement = $this->getDatabaseService()->GetDbConnectionRaw()->prepare('SELECT COUNT(*) FROM shopping_locations WHERE id = ? AND active = 1');
		$statement->execute([$shoppingLocationId]);
		if (intval($statement->fetchColumn()) !== 1)
		{
			throw new \InvalidArgumentException('The selected store does not exist or is inactive');
		}
	}

	private function TransactionExists(string $transactionId, int $productId): bool
	{
		$statement = $this->getDatabaseService()->GetDbConnectionRaw()->prepare(
			'SELECT COUNT(*) FROM stock_log WHERE transaction_id = ? AND product_id = ? AND undone = 0'
		);
		$statement->execute([$transactionId, $productId]);
		return intval($statement->fetchColumn()) > 0;
	}

	private function ValidateRawText(string $rawText): void
	{
		$length = strlen($rawText);
		if ($length === 0 || $length > self::MAX_RAW_TEXT_LENGTH)
		{
			throw new \InvalidArgumentException('Receipt text is empty or too large');
		}
	}

	private function ValidateHash(string $receiptHash): void
	{
		if (preg_match('/^[a-f0-9]{64}$/', $receiptHash) !== 1)
		{
			throw new \InvalidArgumentException('The receipt fingerprint is invalid');
		}
	}

	private function CleanFilename(?string $filename): ?string
	{
		if ($filename === null || trim($filename) === '')
		{
			return null;
		}

		$filename = basename(str_replace('\\', '/', $filename));
		$filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
		return mb_substr($filename, 0, 255);
	}
}
